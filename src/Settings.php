<?php
/**
 * Settings page: configure the bext integration (local bext or bext cloud)
 * from wp-admin, no wp-config editing required.
 *
 * Stored in the `bext_wp_settings` option. wp-config constants (BEXT_WP_*)
 * still override any setting here (see Env).
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Settings {

	const GROUP = 'bext_wp';
	const PAGE  = 'bext-settings';

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_bext_test_connection', array( $this, 'handle_test' ) );
	}

	public function menu(): void {
		add_submenu_page(
			Admin::PAGE,
			'Bext Settings',
			'Settings',
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Env::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Validate + normalize the posted settings.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$mode        = isset( $in['mode'] ) ? (string) $in['mode'] : 'auto';
		$out['mode'] = in_array( $mode, array( 'auto', 'cloud', 'off' ), true ) ? $mode : 'auto';

		$out['cloud_url']   = isset( $in['cloud_url'] ) ? esc_url_raw( trim( (string) $in['cloud_url'] ) ) : '';
		$out['cloud_token'] = isset( $in['cloud_token'] ) ? trim( sanitize_text_field( (string) $in['cloud_token'] ) ) : '';
		$out['app_id']      = isset( $in['app_id'] ) ? sanitize_text_field( (string) $in['app_id'] ) : '';

		foreach ( array( 'enable_cache', 'enable_cron', 'enable_health', 'sdk_email', 'sdk_jobs', 'purge_on_save', 'capture_warnings' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		$out['anon_cache_control'] = isset( $in['anon_cache_control'] ) ? sanitize_text_field( (string) $in['anon_cache_control'] ) : '';

		// Guard against an obvious misconfig: cloud mode without an endpoint.
		if ( 'cloud' === $out['mode'] && '' === $out['cloud_url'] ) {
			add_settings_error( self::GROUP, 'cloud_url_missing', 'Cloud mode needs a bext endpoint URL — falling back to auto.', 'error' );
			$out['mode'] = 'auto';
		}

		$this->env->flush_settings_cache();
		return $out;
	}

	// ---------------------------------------------------------------------
	// "Test connection" (no JS; posts to admin-post, stores a transient)
	// ---------------------------------------------------------------------

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'bext_test_connection' );

		$res = $this->env->bext_get( '/__bext/health' );
		if ( is_wp_error( $res ) ) {
			$result = array(
				'ok'  => false,
				'msg' => 'Unreachable: ' . $res->get_error_message(),
			);
		} else {
			$result = array(
				'ok'  => 200 === $res['code'],
				'msg' => 'HTTP ' . $res['code'] . ' from ' . $this->env->endpoint_base() . '/__bext/health',
			);
		}
		set_transient( 'bext_wp_test_result', $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->env->settings();
		$g = function ( $k, $d = '' ) use ( $s ) {
			return isset( $s[ $k ] ) ? $s[ $k ] : $d;
		};
		$mode = $this->env->mode();

		echo '<div class="wrap bext-wrap">';
		echo '<h1><span class="dashicons dashicons-admin-generic"></span> Bext Settings</h1>';

		$this->maybe_show_test_result();
		$this->maybe_show_constant_notice();
		$this->maybe_show_network_notice();

		echo '<form method="post" action="options.php" class="bext-card" style="max-width:760px">';
		settings_fields( self::GROUP );

		// Connection.
		echo '<h2>Connection</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			'Mode',
			$this->select(
				'mode',
				$g( 'mode', 'auto' ),
				array(
					'auto'  => 'Auto — bext on this server (loopback)',
					'cloud' => 'Cloud — remote bext endpoint',
					'off'   => 'Off — disable the integration',
				)
			) . '<p class="description">Current: <code>' . esc_html( $mode ) . '</code>. Auto detects a local bext server; Cloud talks to a bext endpoint you configure below.</p>'
		);

		$this->row(
			'Cloud endpoint URL',
			$this->text( 'cloud_url', (string) $g( 'cloud_url' ), 'https://your-site.example or your bext cloud URL', 'regular-text code' )
			. '<p class="description">Only used in Cloud mode. The bext origin that serves this site.</p>'
		);
		$this->row(
			'Cloud API token',
			$this->password( 'cloud_token', (string) $g( 'cloud_token' ) )
			. '<p class="description">Sent as <code>Authorization: Bearer …</code> (matches the bext <code>BEXT_PURGE_TOKEN</code>).</p>'
		);
		$this->row(
			'App ID',
			$this->text( 'app_id', (string) $g( 'app_id' ), $this->env->canonical_host(), 'regular-text code' )
			. '<p class="description"><code>X-Bext-App-Id</code> for the SDK bridge. Defaults to the site host.</p>'
		);
		echo '</tbody></table>';

		// Features.
		echo '<h2>Features</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( 'Edge cache', $this->checkbox( 'enable_cache', $g( 'enable_cache', 1 ), 'Cooperate with the bext cache (purge on change, safe headers)' ) );
		$this->row( 'Purge on save', $this->checkbox( 'purge_on_save', $g( 'purge_on_save', 1 ), 'Automatically purge changed URLs when content is edited' ) );
		$this->row(
			'Anonymous Cache-Control',
			$this->text( 'anon_cache_control', (string) $g( 'anon_cache_control' ), 'public, max-age=300, stale-while-revalidate=86400', 'regular-text code' )
			. '<p class="description">Optional header for anonymous pages. Leave blank to defer to the bext vhost config.</p>'
		);
		$this->row( 'Action Scheduler taming', $this->checkbox( 'enable_cron', $g( 'enable_cron', 1 ), 'Disable the admin-ajax async runner; defer to system cron' ) );
		$this->row( 'Health diagnostics', $this->checkbox( 'enable_health', $g( 'enable_health', 1 ), 'Run config checks shown on the dashboard' ) );
		$this->row( 'Capture PHP warnings', $this->checkbox( 'capture_warnings', $g( 'capture_warnings', 0 ), 'Record recent PHP warnings (dev/debug)' ) );
		echo '</tbody></table>';

		// SDK bridge.
		echo '<h2>SDK bridge</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( 'Email via bext', $this->checkbox( 'sdk_email', $g( 'sdk_email', 0 ), 'Route wp_mail() through bext managed email (needs per-app SMTP config)' ) );
		$this->row( 'Jobs via bext', $this->checkbox( 'sdk_jobs', $g( 'sdk_jobs', 0 ), 'Enable bext/enqueue background jobs onto a bext queue' ) );
		echo '</tbody></table>';

		submit_button( 'Save settings' );
		echo '</form>';

		// Test connection (separate form → admin-post).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bext-card" style="max-width:760px">';
		echo '<input type="hidden" name="action" value="bext_test_connection">';
		wp_nonce_field( 'bext_test_connection' );
		echo '<h2>Test connection</h2>';
		echo '<p class="bext-muted">Sends a request to <code>' . esc_html( $this->env->endpoint_base() ) . '/__bext/health</code> using the saved settings.</p>';
		submit_button( 'Test connection', 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	private function maybe_show_test_result(): void {
		$r = get_transient( 'bext_wp_test_result' );
		if ( ! is_array( $r ) ) {
			return;
		}
		delete_transient( 'bext_wp_test_result' );
		printf(
			'<div class="notice notice-%s is-dismissible"><p><strong>Connection test:</strong> %s</p></div>',
			! empty( $r['ok'] ) ? 'success' : 'error',
			esc_html( (string) ( $r['msg'] ?? '' ) )
		);
	}

	private function maybe_show_network_notice(): void {
		if ( $this->env->network_enforced() ) {
			echo '<div class="notice notice-warning"><p>Some settings are <strong>enforced at the network level</strong>' .
				( current_user_can( 'manage_network_options' ) ? ' (<a href="' . esc_url( network_admin_url( 'admin.php?page=' . Network::PAGE ) ) . '">Network → Bext</a>)' : '' ) .
				' and override the values below.</p></div>';
		}
	}

	private function maybe_show_constant_notice(): void {
		$set = array();
		foreach ( array( 'BEXT_WP_MODE', 'BEXT_WP_CLOUD_URL', 'BEXT_WP_CLOUD_TOKEN', 'BEXT_WP_APP_ID', 'BEXT_WP_SDK_EMAIL', 'BEXT_WP_SDK_JOBS' ) as $c ) {
			if ( defined( $c ) ) {
				$set[] = $c;
			}
		}
		if ( $set ) {
			echo '<div class="notice notice-info"><p>These wp-config constants override the matching settings below: <code>' . esc_html( implode( '</code>, <code>', $set ) ) . '</code>.</p></div>';
		}
	}

	// ---------------------------------------------------------------------
	// Field helpers
	// ---------------------------------------------------------------------

	private function name( string $key ): string {
		return Env::SETTINGS_OPTION . '[' . $key . ']';
	}

	private function row( string $label, string $field ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function text( string $key, string $value, string $placeholder = '', string $class = 'regular-text' ): string {
		return sprintf(
			'<input type="text" name="%s" value="%s" placeholder="%s" class="%s" />',
			esc_attr( $this->name( $key ) ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr( $class )
		);
	}

	private function password( string $key, string $value ): string {
		return sprintf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $this->name( $key ) ),
			esc_attr( $value )
		);
	}

	private function checkbox( string $key, $checked, string $label ): string {
		return sprintf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $this->name( $key ) ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}

	private function select( string $key, string $value, array $options ): string {
		$out = '<select name="' . esc_attr( $this->name( $key ) ) . '">';
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
