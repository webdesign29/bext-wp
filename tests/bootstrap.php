<?php
/**
 * Shared WP-free test bootstrap: minimal stubs of the WordPress functions the
 * plugin touches, including a faithful action/filter registry (actions and
 * filters share one registry, exactly like WordPress — which is what makes the
 * SDK enqueue double-fire test meaningful).
 *
 * Tests set behavior via these globals:
 *   $GLOBALS['_bext_opts']       option store (array)
 *   $GLOBALS['_bext_netopts']    site-option store (array)
 *   $GLOBALS['_bext_multisite']  bool
 *   $GLOBALS['_bext_subdomain']  bool
 *   $GLOBALS['_bext_home']       home_url base (string)
 *   $GLOBALS['_bext_logged_in']  is_user_logged_in() (bool)
 *   $GLOBALS['_bext_posts']      post-id → object map (get_post and friends)
 *   $GLOBALS['_bext_sites']      blog-id → object map (get_site / switch_to_blog)
 *   $GLOBALS['_bext_actions_log'] do_action call log (tag → list of args)
 *
 * @package Bext\WP
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['_bext_opts']        = array();
$GLOBALS['_bext_netopts']     = array();
$GLOBALS['_bext_filters']     = array();
$GLOBALS['_bext_multisite']   = false;
$GLOBALS['_bext_subdomain']   = false;
$GLOBALS['_bext_home']        = 'https://example.test';
$GLOBALS['_bext_logged_in']   = false;
$GLOBALS['_bext_posts']       = array();
$GLOBALS['_bext_sites']       = array();
$GLOBALS['_bext_current_blog'] = 1;
$GLOBALS['_bext_actions_log'] = array();

/** Reset all mutable test state between scenarios. */
function bext_test_reset() {
	$GLOBALS['_bext_opts']         = array();
	$GLOBALS['_bext_netopts']      = array();
	$GLOBALS['_bext_filters']      = array();
	$GLOBALS['_bext_multisite']    = false;
	$GLOBALS['_bext_subdomain']    = false;
	$GLOBALS['_bext_home']         = 'https://example.test';
	$GLOBALS['_bext_logged_in']    = false;
	$GLOBALS['_bext_posts']        = array();
	$GLOBALS['_bext_sites']        = array();
	$GLOBALS['_bext_current_blog'] = 1;
	$GLOBALS['_bext_actions_log']  = array();
}

if ( ! function_exists( 'get_option' ) ) {
	// --- options ---
	function get_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_opts'] ) ? $GLOBALS['_bext_opts'][ $k ] : $d;
	}
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['_bext_opts'][ $k ] = $v;
		return true;
	}
	function add_option( $k, $v ) {
		$GLOBALS['_bext_opts'][ $k ] = $v;
		return true;
	}
	function delete_option( $k ) {
		unset( $GLOBALS['_bext_opts'][ $k ] );
		return true;
	}
	function get_site_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_bext_netopts'] ) ? $GLOBALS['_bext_netopts'][ $k ] : $d;
	}
	function update_site_option( $k, $v ) {
		$GLOBALS['_bext_netopts'][ $k ] = $v;
		return true;
	}
	function delete_site_option( $k ) {
		unset( $GLOBALS['_bext_netopts'][ $k ] );
		return true;
	}

	// --- hooks (actions + filters share ONE registry, like WordPress) ---
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['_bext_filters'][ $tag ][] = array( 'cb' => $cb, 'args' => (int) $args );
		return true;
	}
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
		return add_filter( $tag, $cb, $prio, $args );
	}
	function do_action( $tag, ...$a ) {
		// Record every dispatch so tests can assert a hook fired with given args.
		$GLOBALS['_bext_actions_log'][ $tag ][] = $a;
		if ( empty( $GLOBALS['_bext_filters'][ $tag ] ) ) {
			return;
		}
		foreach ( $GLOBALS['_bext_filters'][ $tag ] as $h ) {
			call_user_func_array( $h['cb'], array_slice( $a, 0, $h['args'] ) );
		}
	}
	function apply_filters( $tag, $value, ...$a ) {
		if ( empty( $GLOBALS['_bext_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['_bext_filters'][ $tag ] as $h ) {
			$all   = array_merge( array( $value ), $a );
			$value = call_user_func_array( $h['cb'], array_slice( $all, 0, $h['args'] ) );
		}
		return $value;
	}

	// --- environment / url ---
	function is_multisite() {
		return ! empty( $GLOBALS['_bext_multisite'] );
	}
	function is_subdomain_install() {
		return ! empty( $GLOBALS['_bext_subdomain'] );
	}
	function is_user_logged_in() {
		return ! empty( $GLOBALS['_bext_logged_in'] );
	}
	function home_url( $p = '/' ) {
		return rtrim( $GLOBALS['_bext_home'], '/' ) . $p;
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	// --- sanitization / strings ---
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' );
	}
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/' ) . '/';
	}
	function wp_unslash( $v ) {
		return $v;
	}
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $s ) );
	}
	function esc_url_raw( $s ) {
		return trim( (string) $s );
	}
	function esc_url( $s ) {
		return trim( (string) $s );
	}
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}

	// --- posts / permalinks / terms (purge-set computation) ---
	function get_post( $id ) {
		$id = $id instanceof \WP_Post ? $id->ID : (int) $id;
		return $GLOBALS['_bext_posts'][ $id ] ?? null;
	}
	function get_post_type( $post ) {
		if ( $post instanceof \WP_Post ) {
			return $post->post_type;
		}
		$p = get_post( $post );
		return $p ? $p->post_type : false;
	}
	function get_post_status( $id ) {
		$p = get_post( $id );
		return $p ? $p->post_status : false;
	}
	function is_post_type_viewable( $type ) {
		// Everything except the synthetic 'nonviewable' type used in tests.
		return 'nonviewable' !== $type;
	}
	function get_permalink( $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			return false;
		}
		return home_url( '/' . ltrim( $p->post_name ? $p->post_name : ( 'p=' . $p->ID ), '/' ) . '/' );
	}
	function get_post_type_archive_link( $type ) {
		return home_url( '/' . $type . '/' );
	}
	function get_author_posts_url( $author_id ) {
		return home_url( '/author/' . (int) $author_id . '/' );
	}
	function get_object_taxonomies( $type ) {
		return $GLOBALS['_bext_taxonomies'] ?? array();
	}
	function get_the_terms( $post_id, $tax ) {
		return $GLOBALS['_bext_terms'][ $tax ] ?? array();
	}
	function get_term_link( $term, $taxonomy = '' ) {
		$slug = is_object( $term ) ? ( $term->slug ?? 'term' ) : 'term-' . (int) $term;
		return home_url( '/category/' . $slug . '/' );
	}
	function get_feed_link( $feed = '' ) {
		return home_url( '/feed/' );
	}
	function get_default_feed() {
		return 'rss2';
	}
	function rest_url( $path = '' ) {
		return home_url( '/wp-json/' . ltrim( (string) $path, '/' ) );
	}
	function get_attached_media( $type, $post_id ) {
		return $GLOBALS['_bext_attached'][ (int) $post_id ] ?? array();
	}
	function get_attachment_link( $id ) {
		return home_url( '/?attachment_id=' . (int) $id );
	}
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}

	// --- multisite (Network) ---
	function get_site( $id ) {
		return $GLOBALS['_bext_sites'][ (int) $id ] ?? null;
	}
	function get_sites( $args = array() ) {
		return array_map( 'intval', array_keys( $GLOBALS['_bext_sites'] ) );
	}
	function switch_to_blog( $id ) {
		$GLOBALS['_bext_current_blog'] = (int) $id;
		return true;
	}
	function restore_current_blog() {
		$GLOBALS['_bext_current_blog'] = 1;
		return true;
	}

	// --- action scheduler datetime helper ---
	function as_get_datetime_object( $when = 'now' ) {
		return new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
	}

	// --- object cache / misc ---
	function wp_using_ext_object_cache() {
		return ! empty( $GLOBALS['_bext_ext_object_cache'] );
	}
	function wp_cache_flush() {
		$GLOBALS['_bext_object_cache_flushed'] = true;
		return true;
	}
	function wp_remote_retrieve_header( $res, $name ) {
		if ( ! is_array( $res ) || empty( $res['headers'] ) ) {
			return '';
		}
		$h = (array) $res['headers'];
		return $h[ strtolower( $name ) ] ?? '';
	}
}

/** Minimal WP_Post / WP_Error doubles for the WP-free tests. */
if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public $ID = 0;
		public $post_type = 'post';
		public $post_status = 'publish';
		public $post_name = 'sample';
		public $post_author = 1;
		public function __construct( array $f = array() ) {
			foreach ( $f as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $msg;
		public function __construct( $code = '', $msg = '' ) {
			$this->msg = $msg;
		}
		public function get_error_message() {
			return $this->msg;
		}
	}
}

/** Register a fake post in the store; returns the WP_Post. */
function bext_make_post( int $id, array $fields = array() ) {
	$post                          = new \WP_Post( array( 'ID' => $id ) + $fields );
	$GLOBALS['_bext_posts'][ $id ] = $post;
	return $post;
}

/** Register a fake site (blog) for multisite Network tests. */
function bext_make_site( int $id, array $fields = array() ) {
	$site = (object) ( array( 'blog_id' => $id ) + $fields );
	$GLOBALS['_bext_sites'][ $id ] = $site;
	return $site;
}

/** The args of the Nth (default: last) do_action() call for a tag, or null. */
function bext_last_action( string $tag, int $n = -1 ) {
	$log = $GLOBALS['_bext_actions_log'][ $tag ] ?? array();
	if ( empty( $log ) ) {
		return null;
	}
	if ( $n < 0 ) {
		return end( $log );
	}
	return $log[ $n ] ?? null;
}

/** How many times a tag was dispatched via do_action(). */
function bext_action_count( string $tag ): int {
	return count( $GLOBALS['_bext_actions_log'][ $tag ] ?? array() );
}
