<?php
/**
 * Tests for the Network (multisite) module: purge_blog() blog-id validation,
 * exception-safe switch_to_blog (restore even when purge throws), the
 * blocking-vs-non-blocking result contract, the all-sites loop, and the
 * bext/after_purge hook firing in the network context.
 *
 * Run: php tests/unit/NetworkTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Cache.php'; // Network reads Cache::LOG_OPTION.
require __DIR__ . '/../../src/Network.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\Network;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

// Env double that records purges + the blog context they ran in, and can be
// told whether it's behind bext and what HTTP code to return.
class BextNetEnv extends Env {
	public $purges     = array();
	public $behind     = true;
	public $code       = 200;
	public function is_behind_bext(): bool {
		return $this->behind;
	}
	public function canonical_host(): string {
		return 'blog-' . ( $GLOBALS['_bext_current_blog'] ?? 0 ) . '.example.test';
	}
	public function home_path(): string {
		return '/';
	}
	public function purge_proxy( array $body, bool $blocking = false ) {
		$this->purges[] = array(
			'body'     => $body,
			'blocking' => $blocking,
			'blog'     => $GLOBALS['_bext_current_blog'] ?? 0,
		);
		return array( 'code' => $this->code, 'body' => 'ok' );
	}
}

$purge_blog = new ReflectionMethod( Network::class, 'purge_blog' );
$purge_blog->setAccessible( true );

$GLOBALS['_bext_multisite'] = true;

// =====================================================================
// purge_blog: blog-id validation
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
$env = new BextNetEnv();
$net = new Network( $env, Plugin::instance() );

$check( $purge_blog->invoke( $net, 12345, true ) === 0, 'purge_blog returns 0 for a non-existent blog id' );
$check( $env->purges === array(), 'no purge dispatched for a non-existent blog' );
$check( ( $GLOBALS['_bext_current_blog'] ?? null ) === 1, 'no switch happened for an invalid blog id' );

// =====================================================================
// purge_blog: blocking on a 200 → 1, switches into + restores the blog
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 2 );
$env = new BextNetEnv();
$net = new Network( $env, Plugin::instance() );

$res = $purge_blog->invoke( $net, 2, true );
$check( $res === 1, 'purge_blog returns 1 on a blocking 200' );
$check( count( $env->purges ) === 1, 'exactly one purge dispatched' );
$check( $env->purges[0]['blocking'] === true, 'blocking flag passed through' );
$check( $env->purges[0]['blog'] === 2, 'purge ran inside the switched blog context' );
$check( $env->purges[0]['body']['host'] === 'blog-2.example.test', 'purge used the switched blog host' );
$check( $env->purges[0]['body']['prefixes'] === array( '/' ), 'site-wide purge scoped to the blog home prefix' );
$check( ( $GLOBALS['_bext_current_blog'] ?? null ) === 1, 'restore_current_blog ran after a blocking purge' );
$check( bext_action_count( 'bext/after_purge' ) === 1, 'bext/after_purge fired once for the blog' );
$a = bext_last_action( 'bext/after_purge' );
$check( $a[0] === 'blog-2.example.test' && $a[2] === array( '/' ), 'after_purge carries the blog host + prefix' );

// =====================================================================
// purge_blog: blocking on a non-200 → 0 (still confirms failure)
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 3 );
$env       = new BextNetEnv();
$env->code = 503;
$net       = new Network( $env, Plugin::instance() );
$check( $purge_blog->invoke( $net, 3, true ) === 0, 'purge_blog returns 0 on a blocking non-200' );

// =====================================================================
// purge_blog: non-blocking → 1 (fire-and-forget, no result confirmation)
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 4 );
$env = new BextNetEnv();
$net = new Network( $env, Plugin::instance() );
$check( $purge_blog->invoke( $net, 4, false ) === 1, 'non-blocking purge counts as 1 (dispatched)' );
$check( $env->purges[0]['blocking'] === false, 'non-blocking flag passed through' );

// =====================================================================
// purge_blog: site not behind bext → no purge, returns 0, still restores
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 5 );
$env         = new BextNetEnv();
$env->behind = false;
$net         = new Network( $env, Plugin::instance() );
$check( $purge_blog->invoke( $net, 5, true ) === 0, 'no purge when the blog is not behind bext' );
$check( $env->purges === array(), 'not-behind blog dispatches nothing' );
$check( ( $GLOBALS['_bext_current_blog'] ?? null ) === 1, 'blog restored even when nothing was purged' );

// =====================================================================
// purge_blog: switch_to_blog is exception-safe (restore on a thrown purge)
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 6 );
$throwing = new class() extends BextNetEnv {
	public function purge_proxy( array $body, bool $blocking = false ) {
		throw new \RuntimeException( 'transport blew up' );
	}
};
$net    = new Network( $throwing, Plugin::instance() );
$threw  = false;
try {
	$purge_blog->invoke( $net, 6, true );
} catch ( \Throwable $e ) {
	$threw = true;
}
$check( $threw === true, 'purge_blog propagates the exception (no silent swallow)' );
$check( ( $GLOBALS['_bext_current_blog'] ?? null ) === 1, 'restore_current_blog still ran (try/finally) after a throw' );

// =====================================================================
// site_ids(): capped list of ids from get_sites()
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
bext_make_site( 1 );
bext_make_site( 7 );
bext_make_site( 9 );
$site_ids = new ReflectionMethod( Network::class, 'site_ids' );
$site_ids->setAccessible( true );
$ids = $site_ids->invoke( new Network( new BextNetEnv(), Plugin::instance() ) );
$check( $ids === array( 1, 7, 9 ), 'site_ids returns the integer blog ids' );

// =====================================================================
// register(): no-ops on single-site
// =====================================================================
bext_test_reset();
$GLOBALS['_bext_multisite'] = false;
( new Network( new BextNetEnv(), Plugin::instance() ) )->register();
$check( empty( $GLOBALS['_bext_filters']['network_admin_menu'] ), 'Network register() no-ops on single-site' );

bext_test_reset();
$GLOBALS['_bext_multisite'] = true;
( new Network( new BextNetEnv(), Plugin::instance() ) )->register();
$check( ! empty( $GLOBALS['_bext_filters']['network_admin_menu'] ), 'Network register() wires the menu on multisite' );
$check( ! empty( $GLOBALS['_bext_filters']['admin_post_bext_network_purge'] ), 'Network register() wires the purge handler' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
