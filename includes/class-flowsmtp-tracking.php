<?php
/**
 * Open & click tracking.
 *
 * Injects a 1x1 tracking pixel and rewrites links in HTML emails, then records
 * opens and clicks against the matching email log row.
 *
 * Privacy notes:
 *  - Tracking is opt-in (disabled by default) and applies to HTML emails only.
 *  - No IP addresses, user agents or per-recipient profiles are stored: only
 *    aggregate open/click counters and timestamps on the log row.
 *  - Click links are HMAC-signed, so the redirect endpoint can never be abused
 *    as an open redirect.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Tracking {

	const QUERY_VAR = 'flowsmtp_track';

	/**
	 * Tracking id generated for the email currently being sent.
	 *
	 * @var string
	 */
	private $current_tid = '';

	public function __construct() {
		// After test mode (5), before the logger captures the body (PHP_INT_MAX).
		add_filter( 'wp_mail', array( $this, 'inject' ), 20 );
		// The log row exists by the time pre_wp_mail runs.
		add_filter( 'pre_wp_mail', array( $this, 'attach_to_log' ), 15, 2 );
		add_action( 'init', array( $this, 'handle_request' ) );
	}

	/**
	 * Whether a tracking type is enabled.
	 *
	 * @param string $type opens|clicks.
	 * @return bool
	 */
	public static function enabled( $type ) {
		$settings = FlowSMTP::get_settings();
		$key      = 'opens' === $type ? 'track_opens' : 'track_clicks';

		return ! empty( $settings[ $key ] );
	}

	/**
	 * Signature for a tracked click, so the redirect cannot be tampered with.
	 *
	 * @param string $data Data to sign.
	 * @return string
	 */
	private static function sign( $data ) {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the mail declares an HTML body.
	 *
	 * @param mixed $headers Headers as string or array.
	 * @return bool
	 */
	private static function is_html( $headers ) {
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", $headers );
		}

		return false !== stripos( (string) $headers, 'text/html' );
	}

	/**
	 * Build a tracking endpoint URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private static function endpoint( $args ) {
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Rewrite http(s) links so clicks can be counted.
	 *
	 * @param string $message HTML body.
	 * @param string $tid     Tracking id.
	 * @return string
	 */
	private static function rewrite_links( $message, $tid ) {
		return preg_replace_callback(
			'/(<a\b[^>]*\bhref=["\'])(https?:\/\/[^"\'#][^"\']*)(["\'])/i',
			static function ( $matches ) use ( $tid ) {
				$target = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				// Never rewrite our own tracking links (e.g. on a resend).
				if ( false !== strpos( $target, self::QUERY_VAR . '=' ) ) {
					return $matches[0];
				}

				/**
				 * Filter whether a specific link should be click-tracked.
				 *
				 * @param bool   $track  Whether to track this link.
				 * @param string $target Destination URL.
				 */
				if ( ! apply_filters( 'flowsmtp_track_link', true, $target ) ) {
					return $matches[0];
				}

				$tracked = self::endpoint(
					array(
						self::QUERY_VAR => 'click',
						'tid'           => $tid,
						'url'           => rawurlencode( $target ),
						'sig'           => self::sign( $tid . $target ),
					)
				);

				return $matches[1] . esc_url( $tracked ) . $matches[3];
			},
			$message
		);
	}

	/**
	 * Append the open-tracking pixel.
	 *
	 * @param string $message HTML body.
	 * @param string $tid     Tracking id.
	 * @return string
	 */
	private static function append_pixel( $message, $tid ) {
		$pixel = '<img src="' . esc_url(
			self::endpoint(
				array(
					self::QUERY_VAR => 'open',
					'tid'           => $tid,
				)
			)
		) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0" />';

		if ( false !== stripos( $message, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $pixel . '</body>', $message, 1 );
		}

		return $message . $pixel;
	}

	/**
	 * Inject tracking into outgoing HTML email.
	 *
	 * @param array $atts wp_mail() attributes.
	 * @return array
	 */
	public function inject( $atts ) {
		$this->current_tid = '';

		$track_opens  = self::enabled( 'opens' );
		$track_clicks = self::enabled( 'clicks' );

		if ( ! $track_opens && ! $track_clicks ) {
			return $atts;
		}

		if ( empty( $atts['message'] ) || ! self::is_html( isset( $atts['headers'] ) ? $atts['headers'] : '' ) ) {
			return $atts; // Plain-text email: nothing to inject.
		}

		/**
		 * Filter whether the current email should be tracked.
		 *
		 * @param bool  $track Whether to track this email.
		 * @param array $atts  wp_mail() attributes.
		 */
		if ( ! apply_filters( 'flowsmtp_track_email', true, $atts ) ) {
			return $atts;
		}

		$tid     = wp_generate_password( 24, false, false );
		$message = (string) $atts['message'];

		if ( $track_clicks ) {
			$message = self::rewrite_links( $message, $tid );
		}
		if ( $track_opens ) {
			$message = self::append_pixel( $message, $tid );
		}

		$atts['message']   = $message;
		$this->current_tid = $tid;

		return $atts;
	}

	/**
	 * Store the tracking id on the log row created for this send.
	 *
	 * @param null|bool $short_circuit Short-circuit value.
	 * @param array     $atts          Mail attributes.
	 * @return null|bool Unchanged.
	 */
	public function attach_to_log( $short_circuit, $atts ) {
		if ( '' === $this->current_tid ) {
			return $short_circuit;
		}

		global $wpdb;

		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$table   = FlowSMTP_Logger::table();

		$log_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $table . " WHERE status = 'pending' AND subject = %s ORDER BY id DESC LIMIT 1",
				$subject
			)
		);

		if ( $log_id ) {
			$wpdb->update(
				$table,
				array( 'tracking_id' => $this->current_tid ),
				array( 'id' => $log_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$this->current_tid = '';

		return $short_circuit;
	}

	/**
	 * Handle pixel and redirect requests.
	 */
	public function handle_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$tid    = isset( $_GET['tid'] ) ? sanitize_text_field( wp_unslash( $_GET['tid'] ) ) : '';

		if ( 'open' === $action ) {
			$this->record_open( $tid );
			self::serve_pixel();
		}

		if ( 'click' === $action ) {
			$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
			$sig = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

			// Signature check: without it this endpoint would be an open redirect.
			if ( ! $url || ! $sig || ! hash_equals( self::sign( $tid . $url ), $sig ) ) {
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			$this->record_click( $tid, $url );

			wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destination is HMAC-signed by this plugin.
			exit;
		}
		// phpcs:enable
	}

	/**
	 * Record an open.
	 *
	 * @param string $tid Tracking id.
	 */
	private function record_open( $tid ) {
		if ( '' === $tid ) {
			return;
		}

		global $wpdb;
		$table = FlowSMTP_Logger::table();
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $table . ' SET opens = opens + 1, first_open_at = COALESCE(first_open_at, %s), last_open_at = %s WHERE tracking_id = %s',
				$now,
				$now,
				$tid
			)
		);

		/**
		 * Fires when a tracked email is opened.
		 *
		 * @param string $tid Tracking id.
		 */
		do_action( 'flowsmtp_email_opened', $tid );
	}

	/**
	 * Record a click.
	 *
	 * @param string $tid Tracking id.
	 * @param string $url Destination URL.
	 */
	private function record_click( $tid, $url ) {
		if ( '' === $tid ) {
			return;
		}

		global $wpdb;
		$table = FlowSMTP_Logger::table();

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $table . ' SET clicks = clicks + 1, last_click_url = %s, last_click_at = %s WHERE tracking_id = %s',
				$url,
				current_time( 'mysql' ),
				$tid
			)
		);

		/**
		 * Fires when a tracked link is clicked.
		 *
		 * @param string $tid Tracking id.
		 * @param string $url Destination URL.
		 */
		do_action( 'flowsmtp_email_clicked', $tid, $url );
	}

	/**
	 * Output a transparent 1x1 GIF and stop.
	 */
	private static function serve_pixel() {
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data.
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}
}
