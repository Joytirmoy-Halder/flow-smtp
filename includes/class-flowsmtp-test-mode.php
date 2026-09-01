<?php
/**
 * Test mode: intercept outgoing email on staging/development sites so real
 * customers never receive mail from a copy of the site.
 *
 * Two actions are supported:
 *  - redirect: all intercepted email is delivered to a single address instead.
 *  - log:      the email is logged but not delivered anywhere.
 *
 * An allowlist of addresses/domains can still receive mail normally.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Test_Mode {

	/**
	 * When true, interception is skipped. Used by the plugin's own "Send Test"
	 * feature so configuration can always be verified.
	 *
	 * @var bool
	 */
	public static $bypass = false;

	/**
	 * Whether the mail currently being processed must not be delivered.
	 *
	 * @var bool
	 */
	private $hold_current = false;

	public function __construct() {
		// Runs early so the logger (PHP_INT_MAX) records what actually goes out.
		add_filter( 'wp_mail', array( $this, 'reroute' ), 5 );
		add_filter( 'pre_wp_mail', array( $this, 'maybe_hold' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Whether interception is currently active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( self::$bypass ) {
			return false;
		}

		$settings = FlowSMTP::get_settings();

		/**
		 * Filter whether test mode is active for the current send.
		 *
		 * @param bool $active Whether test mode is active.
		 */
		return (bool) apply_filters( 'flowsmtp_test_mode_active', ! empty( $settings['test_mode'] ) );
	}

	/**
	 * Normalized allowlist entries (bare addresses and bare domains).
	 *
	 * @return string[]
	 */
	public static function allowlist() {
		$settings = FlowSMTP::get_settings();
		$raw      = isset( $settings['test_mode_allowlist'] ) ? (string) $settings['test_mode_allowlist'] : '';

		$entries = array();
		foreach ( preg_split( '/[\r\n,;]+/', $raw ) as $entry ) {
			$entry = ltrim( strtolower( trim( $entry ) ), '@' );
			if ( '' !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Extract a bare email address from a possible "Name <a@b.c>" value.
	 *
	 * @param string $value Recipient value.
	 * @return string
	 */
	private static function bare_address( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
			$value = trim( $matches[1] );
		}

		return $value;
	}

	/**
	 * Whether a recipient may still receive mail while test mode is on.
	 *
	 * @param string $recipient Recipient value.
	 * @return bool
	 */
	public static function is_allowed( $recipient ) {
		$email  = self::bare_address( $recipient );
		$domain = false !== strpos( $email, '@' ) ? substr( strrchr( $email, '@' ), 1 ) : '';

		foreach ( self::allowlist() as $entry ) {
			if ( $entry === $email || ( '' !== $domain && $entry === $domain ) ) {
				return true;
			}
		}

		return false;
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
	 * Remove Cc/Bcc headers so intercepted mail cannot leak to third parties.
	 *
	 * @param mixed $headers Headers as string or array.
	 * @return mixed Headers of the same type.
	 */
	private static function strip_recipient_headers( $headers ) {
		$was_string = ! is_array( $headers );
		$lines      = $was_string ? explode( "\n", (string) $headers ) : $headers;
		$kept       = array();

		foreach ( $lines as $line ) {
			if ( is_string( $line ) && preg_match( '/^\s*(cc|bcc)\s*:/i', $line ) ) {
				continue;
			}
			$kept[] = $line;
		}

		return $was_string ? implode( "\n", $kept ) : $kept;
	}

	/**
	 * Banner prepended to intercepted email so it is never mistaken for real mail.
	 *
	 * @param string[] $held    Intercepted recipients.
	 * @param bool     $is_html Whether the body is HTML.
	 * @return string
	 */
	private static function banner( $held, $is_html ) {
		$intro = sprintf(
			/* translators: %s: site URL. */
			__( 'FlowSMTP test mode is active on %s. This email was intercepted and would normally have been delivered to:', 'flow-smtp' ),
			home_url()
		);
		$list = implode( ', ', $held );

		if ( $is_html ) {
			return '<div style="margin:0 0 16px;padding:12px 14px;border:1px solid #f5e6ab;border-left:4px solid #996800;background:#fcf9e8;color:#1d2327;font:13px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">' .
				'<strong>' . esc_html__( 'Test mode', 'flow-smtp' ) . '</strong><br />' .
				esc_html( $intro ) . ' <strong>' . esc_html( $list ) . '</strong>' .
				'</div>';
		}

		return '--- ' . __( 'FlowSMTP test mode', 'flow-smtp' ) . " ---\n" . $intro . ' ' . $list . "\n\n";
	}

	/**
	 * Intercept outgoing mail: redirect it, or mark it to be held.
	 *
	 * @param array $atts wp_mail() attributes.
	 * @return array
	 */
	public function reroute( $atts ) {
		$this->hold_current = false;

		if ( ! self::is_active() ) {
			return $atts;
		}

		$settings = FlowSMTP::get_settings();

		$recipients = isset( $atts['to'] ) ? $atts['to'] : array();
		$recipients = is_array( $recipients ) ? $recipients : explode( ',', (string) $recipients );
		$recipients = array_filter( array_map( 'trim', $recipients ) );

		$allowed = array();
		$held    = array();

		foreach ( $recipients as $recipient ) {
			if ( self::is_allowed( $recipient ) ) {
				$allowed[] = $recipient;
			} else {
				$held[] = $recipient;
			}
		}

		if ( ! $held ) {
			return $atts; // Every recipient is allowlisted: deliver normally.
		}

		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$is_html = self::is_html( $headers );

		$atts['headers'] = self::strip_recipient_headers( $headers );
		$atts['subject'] = '[' . __( 'Test Mode', 'flow-smtp' ) . '] ' . ( isset( $atts['subject'] ) ? $atts['subject'] : '' );
		$atts['message'] = self::banner( $held, $is_html ) . ( isset( $atts['message'] ) ? $atts['message'] : '' );

		if ( 'redirect' === $settings['test_mode_action'] && is_email( $settings['test_mode_to'] ) ) {
			$atts['to'] = array_merge( $allowed, array( $settings['test_mode_to'] ) );
			return $atts;
		}

		// Log-only: keep allowlisted recipients, otherwise hold the send entirely.
		if ( $allowed ) {
			$atts['to'] = $allowed;
			return $atts;
		}

		$atts['to']         = array();
		$this->hold_current = true;

		return $atts;
	}

	/**
	 * Short-circuit wp_mail() for held emails. The logger resolves the row via
	 * its own pre_wp_mail hook, so nothing is left stuck in "pending".
	 *
	 * @param null|bool $short_circuit Current short-circuit value.
	 * @param array     $atts          Mail attributes.
	 * @return null|bool
	 */
	public function maybe_hold( $short_circuit, $atts ) {
		if ( ! $this->hold_current ) {
			return $short_circuit;
		}

		$this->hold_current = false;

		return null === $short_circuit ? true : $short_circuit;
	}

	/**
	 * Persistent warning so an active test mode is never forgotten.
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_active() ) {
			return;
		}

		$settings = FlowSMTP::get_settings();

		if ( 'redirect' === $settings['test_mode_action'] && is_email( $settings['test_mode_to'] ) ) {
			/* translators: %s: email address. */
			$message = sprintf( __( 'Test mode is active: all outgoing email is being redirected to %s instead of the real recipients.', 'flow-smtp' ), $settings['test_mode_to'] );
		} else {
			$message = __( 'Test mode is active: outgoing email is being logged but not delivered.', 'flow-smtp' );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'FlowSMTP', 'flow-smtp' ),
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=' . FlowSMTP_Admin::PAGE_SLUG . '&tab=settings' ) ),
			esc_html__( 'Review settings', 'flow-smtp' )
		);
	}
}
