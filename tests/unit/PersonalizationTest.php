<?php
/**
 * Tests is_personalized_request() — the gate that keeps logged-in / cart /
 * comment responses out of bext's anonymous cache.
 *
 * Run: php tests/unit/PersonalizationTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';

use Bext\WP\Env;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$personalized = function ( array $cookies, bool $logged_in = false ) {
	$_COOKIE                     = $cookies;
	$GLOBALS['_bext_logged_in']  = $logged_in;
	return ( new Env() )->is_personalized_request();
};

$check( $personalized( array() ) === false, 'anonymous, no cookies → not personalized' );
$check( $personalized( array(), true ) === true, 'logged-in → personalized' );
$check( $personalized( array( 'wordpress_logged_in_abc123' => 'x' ) ) === true, 'wordpress_logged_in_* cookie → personalized' );
$check( $personalized( array( 'comment_author_abc' => 'x' ) ) === true, 'comment_author_* cookie → personalized' );
$check( $personalized( array( 'woocommerce_cart_hash' => 'x' ) ) === true, 'woocommerce_cart_hash cookie → personalized' );
$check( $personalized( array( 'woocommerce_items_in_cart' => '2' ) ) === true, 'woocommerce_items_in_cart cookie → personalized' );
$check( $personalized( array( 'wp_woocommerce_session_xyz' => 'x' ) ) === true, 'wp_woocommerce_session_* cookie → personalized' );
$check( $personalized( array( 'edd_items_in_cart' => '1' ) ) === true, 'edd_items_in_cart cookie → personalized' );
$check( $personalized( array( '_ga' => 'x', 'PHPSESSID' => 'y' ) ) === false, 'analytics/session cookies → not personalized' );
$check( $personalized( array( 'wordpress_test_cookie' => 'x' ) ) === false, 'wp test cookie alone → not personalized' );

$_COOKIE = array();

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
