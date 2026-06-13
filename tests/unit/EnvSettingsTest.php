<?php
/**
 * Unit tests for Env config resolution + transport endpoint selection.
 * WP-free: minimal stubs of the WordPress functions Env touches.
 *
 * Run: php tests/unit/EnvSettingsTest.php
 *
 * @package Bext\WP
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

$GLOBALS['_bext_opts']      = array();
$GLOBALS['_bext_netopts']   = array();
$GLOBALS['_bext_multisite'] = false;
$GLOBALS['_bext_subdomain'] = false;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_opts'] ) ? $GLOBALS['_bext_opts'][ $k ] : $d;
	}
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['_bext_opts'][ $k ] = $v;
		return true;
	}
	function delete_option( $k ) {
		unset( $GLOBALS['_bext_opts'][ $k ] );
		return true;
	}
	function apply_filters( $t, $v ) {
		return $v;
	}
	function home_url( $p = '/' ) {
		$base = isset( $GLOBALS['_bext_home'] ) ? $GLOBALS['_bext_home'] : 'https://example.test';
		return rtrim( $base, '/' ) . $p;
	}
	function wp_parse_url( $u, $c = -1 ) {
		return parse_url( $u, $c );
	}
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' );
	}
	function sanitize_text_field( $s ) {
		return trim( (string) $s );
	}
	function wp_unslash( $s ) {
		return $s;
	}
	function is_user_logged_in() {
		return false;
	}
	function is_multisite() {
		return ! empty( $GLOBALS['_bext_multisite'] );
	}
	function is_subdomain_install() {
		return ! empty( $GLOBALS['_bext_subdomain'] );
	}
	function get_site_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_netopts'] ) ? $GLOBALS['_bext_netopts'][ $k ] : $d;
	}
	function update_site_option( $k, $v ) {
		$GLOBALS['_bext_netopts'][ $k ] = $v;
		return true;
	}
}

require __DIR__ . '/../../src/Env.php';

use Bext\WP\Env;

$failures = 0;
$check    = function ( $cond, $label ) use ( &$failures ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};
$set = function ( array $settings ) {
	$GLOBALS['_bext_opts']['bext_wp_settings'] = $settings;
};

// --- mode() ---
$set( array() );
$check( ( new Env() )->mode() === 'auto', 'mode default = auto' );
$set( array( 'mode' => 'cloud' ) );
$check( ( new Env() )->mode() === 'cloud', 'mode from setting = cloud' );
$set( array( 'mode' => 'bogus' ) );
$check( ( new Env() )->mode() === 'auto', 'invalid mode falls back to auto' );

// --- endpoint_base() ---
$set( array( 'mode' => 'auto' ) );
$check( ( new Env() )->endpoint_base() === 'http://127.0.0.1', 'auto → loopback base' );
$set( array( 'mode' => 'cloud', 'cloud_url' => 'https://edge.example.com/' ) );
$check( ( new Env() )->endpoint_base() === 'https://edge.example.com', 'cloud → untrailingslashit(cloud_url)' );
$set( array( 'mode' => 'cloud', 'cloud_url' => '' ) );
$check( ( new Env() )->endpoint_base() === 'http://127.0.0.1', 'cloud w/o url → loopback fallback' );

// --- is_enabled() ---
$set( array() );
$check( ( new Env() )->is_enabled( 'cache' ) === true, 'module enabled by default' );
$set( array( 'enable_cache' => 0 ) );
$check( ( new Env() )->is_enabled( 'cache' ) === false, 'module disabled via setting' );
$set( array( 'mode' => 'off', 'enable_cache' => 1 ) );
$check( ( new Env() )->is_enabled( 'cache' ) === false, 'mode=off disables all modules' );

// --- app_id() ---
$set( array( 'app_id' => 'my-app' ) );
$check( ( new Env() )->app_id() === 'my-app', 'app_id from setting' );
$set( array( 'app_id' => '' ) );
$check( ( new Env() )->app_id() === 'example.test', 'app_id falls back to host' );

// --- sdk toggles ---
$set( array( 'sdk_email' => 1, 'sdk_jobs' => 0 ) );
$check( ( new Env() )->sdk_email_enabled() === true, 'sdk_email on via setting' );
$check( ( new Env() )->sdk_jobs_enabled() === false, 'sdk_jobs off via setting' );

// --- cloud mode ⇒ behind bext without a fastcgi signal ---
$set( array( 'mode' => 'cloud', 'cloud_url' => 'https://edge.example.com' ) );
$check( ( new Env() )->is_behind_bext() === true, 'cloud mode ⇒ behind bext' );
$set( array( 'mode' => 'off' ) );
$check( ( new Env() )->is_behind_bext() === false, 'off mode ⇒ not behind bext' );

// --- multisite layering ---
$setnet = function ( array $s ) {
	$GLOBALS['_bext_netopts']['bext_wp_network_settings'] = $s;
};
$GLOBALS['_bext_multisite'] = true;

$set( array() );
$setnet( array( 'mode' => 'cloud', 'cloud_url' => 'https://n.example' ) );
$check( ( new Env() )->mode() === 'cloud', 'network default applies when no site setting' );

$set( array( 'mode' => 'auto' ) );
$setnet( array( 'mode' => 'cloud', 'cloud_url' => 'https://n.example' ) );
$check( ( new Env() )->mode() === 'auto', 'site overrides network default (enforce off)' );

$set( array( 'mode' => 'auto' ) );
$setnet( array( 'mode' => 'cloud', 'cloud_url' => 'https://n.example', '_enforce' => 1 ) );
$check( ( new Env() )->mode() === 'cloud', 'enforce: network overrides site' );
$check( ( new Env() )->endpoint_base() === 'https://n.example', 'enforce: cloud_url from network' );

$set( array( 'enable_cache' => 0 ) );
$setnet( array( 'enable_cache' => 1 ) );
$check( ( new Env() )->is_enabled( 'cache' ) === false, 'site disable wins when enforce off' );

$set( array( 'enable_cache' => 1 ) );
$setnet( array( 'enable_cache' => 0, '_enforce' => 1 ) );
$check( ( new Env() )->is_enabled( 'cache' ) === false, 'network enforce disables module' );

// subdirectory multisite ⇒ app_id disambiguated by path
$set( array() );
$setnet( array() );
$GLOBALS['_bext_subdomain'] = false;
$GLOBALS['_bext_home']      = 'https://example.test/blog2';
$check( ( new Env() )->app_id() === 'example.test-blog2', 'subdir multisite app_id = host-path' );

// reset for the constant test
$GLOBALS['_bext_multisite'] = false;
$GLOBALS['_bext_home']      = 'https://example.test';
$setnet( array() );

// --- constant overrides setting (defined last; constants are immutable) ---
define( 'BEXT_WP_MODE', 'off' );
$set( array( 'mode' => 'cloud' ) );
$check( ( new Env() )->mode() === 'off', 'constant BEXT_WP_MODE overrides setting' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
