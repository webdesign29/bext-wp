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
 *
 * All loopback calls go to the bext main listener on 127.0.0.1:80 — the same
 * port that serves the site, always reachable, no port discovery needed:
 *   - cache purge: POST /__bext/cache/purge-proxy  (honors paths + prefixes,
 *     evicts the in-memory FastCGI cache that serves WP pages)
 *   - SDK:         POST /__bext/sdk/*              (X-Bext-App-Id loopback bypass)
 *   - status:      GET  /__bext/health|metrics
 */
class Env {

	const DETECT_OPTION = 'bext_wp_detected';

	const PURGE_ENDPOINT = 'http://127.0.0.1/__bext/cache/purge-proxy';

	/** @var bool|null */
	private $behind = null;

	/**
	 * Are we being served by bext?
	 *
	 * Detection order:
	 *  1. The BEXT_SERVER fastcgi param (set by the bext FastCGI environ builder).
	 *  2. The x-bext-cache-refresh request header (bext's background refresh).
	 *  3. A sticky option flag set the first time we ever saw a bext signal
	 *     (so cache-HIT requests, which never reach PHP, don't un-detect us).
	 *  4. An operator override constant BEXT_WP_ASSUME_BEHIND_BEXT.
	 *
	 * The sticky flag is cleared on uninstall/deactivation (see uninstall.php).
	 */
	public function is_behind_bext(): bool {
		if ( null !== $this->behind ) {
			return $this->behind;
		}

		$signal = isset( $_SERVER['BEXT_SERVER'] ) || $this->is_cache_refresh_request();

		// Sticky one-time write: guarded so it runs at most once ever (the first
		// PHP request that sees a live bext signal), never on subsequent requests.
		if ( $signal && ! get_option( self::DETECT_OPTION ) ) {
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
		return isset( $_SERVER['BEXT_SERVER'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['BEXT_SERVER'] ) ) : '';
	}

	/**
	 * Is this the bext background-refresh self-request?
	 */
	public function is_cache_refresh_request(): bool {
		return isset( $_SERVER['HTTP_X_BEXT_CACHE_REFRESH'] );
	}

	/**
	 * Canonical host bext keys the cache by (aliases 301 -> canonical).
	 */
	public function canonical_host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	/**
	 * The path prefix of this install (for subdirectory sites home_url() carries
	 * a path, e.g. "/blog/"). Always begins and ends with "/".
	 */
	public function home_path(): string {
		$p = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$p = is_string( $p ) ? trim( $p, '/' ) : '';
		return '' === $p ? '/' : '/' . $p . '/';
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

	/**
	 * Forget the sticky detection flag (used on uninstall/deactivation so a site
	 * that later moves off bext doesn't keep believing it's behind bext).
	 */
	public function clear_detection(): void {
		delete_option( self::DETECT_OPTION );
		$this->behind = null;
	}

	// ---------------------------------------------------------------------
	// Loopback transport (all on 127.0.0.1:80, the bext main listener)
	// ---------------------------------------------------------------------

	/**
	 * Purge bext's FastCGI/proxy cache for the given paths/prefixes via the
	 * main-listener endpoint that actually evicts the in-memory cache serving
	 * WP pages. Non-blocking by default so callers on the request path never wait.
	 *
	 * @param array $body     { host, paths[], prefixes[] }.
	 * @param bool  $blocking Wait for + return the response (manual purge / CLI).
	 * @return array|\WP_Error|true ['code'=>int,'body'=>string] when blocking, else true.
	 */
	public function purge_proxy( array $body, bool $blocking = false ) {
		$res = wp_remote_post(
			self::PURGE_ENDPOINT,
			array(
				'method'      => 'POST',
				'timeout'     => $blocking ? 5 : 1,
				'blocking'    => $blocking,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Host'         => $this->canonical_host(),
				),
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
	 * Call a bext SDK endpoint with the app-id loopback bypass header.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $path     e.g. '/__bext/sdk/email/send'.
	 * @param array|null $body     JSON body (for POST), or null.
	 * @param bool       $blocking Wait for the response.
	 * @return array|\WP_Error|true
	 */
	public function sdk_request( string $method, string $path, $body = null, bool $blocking = true ) {
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
		$res = wp_remote_request( 'http://127.0.0.1' . $path, $args );
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
		$res = wp_remote_get(
			'http://127.0.0.1' . $path,
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
