<?php
/**
 * Tests for Settings::sanitize(): mode whitelist, URL/token/app-id sanitization,
 * boolean flag coercion, the anon Cache-Control passthrough, and the
 * cloud-without-endpoint guard (falls back to auto + records a settings error).
 *
 * Run: php tests/unit/SettingsTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Admin.php';     // Settings references Admin::PAGE + Network::PAGE.
require __DIR__ . '/../../src/Network.php';
require __DIR__ . '/../../src/Settings.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Settings;

// Settings::sanitize records guard errors via add_settings_error.
$GLOBALS['_bext_settings_errors'] = array();
if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['_bext_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$settings = new Settings( new Env(), Plugin::instance() );
$san      = function ( $input ) use ( $settings ) {
	$GLOBALS['_bext_settings_errors'] = array();
	return $settings->sanitize( $input );
};

// =====================================================================
// mode whitelist
// =====================================================================
$out = $san( array( 'mode' => 'cloud', 'cloud_url' => 'https://x.test' ) );
$check( $out['mode'] === 'cloud', 'valid mode kept' );
$check( $san( array( 'mode' => 'auto' ) )['mode'] === 'auto', 'auto mode kept' );
$check( $san( array( 'mode' => 'off' ) )['mode'] === 'off', 'off mode kept' );
$check( $san( array( 'mode' => 'nonsense' ) )['mode'] === 'auto', 'unknown mode → auto' );
$check( $san( array() )['mode'] === 'auto', 'missing mode → auto' );

// =====================================================================
// non-array input is tolerated
// =====================================================================
$check( is_array( $san( 'not-an-array' ) ), 'non-array input → array out' );
$check( $san( null )['mode'] === 'auto', 'null input → default mode' );

// =====================================================================
// URL / token / app-id sanitization
// =====================================================================
$out = $san( array( 'mode' => 'auto', 'cloud_url' => '  https://edge.test/  ' ) );
$check( $out['cloud_url'] === 'https://edge.test/', 'cloud_url trimmed' );

$out = $san( array( 'cloud_token' => "  sek\nret  " ) );
$check( $out['cloud_token'] === 'sek ret', 'cloud_token sanitized (newlines collapsed, trimmed)' );

$out = $san( array( 'app_id' => "  my\tapp  " ) );
$check( $out['app_id'] === 'my app', 'app_id sanitized' );

$out = $san( array() );
$check( $out['cloud_url'] === '' && $out['cloud_token'] === '' && $out['app_id'] === '', 'missing url/token/app_id → empty strings' );

// =====================================================================
// boolean flags coerced to 0/1
// =====================================================================
$out = $san(
	array(
		'enable_cache'     => '1',
		'enable_cron'      => 'on',
		'enable_health'    => 1,
		'sdk_email'        => '',
		'sdk_jobs'         => '0',
		'purge_on_save'    => 'yes',
		'capture_warnings' => null,
	)
);
$check( $out['enable_cache'] === 1, 'truthy flag → 1' );
$check( $out['enable_cron'] === 1, 'string "on" → 1' );
$check( $out['enable_health'] === 1, 'int 1 → 1' );
$check( $out['sdk_email'] === 0, 'empty string → 0' );
// WP checkboxes submit "1" when checked and nothing at all when unchecked, so
// empty() coercion is correct: the falsy string "0" maps to 0.
$check( $out['sdk_jobs'] === 0, 'falsy string "0" → 0 (empty() coercion)' );
$check( $out['purge_on_save'] === 1, 'string "yes" → 1' );
$check( $out['capture_warnings'] === 0, 'null flag → 0' );

// Every known flag is always present in the output (even when absent in input).
$out = $san( array() );
foreach ( array( 'enable_cache', 'enable_cron', 'enable_health', 'sdk_email', 'sdk_jobs', 'purge_on_save', 'capture_warnings' ) as $flag ) {
	$check( array_key_exists( $flag, $out ) && $out[ $flag ] === 0, "flag '$flag' defaults to 0 when absent" );
}

// =====================================================================
// anon Cache-Control passthrough
// =====================================================================
$out = $san( array( 'anon_cache_control' => 'public, max-age=300' ) );
$check( $out['anon_cache_control'] === 'public, max-age=300', 'anon_cache_control passed through (sanitized)' );
$check( $san( array() )['anon_cache_control'] === '', 'missing anon_cache_control → empty' );

// =====================================================================
// cloud-without-endpoint guard
// =====================================================================
$out = $san( array( 'mode' => 'cloud', 'cloud_url' => '' ) );
$check( $out['mode'] === 'auto', 'cloud mode without a URL falls back to auto' );
$check( count( $GLOBALS['_bext_settings_errors'] ) === 1, 'a settings error was recorded for the misconfig' );
$check( ( $GLOBALS['_bext_settings_errors'][0]['code'] ?? '' ) === 'cloud_url_missing', 'the right error code was recorded' );

// cloud WITH a URL → no guard, mode preserved.
$out = $san( array( 'mode' => 'cloud', 'cloud_url' => 'https://edge.test' ) );
$check( $out['mode'] === 'cloud', 'cloud mode with a URL is preserved' );
$check( count( $GLOBALS['_bext_settings_errors'] ) === 0, 'no settings error when cloud has a URL' );

// =====================================================================
// register() wires the right admin hooks
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_settings_errors'] = array();
( new Settings( new Env(), Plugin::instance() ) )->register();
$check( ! empty( $GLOBALS['_bext_filters']['admin_menu'] ), 'Settings register() wires admin_menu' );
$check( ! empty( $GLOBALS['_bext_filters']['admin_init'] ), 'Settings register() wires admin_init' );
$check( ! empty( $GLOBALS['_bext_filters']['admin_post_bext_test_connection'] ), 'Settings register() wires the test-connection handler' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
