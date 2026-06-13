<?php
/**
 * Operator dashboard + admin-bar status pill.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Admin {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	const PAGE = 'bext';

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_bext_clear_warnings', array( $this, 'handle_clear_warnings' ) );
	}

	public function menu(): void {
		add_menu_page(
			'Bext',
			'Bext',
			'manage_options',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-cloud',
			81
		);
	}

	public function assets( $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bext-wp-admin', BEXT_WP_URL . '/assets/admin.css', array(), BEXT_WP_VERSION );
	}

	public function admin_bar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$on    = $this->env->is_behind_bext();
		$color = $on ? '#46b450' : '#dc3232';
		$bar->add_node(
			array(
				'id'    => 'bext-status',
				'title' => '<span style="color:' . $color . ';font-size:18px;line-height:1;vertical-align:middle;">&#9679;</span> bext',
				'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
				'meta'  => array( 'title' => $on ? 'Served by bext' : 'bext not detected' ),
			)
		);
	}

	public function notices(): void {
		if ( isset( $_GET['bext_purged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok = '1' === sanitize_text_field( wp_unslash( $_GET['bext_purged'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok ? 'bext cache purged.' : 'bext purge failed — see the Bext dashboard.'
			);
		}
		if ( isset( $_GET['bext_warnings_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Recent warnings cleared.</p></div>';
		}
	}

	public function handle_clear_warnings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'bext_clear_warnings' );
		$health = $this->plugin->module( 'health' );
		if ( $health && method_exists( $health, 'clear_warnings' ) ) {
			$health->clear_warnings();
		}
		wp_safe_redirect( add_query_arg( 'bext_warnings_cleared', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap bext-wrap">';
		echo '<h1><span class="dashicons dashicons-cloud"></span> Bext for WordPress <span class="bext-ver">v' . esc_html( BEXT_WP_VERSION ) . '</span></h1>';

		$this->section_status();
		$this->section_cache();
		$this->section_action_scheduler();
		$this->section_health();
		$this->section_sdk();
		$this->section_server();

		echo '</div>';
	}

	private function section_status(): void {
		$on  = $this->env->is_behind_bext();
		$ver = $this->env->bext_version();
		echo '<div class="bext-card">';
		echo '<h2>Integration</h2>';
		echo '<table class="bext-kv">';
		$this->kv( 'Status', $on ? '<span class="bext-pill ok">Served by bext</span>' : '<span class="bext-pill bad">Not detected</span>', true );
		$this->kv( 'Mode', '<code>' . esc_html( $this->env->mode() ) . '</code> &middot; <a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">Settings</a>', true );
		$this->kv( 'bext version', '' !== $ver ? esc_html( $ver ) : '&mdash;', true );
		$this->kv( 'Canonical host', $this->env->canonical_host() );
		$this->kv( 'App id', $this->env->app_id() );
		$this->kv( 'Purge endpoint', '/__bext/cache/purge-proxy (loopback)' );
		echo '</table>';
		echo '</div>';
	}

	private function section_cache(): void {
		$cache = $this->plugin->module( 'cache' );
		echo '<div class="bext-card">';
		echo '<h2>Edge cache</h2>';

		$purge_url = wp_nonce_url( add_query_arg( 'action', 'bext_purge', admin_url( 'admin-post.php' ) ), 'bext_purge' );
		echo '<p><a class="button button-primary" href="' . esc_url( $purge_url ) . '">Purge entire cache</a></p>';

		$log = ( $cache && method_exists( $cache, 'purge_log' ) ) ? $cache->purge_log() : array();
		if ( empty( $log ) ) {
			echo '<p class="bext-muted">No purges recorded yet. Edit a post or use the button above.</p>';
		} else {
			echo '<table class="widefat striped bext-log"><thead><tr><th>When</th><th>Scope</th><th>Via</th><th>Sample</th></tr></thead><tbody>';
			foreach ( $log as $row ) {
				$when  = isset( $row['time'] ) ? human_time_diff( (int) $row['time'] ) . ' ago' : '&mdash;';
				$scope = ( 'all' === ( $row['count'] ?? '' ) ) ? 'site-wide' : (string) ( $row['count'] ?? 0 ) . ' path(s)';
				$via   = esc_html( (string) ( $row['via'] ?? 'auto' ) );
				$samp  = esc_html( implode( ', ', array_slice( (array) ( $row['sample'] ?? array() ), 0, 6 ) ) );
				echo '<tr><td>' . esc_html( $when ) . '</td><td>' . esc_html( $scope ) . '</td><td>' . $via . '</td><td class="bext-mono">' . $samp . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function section_action_scheduler(): void {
		$cron  = $this->plugin->module( 'cron' );
		$stats = ( $cron && method_exists( $cron, 'stats' ) ) ? $cron->stats() : null;
		echo '<div class="bext-card">';
		echo '<h2>Action Scheduler &amp; cron</h2>';
		if ( ! $stats ) {
			echo '<p class="bext-muted">Action Scheduler is not installed on this site.</p>';
			echo '</div>';
			return;
		}
		echo '<table class="bext-kv">';
		$this->kv( 'Async loopback runner', ! empty( $stats['async_disabled'] ) ? '<span class="bext-pill ok">disabled (system cron)</span>' : '<span class="bext-pill bad">enabled</span>', true );
		$this->kv( 'Pending', $this->num( $stats['pending'] ) );
		$this->kv( 'Running', $this->num( $stats['running'] ) );
		$this->kv( 'Failed', $this->num( $stats['failed'] ) );
		$this->kv( 'Completed', $this->num( $stats['complete'] ) );
		if ( ! empty( $stats['oldest_due'] ) ) {
			$overdue = time() - (int) $stats['oldest_due'];
			$this->kv( 'Oldest overdue action', $overdue > 0 ? esc_html( human_time_diff( (int) $stats['oldest_due'] ) ) . ' overdue' : 'none', true );
		}
		echo '</table>';
		echo '</div>';
	}

	private function section_health(): void {
		$health = $this->plugin->module( 'health' );
		if ( ! $health || ! method_exists( $health, 'checks' ) ) {
			return;
		}
		echo '<div class="bext-card">';
		echo '<h2>Health</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $health->checks() as $c ) {
			$dot = 'ok' === $c['status'] ? 'ok' : ( 'fail' === $c['status'] ? 'bad' : 'warn' );
			echo '<tr><td style="width:24px"><span class="bext-dot ' . esc_attr( $dot ) . '"></span></td><td><strong>' . esc_html( $c['label'] ) . '</strong></td><td>' . esc_html( $c['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$warnings = method_exists( $health, 'recent_warnings' ) ? $health->recent_warnings() : array();
		if ( ! empty( $warnings ) ) {
			$clear = wp_nonce_url( add_query_arg( 'action', 'bext_clear_warnings', admin_url( 'admin-post.php' ) ), 'bext_clear_warnings' );
			echo '<h3>Recent PHP warnings <a class="button button-small" href="' . esc_url( $clear ) . '">Clear</a></h3>';
			echo '<table class="widefat striped bext-log"><thead><tr><th>Message</th><th>Location</th><th>×</th></tr></thead><tbody>';
			foreach ( array_slice( $warnings, 0, 15 ) as $w ) {
				$loc = esc_html( $this->short_path( (string) ( $w['file'] ?? '' ) ) . ':' . (int) ( $w['line'] ?? 0 ) );
				echo '<tr><td class="bext-mono">' . esc_html( wp_trim_words( (string) ( $w['msg'] ?? '' ), 24 ) ) . '</td><td class="bext-mono">' . $loc . '</td><td>' . (int) ( $w['count'] ?? 1 ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function section_sdk(): void {
		$sdk = $this->plugin->module( 'sdk' );
		if ( ! $sdk || ! method_exists( $sdk, 'status' ) ) {
			return;
		}
		$s = $sdk->status();
		echo '<div class="bext-card">';
		echo '<h2>bext SDK bridge</h2>';
		echo '<table class="bext-kv">';
		$this->kv( 'Email via bext', ! empty( $s['email'] ) ? '<span class="bext-pill ok">on</span>' : '<span class="bext-pill">off</span>', true );
		$this->kv( 'Jobs via bext', ! empty( $s['jobs'] ) ? '<span class="bext-pill ok">on</span>' : '<span class="bext-pill">off</span>', true );
		$this->kv( 'App id', esc_html( (string) ( $s['app_id'] ?? '' ) ) );
		echo '</table>';
		echo '<p class="bext-muted">Toggle these under <a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">Settings → SDK bridge</a> (or <code>BEXT_WP_SDK_EMAIL</code> / <code>BEXT_WP_SDK_JOBS</code> in wp-config.php).</p>';
		echo '</div>';
	}

	private function section_server(): void {
		echo '<div class="bext-card">';
		echo '<h2>bext server</h2>';
		$health = $this->env->bext_get( '/__bext/health' );
		if ( is_array( $health ) && 200 === $health['code'] ) {
			echo '<p><span class="bext-dot ok"></span> <code>/__bext/health</code> → 200</p>';
			$this->render_bext_headers( isset( $health['headers'] ) ? (array) $health['headers'] : array() );
			echo '<pre class="bext-pre">' . esc_html( wp_trim_words( $health['body'], 60 ) ) . '</pre>';
		} else {
			echo '<p><span class="bext-dot warn"></span> Could not reach <code>/__bext/health</code> over loopback.</p>';
		}
		echo '</div>';
	}

	/**
	 * Render bext's diagnostic response headers (x-bext-cache / x-bext-php …) as
	 * small badges, when the last server probe returned any.
	 *
	 * @param array<string,string> $headers Lowercase-keyed header map.
	 */
	private function render_bext_headers( array $headers ): void {
		$labels = array(
			'x-bext-cache' => 'cache',
			'x-bext-php'   => 'php',
			'x-bext-wp'    => 'wp',
			'server'       => 'server',
		);
		$out = '';
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $headers[ $key ] ) ) {
				$out .= '<span class="bext-pill">' . esc_html( $label ) . ': ' . esc_html( (string) $headers[ $key ] ) . '</span> ';
			}
		}
		if ( '' !== $out ) {
			echo '<p class="bext-muted">Last response: ' . $out . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// ---------------------------------------------------------------------
	// Tiny render helpers
	// ---------------------------------------------------------------------

	private function kv( string $k, string $v, bool $raw = false ): void {
		echo '<tr><th>' . esc_html( $k ) . '</th><td>' . ( $raw ? $v : esc_html( $v ) ) . '</td></tr>';
	}

	private function num( $v ): string {
		return null === $v ? '&mdash;' : number_format_i18n( (int) $v );
	}

	private function short_path( string $path ): string {
		$pos = strpos( $path, 'wp-content' );
		return false !== $pos ? substr( $path, $pos ) : $path;
	}
}
