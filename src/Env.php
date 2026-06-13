<?php
/**
 * Environment service: bext detection, endpoint resolution, loopback helpers.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the modules need to know about "are we behind bext, and how do we
 * talk back to it" lives here. Reads are cached per-request.
 */
class Env {

	const DETECT_OPTION = 'bext_wp_detected';

	/** @var bool|null */
	private $behind = null;

	/** @var int|null */
	private $purge_port = null;

	/**
	 * Are we being served by bext?
	 *
	 * Detection order:
	 *  1. The BEXT_SERVER fastcgi param (set by the bext FastCGI environ builder).
	 *  2. The x-bext-cache-refresh request header (bext's background refresh).
	 *  3. A sticky option flag set the first time we ever saw a bext signal.
	 *  4. An operator override constant BEXT_WP_ASSUME_BEHIND_BEXT.
	 */
	public function is_behind_bext(): bool {
		if ( null !== $this->behind ) {
			return $this->behind;
		}

		$signal = isset( $_SERVER['BEXT_SERVER'] ) || $this->is_cache_refresh_request();

		if ( $signal && ! get_option( self::DETECT_OPTION ) ) {
			// Make detection sticky so normal requests (which may lack a signal
			// before the bext param ships) still know they're behind bext.
			update_option( self::DETECT_OPTION, 1, true );
		}

		$this->behind = $signal
			|| (bool) get_option( self::DETECT_OPTION )
			|| ( defined( 'BEXT_WP_ASSUME_BEHIND_BEXT' ) && BEXT_WP_ASSUME_BEHIND_BEXT );

		return $this->behind;
	}

	/**
	 * bext server version string (from the BEXT_SERVER param), or '' if unknown.
	 */
	public function bext_version(): string {
		return isset( $_SERVER['BEXT_SERVER'] ) ? sanitize_text_field( (string) $_SERVER['BEXT_SERVER'] ) : '';
	}

	/**
	 * Is this the bext background-refresh self-request? Such requests should
	 * skip work that only matters for real visitors (e.g. analytics).
	 */
	public function is_cache_refresh_request(): bool {
		return isset( $_SERVER['HTTP_X_BEXT_CACHE_REFRESH'] );
	}

	/**
	 * FastCGI/PHP execution time bext measured for the previous response, if it
	 * surfaced one (informational; not always present).
	 */
	public function php_exec_hint(): string {
		return isset( $_SERVER['BEXT_PHP_EXEC_US'] ) ? sanitize_text_field( (string) $_SERVER['BEXT_PHP_EXEC_US'] ) : '';
	}

	/**
	 * The cache-purge port. Prefers the fastcgi param (no file read under
	 * open_basedir), then a constant, then the discovery file, then 8444.
	 */
	public function purge_port(): int {
		if ( null !== $this->purge_port ) {
			return $this->purge_port;
		}

		$port = 0;

		if ( isset( $_SERVER['BEXT_CACHE_PURGE_PORT'] ) ) {
			$port = (int) $_SERVER['BEXT_CACHE_PURGE_PORT'];
		}
		if ( $port <= 0 && defined( 'BEXT_WP_PURGE_PORT' ) ) {
			$port = (int) BEXT_WP_PURGE_PORT;
		}
		if ( $port <= 0 ) {
			// Discovery files (likely blocked by open_basedir; tried best-effort).
			foreach ( array( '/run/bext/cache-purge.port', '/tmp/bext-cache-purge.port' ) as $file ) {
				if ( @is_readable( $file ) ) {
					$val = (int) trim( (string) @file_get_contents( $file ) );
					if ( $val > 0 ) {
						$port = $val;
						break;
					}
				}
			}
		}
		if ( $port <= 0 ) {
			$port = 8444; // bext default.
		}

		$this->purge_port = $port;
		return $port;
	}

	/**
	 * Canonical host bext keys the cache by (aliases 301 -> canonical).
	 */
	public function canonical_host(): string {
		$home = home_url( '/' );
		$host = wp_parse_url( $home, PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	/**
	 * The bext app id used for SDK calls (X-Bext-App-Id). Operator-set, else
	 * derived from the canonical host.
	 */
	public function app_id(): string {
		if ( defined( 'BEXT_WP_APP_ID' ) && BEXT_WP_APP_ID ) {
			return (string) BEXT_WP_APP_ID;
		}
		return $this->canonical_host();
	}

	/**
	 * Does the current request carry cookies that imply a personalized response
	 * (logged-in, WooCommerce cart/session, or a comment author)? Such responses
	 * must never be cached for the anonymous key.
	 */
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

	// ---------------------------------------------------------------------
	// Loopback transport
	// ---------------------------------------------------------------------

	/**
	 * POST to the bext cache-purge port. Non-blocking by default so callers on
	 * the request path never wait.
	 *
	 * @param string $path    Endpoint path, e.g. '/nginx-cache/purge-site'.
	 * @param array  $body    JSON-encodable body.
	 * @param bool   $blocking Wait for + return the response (used by manual purge/CLI).
	 * @return array|\WP_Error  ['code'=>int,'body'=>string] when blocking, else true.
	 */
	public function purge_request( string $path, array $body, bool $blocking = false ) {
		$url  = 'http://127.0.0.1:' . $this->purge_port() . $path;
		$args = array(
			'method'      => 'POST',
			'timeout'     => $blocking ? 5 : 1,
			'blocking'    => $blocking,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $body ),
		);
		$res = wp_remote_post( $url, $args );
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
	 * Call a bext SDK endpoint on the main listener with the app-id loopback
	 * bypass header.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    e.g. '/__bext/sdk/email/send'.
	 * @param array|null $body JSON body (for POST), or null.
	 * @param bool $blocking  Wait for the response.
	 * @return array|\WP_Error
	 */
	public function sdk_request( string $method, string $path, $body = null, bool $blocking = true ) {
		$url  = 'http://127.0.0.1' . $path;
		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => $blocking ? 8 : 1,
			'blocking'    => $blocking,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'X-Bext-App-Id' => $this->app_id(),
				'Host'          => $this->canonical_host(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
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
	 * GET a global bext endpoint (e.g. /__bext/health, /__bext/metrics).
	 *
	 * @param string $path Endpoint path.
	 * @return array|\WP_Error ['code'=>int,'body'=>string]
	 */
	public function bext_get( string $path ) {
		$url = 'http://127.0.0.1' . $path;
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 4,
				'redirection' => 0,
				'headers'     => array( 'Host' => $this->canonical_host() ),
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
