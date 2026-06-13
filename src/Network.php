<?php
/**
 * Multisite: a Network Admin page to configure bext network-wide and manage the
 * cache across every site.
 *
 * Network settings (stored in the `bext_wp_network_settings` site option) act as
 * defaults for every site, and — when "Enforce" is on — override per-site
 * settings. See Env::resolved().
 *
 * Loads only on multisite (Plugin only registers it then; register() guards too).
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Network {

	const PAGE   = 'bext-network';
	const MAX    = 500;

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bext_network_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_bext_network_purge', array( $this, 'handle_purge' ) );
	}

	public function menu(): void {
		add_menu_page(
			'Bext',
			'Bext',
			'manage_network_options',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-cloud',
			81
		);
	}

	private function cap(): bool {
		return current_user_can( 'manage_network_options' );
	}

	// ---------------------------------------------------------------------
	// Save network settings
	// ---------------------------------------------------------------------

	public function handle_save(): void {
		if ( ! $this->cap() ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'bext_network_save' );

		$in  = isset( $_POST['bext_net'] ) && is_array( $_POST['bext_net'] ) ? wp_unslash( $_POST['bext_net'] ) : array();
		$out = array();

		$mode        = isset( $in['mode'] ) ? (string) $in['mode'] : 'auto';
		$out['mode'] = in_array( $mode, array( 'auto', 'cloud', 'off' ), true ) ? $mode : 'auto';

		$out['cloud_url']          = isset( $in['cloud_url'] ) ? esc_url_raw( trim( (string) $in['cloud_url'] ) ) : '';
		$out['cloud_token']        = isset( $in['cloud_token'] ) ? trim( sanitize_text_field( (string) $in['cloud_token'] ) ) : '';
		$out['anon_cache_control'] = isset( $in['anon_cache_control'] ) ? sanitize_text_field( (string) $in['anon_cache_control'] ) : '';

		foreach ( array( 'enable_cache', 'enable_cron', 'enable_health', 'sdk_email', 'sdk_jobs', 'purge_on_save', 'capture_warnings', '_enforce' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		update_site_option( Env::NETWORK_OPTION, $out );
		$this->env->flush_settings_cache();

		wp_safe_redirect( add_query_arg( 'bext_saved', '1', network_admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Cross-site purge
	// ---------------------------------------------------------------------

	public function handle_purge(): void {
		if ( ! $this->cap() ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'bext_network_purge' );

		$blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
		$count   = 0;

		if ( $blog_id > 0 ) {
			// Single site: blocking, so we can confirm the result.
			$count += (int) $this->purge_blog( $blog_id, true );
		} else {
			// All sites: non-blocking, so N sites can't stall the request for N×5s.
			foreach ( $this->site_ids() as $id ) {
				$count += (int) $this->purge_blog( $id, false );
			}
		}

		wp_safe_redirect( add_query_arg( 'bext_purged_sites', $count, network_admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Purge a single blog's entire cache. switch_to_blog is exception-safe; the
	 * blog id is validated to exist first.
	 *
	 * @param int  $blog_id
	 * @param bool $blocking Wait for the response (confirm) vs fire-and-forget.
	 * @return int 1 if dispatched (blocking: only on a 200), else 0.
	 */
	private function purge_blog( int $blog_id, bool $blocking = false ): int {
		if ( ! get_site( $blog_id ) ) {
			return 0;
		}
		switch_to_blog( $blog_id );
		$ok = 0;
		try {
			if ( $this->env->is_behind_bext() ) {
				$host   = $this->env->canonical_host();
				$prefix = $this->env->home_path();
				$res    = $this->env->purge_proxy(
					array(
						'host'     => $host,
						'paths'    => array(),
						'prefixes' => array( $prefix ),
					),
					$blocking
				);
				$ok = $blocking ? ( ( is_array( $res ) && 200 === $res['code'] ) ? 1 : 0 ) : 1;

				/** @see Cache::fire_after_purge() — same contract, network context. */
				do_action( 'bext/after_purge', $host, array(), array( $prefix ) );
			}
		} finally {
			restore_current_blog();
		}
		return $ok;
	}

	/** @return int[] Blog IDs (capped). */
	private function site_ids(): array {
		$ids = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => self::MAX,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function render(): void {
		if ( ! $this->cap() ) {
			return;
		}
		$n = $this->env->network_settings();
		$g = function ( $k, $d = '' ) use ( $n ) {
			return isset( $n[ $k ] ) ? $n[ $k ] : $d;
		};

		echo '<div class="wrap bext-wrap">';
		echo '<h1><span class="dashicons dashicons-cloud"></span> Bext — Network</h1>';

		if ( isset( $_GET['bext_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Network settings saved.</p></div>';
		}
		if ( isset( $_GET['bext_purged_sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$c = (int) $_GET['bext_purged_sites']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Purged ' . esc_html( (string) $c ) . ' site(s).</p></div>';
		}

		$this->render_settings_form( $g );
		$this->render_sites_table();

		echo '</div>';
	}

	private function render_settings_form( callable $g ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bext-card" style="max-width:760px">';
		echo '<input type="hidden" name="action" value="bext_network_save">';
		wp_nonce_field( 'bext_network_save' );
		echo '<h2>Network defaults</h2>';
		echo '<p class="bext-muted">These apply to every site as defaults. Turn on <em>Enforce</em> to override each site\'s own settings.</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">Enforce on all sites</th><td>' .
			$this->checkbox( '_enforce', $g( '_enforce', 0 ), 'Network settings override per-site settings' ) . '</td></tr>';

		echo '<tr><th scope="row">Mode</th><td>' .
			$this->select( 'mode', (string) $g( 'mode', 'auto' ), array( 'auto' => 'Auto (loopback)', 'cloud' => 'Cloud (remote)', 'off' => 'Off' ) ) . '</td></tr>';
		echo '<tr><th scope="row">Cloud endpoint URL</th><td>' .
			$this->text( 'cloud_url', (string) $g( 'cloud_url' ), 'https://…' ) . '</td></tr>';
		echo '<tr><th scope="row">Cloud API token</th><td>' .
			$this->password( 'cloud_token', (string) $g( 'cloud_token' ) ) . '</td></tr>';
		echo '<tr><th scope="row">Anonymous Cache-Control</th><td>' .
			$this->text( 'anon_cache_control', (string) $g( 'anon_cache_control' ), 'public, max-age=300, stale-while-revalidate=86400' ) . '</td></tr>';

		foreach ( array(
			'enable_cache'     => 'Edge cache',
			'purge_on_save'    => 'Purge on save',
			'enable_cron'      => 'Action Scheduler taming',
			'enable_health'    => 'Health diagnostics',
			'capture_warnings' => 'Capture PHP warnings',
			'sdk_email'        => 'Email via bext',
			'sdk_jobs'         => 'Jobs via bext',
		) as $key => $label ) {
			$default = in_array( $key, array( 'enable_cache', 'enable_cron', 'enable_health', 'purge_on_save' ), true ) ? 1 : 0;
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' .
				$this->checkbox( $key, $g( $key, $default ), 'Enabled' ) . '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( 'Save network settings' );
		echo '</form>';
	}

	private function render_sites_table(): void {
		$ids = $this->site_ids();

		echo '<div class="bext-card">';
		echo '<h2>Sites <span class="bext-ver">' . esc_html( (string) count( $ids ) ) . '</span></h2>';

		$purge_all = wp_nonce_url( add_query_arg( 'action', 'bext_network_purge', admin_url( 'admin-post.php' ) ), 'bext_network_purge' );
		echo '<p><a class="button button-primary" href="' . esc_url( $purge_all ) . '" onclick="return confirm(\'Purge the bext cache for ALL sites?\')">Purge all sites</a></p>';

		// Build admin-post URL in the network/main-site context, before any switch.
		$post_url = admin_url( 'admin-post.php' );

		echo '<table class="widefat striped"><thead><tr><th>Site</th><th>bext</th><th>Mode</th><th>Last purge</th><th></th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			switch_to_blog( $id );
			try {
				$home     = home_url( '/' );
				$detected = (bool) get_option( Env::DETECT_OPTION );
				$settings = get_option( Env::SETTINGS_OPTION, array() );
				$mode     = is_array( $settings ) && isset( $settings['mode'] ) ? (string) $settings['mode'] : '(default)';
				$log      = get_option( Cache::LOG_OPTION, array() );
				$last     = ( is_array( $log ) && isset( $log[0]['time'] ) ) ? human_time_diff( (int) $log[0]['time'] ) . ' ago' : '—';
			} finally {
				restore_current_blog();
			}
			$purge1 = wp_nonce_url( add_query_arg( array( 'action' => 'bext_network_purge', 'blog_id' => $id ), $post_url ), 'bext_network_purge' );

			$dot = $detected ? '<span class="bext-dot ok"></span>' : '<span class="bext-dot warn"></span>';
			echo '<tr>'
				. '<td><a href="' . esc_url( $home ) . '">' . esc_html( $home ) . '</a></td>'
				. '<td>' . $dot . '</td>'
				. '<td><code>' . esc_html( $mode ) . '</code></td>'
				. '<td>' . esc_html( $last ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( $purge1 ) . '">Purge</a></td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $ids ) >= self::MAX ) {
			echo '<p class="bext-muted">Showing the first ' . esc_html( (string) self::MAX ) . ' sites.</p>';
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Field helpers
	// ---------------------------------------------------------------------

	private function field_name( string $key ): string {
		return 'bext_net[' . $key . ']';
	}

	private function text( string $key, string $value, string $placeholder = '' ): string {
		return sprintf(
			'<input type="text" name="%s" value="%s" placeholder="%s" class="regular-text code" />',
			esc_attr( $this->field_name( $key ) ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	private function password( string $key, string $value ): string {
		return sprintf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $this->field_name( $key ) ),
			esc_attr( $value )
		);
	}

	private function checkbox( string $key, $checked, string $label ): string {
		return sprintf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $this->field_name( $key ) ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}

	private function select( string $key, string $value, array $options ): string {
		$out = '<select name="' . esc_attr( $this->field_name( $key ) ) . '">';
		foreach ( $options as $val => $label ) {
			$out .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $val ),
				selected( $value, (string) $val, false ),
				esc_html( (string) $label )
			);
		}
		return $out . '</select>';
	}
}
