<?php
/**
 * Uninstall cleanup: remove all options bext-wp creates.
 *
 * Runs only when the plugin is deleted via the WordPress admin (normal-plugin
 * install). For must-use installs, removal is handled by the fleet deploy
 * script (`deploy-fleet.sh --remove`).
 *
 * @package Bext\WP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bext_wp_options = array(
	'bext_wp_detected',
	'bext_wp_purge_log',
	'bext_wp_recent_warnings',
);

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		foreach ( $bext_wp_options as $opt ) {
			delete_option( $opt );
		}
		restore_current_blog();
	}
} else {
	foreach ( $bext_wp_options as $opt ) {
		delete_option( $opt );
	}
}
