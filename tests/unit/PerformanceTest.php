<?php
/**
 * Tests for Performance: front-end plugin de-registration (the curated,
 * opt-in `option_active_plugins` filter), emoji TinyMCE strip, and the
 * Heartbeat throttle. Pure logic — no WP runtime.
 *
 * Run: php tests/unit/PerformanceTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Performance.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Performance;

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['_bext_is_admin'] );
	}
}

$fail = 0;
function check( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "  ok   $msg\n";
	} else {
		echo "  FAIL $msg\n";
		$fail++;
	}
}

bext_test_reset();
$GLOBALS['_bext_is_admin'] = false;
$_SERVER['REQUEST_URI']    = '/horizons-25/';
$GLOBALS['_bext_opts']['bext_wp_settings'] = array(
	'perf_frontend_disabled_plugins' => "query-monitor/query-monitor.php\nadminer",
);

$env    = new Env();
$plugin = Plugin::instance();
$perf   = new Performance( $env, $plugin );

$in = array(
	'query-monitor/query-monitor.php',
	'wordpress-seo/wp-seo.php',
	'adminer/adminer.php',
	'contact-form-7/wp-contact-form-7.php',
);

// Front-end request: listed plugins dropped (full basename + dir-slug match).
$out = $perf->filter_active_plugins( $in );
check( ! in_array( 'query-monitor/query-monitor.php', $out, true ), 'drops query-monitor (full basename)' );
check( ! in_array( 'adminer/adminer.php', $out, true ), 'drops adminer (dir-slug match)' );
check( in_array( 'wordpress-seo/wp-seo.php', $out, true ), 'keeps yoast' );
check( count( $out ) === 2, 'two plugins remain' );

// Admin request: never touched.
$GLOBALS['_bext_is_admin'] = true;
check( count( $perf->filter_active_plugins( $in ) ) === 4, 'admin request: nothing dropped' );
$GLOBALS['_bext_is_admin'] = false;

// REST request: never touched (detected via REQUEST_URI before REST_REQUEST exists).
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
check( count( $perf->filter_active_plugins( $in ) ) === 4, 'REST request: nothing dropped' );
$_SERVER['REQUEST_URI'] = '/horizons-25/';

// Empty disabled-list: untouched (safe default).
$GLOBALS['_bext_opts']['bext_wp_settings'] = array();
$perf2 = new Performance( new Env(), $plugin );
check( count( $perf2->filter_active_plugins( $in ) ) === 4, 'empty list: nothing dropped' );

// Non-array option: untouched.
check( 'nope' === $perf->filter_active_plugins( 'nope' ), 'non-array passthrough' );

// Emoji TinyMCE strip.
check( array_values( $perf->strip_emoji_tinymce( array( 'wpemoji', 'foo' ) ) ) === array( 'foo' ), 'strips wpemoji from tinymce' );

// Heartbeat throttle.
$hb = $perf->throttle_heartbeat( array( 'interval' => 15 ) );
check( 60 === $hb['interval'], 'heartbeat throttled to 60s' );

echo $fail ? "FAILED ($fail)\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
