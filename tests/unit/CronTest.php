<?php
/**
 * Tests for the Cron module: register() taming filters (async runner off, batch
 * bounds, fallback when no system cron) and stats() against a stubbed Action
 * Scheduler store.
 *
 * Run: php tests/unit/CronTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Cron.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Cron;

// Stubs WordPress provides that the AS-runner taming filters rely on.
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $cb, $prio = 10 ) {
		if ( empty( $GLOBALS['_bext_filters'][ $tag ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['_bext_filters'][ $tag ] as $i => $h ) {
			if ( $h['cb'] === $cb ) {
				unset( $GLOBALS['_bext_filters'][ $tag ][ $i ] );
			}
		}
		$GLOBALS['_bext_filters'][ $tag ] = array_values( $GLOBALS['_bext_filters'][ $tag ] );
		return true;
	}
}

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$behind_env = function () {
	return new class() extends Env {
		public function is_behind_bext(): bool {
			return true;
		}
	};
};

// =====================================================================
// register(): not behind bext → no-op
// =====================================================================
bext_test_reset();
$envoff = new class() extends Env {
	public function is_behind_bext(): bool {
		return false;
	}
};
( new Cron( $envoff, Plugin::instance() ) )->register();
$check( empty( $GLOBALS['_bext_filters'] ), 'cron register() no-ops when not behind bext' );

// =====================================================================
// register(): with a system cron expected (DISABLE_WP_CRON on)
// =====================================================================
bext_test_reset();
define( 'DISABLE_WP_CRON', true );
( new Cron( $behind_env(), Plugin::instance() ) )->register();

$check( ! empty( $GLOBALS['_bext_filters']['action_scheduler_allow_async_request_runner'] ), 'async-runner filter registered' );
// With a system cron, the async-runner-disabling filter stays in place.
$disable_cb = $GLOBALS['_bext_filters']['action_scheduler_allow_async_request_runner'][0]['cb'] ?? null;
$check( '__return_false' === $disable_cb, 'async runner is disabled (__return_false) when a system cron is expected' );

// Batch + time-limit bounds, overridable via filters.
$batches = apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 8 );
$check( 1 === $batches, 'concurrent batches bounded to 1 by default' );
add_filter( 'bext/as_concurrent_batches', function () { return 3; } );
$check( 3 === apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 8 ), 'concurrent batches overridable via bext/as_concurrent_batches' );

$tl = apply_filters( 'action_scheduler_queue_runner_time_limit', 120 );
$check( 20 === $tl, 'time limit clamped to <=20s' );
$tl2 = apply_filters( 'action_scheduler_queue_runner_time_limit', 5 );
$check( 5 === $tl2, 'time limit keeps a smaller configured value' );

// =====================================================================
// stats(): null when Action Scheduler isn't installed
// =====================================================================
bext_test_reset();
$check( ( new Cron( $behind_env(), Plugin::instance() ) )->stats() === null, 'stats() is null without Action Scheduler' );

// =====================================================================
// stats(): with a stubbed Action Scheduler store
// =====================================================================

// Minimal AS doubles. ActionScheduler::store() returns our fake store; the
// store answers query_actions('count') per status and fetch_action() for the
// oldest-due lookup.
if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	class ActionScheduler_Store {
		const STATUS_PENDING  = 'pending';
		const STATUS_RUNNING  = 'in-progress';
		const STATUS_FAILED   = 'failed';
		const STATUS_COMPLETE = 'complete';
	}
}

class BextFakeASStore {
	public $counts = array(
		'pending'     => 7,
		'in-progress' => 1,
		'failed'      => 2,
		'complete'    => 99,
	);
	public $oldest_ts = null;
	public $throw_on_count = false;

	public function query_actions( $args, $return = 'ids' ) {
		if ( 'count' === $return ) {
			if ( $this->throw_on_count ) {
				throw new \RuntimeException( 'boom' );
			}
			$status = $args['status'] ?? '';
			return $this->counts[ $status ] ?? 0;
		}
		// oldest-due query: return one id when we have a timestamp to report.
		return null === $this->oldest_ts ? array() : array( 42 );
	}

	public function fetch_action( $id ) {
		$ts = $this->oldest_ts;
		return new class( $ts ) {
			private $ts;
			public function __construct( $ts ) {
				$this->ts = $ts;
			}
			public function get_schedule() {
				$ts = $this->ts;
				return new class( $ts ) {
					private $ts;
					public function __construct( $ts ) {
						$this->ts = $ts;
					}
					public function get_date() {
						$ts = $this->ts;
						return new class( $ts ) {
							private $ts;
							public function __construct( $ts ) {
								$this->ts = $ts;
							}
							public function getTimestamp() {
								return $this->ts;
							}
						};
					}
				};
			}
		};
	}
}

$GLOBALS['_bext_as_store'] = new BextFakeASStore();

if ( ! class_exists( 'ActionScheduler' ) ) {
	class ActionScheduler {
		public static function store() {
			return $GLOBALS['_bext_as_store'];
		}
	}
}

bext_test_reset();
$GLOBALS['_bext_as_store']            = new BextFakeASStore();
$GLOBALS['_bext_as_store']->oldest_ts = 1700000000;

$cron  = new Cron( $behind_env(), Plugin::instance() );
$stats = $cron->stats();

$check( is_array( $stats ), 'stats() returns an array when AS is installed' );
$check( ( $stats['available'] ?? null ) === true, 'stats: available = true' );
$check( ( $stats['pending'] ?? null ) === 7, 'stats: pending count from the store' );
$check( ( $stats['running'] ?? null ) === 1, 'stats: running count from the store' );
$check( ( $stats['failed'] ?? null ) === 2, 'stats: failed count from the store' );
$check( ( $stats['complete'] ?? null ) === 99, 'stats: complete count from the store' );
$check( ( $stats['oldest_due'] ?? null ) === 1700000000, 'stats: oldest_due timestamp resolved' );
$check( ( $stats['wp_cron_disabled'] ?? null ) === true, 'stats: wp_cron_disabled reflects DISABLE_WP_CRON' );
$check( ( $stats['async_disabled'] ?? null ) === true, 'stats: async_disabled reflects system-cron expectation' );

// No oldest-due action → oldest_due is null (queue is drained/empty).
bext_test_reset();
$GLOBALS['_bext_as_store']            = new BextFakeASStore();
$GLOBALS['_bext_as_store']->oldest_ts = null;
$stats2                               = ( new Cron( $behind_env(), Plugin::instance() ) )->stats();
$check( array_key_exists( 'oldest_due', $stats2 ) && null === $stats2['oldest_due'], 'stats: oldest_due null when nothing is overdue' );

// A throwing store is caught: counts become null, stats() still returns.
bext_test_reset();
$GLOBALS['_bext_as_store']                 = new BextFakeASStore();
$GLOBALS['_bext_as_store']->throw_on_count = true;
$stats3                                     = ( new Cron( $behind_env(), Plugin::instance() ) )->stats();
$check( is_array( $stats3 ), 'stats() survives a throwing store' );
$check( array_key_exists( 'pending', $stats3 ) && null === $stats3['pending'], 'stats: pending null when the store throws (exception-safe count)' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
