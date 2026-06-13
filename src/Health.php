<?php
/**
 * Health & hygiene: surface misconfiguration and noisy/broken plugins, and
 * (opt-in) capture recent PHP warnings for the dashboard.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Health {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	/** @var array<int,array{msg:string,file:string,line:int,type:int,count:int}> */
	private $warnings = array();

	/** @var callable|null Previous error handler, for chaining. */
	private $prev_handler = null;

	const WARN_OPTION = 'bext_wp_recent_warnings';
	const WARN_MAX    = 30;

	/** Plugins known to misbehave behind a strict open_basedir / reverse proxy. */
	const KNOWN_ISSUES = array(
		'wp-memory-usage'  => 'Triggers open_basedir warnings on every admin-ajax request (reads outside the site root).',
		'wp-file-manager'  => 'Path operations may fail under open_basedir.',
	);

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		// Warning capture is opt-in (production-safe default off).
		if ( $this->env->capture_warnings_enabled() && apply_filters( 'bext/enable_warning_capture', true ) ) {
			$this->prev_handler = set_error_handler( array( $this, 'handle_php_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			add_action( 'shutdown', array( $this, 'persist_warnings' ), 1 );
		}
	}

	/**
	 * Lightweight error handler: records E_WARNING/E_DEPRECATED (deduped) and
	 * always chains to the previous handler so we never swallow errors.
	 */
	public function handle_php_error( $type, $message, $file = '', $line = 0 ) {
		if ( in_array( $type, array( E_WARNING, E_DEPRECATED, E_USER_WARNING, E_NOTICE ), true ) ) {
			$key = md5( $message . '|' . $file . '|' . $line );
			if ( isset( $this->warnings[ $key ] ) ) {
				++$this->warnings[ $key ]['count'];
			} elseif ( count( $this->warnings ) < self::WARN_MAX ) {
				$this->warnings[ $key ] = array(
					'msg'   => (string) $message,
					'file'  => (string) $file,
					'line'  => (int) $line,
					'type'  => (int) $type,
					'count' => 1,
				);
			}
		}
		if ( $this->prev_handler ) {
			return call_user_func( $this->prev_handler, $type, $message, $file, $line );
		}
		return false; // Let PHP's internal handler run too.
	}

	public function persist_warnings(): void {
		if ( empty( $this->warnings ) ) {
			return;
		}
		$existing = get_option( self::WARN_OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		foreach ( $this->warnings as $w ) {
			$w['time'] = time();
			array_unshift( $existing, $w );
		}
		update_option( self::WARN_OPTION, array_slice( $existing, 0, self::WARN_MAX ), false );
	}

	public function recent_warnings(): array {
		$w = get_option( self::WARN_OPTION, array() );
		return is_array( $w ) ? $w : array();
	}

	public function clear_warnings(): void {
		delete_option( self::WARN_OPTION );
	}

	/**
	 * Run the configuration checks shown on the dashboard.
	 *
	 * @return array<int,array{id:string,label:string,status:string,detail:string}>
	 */
	public function checks(): array {
		$checks = array();

		$checks[] = array(
			'id'     => 'detected',
			'label'  => 'Served by bext',
			'status' => $this->env->is_behind_bext() ? 'ok' : 'warn',
			'detail' => $this->env->is_behind_bext()
				? ( $this->env->bext_version() ? 'bext ' . $this->env->bext_version() : 'detected' )
				: 'No bext signal seen yet (the plugin no-ops until detected).',
		);

		$wp_cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[]    = array(
			'id'     => 'wp_cron',
			'label'  => 'WP-cron handled by system cron',
			'status' => $wp_cron_off ? 'ok' : 'warn',
			'detail' => $wp_cron_off
				? 'DISABLE_WP_CRON is on — a system crontab should run `wp cron event run`.'
				: 'WP-cron runs on page loads; consider DISABLE_WP_CRON + a system crontab.',
		);

		$cron  = $this->plugin->module( 'cron' );
		$stats = ( $cron && method_exists( $cron, 'stats' ) ) ? $cron->stats() : null;
		if ( $stats ) {
			$checks[] = array(
				'id'     => 'as_async',
				'label'  => 'Action Scheduler async runner',
				'status' => ! empty( $stats['async_disabled'] ) ? 'ok' : 'warn',
				'detail' => ! empty( $stats['async_disabled'] )
					? 'Disabled — queue drains via system cron (no admin-ajax self-calls).'
					: 'Still enabled — admin-ajax self-calls may load the server.',
			);
		}

		// Known problematic active plugins.
		$active = $this->active_plugin_slugs();
		foreach ( self::KNOWN_ISSUES as $slug => $why ) {
			if ( in_array( $slug, $active, true ) ) {
				$checks[] = array(
					'id'     => 'plugin_' . $slug,
					'label'  => 'Plugin: ' . $slug,
					'status' => 'warn',
					'detail' => $why,
				);
			}
		}

		// open_basedir (informational — affects file ops + port-file discovery).
		$obd = ini_get( 'open_basedir' );
		if ( $obd ) {
			$checks[] = array(
				'id'     => 'open_basedir',
				'label'  => 'open_basedir restriction',
				'status' => 'ok',
				'detail' => 'Active: ' . $obd . ' (purge uses the BEXT_CACHE_PURGE_PORT param, not file reads).',
			);
		}

		// Persistent object cache (informational).
		$checks[] = array(
			'id'     => 'object_cache',
			'label'  => 'Persistent object cache',
			'status' => wp_using_ext_object_cache() ? 'ok' : 'warn',
			'detail' => wp_using_ext_object_cache() ? 'Active.' : 'Not detected (optional; bext is the edge cache).',
		);

		return apply_filters( 'bext/health_checks', $checks );
	}

	/** @return string[] Slugs (directory names) of active plugins. */
	private function active_plugin_slugs(): array {
		$slugs  = array();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $file ) {
			$slugs[] = strtok( (string) $file, '/' );
		}
		return $slugs;
	}
}
