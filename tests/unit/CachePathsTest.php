<?php
/**
 * Tests for URL→path normalization (the building block of every purge) and the
 * install base path (subdirectory correctness).
 *
 * Run: php tests/unit/CachePathsTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Cache.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Cache;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$env   = new Env();
$cache = new Cache( $env, Plugin::instance() );

$u2p = new ReflectionMethod( Cache::class, 'url_to_path' );
$u2p->setAccessible( true );
$np = new ReflectionMethod( Cache::class, 'normalize_path' );
$np->setAccessible( true );
$path = function ( $url ) use ( $u2p, $cache ) {
	return $u2p->invoke( $cache, $url );
};
$norm = function ( $p ) use ( $np, $cache ) {
	return $np->invoke( $cache, $p );
};

// url_to_path
$check( $path( 'https://example.test/about/' ) === '/about/', 'absolute URL → path' );
$check( $path( 'https://example.test/' ) === '/', 'root URL → /' );
$check( $path( 'https://example.test/x?a=b' ) === '/x?a=b', 'query string kept (plain-permalink sites)' );
$check( $path( 'https://example.test/?p=123' ) === '/?p=123', 'plain permalink kept' );
$check( $path( 'https://example.test/caf%C3%A9/' ) === '/caf%C3%A9/', 'encoded path preserved' );

// normalize_path
$check( $norm( 'about' ) === '/about', 'bare path gets a leading slash' );
$check( $norm( '/already/' ) === '/already/', 'already-rooted path unchanged' );
$check( $norm( 'https://example.test/y/' ) === '/y/', 'full URL reduced to path' );
$check( $norm( '' ) === '', 'empty stays empty' );
$check( $norm( '  /spaced/  ' ) === '/spaced/', 'whitespace trimmed' );

// home_path (install base)
$GLOBALS['_bext_home'] = 'https://example.test';
$check( ( new Env() )->home_path() === '/', 'root install → /' );
$GLOBALS['_bext_home'] = 'https://example.test/blog';
$check( ( new Env() )->home_path() === '/blog/', 'subdirectory install → /blog/' );
$GLOBALS['_bext_home'] = 'https://example.test/a/b';
$check( ( new Env() )->home_path() === '/a/b/', 'nested base → /a/b/' );
$GLOBALS['_bext_home'] = 'https://example.test';

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
