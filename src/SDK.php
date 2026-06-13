<?php
/**
 * bext SDK bridge: route wp_mail through bext's managed email send, and expose
 * a background-job enqueue backed by bext's queue.
 *
 * Both are opt-in (off by default) and fail OPEN — if bext can't take the work,
 * WordPress falls back to its normal path so nothing is lost.
 *
 * @package Bext\WP
 */

namespace Bext\WP;

defined( 'ABSPATH' ) || exit;

class SDK {

	/** @var Env */
	private $env;

	/** @var Plugin */
	private $plugin;

	public function __construct( Env $env, Plugin $plugin ) {
		$this->env    = $env;
		$this->plugin = $plugin;
	}

	public function register(): void {
		if ( ! $this->env->is_behind_bext() ) {
			return;
		}

		if ( $this->email_enabled() ) {
			// pre_wp_mail (WP 5.7+): return non-null to short-circuit wp_mail.
			add_filter( 'pre_wp_mail', array( $this, 'send_mail' ), 10, 2 );
		}

		// Enqueue shims. NOTE: these MUST be on different tags — WordPress shares
		// one callback registry for actions and filters, so registering both on
		// `bext/enqueue` would make do_action() also invoke the filter callback
		// (with shifted args) and enqueue a second, malformed job.
		//   do_action( 'bext/enqueue', $name, $payload, $delay )               — fire-and-forget
		//   apply_filters( 'bext/enqueue_job', null, $name, $payload, $delay ) — returns the job id
		add_action( 'bext/enqueue', array( $this, 'enqueue_action' ), 10, 3 );
		add_filter( 'bext/enqueue_job', array( $this, 'enqueue_filter' ), 10, 4 );
	}

	private function email_enabled(): bool {
		return $this->env->sdk_email_enabled();
	}

	private function jobs_enabled(): bool {
		return $this->env->sdk_jobs_enabled();
	}

	/**
	 * @return array{email:bool,jobs:bool,app_id:string}
	 */
	public function status(): array {
		return array(
			'email'  => $this->email_enabled(),
			'jobs'   => $this->jobs_enabled(),
			'app_id' => $this->env->app_id(),
		);
	}

	/**
	 * pre_wp_mail handler. Returns true on successful bext send, or $short
	 * (null) to let WordPress's own wp_mail run as a fallback.
	 *
	 * @param null|bool $short Short-circuit value (null by default).
	 * @param array     $atts  to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public function send_mail( $short, $atts ) {
		try {
			$to = $atts['to'] ?? array();
			if ( is_string( $to ) ) {
				$to = array_map( 'trim', explode( ',', $to ) );
			}
			$to = array_values( array_filter( (array) $to ) );
			if ( empty( $to ) ) {
				return $short;
			}

			$headers = $this->parse_headers( $atts['headers'] ?? array() );
			$message = (string) ( $atts['message'] ?? '' );
			$is_html = isset( $headers['content-type'] ) && false !== stripos( $headers['content-type'], 'text/html' );

			$payload = array(
				'to'      => $to,
				'subject' => (string) ( $atts['subject'] ?? '' ),
			);
			if ( $is_html ) {
				$payload['html'] = $message;
			} else {
				$payload['text'] = $message;
			}
			if ( ! empty( $headers['reply-to'] ) ) {
				$payload['reply_to'] = $headers['reply-to'];
			}
			if ( ! empty( $headers['from'] ) ) {
				$payload['from'] = $headers['from'];
			}
			if ( ! empty( $headers['cc'] ) ) {
				$payload['cc'] = array_map( 'trim', explode( ',', $headers['cc'] ) );
			}
			if ( ! empty( $headers['bcc'] ) ) {
				$payload['bcc'] = array_map( 'trim', explode( ',', $headers['bcc'] ) );
			}

			// Bound attachment size: base64 inflates by ~33% and is held in memory.
			// If the total is large, fall back to native wp_mail (which streams
			// from disk) rather than risk an uncatchable OOM on this request.
			$atts_in   = $atts['attachments'] ?? array();
			$max_bytes = (int) apply_filters( 'bext/sdk_email_max_attachment_bytes', 15 * 1024 * 1024 );
			if ( $this->attachments_total_bytes( $atts_in ) > $max_bytes ) {
				do_action( 'bext/sdk_email_fallback', 'attachments-too-large', $atts );
				return $short;
			}
			$attachments = $this->build_attachments( $atts_in );
			if ( ! empty( $attachments ) ) {
				$payload['attachments'] = $attachments;
			}

			$res = $this->env->sdk_request( 'POST', '/__bext/sdk/email/send', $payload, true );
			if ( is_array( $res ) && 200 === $res['code'] ) {
				$decoded = json_decode( $res['body'], true );
				if ( is_array( $decoded ) && ! empty( $decoded['ok'] ) ) {
					return true; // Delivered by bext.
				}
			}
			// bext couldn't take it (no SMTP config, error, unreachable). Surface
			// the reason so operators don't silently think bext is sending.
			do_action( 'bext/sdk_email_fallback', $res, $payload );
		} catch ( \Throwable $e ) {
			do_action( 'bext/sdk_email_fallback', $e, $atts );
		}
		return $short; // null → WordPress sends it the normal way.
	}

	/**
	 * Action wrapper: do_action( 'bext/enqueue', $name, $payload, $delay ).
	 * Fire-and-forget (action return values are discarded by do_action).
	 *
	 * @param string   $name    Queue name.
	 * @param mixed    $payload JSON-encodable payload.
	 * @param int|null $delay   Seconds to delay.
	 */
	public function enqueue_action( string $name, $payload = array(), $delay = null ): void {
		$this->enqueue( $name, $payload, $delay );
	}

	/**
	 * Filter wrapper: apply_filters( 'bext/enqueue_job', null, $name, $payload, $delay )
	 * → returns the job id (or the passed-through default on failure).
	 *
	 * @param mixed    $default Returned if enqueue fails/disabled.
	 * @param string   $name    Queue name.
	 * @param mixed    $payload JSON-encodable payload.
	 * @param int|null $delay   Seconds to delay.
	 * @return mixed Job id string, or $default.
	 */
	public function enqueue_filter( $default, string $name, $payload = array(), $delay = null ) {
		$id = $this->enqueue( $name, $payload, $delay );
		return null === $id ? $default : $id;
	}

	/**
	 * Enqueue a background job onto a bext queue. No-op (returns null) unless
	 * jobs are enabled.
	 *
	 * @param string   $name    Queue name.
	 * @param mixed    $payload JSON-encodable payload.
	 * @param int|null $delay   Seconds to delay.
	 * @return string|null Job id, or null.
	 */
	public function enqueue( string $name, $payload = array(), $delay = null ) {
		if ( ! $this->jobs_enabled() ) {
			return null;
		}
		$body = array(
			'queue'   => $name ?: 'default',
			'payload' => $payload,
		);
		if ( null !== $delay ) {
			$body['delay'] = (int) $delay;
		}
		$res = $this->env->sdk_request( 'POST', '/__bext/sdk/queue/push', $body, true );
		if ( is_array( $res ) && 200 === $res['code'] ) {
			$decoded = json_decode( $res['body'], true );
			return is_array( $decoded ) && isset( $decoded['id'] ) ? (string) $decoded['id'] : null;
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Normalize wp_mail headers (string or array) into a lowercase-keyed map.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array<string,string>
	 */
	private function parse_headers( $headers ): array {
		$out = array();
		if ( empty( $headers ) ) {
			return $out;
		}
		if ( is_string( $headers ) ) {
			$headers = preg_split( "/\r\n|\n|\r/", $headers );
		}
		foreach ( (array) $headers as $line ) {
			$line = (string) $line;
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$out[ strtolower( trim( $name ) ) ] = trim( $value );
		}
		return $out;
	}

	/**
	 * Total on-disk size of the given attachment paths (readable ones only).
	 *
	 * @param string|string[] $attachments File paths.
	 */
	private function attachments_total_bytes( $attachments ): int {
		$total = 0;
		foreach ( (array) $attachments as $path ) {
			$path = (string) $path;
			if ( '' !== $path && @is_readable( $path ) ) {
				$size   = @filesize( $path );
				$total += is_int( $size ) ? $size : 0;
			}
		}
		return $total;
	}

	/**
	 * Read attachment files and base64-encode them for the email/send API.
	 *
	 * @param string|string[] $attachments File paths.
	 * @return array<int,array{filename:string,content_type:string,content_base64:string}>
	 */
	private function build_attachments( $attachments ): array {
		$out = array();
		foreach ( (array) $attachments as $path ) {
			$path = (string) $path;
			if ( '' === $path || ! @is_readable( $path ) ) {
				continue;
			}
			$data = @file_get_contents( $path );
			if ( false === $data ) {
				continue;
			}
			$out[] = array(
				'filename'       => basename( $path ),
				'content_type'   => function_exists( 'mime_content_type' ) ? ( mime_content_type( $path ) ?: 'application/octet-stream' ) : 'application/octet-stream',
				'content_base64' => base64_encode( $data ),
			);
		}
		return $out;
	}
}
