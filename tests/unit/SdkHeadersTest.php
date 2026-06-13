<?php
/**
 * Pure-PHP unit test for SDK::parse_headers (no WordPress runtime needed).
 *
 * Run: php tests/unit/SdkHeadersTest.php
 *
 * @package Bext\WP
 */

// Minimal stubs so the class file can be required outside WordPress.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

require __DIR__ . '/../../src/SDK.php';

$sdk = ( new ReflectionClass( \Bext\WP\SDK::class ) )->newInstanceWithoutConstructor();
$m   = new ReflectionMethod( \Bext\WP\SDK::class, 'parse_headers' );
$m->setAccessible( true );

$failures = 0;
$assert   = function ( $cond, $label ) use ( &$failures ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};

// String headers.
$h = $m->invoke( $sdk, "Content-Type: text/html; charset=UTF-8\r\nReply-To: a@b.co" );
$assert( ( $h['content-type'] ?? '' ) === 'text/html; charset=UTF-8', 'parses Content-Type from string' );
$assert( ( $h['reply-to'] ?? '' ) === 'a@b.co', 'parses Reply-To from string' );

// Array headers.
$h = $m->invoke( $sdk, array( 'From: WP <wp@x.co>', 'Cc: c@x.co' ) );
$assert( ( $h['from'] ?? '' ) === 'WP <wp@x.co>', 'parses From from array' );
$assert( ( $h['cc'] ?? '' ) === 'c@x.co', 'parses Cc from array' );

// Empty + malformed.
$assert( $m->invoke( $sdk, '' ) === array(), 'empty headers -> empty map' );
$assert( $m->invoke( $sdk, array( 'no-colon-here' ) ) === array(), 'malformed line ignored' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
