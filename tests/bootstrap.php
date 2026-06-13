<?php
/**
 * Shared WP-free test bootstrap: minimal stubs of the WordPress functions the
 * plugin touches, including a faithful action/filter registry (actions and
 * filters share one registry, exactly like WordPress — which is what makes the
 * SDK enqueue double-fire test meaningful).
 *
 * Tests set behavior via these globals:
 *   $GLOBALS['_bext_opts']       option store (array)
 *   $GLOBALS['_bext_netopts']    site-option store (array)
 *   $GLOBALS['_bext_multisite']  bool
 *   $GLOBALS['_bext_subdomain']  bool
 *   $GLOBALS['_bext_home']       home_url base (string)
 *   $GLOBALS['_bext_logged_in']  is_user_logged_in() (bool)
 *
 * @package Bext\WP
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['_bext_opts']       = array();
$GLOBALS['_bext_netopts']    = array();
$GLOBALS['_bext_filters']    = array();
$GLOBALS['_bext_multisite']  = false;
$GLOBALS['_bext_subdomain']  = false;
$GLOBALS['_bext_home']       = 'https://example.test';
$GLOBALS['_bext_logged_in']  = false;

/** Reset all mutable test state between scenarios. */
function bext_test_reset() {
	$GLOBALS['_bext_opts']      = array();
	$GLOBALS['_bext_netopts']   = array();
	$GLOBALS['_bext_filters']   = array();
	$GLOBALS['_bext_multisite'] = false;
	$GLOBALS['_bext_subdomain'] = false;
	$GLOBALS['_bext_home']      = 'https://example.test';
	$GLOBALS['_bext_logged_in'] = false;
}

if ( ! function_exists( 'get_option' ) ) {
	// --- options ---
	function get_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_opts'] ) ? $GLOBALS['_bext_opts'][ $k ] : $d;
	}
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['_bext_opts'][ $k ] = $v;
		return true;
	}
	function add_option( $k, $v ) {
		$GLOBALS['_bext_opts'][ $k ] = $v;
		return true;
	}
	function delete_option( $k ) {
		unset( $GLOBALS['_bext_opts'][ $k ] );
		return true;
	}
	function get_site_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_netopts'] ) ? $GLOBALS['_bext_netopts'][ $k ] : $d;
	}
	function update_site_option( $k, $v ) {
		$GLOBALS['_bext_netopts'][ $k ] = $v;
		return true;
	}
	function delete_site_option( $k ) {
		unset( $GLOBALS['_bext_netopts'][ $k ] );
		return true;
	}

	// --- hooks (actions + filters share ONE registry, like WordPress) ---
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['_bext_filters'][ $tag ][] = array( 'cb' => $cb, 'args' => (int) $args );
		return true;
	}
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
		return add_filter( $tag, $cb, $prio, $args );
	}
	function do_action( $tag, ...$a ) {
		if ( empty( $GLOBALS['_bext_filters'][ $tag ] ) ) {
			return;
		}
		foreach ( $GLOBALS['_bext_filters'][ $tag ] as $h ) {
			call_user_func_array( $h['cb'], array_slice( $a, 0, $h['args'] ) );
		}
	}
	function apply_filters( $tag, $value, ...$a ) {
		if ( empty( $GLOBALS['_bext_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['_bext_filters'][ $tag ] as $h ) {
			$all   = array_merge( array( $value ), $a );
			$value = call_user_func_array( $h['cb'], array_slice( $all, 0, $h['args'] ) );
		}
		return $value;
	}

	// --- environment / url ---
	function is_multisite() {
		return ! empty( $GLOBALS['_bext_multisite'] );
	}
	function is_subdomain_install() {
		return ! empty( $GLOBALS['_bext_subdomain'] );
	}
	function is_user_logged_in() {
		return ! empty( $GLOBALS['_bext_logged_in'] );
	}
	function home_url( $p = '/' ) {
		return rtrim( $GLOBALS['_bext_home'], '/' ) . $p;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	// --- sanitization / strings ---
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' );
	}
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/' ) . '/';
	}
	function wp_unslash( $v ) {
		return $v;
	}
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $s ) );
	}
	function esc_url_raw( $s ) {
		return trim( (string) $s );
	}
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}
