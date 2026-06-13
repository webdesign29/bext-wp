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

		// Multisite: per-request caches are blog-scoped, so reset them whenever
		// code switches the current blog (network purge loops, etc.).
		if ( is_multisite() ) {
			add_action( 'switch_blog', array( $this->env, 'flush_settings_cache' ) );
		}

		// Boot the admin UI only where it can actually do something — admin
		// requests (incl. admin-ajax/admin-post) and WP-CLI. Anonymous front-end
		// renders (the hot path that bext serves on a cache miss) skip Settings,
		// Network, and (usually) Health entirely.
		$want_admin_ui = is_admin() || ( defined( 'WP_CLI' ) && WP_CLI );

		// Admin owns the front-end admin-bar pill (logged-in users), so it's
		// always booted — its admin-only hooks simply never fire on the front end.
		$always = array( 'admin' => Admin::class );

		if ( $want_admin_ui ) {
			$always['settings'] = Settings::class;
			if ( is_multisite() ) {
				$always['network'] = Network::class;
			}
		}

		// Health is needed in admin/CLI (dashboard + `wp bext doctor`), and on
		// the front end only when warning capture is on (it must hook early).
		if ( $want_admin_ui || $this->env->capture_warnings_enabled() ) {
			$always['health'] = Health::class;
		}

		// Auto-updates for normal-plugin installs only (must-use installs update
		// via bin/deploy-fleet.sh). Needed in admin/CLI and during cron update
		// checks — not on the anonymous front-end hot path.
		if ( ! BEXT_WP_IS_MU && ( $want_admin_ui || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) ) {
			$always['updater'] = Updater::class;
		}
		// Feature modules, gated by mode + constant + setting + filter.
		$gated = array(
			'cache' => Cache::class,
			'cron'  => Cron::class,
			'sdk'   => SDK::class,
		);

		foreach ( $always as $slug => $class ) {
			$this->boot_module( $slug, $class );
		}
		foreach ( $gated as $slug => $class ) {
			if ( $this->env->is_enabled( $slug ) ) {
				$this->boot_module( $slug, $class );
			}
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
	 * Instantiate a module, call register(), and track it.
	 *
	 * @param string $slug  Module slug.
	 * @param string $class Fully-qualified class name.
	 */
	private function boot_module( string $slug, string $class ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		/** @var object $module */
		$module = new $class( $this->env, $this );
		if ( method_exists( $module, 'register' ) ) {
			$module->register();
		}
		$this->modules[ $slug ] = $module;
	}
}
