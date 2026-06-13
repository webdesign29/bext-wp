<?php
/**
 * Cron / Action Scheduler taming.
 *
 * Action Scheduler's "async request runner" fires a loopback POST to
 * wp-admin/admin-ajax.php?action=as_async_request_queue_runner on request
 * shutdown. Behind bext those self-calls were measured at 5-21 s, tying up
 * PHP-FPM workers — the biggest raw time sink on the box. WP-cron is already
 * driven by a system crontab (DISABLE_WP_CRON=true), so the async runner is
 * redundant here: we disable it and let the system cron drain the queue.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Cron {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		if ( ! $this->env->is_behind_bext() ) {
			return;
		}

		// 1. Kill the admin-ajax async loopback runner. The queue still drains
		//    via the system cron (`wp cron event run`).
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', 100 );

		// 2. Bound the work a single queue run can do, so a cron-triggered run
		//    can't wedge a worker on a shared host. Overridable via filter.
		add_filter(
			'action_scheduler_queue_runner_concurrent_batches',
			function ( $n ) {
				return (int) apply_filters( 'bext/as_concurrent_batches', 1 );
			},
			100
		);
		add_filter(
			'action_scheduler_queue_runner_time_limit',
			function ( $seconds ) {
				return (int) apply_filters( 'bext/as_time_limit', min( 20, (int) $seconds ) );
			},
			100
		);

		// 3. When WP-cron is disabled but no system cron appears to be running
		//    Action Scheduler, fall back to allowing the runner so the queue
		//    doesn't stall silently. (Health module surfaces this too.)
		if ( ! $this->system_cron_expected() ) {
			remove_filter( 'action_scheduler_allow_async_request_runner', '__return_false', 100 );
		}
	}

	/**
	 * Heuristic: do we expect a system cron to drive Action Scheduler? True when
	 * WP-cron is disabled (the Ploi/bext setup) — that's our signal a crontab
	 * runs `wp cron event run`.
	 */
	private function system_cron_expected(): bool {
		$default = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return (bool) apply_filters( 'bext/system_cron_expected', $default );
	}

	/**
	 * Action Scheduler queue stats for the dashboard. Returns null when AS isn't
	 * installed.
	 *
	 * @return array<string,mixed>|null
	 */
	public function stats(): ?array {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return null;
		}

		try {
			$store = \ActionScheduler::store();
		} catch ( \Throwable $e ) {
			return null;
		}

		$count = function ( $status ) use ( $store ) {
			try {
				return (int) $store->query_actions( array( 'status' => $status ), 'count' );
			} catch ( \Throwable $e ) {
				return null;
			}
		};

		$oldest_due = null;
		try {
			$ids = $store->query_actions(
				array(
					'status'       => \ActionScheduler_Store::STATUS_PENDING,
					'date'         => as_get_datetime_object( 'now' ),
					'date_compare' => '<=',
					'per_page'     => 1,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			if ( ! empty( $ids ) ) {
				$action = $store->fetch_action( reset( $ids ) );
				if ( $action && $action->get_schedule() && $action->get_schedule()->get_date() ) {
					$oldest_due = $action->get_schedule()->get_date()->getTimestamp();
				}
			}
		} catch ( \Throwable $e ) {
			$oldest_due = null;
		}

		return array(
			'available'        => true,
			'pending'          => $count( \ActionScheduler_Store::STATUS_PENDING ),
			'running'          => $count( \ActionScheduler_Store::STATUS_RUNNING ),
			'failed'           => $count( \ActionScheduler_Store::STATUS_FAILED ),
			'complete'         => $count( \ActionScheduler_Store::STATUS_COMPLETE ),
			'oldest_due'       => $oldest_due,
			'async_disabled'   => $this->system_cron_expected(),
			'system_cron'      => $this->system_cron_expected(),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}
}
