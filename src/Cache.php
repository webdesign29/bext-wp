<?php
/**
 * Cache cooperation: purge bext exactly when content changes, and keep
 * personalized responses out of the anonymous cache.
 *
 * This is what lets bext run long TTLs AND stay instantly fresh — instead of
 * the operator choosing between "stale" (long TTL) and "slow" (short TTL, the
 * 4.4 s blocking re-renders).
 *
 * Purges go to bext's main-listener endpoint /__bext/cache/purge-proxy, which
 * honors paths + prefixes and evicts the in-memory FastCGI cache that serves
 * WP pages (see Env::purge_proxy).
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

		// Content-change → purge (the "purge on save" setting; on by default).
		if ( $this->env->purge_on_save_enabled() ) {
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
		}

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
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return; // Drafts/pending aren't publicly cached.
		}
		$this->queue_post_urls( $post_id );
	}

	public function on_delete_post( $post_id ): void {
		// queue_post_urls gates on a viewable post type; purge regardless of
		// status so a trashed-then-deleted published post still drops its URLs.
		$this->queue_post_urls( (int) $post_id );
	}

	public function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( ! is_wp_error( $link ) && $link ) {
			$this->queue_paths( array( $this->url_to_path( $link ), $this->env->home_path() ) );
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
		if ( ! $comment instanceof \WP_Comment ) {
			$comment = get_comment( $comment_id );
		}
		if ( $comment instanceof \WP_Comment && $comment->comment_post_ID ) {
			$this->queue_post_urls( (int) $comment->comment_post_ID );
		}
	}

	public function on_comment_status( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof \WP_Comment && $comment->comment_post_ID ) {
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

		$paths = array( $this->env->home_path() ); // Home lists recent content.

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$paths[] = $this->url_to_path( $permalink );
		}

		$archive = get_post_type_archive_link( $type );
		if ( $archive ) {
			$paths[] = $this->url_to_path( $archive );
		}

		$author = get_author_posts_url( (int) $post->post_author );
		if ( $author ) {
			$paths[] = $this->url_to_path( $author );
		}

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

	/**
	 * Queue feeds + sitemaps + the REST collection, derived from WP so they're
	 * correct on subdirectory installs and custom permalink/REST prefixes.
	 */
	public function queue_sitemap_and_feeds(): void {
		$paths = array();

		$feed = get_feed_link();
		if ( $feed ) {
			$paths[] = $this->url_to_path( $feed );
		}
		$cfeed = get_feed_link( 'comments_' . get_default_feed() );
		if ( $cfeed ) {
			$paths[] = $this->url_to_path( $cfeed );
		}
		if ( function_exists( 'get_sitemap_url' ) ) {
			$sitemap = get_sitemap_url( 'index' ); // WP 5.5+ core sitemaps.
			if ( $sitemap ) {
				$paths[] = $this->url_to_path( $sitemap );
			}
		}
		// Third-party sitemaps, under the install base — only when that plugin is
		// active (avoids padding every purge with paths that are never cached).
		$base = $this->env->home_path();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$paths[] = $base . 'sitemap_index.xml'; // Yoast SEO
		}
		if ( class_exists( 'RankMath' ) ) {
			$paths[] = $base . 'sitemap_index.xml'; // Rank Math
		}

		$paths[] = $this->url_to_path( rest_url( 'wp/v2/posts' ) );

		$this->queue_paths( $paths );
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

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		}

		$host = $this->env->canonical_host();
		if ( '' === $host ) {
			return;
		}

		if ( $this->purge_all ) {
			// Scope the site-wide purge to this install's base path so a
			// subdirectory multisite blog doesn't wipe its siblings' cache.
			$prefix = $this->env->home_path();
			$body   = array(
				'host'     => $host,
				'paths'    => array(),
				'prefixes' => array( $prefix ),
			);
			$count  = 'all';
			$sample = array( $prefix . '*' );
		} else {
			$paths  = array_keys( $this->paths );
			$body   = array(
				'host'     => $host,
				'paths'    => $paths,
				'prefixes' => array(),
			);
			$count  = count( $paths );
			$sample = $paths;
		}

		$this->env->purge_proxy( $body, false );
		$this->log_purge( $count, $sample );

		$this->paths     = array();
		$this->purge_all = false;
	}

	// ---------------------------------------------------------------------
	// Response headers
	// ---------------------------------------------------------------------

	public function send_headers(): void {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		header( 'X-Bext-WP: ' . BEXT_WP_VERSION, true );

		if ( is_user_logged_in() || $this->env->is_personalized_request() || is_preview() ) {
			header( 'Cache-Control: private, no-store, max-age=0', true );
			return;
		}

		$cc = apply_filters( 'bext/anonymous_cache_control', $this->env->anon_cache_control() );
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
				'href'  => esc_url( wp_nonce_url( add_query_arg( 'action', 'bext_purge', $base ), 'bext_purge' ) ),
				'meta'  => array( 'title' => 'Purge the entire bext cache for this site' ),
			)
		);

		if ( ! is_admin() && ! is_404() ) {
			// Path-only (drop the query string) so it matches the cached key,
			// and never feed the raw request URI into the href (XSS-safe).
			$req     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
			$current = $this->normalize_path( strtok( $req, '?' ) );
			$wp_admin_bar->add_node(
				array(
					'parent' => 'bext-purge',
					'id'     => 'bext-purge-this',
					'title'  => 'Purge this URL',
					'href'   => esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'bext_purge',
									'path'   => rawurlencode( $current ),
								),
								$base
							),
							'bext_purge'
						)
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
		$path = '';
		if ( isset( $_GET['path'] ) ) {
			// Take the path component only; the query/fragment aren't part of
			// the cached key for a normal page view.
			$raw  = strtok( rawurldecode( wp_unslash( (string) $_GET['path'] ) ), '?' );
			$path = $this->normalize_path( $raw );
		}

		if ( '' !== $path && '/' !== $path ) {
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
				'prefixes' => array( $this->env->home_path() ),
			);
			$label = array( $this->env->home_path() . '*' );
		}

		$res = $this->env->purge_proxy( $body, true );
		$ok  = is_array( $res ) && 200 === $res['code'];
		$this->log_purge( '' !== $path && '/' !== $path ? 1 : 'all', $label, $ok ? 'manual-ok' : 'manual-fail' );

		$back = wp_get_referer() ? wp_get_referer() : admin_url();
		wp_safe_redirect( add_query_arg( 'bext_purged', $ok ? '1' : '0', $back ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Purge log (for the dashboard)
	// ---------------------------------------------------------------------

	/**
	 * @param int|string $count  Number of paths or 'all'.
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
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
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
			$out .= '?' . $qs; // Kept for plain-permalink sites (/?p=123).
		}
		return $this->normalize_path( $out );
	}

	private function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $path ) ) {
			$path = $this->url_to_path( $path );
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}
		return $path;
	}
}
