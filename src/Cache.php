<?php
/**
 * Cache cooperation: purge bext exactly when content changes, and keep
 * personalized responses out of the anonymous cache.
 *
 * This is what lets bext run long TTLs AND stay instantly fresh — instead of
 * the operator choosing between "stale" (long TTL) and "slow" (short TTL, the
 * 4.4 s blocking re-renders).
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Cache {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	/** @var array<string,true> Relative paths queued for purge this request (set). */
	private $paths = array();

	/** @var bool Site-wide purge requested this request. */
	private $purge_all = false;

	/** @var bool Whether the shutdown flush has been wired. */
	private $flush_wired = false;

	const LOG_OPTION = 'bext_wp_purge_log';
	const LOG_MAX    = 25;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		if ( ! $this->env->is_behind_bext() ) {
			return;
		}

		// Content-change → purge.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'created_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'queue_all' ) );
		add_action( 'customize_save_after', array( $this, 'queue_all' ) );
		add_action( 'switch_theme', array( $this, 'queue_all' ) );
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 10, 1 );
		add_action( 'wp_insert_comment', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );

		// WooCommerce stock/product changes.
		add_action( 'woocommerce_update_product', array( $this, 'on_woo_product' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_woo_product' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_woo_stock' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_woo_stock' ), 10, 1 );

		// Personalization-safe response headers (front-end only).
		add_action( 'template_redirect', array( $this, 'send_headers' ), 1 );

		// Manual purge: admin bar + handler.
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_post_bext_purge', array( $this, 'handle_manual_purge' ) );
	}

	// ---------------------------------------------------------------------
	// Change hooks
	// ---------------------------------------------------------------------

	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		// Only care about transitions involving a public state.
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$this->queue_post_urls( $post->ID );
	}

	public function on_save_post( $post_id, $post = null ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$status = get_post_status( $post_id );
		if ( in_array( $status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}
		if ( 'publish' !== $status ) {
			return; // Drafts aren't publicly cached.
		}
		$this->queue_post_urls( $post_id );
	}

	public function on_delete_post( $post_id ): void {
		if ( 'publish' === get_post_status( $post_id ) ) {
			$this->queue_post_urls( $post_id );
		}
	}

	public function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( ! is_wp_error( $link ) && $link ) {
			$this->queue_paths( array( $this->url_to_path( $link ), '/' ) );
			$this->queue_sitemap_and_feeds();
		}
	}

	public function on_updated_option( $option ): void {
		static $watched = array(
			'permalink_structure',
			'category_base',
			'tag_base',
			'blogname',
			'blogdescription',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'sticky_posts',
			'posts_per_page',
			'home',
			'siteurl',
			'template',
			'stylesheet',
		);
		if ( in_array( (string) $option, $watched, true ) || 0 === strpos( (string) $option, 'theme_mods_' ) ) {
			$this->queue_all();
		}
	}

	public function on_comment( $comment_id, $comment = null ): void {
		$post_id = $comment ? (int) $comment->comment_post_ID : (int) get_comment( $comment_id )->comment_post_ID;
		if ( $post_id ) {
			$this->queue_post_urls( $post_id );
		}
	}

	public function on_comment_status( $new_status, $old_status, $comment ): void {
		if ( $comment && $comment->comment_post_ID ) {
			$this->queue_post_urls( (int) $comment->comment_post_ID );
		}
	}

	public function on_woo_product( $product_id ): void {
		$this->queue_post_urls( (int) $product_id );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$this->queue_paths( array( $this->url_to_path( $shop ) ) );
			}
		}
	}

	public function on_woo_stock( $product ): void {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		if ( $id ) {
			$this->on_woo_product( $id );
		}
	}

	// ---------------------------------------------------------------------
	// Queueing
	// ---------------------------------------------------------------------

	/**
	 * Queue the set of URLs whose output depends on a given post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function queue_post_urls( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$type = get_post_type( $post );
		if ( ! is_post_type_viewable( $type ) ) {
			return;
		}

		$paths = array( '/' ); // Home almost always lists recent content.

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$paths[] = $this->url_to_path( $permalink );
		}

		// Post type archive.
		$archive = get_post_type_archive_link( $type );
		if ( $archive ) {
			$paths[] = $this->url_to_path( $archive );
		}

		// Author archive.
		$author = get_author_posts_url( (int) $post->post_author );
		if ( $author ) {
			$paths[] = $this->url_to_path( $author );
		}

		// Term archives across all the post's taxonomies.
		foreach ( get_object_taxonomies( $type ) as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) && $link ) {
						$paths[] = $this->url_to_path( $link );
					}
				}
			}
		}

		/**
		 * Filter the relative paths purged for a post.
		 *
		 * @param string[] $paths   Relative paths.
		 * @param int      $post_id Post ID.
		 */
		$paths = apply_filters( 'bext/purge_urls_for_post', $paths, $post_id );

		$this->queue_paths( $paths );
		$this->queue_sitemap_and_feeds();
	}

	public function queue_sitemap_and_feeds(): void {
		$this->queue_paths(
			array(
				'/feed/',
				'/comments/feed/',
				'/wp-sitemap.xml',     // Core sitemaps.
				'/sitemap_index.xml',  // Yoast.
				'/sitemap.xml',        // RankMath / others.
				'/wp-json/wp/v2/posts',
			)
		);
	}

	/**
	 * @param string[] $paths Relative paths.
	 */
	public function queue_paths( array $paths ): void {
		foreach ( $paths as $p ) {
			$p = $this->normalize_path( (string) $p );
			if ( '' !== $p ) {
				$this->paths[ $p ] = true;
			}
		}
		$this->wire_flush();
	}

	public function queue_all(): void {
		$this->purge_all = true;
		$this->wire_flush();
	}

	private function wire_flush(): void {
		if ( $this->flush_wired ) {
			return;
		}
		$this->flush_wired = true;
		add_action( 'shutdown', array( $this, 'flush' ), 99 );
	}

	// ---------------------------------------------------------------------
	// Flush
	// ---------------------------------------------------------------------

	/**
	 * Send one coalesced purge to bext. Runs on shutdown; releases the request
	 * to the browser first (fastcgi_finish_request) so editors never wait.
	 */
	public function flush(): void {
		if ( ! $this->purge_all && empty( $this->paths ) ) {
			return;
		}

		// Let the editor's response return before we talk to bext.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		}

		$host = $this->env->canonical_host();
		if ( '' === $host ) {
			return;
		}

		if ( $this->purge_all ) {
			$body  = array(
				'host'     => $host,
				'paths'    => array(),
				'prefixes' => array( '/' ),
			);
			$count = 'all';
		} else {
			$paths = array_keys( $this->paths );
			$body  = array(
				'host'     => $host,
				'paths'    => $paths,
				'prefixes' => array(),
			);
			$count = count( $paths );
		}

		$this->env->purge_request( '/nginx-cache/purge-site', $body, false );
		$this->log_purge( $count, $this->purge_all ? array( '/*' ) : array_keys( $this->paths ) );

		// Reset for any further work in the same process (CLI long-runs).
		$this->paths     = array();
		$this->purge_all = false;
	}

	// ---------------------------------------------------------------------
	// Response headers
	// ---------------------------------------------------------------------

	/**
	 * Keep personalized responses out of the anonymous cache, and (optionally)
	 * advertise a positive Cache-Control for anonymous pages.
	 */
	public function send_headers(): void {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		header( 'X-Bext-WP: ' . BEXT_WP_VERSION, true );

		if ( $this->env->is_personalized_request() || is_user_logged_in() || is_preview() ) {
			header( 'Cache-Control: private, no-store, max-age=0', true );
			return;
		}

		// Anonymous: by default defer to bext's proxy_cache_anonymous_response_header.
		// Operators who don't configure that at the vhost can opt in here.
		$cc = apply_filters( 'bext/anonymous_cache_control', '' );
		if ( is_string( $cc ) && '' !== $cc && $this->is_cacheable_view() ) {
			header( 'Cache-Control: ' . $cc, true );
		}
	}

	private function is_cacheable_view(): bool {
		if ( is_404() || is_search() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Manual purge
	// ---------------------------------------------------------------------

	public function admin_bar( $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$base = admin_url( 'admin-post.php' );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'bext-purge',
				'title' => 'Purge bext cache',
				'href'  => wp_nonce_url( add_query_arg( 'action', 'bext_purge', $base ), 'bext_purge' ),
				'meta'  => array( 'title' => 'Purge the entire bext cache for this site' ),
			)
		);

		if ( ! is_admin() && ! is_404() ) {
			$current = $this->normalize_path( add_query_arg( array() ) );
			$wp_admin_bar->add_node(
				array(
					'parent' => 'bext-purge',
					'id'     => 'bext-purge-this',
					'title'  => 'Purge this URL',
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'bext_purge',
								'path'   => rawurlencode( $current ),
							),
							$base
						),
						'bext_purge'
					),
				)
			);
		}
	}

	public function handle_manual_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'bext_purge' );

		$host = $this->env->canonical_host();
		$path = isset( $_GET['path'] ) ? $this->normalize_path( rawurldecode( wp_unslash( $_GET['path'] ) ) ) : '';

		if ( '' !== $path ) {
			$body  = array(
				'host'     => $host,
				'paths'    => array( $path ),
				'prefixes' => array(),
			);
			$label = array( $path );
		} else {
			$body  = array(
				'host'     => $host,
				'paths'    => array(),
				'prefixes' => array( '/' ),
			);
			$label = array( '/*' );
		}

		$res = $this->env->purge_request( '/nginx-cache/purge-site', $body, true );
		$ok  = is_array( $res ) && 200 === $res['code'];
		$this->log_purge( '' !== $path ? 1 : 'all', $label, $ok ? 'manual-ok' : 'manual-fail' );

		$back = wp_get_referer() ? wp_get_referer() : admin_url();
		wp_safe_redirect( add_query_arg( 'bext_purged', $ok ? '1' : '0', $back ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Purge log (for the dashboard)
	// ---------------------------------------------------------------------

	/**
	 * @param int|string $count Number of paths or 'all'.
	 * @param string[]   $sample Sample of paths purged.
	 * @param string     $via    Origin tag.
	 */
	private function log_purge( $count, array $sample, string $via = 'auto' ): void {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'   => time(),
				'count'  => $count,
				'via'    => $via,
				'sample' => array_slice( array_values( $sample ), 0, 6 ),
			)
		);
		$log = array_slice( $log, 0, self::LOG_MAX );
		update_option( self::LOG_OPTION, $log, false );
	}

	public function purge_log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function url_to_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$qs   = wp_parse_url( $url, PHP_URL_QUERY );
		$out  = $path ? $path : '/';
		if ( $qs ) {
			$out .= '?' . $qs;
		}
		return $this->normalize_path( $out );
	}

	private function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		// Strip any accidental scheme+host.
		if ( preg_match( '#^https?://#i', $path ) ) {
			$path = $this->url_to_path( $path );
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}
		return $path;
	}
}
