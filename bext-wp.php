<?php
/**
 * Plugin Name:       Bext for WordPress
 * Plugin URI:        https://github.com/webdesign29/bext-wp
 * Description:        Integrates WordPress with the bext server: purge-on-change edge caching, Action Scheduler taming, personalization-safe cache headers, an operator dashboard, and an optional SDK bridge (email/jobs).
 * Version:           0.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            webdesign29
 * Author URI:        https://bext.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bext-wp
 *
 * This file carries a standard plugin header so the package works BOTH as a
 * normal activatable plugin AND as a must-use plugin (loaded via mu-loader.php).
 *
 * @package Bext\WP
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BEXT_WP_VERSION' ) ) {
	// Already loaded (e.g. present as both mu-plugin and normal plugin).
	return;
}

define( 'BEXT_WP_VERSION', '0.5.0' );
define( 'BEXT_WP_FILE', __FILE__ );
define( 'BEXT_WP_DIR', __DIR__ );
// Must-use installs can't self-update through wp-admin (the fleet deploy script
// handles those); the Updater only registers for normal-plugin installs.
define( 'BEXT_WP_IS_MU', false !== strpos( __DIR__, 'mu-plugins' ) );
define(
	'BEXT_WP_URL',
	untrailingslashit(
		defined( 'WPMU_PLUGIN_URL' ) && false !== strpos( __DIR__, 'mu-plugins' )
			? rtrim( WPMU_PLUGIN_URL, '/' ) . '/' . basename( __DIR__ )
			: plugin_dir_url( __FILE__ )
	)
);

/**
 * Minimal PSR-4-ish autoloader for the Bext\WP namespace.
 * Bext\WP\Foo        -> src/Foo.php
 * Bext\WP\CLI\Foo    -> cli/Foo.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Bext\\WP\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'Bext\\WP\\' ) );
		$relative = str_replace( '\\', '/', $relative );

		if ( 0 === strpos( $relative, 'CLI/' ) ) {
			$path = BEXT_WP_DIR . '/cli/' . substr( $relative, 4 ) . '.php';
		} else {
			$path = BEXT_WP_DIR . '/src/' . $relative . '.php';
		}

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Clear sticky detection when deactivated as a normal plugin (mu-plugins can't
// be deactivated; uninstall.php covers the uninstall path).
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook(
		__FILE__,
		static function () {
			delete_option( 'bext_wp_detected' );
		}
	);
}

// Boot once WordPress core helpers are available.
\Bext\WP\Plugin::instance()->boot();
