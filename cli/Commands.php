<?php
/**
 * WP-CLI: `wp bext <command>`.
 *
 * @package Bext\WP
 */

namespace Bext\WP\CLI;

use Bext\WP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the bext integration from the command line.
 */
class Commands {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function register( Plugin $plugin ): void {
		\WP_CLI::add_command( 'bext', new self( $plugin ) );
	}

	/**
	 * Show bext integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bext status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc ): void {
		$env  = $this->plugin->env();
		$rows = array(
			array( 'key' => 'Behind bext', 'value' => $env->is_behind_bext() ? 'yes' : 'no' ),
			array( 'key' => 'bext version', 'value' => $env->bext_version() ?: '-' ),
			array( 'key' => 'Canonical host', 'value' => $env->canonical_host() ),
			array( 'key' => 'App id', 'value' => $env->app_id() ),
			array( 'key' => 'Purge endpoint', 'value' => '/__bext/cache/purge-proxy' ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Purge the bext edge cache for this site.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Purge only this path (e.g. /about/). Omit to purge the entire site.
	 *   A positional arg is used because --url and --path are WP-CLI global flags.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bext purge
	 *     wp bext purge /blog/hello-world/
	 *
	 * @when after_wp_load
	 */
	public function purge( $args, $assoc ): void {
		$env  = $this->plugin->env();
		$host = $env->canonical_host();
		$path = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' !== $path && '/' !== $path ) {
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}
			$body = array(
				'host'     => $host,
				'paths'    => array( $path ),
				'prefixes' => array(),
			);
		} else {
			$path = '';
			$body = array(
				'host'     => $host,
				'paths'    => array(),
				'prefixes' => array( $env->home_path() ),
			);
		}

		$res = $env->purge_proxy( $body, true );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Purge failed: ' . $res->get_error_message() );
		}
		if ( ! is_array( $res ) || 200 !== $res['code'] ) {
			\WP_CLI::error( 'Purge returned HTTP ' . ( is_array( $res ) ? $res['code'] : '?' ) );
		}
		do_action( 'bext/after_purge', $host, $body['paths'], $body['prefixes'] );
		\WP_CLI::success( ( '' !== $path ? "Purged {$path}" : 'Purged entire site' ) . ' — ' . $res['body'] );
	}

	/**
	 * Flush both the bext edge cache (entire site) and the WordPress object cache.
	 *
	 * The "big hammer": use after a deploy or a bulk data import when you want
	 * everything fresh — the persistent object cache (Redis/Memcached, if any) and
	 * bext's edge cache for this site, in one command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bext flush
	 *
	 * @when after_wp_load
	 */
	public function flush( $args, $assoc ): void {
		$env  = $this->plugin->env();
		$host = $env->canonical_host();

		$flushed_object = function_exists( 'wp_cache_flush' ) ? (bool) wp_cache_flush() : false;
		if ( $flushed_object ) {
			\WP_CLI::log( 'Object cache flushed.' );
		} else {
			\WP_CLI::log( 'Object cache: nothing to flush (no persistent cache).' );
		}

		$body = array(
			'host'     => $host,
			'paths'    => array(),
			'prefixes' => array( $env->home_path() ),
		);
		$res  = $env->purge_proxy( $body, true );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Edge purge failed: ' . $res->get_error_message() );
		}
		if ( ! is_array( $res ) || 200 !== $res['code'] ) {
			\WP_CLI::error( 'Edge purge returned HTTP ' . ( is_array( $res ) ? $res['code'] : '?' ) );
		}
		do_action( 'bext/after_purge', $host, $body['paths'], $body['prefixes'] );
		\WP_CLI::success( 'Flushed bext edge cache for the entire site — ' . $res['body'] );
	}

	/**
	 * Run the bext health checks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bext doctor
	 *
	 * @when after_wp_load
	 */
	public function doctor( $args, $assoc ): void {
		$health = $this->plugin->module( 'health' );
		if ( ! $health || ! method_exists( $health, 'checks' ) ) {
			\WP_CLI::warning( 'Health module not available.' );
			return;
		}
		$rows = array();
		foreach ( $health->checks() as $c ) {
			$rows[] = array(
				'check'  => $c['label'],
				'status' => strtoupper( $c['status'] ),
				'detail' => $c['detail'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'detail' ) );
	}
}
