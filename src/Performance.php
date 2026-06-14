<?php
/**
 * Performance: a curated set of well-known WordPress + plugin front-end
 * optimizations.
 *
 * Two kinds of waste this targets:
 *   1. Core/plugin assets loaded site-wide that most pages don't use
 *      (emoji polyfill, oEmbed JS, block-library CSS on classic themes,
 *      Contact Form 7's CSS/JS on every page, WooCommerce's cart-fragments
 *      on non-shop pages, …).
 *   2. Back-end-only plugins that still boot their full PHP on every public
 *      request (admin tools, DB browsers, debug helpers) — skipped on the
 *      front end via an `option_active_plugins` filter, the same mechanism a
 *      "plugin organizer" uses, but curated and opt-in.
 *
 * Design: every tweak is individually gated by a `bext/perf/<name>` filter so
 * an operator can flip any of them without touching code. Defaults are the
 * SAFE subset that never changes a correctly-built site's output (emoji, head
 * meta, heartbeat throttle, CF7's own conditional-load mechanism). The tweaks
 * that *can* change rendering (jQuery Migrate, block CSS, embeds, WooCommerce
 * assets, front-end plugin disabling) ship OFF — opt in per site.
 *
 * Note: these reduce per-request PHP bootstrap + browser payload. They do NOT
 * speed a theme's own heavy template rendering — for that, lean on bext's edge
 * cache (long TTL + purge-on-change via Cache.php).
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class Performance {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	/**
	 * Per-tweak gate: setting (`perf_<name>`) overridden by a `bext/perf/<name>`
	 * filter, falling back to the supplied default. Lets operators toggle any
	 * single optimization from wp-config, the settings screen, or a filter.
	 */
	private function on( string $name, bool $default ): bool {
		$val = $this->env->resolved_bool( 'perf_' . $name, $default );
		return (bool) apply_filters( 'bext/perf/' . $name, $val );
	}

	public function register(): void {
		if ( ! $this->env->is_behind_bext() ) {
			return;
		}

		// Front-end plugin disabling must hook option_active_plugins NOW —
		// register() runs during mu-plugin load (Plugin::boot at file scope),
		// which is before wp-settings reads the active-plugins option. Doing it
		// any later (e.g. at plugins_loaded) would be too late.
		//
		// Defer to a dedicated per-URL plugin loader if one is already present
		// (Plugin Organizer, Freesoul Deactivate Plugins, Plugin Load Filter,
		// …). Those own `option_active_plugins` and would override us anyway —
		// fighting them just yields confusing no-ops. They sort before us in the
		// mu-plugins dir, so by now they've registered their filter / class.
		if ( $this->on( 'disable_backend_plugins', false ) && ! $this->plugin_loader_present() ) {
			add_filter( 'option_active_plugins', array( $this, 'filter_active_plugins' ), 1 );
		}

		// Everything else is front-end-only and can wire on init/enqueue.
		if ( is_admin() ) {
			// Heartbeat throttle still helps in wp-admin, wire just that.
			if ( $this->on( 'heartbeat', true ) ) {
				add_filter( 'heartbeat_settings', array( $this, 'throttle_heartbeat' ) );
			}
			return;
		}

		if ( $this->on( 'emoji', true ) ) {
			$this->disable_emoji();
		}
		if ( $this->on( 'head_cleanup', true ) ) {
			$this->clean_head();
		}
		if ( $this->on( 'heartbeat', true ) ) {
			add_filter( 'heartbeat_settings', array( $this, 'throttle_heartbeat' ) );
		}
		if ( $this->on( 'disable_jquery_migrate', false ) ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}
		if ( $this->on( 'disable_embeds', false ) ) {
			add_action( 'wp_footer', array( $this, 'dequeue_embed' ) );
			add_action( 'init', array( $this, 'disable_embeds' ), 9999 );
		}
		// Asset trims run late on wp_enqueue_scripts so the target plugins have
		// already enqueued and we can selectively dequeue.
		add_action( 'wp_enqueue_scripts', array( $this, 'trim_assets' ), 100 );
	}

	// ── Core front-end trims ────────────────────────────────────────────────

	/** Strip the emoji-detection script + styles (an IE/legacy polyfill). */
	private function disable_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'strip_emoji_tinymce' ) );
		add_filter( 'wp_resource_hints', array( $this, 'strip_emoji_dns_prefetch' ), 10, 2 );
	}

	/** @param array $plugins @return array */
	public function strip_emoji_tinymce( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
	}

	/** @param array $urls @param string $relation @return array */
	public function strip_emoji_dns_prefetch( $urls, $relation ) {
		if ( 'dns-prefetch' !== $relation || ! is_array( $urls ) ) {
			return $urls;
		}
		$svg = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/' );
		return array_filter(
			$urls,
			static function ( $u ) use ( $svg ) {
				$u = is_array( $u ) && isset( $u['href'] ) ? $u['href'] : $u;
				return is_string( $u ) ? false === strpos( $u, $svg ) : true;
			}
		);
	}

	/** Remove generator / RSD / wlwmanifest / shortlink / adjacent-rel head noise. */
	private function clean_head(): void {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
	}

	/** @param array $settings @return array — slow the front-end Heartbeat to 60s. */
	public function throttle_heartbeat( $settings ) {
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['interval']  = (int) apply_filters( 'bext/perf/heartbeat_interval', 60 );
		return $settings;
	}

	/** @param \WP_Scripts $scripts */
	public function remove_jquery_migrate( $scripts ): void {
		if ( ! empty( $scripts->registered['jquery'] ) ) {
			$deps = $scripts->registered['jquery']->deps;
			$scripts->registered['jquery']->deps = array_diff( (array) $deps, array( 'jquery-migrate' ) );
		}
	}

	public function dequeue_embed(): void {
		wp_dequeue_script( 'wp-embed' );
	}

	/** Disable oEmbed discovery + the host JS (opt-in; affects being embedded). */
	public function disable_embeds(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
	}

	// ── Known-plugin conditional assets ─────────────────────────────────────

	/**
	 * Dequeue plugin assets that are loaded site-wide but only needed on some
	 * pages. Runs at wp_enqueue_scripts:100, after the plugins enqueued.
	 */
	public function trim_assets(): void {
		// Contact Form 7: only load its CSS/JS on pages that actually embed a
		// form. Safe default — CF7 itself recommends this for non-form pages.
		if ( $this->on( 'cf7_conditional', true ) && ! $this->page_has_cf7() ) {
			wp_dequeue_script( 'contact-form-7' );
			wp_dequeue_style( 'contact-form-7' );
			wp_dequeue_script( 'wpcf7-recaptcha' );
			wp_dequeue_style( 'contact-form-7-rtl' );
		}

		// WooCommerce: drop cart-fragments + the bulk of its styles/scripts on
		// pages that aren't shop/cart/checkout/account. Opt-in (theme cart
		// widgets on other pages would need them).
		if ( $this->on( 'woo_conditional', false ) && function_exists( 'is_woocommerce' ) && ! $this->is_woo_page() ) {
			foreach ( array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'wc-blocks-style' ) as $h ) {
				wp_dequeue_style( $h );
			}
			foreach ( array( 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart' ) as $h ) {
				wp_dequeue_script( $h );
			}
		}

		// Classic-theme block CSS (opt-in — breaks pages that DO use blocks).
		if ( $this->on( 'block_css', false ) ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles' ) as $h ) {
				wp_dequeue_style( $h );
			}
		}
	}

	/** Does the queried singular content embed a CF7 form? Non-singular → assume no. */
	private function page_has_cf7(): bool {
		if ( (bool) apply_filters( 'bext/perf/cf7_force_load', false ) ) {
			return true;
		}
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return true; // unknown → keep assets, never break a form.
		}
		$c = (string) $post->post_content;
		return false !== strpos( $c, '[contact-form-7' )
			|| false !== strpos( $c, 'wp:contact-form-7/' )
			|| has_shortcode( $c, 'contact-form-7' );
	}

	private function is_woo_page(): bool {
		return ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}

	// ── Front-end plugin de-registration (opt-in) ───────────────────────────

	/**
	 * Drop back-end-only plugins from the active list on pure front-end
	 * requests, so their PHP never boots for anonymous visitors.
	 *
	 * VERY conservative: only on genuine front-end page views (never admin,
	 * AJAX, cron, CLI, REST, XML-RPC, login). The list is empty by default —
	 * the operator opts in per plugin via the `perf_frontend_disabled_plugins`
	 * setting (one plugin basename per line, e.g. `query-monitor/query-monitor.php`)
	 * or the `bext/perf/frontend_disabled_plugins` filter. Only list plugins
	 * that do NOTHING on the front end (admin tools, DB browsers, debug
	 * helpers) — a plugin that registers a CPT, taxonomy, or shortcode used in
	 * a template must NOT be listed.
	 *
	 * @param mixed $plugins Active-plugins option value.
	 * @return mixed
	 */
	public function filter_active_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || empty( $plugins ) || ! $this->is_pure_frontend() ) {
			return $plugins;
		}
		$disabled = $this->frontend_disabled_plugins();
		if ( empty( $disabled ) ) {
			return $plugins;
		}
		$out = array();
		foreach ( $plugins as $p ) {
			$basename = strtolower( (string) $p );
			$drop     = false;
			foreach ( $disabled as $d ) {
				// Match full basename or just the plugin directory slug.
				if ( $basename === $d || 0 === strpos( $basename, rtrim( $d, '/' ) . '/' ) ) {
					$drop = true;
					break;
				}
			}
			if ( ! $drop ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Is a dedicated per-URL plugin loader already managing
	 * `option_active_plugins`? If so, bext-wp defers to it (its drops would
	 * override ours regardless). Detected by class (their MU loaders boot
	 * before us) or by an existing filter on the hook at this early point —
	 * core registers none, so a present callback is another loader.
	 */
	private function plugin_loader_present(): bool {
		foreach ( array( 'PluginOrganizer', 'PluginOrganizerMU', 'FreesoulDeactivatePlugins', 'Plugin_Load_Filter', 'Ironikus_Deactivate_Plugins' ) as $cls ) {
			if ( class_exists( $cls, false ) ) {
				return true;
			}
		}
		return (bool) has_filter( 'option_active_plugins' );
	}

	/** @return string[] Lower-cased plugin basenames/slugs to skip on the front end. */
	private function frontend_disabled_plugins(): array {
		$raw  = (string) $this->env->resolved( 'perf_frontend_disabled_plugins', '' );
		$list = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		$list = array_filter( array_map( 'trim', $list ) );
		$list = array_map( 'strtolower', $list );
		/** @var string[] $list */
		$list = (array) apply_filters( 'bext/perf/frontend_disabled_plugins', $list );
		return array_values( array_unique( array_filter( $list ) ) );
	}

	/** True only for a genuine anonymous front-end page view. */
	private function is_pure_frontend(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		foreach ( array( '/wp-json/', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-admin/' ) as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return false;
			}
		}
		return true;
	}
}
