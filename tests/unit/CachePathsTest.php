<?php
/**
 * Tests for the Cache module: URL→path normalization (the building block of
 * every purge), the install base path (subdirectory correctness), the
 * purge-set computation for a post (incl. dedup + the broadened set), the
 * shutdown flush body + the `bext/after_purge` hook, manual-purge path handling,
 * and purge-on-save gating.
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

// A purge-proxy-capturing Env so we can inspect the flushed body without HTTP.
class BextCacheEnv extends Env {
	public $purge_bodies = array();
	public $behind       = true;
	public function is_behind_bext(): bool {
		return $this->behind;
	}
	public function canonical_host(): string {
		return 'example.test';
	}
	public function purge_proxy( array $body, bool $blocking = false ) {
		$this->purge_bodies[] = $body;
		return array( 'code' => 200, 'body' => '{"ok":true}' );
	}
}

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

// --- url_to_path ---
$check( $path( 'https://example.test/about/' ) === '/about/', 'absolute URL → path' );
$check( $path( 'https://example.test/' ) === '/', 'root URL → /' );
$check( $path( 'https://example.test/x?a=b' ) === '/x?a=b', 'query string kept (plain-permalink sites)' );
$check( $path( 'https://example.test/?p=123' ) === '/?p=123', 'plain permalink kept' );
$check( $path( 'https://example.test/caf%C3%A9/' ) === '/caf%C3%A9/', 'encoded path preserved' );
$check( $path( '/already/rooted/' ) === '/already/rooted/', 'already-relative URL → unchanged path' );

// --- normalize_path ---
$check( $norm( 'about' ) === '/about', 'bare path gets a leading slash' );
$check( $norm( '/already/' ) === '/already/', 'already-rooted path unchanged' );
$check( $norm( 'https://example.test/y/' ) === '/y/', 'full URL reduced to path' );
$check( $norm( '' ) === '', 'empty stays empty' );
$check( $norm( '  /spaced/  ' ) === '/spaced/', 'whitespace trimmed' );
$check( $norm( '   ' ) === '', 'whitespace-only → empty' );
$check( $norm( 'HTTP://Example.Test/Up/' ) === '/Up/', 'uppercase scheme still reduced to path' );

// --- home_path (install base) ---
$GLOBALS['_bext_home'] = 'https://example.test';
$check( ( new Env() )->home_path() === '/', 'root install → /' );
$GLOBALS['_bext_home'] = 'https://example.test/blog';
$check( ( new Env() )->home_path() === '/blog/', 'subdirectory install → /blog/' );
$GLOBALS['_bext_home'] = 'https://example.test/a/b';
$check( ( new Env() )->home_path() === '/a/b/', 'nested base → /a/b/' );
$GLOBALS['_bext_home'] = 'https://example.test';

// =====================================================================
// queue_paths: normalization + dedup (the "set" semantics)
// =====================================================================
$paths_prop = new ReflectionProperty( Cache::class, 'paths' );
$paths_prop->setAccessible( true );

$c = new Cache( new Env(), Plugin::instance() );
$c->queue_paths( array( '/a/', '/a/', 'b', '/a/' ) );
$queued = array_keys( $paths_prop->getValue( $c ) );
$check( $queued === array( '/a/', '/b' ), 'queue_paths dedups + normalizes to a set' );

$c2 = new Cache( new Env(), Plugin::instance() );
$c2->queue_paths( array( '', '   ', '/keep/' ) );
$check( array_keys( $paths_prop->getValue( $c2 ) ) === array( '/keep/' ), 'queue_paths drops empty/whitespace paths' );

// =====================================================================
// queue_post_urls: the (broadened) purge set for a post
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_home']        = 'https://example.test';
$GLOBALS['_bext_taxonomies']  = array( 'category' );
$GLOBALS['_bext_terms']       = array( 'category' => array( (object) array( 'slug' => 'news' ) ) );
$GLOBALS['_bext_attached']    = array();

bext_make_post( 10, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hello', 'post_author' => 3 ) );

$c3 = new Cache( new Env(), Plugin::instance() );
$c3->queue_post_urls( 10 );
$set = array_keys( $paths_prop->getValue( $c3 ) );

$check( in_array( '/', $set, true ), 'post purge set includes home' );
$check( in_array( '/hello/', $set, true ), 'post purge set includes the permalink' );
$check( in_array( '/post/', $set, true ), 'post purge set includes the post-type archive' );
$check( in_array( '/post/page/2/', $set, true ), 'post purge set includes the paginated archive (page/2)' );
$check( in_array( '/author/3/', $set, true ), 'post purge set includes the author archive' );
$check( in_array( '/category/news/', $set, true ), 'post purge set includes term archives' );
$check( in_array( '/feed/', $set, true ), 'post purge set includes the feed' );
$check( count( $set ) === count( array_unique( $set ) ), 'post purge set has no duplicate paths' );

// Attachment page added only when the post has attachments.
bext_test_reset();
$GLOBALS['_bext_home']     = 'https://example.test';
$GLOBALS['_bext_attached'] = array( 11 => array( (object) array( 'ID' => 99 ) ) );
bext_make_post( 11, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'with-media', 'post_author' => 1 ) );
$c4 = new Cache( new Env(), Plugin::instance() );
$c4->queue_post_urls( 11 );
$set4 = array_keys( $paths_prop->getValue( $c4 ) );
$check( in_array( '/?attachment_id=99', $set4, true ), 'attachment page purged when the post has one' );

bext_test_reset();
$GLOBALS['_bext_home'] = 'https://example.test';
bext_make_post( 12, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'no-media', 'post_author' => 1 ) );
$c5 = new Cache( new Env(), Plugin::instance() );
$c5->queue_post_urls( 12 );
$set5 = array_keys( $paths_prop->getValue( $c5 ) );
$check( ! preg_grep( '#attachment_id#', $set5 ), 'no attachment path when the post has none' );

// Non-viewable post type → nothing queued.
bext_test_reset();
bext_make_post( 13, array( 'post_type' => 'nonviewable', 'post_status' => 'publish' ) );
$c6 = new Cache( new Env(), Plugin::instance() );
$c6->queue_post_urls( 13 );
$check( $paths_prop->getValue( $c6 ) === array(), 'non-viewable post type queues nothing' );

// Missing post → nothing queued (defensive).
bext_test_reset();
$c7 = new Cache( new Env(), Plugin::instance() );
$c7->queue_post_urls( 9999 );
$check( $paths_prop->getValue( $c7 ) === array(), 'missing post queues nothing' );

// The bext/purge_urls_for_post filter can replace the whole set.
bext_test_reset();
$GLOBALS['_bext_home'] = 'https://example.test';
bext_make_post( 14, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'filtered', 'post_author' => 1 ) );
add_filter(
	'bext/purge_urls_for_post',
	function ( $paths, $post_id ) {
		return array( '/only/', '/' . $post_id . '/' );
	},
	10,
	2
);
$c8 = new Cache( new Env(), Plugin::instance() );
$c8->queue_post_urls( 14 );
$set8 = array_keys( $paths_prop->getValue( $c8 ) );
$check( in_array( '/only/', $set8, true ) && in_array( '/14/', $set8, true ), 'purge_urls_for_post filter replaces the set' );
$check( ! in_array( '/filtered/', $set8, true ), 'filter return wins over the computed permalink' );

// =====================================================================
// flush(): body shape (paths vs prefixes) + bext/after_purge
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_home'] = 'https://example.test';

$envp = new BextCacheEnv();
$cf   = new Cache( $envp, Plugin::instance() );
$cf->queue_paths( array( '/a/', '/b/' ) );
$cf->flush();
$check( count( $envp->purge_bodies ) === 1, 'flush sends exactly one purge' );
$body = $envp->purge_bodies[0];
$check( $body['host'] === 'example.test', 'flush body carries the canonical host' );
$check( $body['paths'] === array( '/a/', '/b/' ), 'flush body carries the queued paths' );
$check( $body['prefixes'] === array(), 'path flush has no prefixes' );
$check( bext_action_count( 'bext/after_purge' ) === 1, 'bext/after_purge fired once on flush' );
$args = bext_last_action( 'bext/after_purge' );
$check( $args[0] === 'example.test', 'after_purge arg 0 = host' );
$check( $args[1] === array( '/a/', '/b/' ), 'after_purge arg 1 = purged paths' );
$check( $args[2] === array(), 'after_purge arg 2 = prefixes (empty for path purge)' );

// queue_all() → a prefix (site-wide) purge scoped to the install base.
bext_test_reset();
$GLOBALS['_bext_home'] = 'https://example.test/blog';
$envp2                 = new BextCacheEnv();
$ca                    = new Cache( $envp2, Plugin::instance() );
$ca->queue_all();
$ca->flush();
$body2 = $envp2->purge_bodies[0];
$check( $body2['paths'] === array(), 'site-wide purge has no explicit paths' );
$check( $body2['prefixes'] === array( '/blog/' ), 'site-wide purge is scoped to the install base prefix' );
$args2 = bext_last_action( 'bext/after_purge' );
$check( $args2[2] === array( '/blog/' ), 'after_purge prefixes = the scoped base on a site-wide purge' );

// flush() with nothing queued → no purge, no hook.
bext_test_reset();
$envp3 = new BextCacheEnv();
$cn    = new Cache( $envp3, Plugin::instance() );
$cn->flush();
$check( $envp3->purge_bodies === array(), 'empty flush sends nothing' );
$check( bext_action_count( 'bext/after_purge' ) === 0, 'empty flush fires no after_purge hook' );

// flush() with no canonical host → bails before purging.
bext_test_reset();
$envh = new class() extends BextCacheEnv {
	public function canonical_host(): string {
		return '';
	}
};
$ch = new Cache( $envh, Plugin::instance() );
$ch->queue_paths( array( '/x/' ) );
$ch->flush();
$check( $envh->purge_bodies === array(), 'flush bails when there is no canonical host' );

// =====================================================================
// purge-on-save gating: register() wires save hooks only when enabled
// =====================================================================
bext_test_reset();
$envs = new class() extends Env {
	public $on = true;
	public function is_behind_bext(): bool {
		return true;
	}
	public function purge_on_save_enabled(): bool {
		return $this->on;
	}
};
$cr = new Cache( $envs, Plugin::instance() );
$cr->register();
$check( ! empty( $GLOBALS['_bext_filters']['save_post'] ), 'purge-on-save ON → save_post hook wired' );
$check( ! empty( $GLOBALS['_bext_filters']['transition_post_status'] ), 'purge-on-save ON → transition_post_status wired' );

bext_test_reset();
$envs2     = new class() extends Env {
	public function is_behind_bext(): bool {
		return true;
	}
	public function purge_on_save_enabled(): bool {
		return false;
	}
};
$cr2 = new Cache( $envs2, Plugin::instance() );
$cr2->register();
$check( empty( $GLOBALS['_bext_filters']['save_post'] ), 'purge-on-save OFF → no save_post hook' );
$check( ! empty( $GLOBALS['_bext_filters']['template_redirect'] ), 'headers hook still wired when purge-on-save off' );

// Not behind bext → register() no-ops entirely.
bext_test_reset();
$envoff = new class() extends Env {
	public function is_behind_bext(): bool {
		return false;
	}
};
( new Cache( $envoff, Plugin::instance() ) )->register();
$check( empty( $GLOBALS['_bext_filters'] ), 'register() no-ops when not behind bext' );

$GLOBALS['_bext_home'] = 'https://example.test';

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
