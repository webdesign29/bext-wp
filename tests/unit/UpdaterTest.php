<?php
/**
 * Unit tests for the self-hosted updater: version comparison + how it populates
 * the update_plugins transient and the plugins_api details object.
 *
 * Run: php tests/unit/UpdaterTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';

// Constants the plugin entry file would normally define.
defined( 'BEXT_WP_FILE' ) || define( 'BEXT_WP_FILE', '/wp/wp-content/plugins/bext-wp/bext-wp.php' );
defined( 'BEXT_WP_VERSION' ) || define( 'BEXT_WP_VERSION', '0.4.3' );
defined( 'BEXT_WP_IS_MU' ) || define( 'BEXT_WP_IS_MU', false );

// Extra WP stubs the Updater needs (bootstrap doesn't define these).
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		// .../plugins/bext-wp/bext-wp.php → bext-wp/bext-wp.php
		$parts = explode( '/', $file );
		$n     = count( $parts );
		return $n >= 2 ? $parts[ $n - 2 ] . '/' . $parts[ $n - 1 ] : $file;
	}
	function get_site_transient( $k ) {
		return $GLOBALS['_bext_st'][ $k ] ?? false;
	}
	function set_site_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['_bext_st'][ $k ] = $v;
		return true;
	}
	function delete_site_transient( $k ) {
		unset( $GLOBALS['_bext_st'][ $k ] );
		return true;
	}
}
$GLOBALS['_bext_st'] = array();

require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Updater.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Updater;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$basename = 'bext-wp/bext-wp.php';
$prime    = function ( string $version ) {
	$GLOBALS['_bext_st'][ Updater::CACHE_KEY ] = array(
		'name'         => 'Bext for WordPress',
		'slug'         => 'bext-wp',
		'version'      => $version,
		'download_url' => 'https://github.com/webdesign29/bext-wp/releases/latest/download/bext-wp.zip',
		'homepage'     => 'https://wp-plugins.inklura.fr/bext',
		'requires'     => '5.8',
		'tested'       => '6.7',
		'requires_php' => '7.4',
		'sections'     => array( 'description' => 'desc', 'changelog' => '<h4>x</h4>' ),
	);
};

$updater = new Updater( new Env(), Plugin::instance() );
$mk      = function () {
	$t            = new stdClass();
	$t->response  = array();
	$t->no_update = array();
	return $t;
};

// Newer remote → goes into ->response with the right package + version.
$prime( '0.5.0' );
$t = $updater->inject_update( $mk() );
$check( isset( $t->response[ $basename ] ), 'newer version → injected into ->response' );
$check( ( $t->response[ $basename ]->new_version ?? '' ) === '0.5.0', 'new_version is the remote version' );
$check( false !== strpos( (string) ( $t->response[ $basename ]->package ?? '' ), 'releases/latest/download/bext-wp.zip' ), 'package is the GitHub release URL' );
$check( ! isset( $t->no_update[ $basename ] ), 'newer version → not in ->no_update' );

// Same version → ->no_update (not an update).
$prime( '0.4.3' );
$t = $updater->inject_update( $mk() );
$check( ! isset( $t->response[ $basename ] ), 'same version → not in ->response' );
$check( isset( $t->no_update[ $basename ] ), 'same version → listed in ->no_update' );

// Older remote → ->no_update.
$prime( '0.4.0' );
$t = $updater->inject_update( $mk() );
$check( ! isset( $t->response[ $basename ] ), 'older version → not in ->response' );

// Non-object transient passed through unchanged (defensive).
$check( $updater->inject_update( false ) === false, 'non-object transient passed through' );

// plugins_api details for our slug.
$prime( '0.5.0' );
$info = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'bext-wp' ) );
$check( is_object( $info ) && ( $info->version ?? '' ) === '0.5.0', 'plugin_info returns version' );
$check( is_object( $info ) && false !== strpos( (string) ( $info->download_link ?? '' ), 'bext-wp.zip' ), 'plugin_info has download_link' );
$check( is_object( $info ) && isset( $info->sections['changelog'] ), 'plugin_info has changelog section' );

// plugins_api ignores other slugs.
$other = $updater->plugin_info( 'ORIG', 'plugin_information', (object) array( 'slug' => 'something-else' ) );
$check( $other === 'ORIG', 'plugin_info passes through other slugs' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
