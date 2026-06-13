<?php
/**
 * Plugin bootstrap: detects bext and wires the feature modules.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton orchestrator.
 *
 * Each feature module is a small class exposing register(); modules are gated
 * by a constant (BEXT_WP_DISABLE_<KEY>) and a filter (bext/enable_<key>), and
 * every module is expected to no-op gracefully when WordPress is NOT behind
 * bext, so the package is harmless if dropped onto a non-bext host.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Env */
	private $env;

	/** @var array<string,object> Booted module instances, keyed by module slug. */
	private $modules = array();

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function env(): Env {
		return $this->env;
	}

	/**
	 * Module instance accessor (used by the dashboard + CLI to read state).
	 *
	 * @param string $slug Module slug, e.g. 'cache'.
	 * @return object|null
	 */
	public function module( string $slug ) {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Boot everything. Safe to call once; subsequent calls no-op.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Master kill switch (wp-config: define('BEXT_WP_ENABLE', false);).
		if ( defined( 'BEXT_WP_ENABLE' ) && ! BEXT_WP_ENABLE ) {
			return;
		}

		$this->env = new Env();

		// Module slug => class. Order is registration order, not load order.
		$registry = array(
			'cache'  => Cache::class,
			'cron'   => Cron::class,
			'health' => Health::class,
			'admin'  => Admin::class,
			'sdk'    => SDK::class,
		);

		foreach ( $registry as $slug => $class ) {
			if ( ! $this->is_module_enabled( $slug ) ) {
				continue;
			}
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var object $module */
			$module = new $class( $this->env, $this );
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
			$this->modules[ $slug ] = $module;
		}

		// WP-CLI commands (only when running under WP-CLI).
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( CLI\Commands::class ) ) {
			CLI\Commands::register( $this );
		}

		/**
		 * Fires after bext-wp has booted all enabled modules.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'bext/booted', $this );
	}

	/**
	 * Is a module enabled? Default on; overridable by constant + filter.
	 *
	 * @param string $slug Module slug.
	 */
	private function is_module_enabled( string $slug ): bool {
		$const = 'BEXT_WP_DISABLE_' . strtoupper( $slug );
		if ( defined( $const ) && constant( $const ) ) {
			return false;
		}

		/**
		 * Filter whether a bext-wp module is enabled.
		 *
		 * @param bool $enabled Whether the module is enabled (default true).
		 */
		return (bool) apply_filters( "bext/enable_{$slug}", true );
	}
}
