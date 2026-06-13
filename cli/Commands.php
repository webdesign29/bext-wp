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
	 * [--url=<path>]
	 * : Purge only this path (e.g. /about/). Omit to purge everything.
	 *
	 * [--all]
	 * : Purge the entire site cache (default when --url is omitted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp bext purge
	 *     wp bext purge --url=/blog/hello-world/
	 *
	 * @when after_wp_load
	 */
	public function purge( $args, $assoc ): void {
		$env  = $this->plugin->env();
		$host = $env->canonical_host();
		$path = isset( $assoc['url'] ) ? (string) $assoc['url'] : '';

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
		\WP_CLI::success( ( '' !== $path ? "Purged {$path}" : 'Purged entire site' ) . ' — ' . $res['body'] );
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
