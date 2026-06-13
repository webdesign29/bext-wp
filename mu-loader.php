<?php
/**
 * Plugin Name: Bext for WordPress (mu-loader)
 * Description: Must-use loader that boots the bext-wp package from its subdirectory.
 * Version:     0.4.0
 * Author:      webdesign29
 * License:     GPL-2.0-or-later
 *
 * WordPress only auto-loads PHP files placed *directly* in wp-content/mu-plugins/,
 * not files inside subdirectories. The fleet deploy copies this file to
 * `wp-content/mu-plugins/bext.php` and the package to
 * `wp-content/mu-plugins/bext-wp/`, so this stub pulls in the real plugin.
 *
 * @package Bext\WP
 */

defined( 'ABSPATH' ) || exit;

$bext_wp_main = __DIR__ . '/bext-wp/bext-wp.php';
if ( is_readable( $bext_wp_main ) ) {
	require_once $bext_wp_main;
}
