<?php
/**
 * Tests for the Health module's checks() decision logic: the served-by-bext
 * status, the WP-cron / system-cron check, the Action-Scheduler-async check
 * (from a stubbed cron module), known-bad-plugin detection, the object-cache
 * check, and the bext/health_checks filter.
 *
 * Run: php tests/unit/HealthTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Health.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Health;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

// Index the checks() result by check id for easy assertions.
$by_id = function ( array $checks ) {
	$out = array();
	foreach ( $checks as $c ) {
		$out[ $c['id'] ] = $c;
	}
	return $out;
};

// Env double: control whether we're behind bext + the reported version.
class BextHealthEnv extends Env {
	public $behind  = true;
	public $version = 'bext/1.2.3';
	public function is_behind_bext(): bool {
		return $this->behind;
	}
	public function bext_version(): string {
		return $this->version;
	}
}

// Cron double exposing stats() with a settable async_disabled flag.
class BextHealthCron {
	public $stats;
	public function __construct( $stats ) {
		$this->stats = $stats;
	}
	public function stats() {
		return $this->stats;
	}
}

// Health type-hints the real Plugin (final, private ctor), so use the singleton
// and inject the cron module into its private $modules map via reflection.
$plugin     = Plugin::instance();
$modules_rp = new ReflectionProperty( Plugin::class, 'modules' );
$modules_rp->setAccessible( true );
$set_cron = function ( $cron ) use ( $plugin, $modules_rp ) {
	$mods = $modules_rp->getValue( $plugin );
	if ( ! is_array( $mods ) ) {
		$mods = array();
	}
	if ( null === $cron ) {
		unset( $mods['cron'] );
	} else {
		$mods['cron'] = $cron;
	}
	$modules_rp->setValue( $plugin, $mods );
};
$set_cron( null );

// =====================================================================
// served-by-bext check
// =====================================================================
bext_test_reset();
$env = new BextHealthEnv();
$h   = new Health( $env, $plugin );
$c   = $by_id( $h->checks() );
$check( ( $c['detected']['status'] ?? '' ) === 'ok', 'detected check = ok when behind bext' );
$check( strpos( (string) ( $c['detected']['detail'] ?? '' ), 'bext/1.2.3' ) !== false, 'detected detail shows the bext version' );

bext_test_reset();
$env         = new BextHealthEnv();
$env->behind = false;
$c           = $by_id( ( new Health( $env, $plugin ) )->checks() );
$check( ( $c['detected']['status'] ?? '' ) === 'warn', 'detected check = warn when not behind bext' );

// behind bext but no version string → still ok, detail = "detected"
bext_test_reset();
$env          = new BextHealthEnv();
$env->version = '';
$c            = $by_id( ( new Health( $env, $plugin ) )->checks() );
$check( ( $c['detected']['status'] ?? '' ) === 'ok', 'detected ok even without a version string' );
$check( ( $c['detected']['detail'] ?? '' ) === 'detected', 'detected detail = "detected" when version unknown' );

// =====================================================================
// WP-cron / system-cron check (DISABLE_WP_CRON)
// =====================================================================
bext_test_reset();
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ( $c['wp_cron']['status'] ?? '' ) === 'warn', 'wp_cron check = warn without DISABLE_WP_CRON' );

// Turn DISABLE_WP_CRON on for the rest of the file.
define( 'DISABLE_WP_CRON', true );
bext_test_reset();
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ( $c['wp_cron']['status'] ?? '' ) === 'ok', 'wp_cron check = ok with DISABLE_WP_CRON on' );

// =====================================================================
// Action Scheduler async-runner check, from the cron module's stats()
// =====================================================================
bext_test_reset();
$set_cron( new BextHealthCron( array( 'async_disabled' => true ) ) );
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( isset( $c['as_async'] ), 'as_async check present when the cron module has stats' );
$check( ( $c['as_async']['status'] ?? '' ) === 'ok', 'as_async = ok when async runner is disabled' );

bext_test_reset();
$set_cron( new BextHealthCron( array( 'async_disabled' => false ) ) );
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ( $c['as_async']['status'] ?? '' ) === 'warn', 'as_async = warn when async runner still enabled' );

// No stats (AS not installed) → no as_async check.
bext_test_reset();
$set_cron( new BextHealthCron( null ) );
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ! isset( $c['as_async'] ), 'no as_async check when AS is not installed' );
$set_cron( null );

// =====================================================================
// known-problematic active plugins
// =====================================================================
bext_test_reset();
update_option( 'active_plugins', array( 'wp-memory-usage/wp-memory-usage.php', 'akismet/akismet.php' ) );
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( isset( $c['plugin_wp-memory-usage'] ), 'known-bad plugin surfaced as a check' );
$check( ( $c['plugin_wp-memory-usage']['status'] ?? '' ) === 'warn', 'known-bad plugin check = warn' );

bext_test_reset();
update_option( 'active_plugins', array( 'akismet/akismet.php' ) );
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ! isset( $c['plugin_wp-memory-usage'] ), 'no plugin check when the known-bad plugin is inactive' );

// =====================================================================
// persistent object cache check
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_ext_object_cache'] = false;
$c                                 = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ( $c['object_cache']['status'] ?? '' ) === 'warn', 'object_cache = warn without a persistent cache' );

bext_test_reset();
$GLOBALS['_bext_ext_object_cache'] = true;
$c                                 = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( ( $c['object_cache']['status'] ?? '' ) === 'ok', 'object_cache = ok with a persistent cache' );
$GLOBALS['_bext_ext_object_cache'] = false;

// =====================================================================
// every check has the expected shape
// =====================================================================
bext_test_reset();
$checks = ( new Health( new BextHealthEnv(), $plugin ) )->checks();
$shape_ok = true;
foreach ( $checks as $chk ) {
	if ( ! isset( $chk['id'], $chk['label'], $chk['status'], $chk['detail'] ) ) {
		$shape_ok = false;
	}
	if ( ! in_array( $chk['status'], array( 'ok', 'warn', 'fail' ), true ) ) {
		$shape_ok = false;
	}
}
$check( $shape_ok, 'every check has id/label/status/detail with a valid status' );

// =====================================================================
// bext/health_checks filter can add/modify checks
// =====================================================================
bext_test_reset();
add_filter(
	'bext/health_checks',
	function ( $checks ) {
		$checks[] = array( 'id' => 'custom', 'label' => 'Custom', 'status' => 'ok', 'detail' => 'added by a filter' );
		return $checks;
	}
);
$c = $by_id( ( new Health( new BextHealthEnv(), $plugin ) )->checks() );
$check( isset( $c['custom'] ) && 'added by a filter' === $c['custom']['detail'], 'bext/health_checks filter can append a check' );

// =====================================================================
// register(): warning capture is opt-in
// =====================================================================
bext_test_reset();
$envcap = new class() extends Env {
	public function capture_warnings_enabled(): bool {
		return false;
	}
};
( new Health( $envcap, $plugin ) )->register();
$check( empty( $GLOBALS['_bext_filters']['shutdown'] ), 'warning capture OFF → no shutdown hook wired' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
