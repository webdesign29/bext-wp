<?php
/**
 * Locks the v0.4.1 fix: the enqueue action and filter live on DIFFERENT tags, so
 * do_action('bext/enqueue', …) enqueues exactly once (it must not also invoke the
 * filter callback and enqueue a second, malformed job).
 *
 * Run: php tests/unit/SdkEnqueueTest.php
 *
 * @package Bext\WP
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/SDK.php';

use Bext\WP\Env;
use Bext\WP\Plugin;
use Bext\WP\SDK;

/** Env double that records SDK calls and forces jobs-on / behind-bext. */
class BextFakeEnv extends Env {
	public $sdk_calls = array();
	public function is_behind_bext(): bool {
		return true;
	}
	public function sdk_email_enabled(): bool {
		return false; // don't register pre_wp_mail
	}
	public function sdk_jobs_enabled(): bool {
		return true;
	}
	public function app_id(): string {
		return 'test';
	}
	public function canonical_host(): string {
		return 'test';
	}
	public function sdk_request( string $method, string $path, $body = null, bool $blocking = true ) {
		$this->sdk_calls[] = array( 'path' => $path, 'body' => $body );
		return array( 'code' => 200, 'body' => '{"id":"job-' . count( $this->sdk_calls ) . '"}' );
	}
}

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$env = new BextFakeEnv();
$sdk = new SDK( $env, Plugin::instance() );
$sdk->register();

// 1. Fire-and-forget action enqueues EXACTLY once. (Before v0.4.1 the filter was
//    on the same tag, so do_action also ran it → a second, malformed job.)
$env->sdk_calls = array();
do_action( 'bext/enqueue', 'thumbs', array( 'id' => 1 ) );
$check( count( $env->sdk_calls ) === 1, 'do_action(bext/enqueue) enqueues exactly once (no double-fire)' );
$check( ( $env->sdk_calls[0]['path'] ?? '' ) === '/__bext/sdk/queue/push', 'posts to queue/push' );
$check( ( $env->sdk_calls[0]['body']['queue'] ?? '' ) === 'thumbs', 'queue name correct (args not shifted)' );

// 2. Filter form on the SEPARATE tag returns the job id.
$env->sdk_calls = array();
$id = apply_filters( 'bext/enqueue_job', null, 'emails', array( 'to' => 'a@b.co' ) );
$check( count( $env->sdk_calls ) === 1, 'apply_filters(bext/enqueue_job) enqueues once' );
$check( $id === 'job-1', 'filter returns the job id' );
$check( ( $env->sdk_calls[0]['body']['queue'] ?? '' ) === 'emails', 'filter queue name correct' );

// 3. The action tag and filter tag are genuinely distinct.
$check( ! empty( $GLOBALS['_bext_filters']['bext/enqueue'] ), 'action registered on bext/enqueue' );
$check( ! empty( $GLOBALS['_bext_filters']['bext/enqueue_job'] ), 'filter registered on bext/enqueue_job' );
$check( count( $GLOBALS['_bext_filters']['bext/enqueue'] ) === 1, 'only one callback on the action tag' );

// 4. Jobs disabled ⇒ no enqueue.
$env2 = new class() extends BextFakeEnv {
	public function sdk_jobs_enabled(): bool {
		return false;
	}
};
$env2->sdk_calls = array();
( new SDK( $env2, Plugin::instance() ) )->enqueue( 'x', array() );
$check( count( $env2->sdk_calls ) === 0, 'jobs disabled ⇒ enqueue is a no-op' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
