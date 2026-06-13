<?php
/**
 * Environment service: configuration resolution, bext detection, and the
 * loopback / cloud transport.
 *
 * Configuration precedence (highest wins):
 *   1. wp-config constant (BEXT_WP_*)         — power users / locked config
 *   2. Settings page option (bext_wp_settings) — the wp-admin UI
 *   3. Built-in default
 * Filters (bext/*) still apply on top where documented.
 *
 * Transport: in "auto" mode everything goes to the bext main listener on
 * 127.0.0.1:80 (loopback). In "cloud" mode it goes to the configured bext
 * endpoint with a bearer token, so a site served by bext cloud can integrate
 * even when WordPress runs off-box.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Env {

	const DETECT_OPTION   = 'bext_wp_detected';
	const SETTINGS_OPTION = 'bext_wp_settings';
	const NETWORK_OPTION  = 'bext_wp_network_settings';
	const PURGE_PATH      = '/__bext/cache/purge-proxy';

	/** @var bool|null */
	private $behind = null;

	/** @var array|null */
	private $settings = null;

	/** @var array|null */
	private $network = null;

	/** @var string|null */
	private $mode = null;

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/** @return array<string,mixed> The stored settings (cached per-request). */
	public function settings(): array {
		if ( null === $this->settings ) {
			$opt            = get_option( self::SETTINGS_OPTION, array() );
			$this->settings = is_array( $opt ) ? $opt : array();
		}
		return $this->settings;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function setting( string $key, $default = null ) {
		$s = $this->settings();
		return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
	}

	public function setting_bool( string $key, bool $default ): bool {
		$v = $this->setting( $key, null );
		return null === $v ? $default : (bool) $v;
	}

	/** Invalidate the per-request caches (after a save or a switch_to_blog). */
	public function flush_settings_cache(): void {
		$this->settings = null;
		$this->network  = null;
		$this->behind   = null;
		$this->mode     = null;
	}

	// ---------------------------------------------------------------------
	// Network (multisite) settings
	// ---------------------------------------------------------------------

	/** @return array<string,mixed> Network-wide settings (empty on single-site). */
	public function network_settings(): array {
		if ( null === $this->network ) {
			if ( is_multisite() ) {
				$opt           = get_site_option( self::NETWORK_OPTION, array() );
				$this->network = is_array( $opt ) ? $opt : array();
			} else {
				$this->network = array();
			}
		}
		return $this->network;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function network_setting( string $key, $default = null ) {
		$n = $this->network_settings();
		return array_key_exists( $key, $n ) ? $n[ $key ] : $default;
	}

	/** Are network settings enforced over per-site settings? (multisite only) */
	public function network_enforced(): bool {
		return is_multisite() && (bool) $this->network_setting( '_enforce', false );
	}

	/**
	 * Resolve a setting with multisite layering:
	 *   constant (handled by callers) > network(enforced) > site > network(default) > default.
	 * On single-site this is exactly "site > default".
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function resolved( string $key, $default = null ) {
		$site = $this->setting( $key, null );

		if ( ! is_multisite() ) {
			return null === $site ? $default : $site;
		}

		$net = $this->network_setting( $key, null );

		if ( $this->network_enforced() && null !== $net ) {
			return $net;
		}
		if ( null !== $site ) {
			return $site;
		}
		if ( null !== $net ) {
			return $net;
		}
		return $default;
	}

	public function resolved_bool( string $key, bool $default ): bool {
		$v = $this->resolved( $key, null );
		return null === $v ? $default : (bool) $v;
	}

	/**
	 * Connection mode: 'auto' (loopback), 'cloud' (remote endpoint), or 'off'.
	 */
	public function mode(): string {
		if ( null !== $this->mode ) {
			return $this->mode;
		}
		if ( defined( 'BEXT_WP_MODE' ) && BEXT_WP_MODE ) {
			$m = (string) BEXT_WP_MODE;
		} else {
			$m = (string) $this->resolved( 'mode', 'auto' );
		}
		$this->mode = in_array( $m, array( 'auto', 'cloud', 'off' ), true ) ? $m : 'auto';
		return $this->mode;
	}

	/**
	 * Is a feature module enabled? constant (force-off) > setting > filter(default true).
	 *
	 * @param string $module cache|cron|health|sdk
	 */
	public function is_enabled( string $module ): bool {
		if ( 'off' === $this->mode() ) {
			return false;
		}
		$const = 'BEXT_WP_DISABLE_' . strtoupper( $module );
		if ( defined( $const ) && constant( $const ) ) {
			return false;
		}
		$default = $this->resolved_bool( 'enable_' . $module, true );

		/** @param bool $default Whether the module is enabled. */
		return (bool) apply_filters( "bext/enable_{$module}", $default );
	}

	public function sdk_email_enabled(): bool {
		if ( defined( 'BEXT_WP_SDK_EMAIL' ) ) {
			$on = (bool) BEXT_WP_SDK_EMAIL;
		} else {
			$on = $this->resolved_bool( 'sdk_email', false );
		}
		return $on && apply_filters( 'bext/enable_sdk_email', true );
	}

	public function sdk_jobs_enabled(): bool {
		if ( defined( 'BEXT_WP_SDK_JOBS' ) ) {
			$on = (bool) BEXT_WP_SDK_JOBS;
		} else {
			$on = $this->resolved_bool( 'sdk_jobs', false );
		}
		return $on && apply_filters( 'bext/enable_sdk_jobs', true );
	}

	public function purge_on_save_enabled(): bool {
		return $this->resolved_bool( 'purge_on_save', true );
	}

	public function capture_warnings_enabled(): bool {
		if ( defined( 'BEXT_WP_CAPTURE_WARNINGS' ) ) {
			return (bool) BEXT_WP_CAPTURE_WARNINGS;
		}
		if ( $this->resolved_bool( 'capture_warnings', false ) ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	public function anon_cache_control(): string {
		return (string) $this->resolved( 'anon_cache_control', '' );
	}

	// ---------------------------------------------------------------------
	// Detection
	// ---------------------------------------------------------------------

	/**
	 * Are we being served by bext?
	 *
	 * cloud mode ⇒ yes (explicitly configured). Otherwise: the BEXT_SERVER
	 * fastcgi param, the x-bext-cache-refresh header, a sticky one-time flag, or
	 * the BEXT_WP_ASSUME_BEHIND_BEXT constant.
	 */
	public function is_behind_bext(): bool {
		if ( null !== $this->behind ) {
			return $this->behind;
		}
		if ( 'off' === $this->mode() ) {
			$this->behind = false;
			return false;
		}
		if ( 'cloud' === $this->mode() ) {
			$this->behind = true;
			return true;
		}

		$signal = isset( $_SERVER['BEXT_SERVER'] ) || $this->is_cache_refresh_request();

		if ( $signal && ! get_option( self::DETECT_OPTION ) ) {
			update_option( self::DETECT_OPTION, 1, true ); // One-time sticky write.
		}

		$this->behind = $signal
			|| (bool) get_option( self::DETECT_OPTION )
			|| ( defined( 'BEXT_WP_ASSUME_BEHIND_BEXT' ) && BEXT_WP_ASSUME_BEHIND_BEXT );

		return $this->behind;
	}

	public function bext_version(): string {
		return isset( $_SERVER['BEXT_SERVER'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['BEXT_SERVER'] ) ) : '';
	}

	public function is_cache_refresh_request(): bool {
		return isset( $_SERVER['HTTP_X_BEXT_CACHE_REFRESH'] );
	}

	public function canonical_host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	/** Install base path; "/" for root installs, "/blog/" for subdirectory. */
	public function home_path(): string {
		$p = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$p = is_string( $p ) ? trim( $p, '/' ) : '';
		return '' === $p ? '/' : '/' . $p . '/';
	}

	public function app_id(): string {
		if ( defined( 'BEXT_WP_APP_ID' ) && BEXT_WP_APP_ID ) {
			return (string) BEXT_WP_APP_ID;
		}
		$s = (string) $this->resolved( 'app_id', '' );
		if ( '' !== $s ) {
			return $s;
		}
		$host = $this->canonical_host();
		// Subdirectory multisite blogs share a host — disambiguate by path so the
		// SDK queue/email config doesn't collide between blogs. (Subdomain
		// multisite + single-site already have a unique host.)
		if ( is_multisite() && ! is_subdomain_install() ) {
			$path = trim( $this->home_path(), '/' );
			if ( '' !== $path ) {
				return $host . '-' . str_replace( '/', '-', $path );
			}
		}
		return $host;
	}

	/** Does the current request imply a personalized (un-cacheable) response? */
	public function is_personalized_request(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			if (
				0 === strpos( $name, 'wordpress_logged_in_' ) ||
				0 === strpos( $name, 'comment_author_' ) ||
				0 === strpos( $name, 'woocommerce_items_in_cart' ) ||
				0 === strpos( $name, 'woocommerce_cart_hash' ) ||
				0 === strpos( $name, 'wp_woocommerce_session_' ) ||
				0 === strpos( $name, 'edd_items_in_cart' )
			) {
				return true;
			}
		}
		return false;
	}

	public function clear_detection(): void {
		delete_option( self::DETECT_OPTION );
		$this->behind = null;
	}

	// ---------------------------------------------------------------------
	// Transport endpoint resolution
	// ---------------------------------------------------------------------

	/** Base origin for bext calls: the cloud endpoint, or loopback. */
	public function endpoint_base(): string {
		if ( 'cloud' === $this->mode() ) {
			$url = $this->cloud_url();
			if ( '' !== $url ) {
				return untrailingslashit( $url );
			}
		}
		return 'http://127.0.0.1';
	}

	public function cloud_url(): string {
		if ( defined( 'BEXT_WP_CLOUD_URL' ) && BEXT_WP_CLOUD_URL ) {
			return (string) BEXT_WP_CLOUD_URL;
		}
		return (string) $this->resolved( 'cloud_url', '' );
	}

	public function cloud_token(): string {
		if ( defined( 'BEXT_WP_CLOUD_TOKEN' ) && BEXT_WP_CLOUD_TOKEN ) {
			return (string) BEXT_WP_CLOUD_TOKEN;
		}
		return (string) $this->resolved( 'cloud_token', '' );
	}

	/** Auth + host headers appropriate to the current mode. */
	private function transport_headers(): array {
		$headers = array( 'Host' => $this->canonical_host() );
		if ( 'cloud' === $this->mode() ) {
			$token = $this->cloud_token();
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}
		return $headers;
	}

	// ---------------------------------------------------------------------
	// Loopback / cloud transport
	// ---------------------------------------------------------------------

	/**
	 * Purge bext's FastCGI/proxy cache for the given paths/prefixes.
	 *
	 * @param array $body     { host, paths[], prefixes[] }.
	 * @param bool  $blocking Wait for + return the response.
	 * @return array|\WP_Error|true
	 */
	public function purge_proxy( array $body, bool $blocking = false ) {
		$res = wp_remote_post(
			$this->endpoint_base() . self::PURGE_PATH,
			array(
				'method'      => 'POST',
				'timeout'     => $blocking ? 5 : 1,
				'blocking'    => $blocking,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ) + $this->transport_headers(),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( ! $blocking ) {
			return true;
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		);
	}

	/**
	 * Call a bext SDK endpoint (loopback app-id bypass, or cloud bearer).
	 *
	 * @param string     $method
	 * @param string     $path     e.g. '/__bext/sdk/email/send'.
	 * @param array|null $body
	 * @param bool       $blocking
	 * @return array|\WP_Error|true
	 */
	public function sdk_request( string $method, string $path, $body = null, bool $blocking = true ) {
		$headers = array(
			'Content-Type'  => 'application/json',
			'X-Bext-App-Id' => $this->app_id(),
		) + $this->transport_headers();

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => $blocking ? 8 : 1,
			'blocking'    => $blocking,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $this->endpoint_base() . $path, $args );
		if ( ! $blocking ) {
			return true;
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		);
	}

	/**
	 * GET a bext endpoint (e.g. /__bext/health, /__bext/metrics).
	 *
	 * @param string $path
	 * @param array  $override_headers Extra headers (e.g. a test token).
	 * @return array|\WP_Error ['code'=>int,'body'=>string]
	 */
	public function bext_get( string $path, array $override_headers = array() ) {
		$res = wp_remote_get(
			$this->endpoint_base() . $path,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => $this->transport_headers() + $override_headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		);
	}
}
