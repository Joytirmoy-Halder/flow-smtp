<?php
/**
 * SMTP mailer: configures PHPMailer from plugin settings, with an optional
 * fallback SMTP connection used when the primary connection fails.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Mailer {

	/**
	 * Logger instance.
	 *
	 * @var FlowSMTP_Logger
	 */
	private $logger;

	/**
	 * Whether the current send is a fallback-connection retry.
	 *
	 * @var bool
	 */
	public $in_fallback = false;

	/**
	 * @param FlowSMTP_Logger $logger Logger.
	 */
	public function __construct( FlowSMTP_Logger $logger ) {
		$this->logger = $logger;

		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );

		// Runs just before configure_phpmailer so an explicitly supplied AltBody
		// (set at PHP_INT_MAX, e.g. by send_test_email) still wins.
		add_action( 'phpmailer_init', array( $this, 'maybe_set_alt_body' ), PHP_INT_MAX - 1 );

		// Try the fallback connection before the logger records the failure (priority 5 < 10).
		add_action( 'wp_mail_failed', array( $this, 'maybe_fallback' ), 5 );
	}

	/**
	 * Apply SMTP settings to PHPMailer. Uses the fallback connection settings
	 * while a fallback retry is in progress.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance (passed by reference by WP).
	 */
	public function configure_phpmailer( $phpmailer ) {
		$settings = FlowSMTP::get_settings();

		if ( $this->in_fallback ) {
			$settings = array_merge(
				$settings,
				array(
					'host'       => $settings['fallback_host'],
					'port'       => $settings['fallback_port'],
					'encryption' => $settings['fallback_encryption'],
					'auth'       => $settings['fallback_auth'],
					'username'   => $settings['fallback_username'],
					'password'   => $settings['fallback_password'],
				)
			);
		}

		if ( empty( $settings['host'] ) ) {
			return; // Not configured yet; let WordPress use the default mail() transport.
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->isSMTP();
		$phpmailer->Host    = $settings['host'];
		$phpmailer->Port    = (int) $settings['port'];
		$phpmailer->Timeout = 15;

		if ( ! empty( $settings['auth'] ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $settings['username'];
			if ( $this->in_fallback ) {
				$phpmailer->Password = self::decrypt( $settings['password'] );
			} else {
				// A constant defined in wp-config.php always wins and never touches the database.
				$phpmailer->Password = defined( 'FLOWSMTP_SMTP_PASSWORD' ) ? FLOWSMTP_SMTP_PASSWORD : self::decrypt( $settings['password'] );
			}
		} else {
			$phpmailer->SMTPAuth = false;
		}

		switch ( $settings['encryption'] ) {
			case 'ssl':
				$phpmailer->SMTPSecure = 'ssl';
				break;
			case 'tls':
				$phpmailer->SMTPSecure = 'tls';
				break;
			default:
				$phpmailer->SMTPSecure  = '';
				$phpmailer->SMTPAutoTLS = false;
		}
		// phpcs:enable
	}

	/**
	 * Give HTML messages a plain-text alternative.
	 *
	 * A single-part text/html message trips SpamAssassin's MIME_HTML_ONLY rule
	 * and scores worse with the large mailbox providers, which expect
	 * multipart/alternative. Most plugins and themes call wp_mail() with an
	 * HTML body and no plain-text part at all, so we derive one.
	 *
	 * An AltBody supplied by the caller is never overwritten.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance (by reference).
	 */
	public function maybe_set_alt_body( $phpmailer ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! is_object( $phpmailer ) || ! isset( $phpmailer->ContentType ) ) {
			return;
		}

		if ( 'text/html' !== strtolower( (string) $phpmailer->ContentType ) ) {
			return; // Already a plain-text message.
		}

		if ( isset( $phpmailer->AltBody ) && '' !== trim( (string) $phpmailer->AltBody ) ) {
			return; // The caller provided its own plain-text part.
		}

		if ( ! self::auto_plaintext_enabled() ) {
			return;
		}

		$html = (string) $phpmailer->Body;
		if ( '' === trim( $html ) ) {
			return;
		}

		$text = self::html_to_text( $html );

		/**
		 * Filter the generated plain-text alternative.
		 *
		 * @param string $text Generated plain text.
		 * @param string $html Original HTML body.
		 */
		$text = (string) apply_filters( 'flowsmtp_plaintext_body', $text, $html );

		if ( '' !== trim( $text ) ) {
			$phpmailer->AltBody = $text;
		}
		// phpcs:enable
	}

	/**
	 * Whether automatic plain-text generation is enabled.
	 *
	 * Resolution order: the FLOWSMTP_AUTO_PLAINTEXT constant, then the
	 * auto_plaintext setting, then the flowsmtp_auto_plaintext filter.
	 *
	 * @return bool
	 */
	public static function auto_plaintext_enabled() {
		if ( defined( 'FLOWSMTP_AUTO_PLAINTEXT' ) ) {
			$enabled = (bool) FLOWSMTP_AUTO_PLAINTEXT;
		} else {
			$settings = FlowSMTP::get_settings();
			$enabled  = ! empty( $settings['auto_plaintext'] );
		}

		/**
		 * Filter whether a plain-text alternative is generated for HTML email.
		 *
		 * @param bool $enabled Whether generation is enabled.
		 */
		return (bool) apply_filters( 'flowsmtp_auto_plaintext', $enabled );
	}

	/**
	 * Convert an HTML email body into readable plain text.
	 *
	 * Deliberately conservative: no DOM parsing, no external dependencies, and
	 * it never throws on malformed markup. Links keep their URLs, images are
	 * dropped (a tracking pixel has no plain-text meaning), and block-level
	 * elements become line breaks.
	 *
	 * @param string $html HTML body.
	 * @return string Plain text, or an empty string when nothing usable remains.
	 */
	public static function html_to_text( $html ) {
		$text = (string) $html;

		if ( '' === trim( $text ) ) {
			return '';
		}

		$original = $text;

		// Remove content that is never rendered to the reader.
		$text = preg_replace( '#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $text );
		$text = preg_replace( '#<!--.*?-->#s', '', $text );

		// Images carry no plain-text meaning; this also drops tracking pixels.
		$text = preg_replace( '#<img\b[^>]*>#i', '', $text );

		// Keep link targets: "label (https://example.com)".
		$text = preg_replace_callback(
			'#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			array( __CLASS__, 'link_to_text' ),
			$text
		);

		// Structural markup becomes whitespace a reader can follow.
		$text = preg_replace( '#<hr\b[^>]*>#i', "\n---\n", $text );
		$text = preg_replace( '#</t[dh]>\s*<t[dh]\b[^>]*>#i', ' | ', $text );
		$text = preg_replace( '#<li\b[^>]*>#i', "\n* ", $text );
		$text = preg_replace( '#</li>#i', "\n", $text );
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = preg_replace( '#<(p|div|tr|h[1-6]|blockquote)\b[^>]*>#i', "\n", $text );
		$text = preg_replace( '#</(p|div|tr|table|h[1-6]|ul|ol|blockquote|section|article|header|footer)>#i', "\n\n", $text );

		// Anything left over is decoration.
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalise whitespace.
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( "\xc2\xa0", ' ', $text ); // Non-breaking space.
		$text = preg_replace( '#[ \t]{2,}#', ' ', $text );
		$text = preg_replace( '#[ \t]+\n#', "\n", $text );
		$text = preg_replace( '#\n{3,}#', "\n\n", $text );

		// A regex failure (e.g. PREG backtrack limit on a huge body) returns null.
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$text = wp_strip_all_tags( $original );
		}

		return trim( (string) $text );
	}

	/**
	 * Render a single anchor tag as plain text. Callback for html_to_text().
	 *
	 * @param array $matches Regex matches: 2 = href, 3 = inner HTML.
	 * @return string
	 */
	private static function link_to_text( $matches ) {
		$url   = trim( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$label = trim( wp_strip_all_tags( $matches[3] ) );

		if ( '' === $url || 0 === stripos( $url, 'javascript:' ) ) {
			return $label;
		}

		if ( '' === $label ) {
			return $url;
		}

		// Don't duplicate when the label already is the destination.
		if ( 0 === strcasecmp( $label, $url ) ) {
			return $url;
		}

		if ( 0 === stripos( $url, 'mailto:' ) && 0 === strcasecmp( $label, substr( $url, 7 ) ) ) {
			return $label;
		}

		return $label . ' (' . $url . ')';
	}

	/**
	 * When the primary connection fails, retry the same email once through the
	 * fallback SMTP connection.
	 *
	 * Runs at priority 5, before the logger's failure handler (priority 10):
	 * the fallback resend is suppressed from creating a new log row, so the
	 * original row resolves to 'sent' (via wp_mail_succeeded) when the
	 * fallback delivers, or to 'failed' with the fallback error when it does
	 * not — without duplicate rows or duplicate retries.
	 *
	 * @param WP_Error $error Error from wp_mail_failed.
	 */
	public function maybe_fallback( $error ) {
		if ( $this->in_fallback ) {
			return; // Never chain fallbacks.
		}

		$settings = FlowSMTP::get_settings();
		if ( empty( $settings['fallback'] ) || empty( $settings['fallback_host'] ) ) {
			return;
		}

		$data = $error->get_error_data();
		if ( empty( $data['to'] ) ) {
			return; // No mail payload attached (e.g. failure outside wp_mail()).
		}

		$this->in_fallback = true;
		$this->logger->suppress_logging( true );

		wp_mail(
			$data['to'],
			isset( $data['subject'] ) ? $data['subject'] : '',
			isset( $data['message'] ) ? $data['message'] : '',
			isset( $data['headers'] ) ? $data['headers'] : '',
			isset( $data['attachments'] ) ? $data['attachments'] : array()
		);

		$this->logger->suppress_logging( false );
		$this->in_fallback = false;
	}

	/**
	 * Force the configured From email when enabled.
	 *
	 * @param string $email Original from email.
	 * @return string
	 */
	public function filter_from_email( $email ) {
		$settings = FlowSMTP::get_settings();

		if ( ! empty( $settings['force_from'] ) && is_email( $settings['from_email'] ) ) {
			return $settings['from_email'];
		}

		// Replace the default wordpress@ address even when not forcing.
		if ( 0 === strpos( $email, 'wordpress@' ) && is_email( $settings['from_email'] ) ) {
			return $settings['from_email'];
		}

		return $email;
	}

	/**
	 * Force the configured From name when enabled.
	 *
	 * @param string $name Original from name.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		$settings = FlowSMTP::get_settings();

		if ( ! empty( $settings['force_from'] ) && ! empty( $settings['from_name'] ) ) {
			return $settings['from_name'];
		}

		if ( 'WordPress' === $name && ! empty( $settings['from_name'] ) ) {
			return $settings['from_name'];
		}

		return $name;
	}

	/**
	 * Send a test email and capture the outcome.
	 *
	 * @param string $to   Recipient.
	 * @param bool   $html Send as HTML.
	 * @return array{success: bool, message: string}
	 */
	public function send_test_email( $to, $html = true ) {
		if ( ! is_email( $to ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please provide a valid email address.', 'flow-smtp' ),
			);
		}

		$error_message = '';
		$catch_error   = function ( $error ) use ( &$error_message ) {
			$error_message = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch_error );

		// Explicit test context: only emails sent inside this window are flagged as tests.
		$this->logger->set_test_context( true );

		do_action( 'flowsmtp_sending_test_email' );

		$subject = sprintf( '[FlowSMTP] Test email from %s', wp_parse_url( home_url(), PHP_URL_HOST ) );
		$plain   = "Congratulations!\n\nThis test email was sent successfully by FlowSMTP.\nYour SMTP settings are working.\n\n-- FlowSMTP";

		// Provide a hand-written plain-text alternative for the HTML version,
		// which reads better than the automatically generated one.
		$set_alt_body = function ( $phpmailer ) use ( $plain ) {
			$phpmailer->AltBody = $plain; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};

		if ( $html ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$body    = self::test_email_html();
			add_action( 'phpmailer_init', $set_alt_body, PHP_INT_MAX );
		} else {
			$headers = array();
			$body    = $plain;
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $catch_error );
		remove_action( 'phpmailer_init', $set_alt_body, PHP_INT_MAX );
		$this->logger->set_test_context( false );

		if ( $sent ) {
			return array(
				'success' => true,
				'message' => sprintf( __( 'Test email sent to %s. Check the inbox (and spam folder).', 'flow-smtp' ), $to ),
			);
		}

		return array(
			'success' => false,
			'message' => $error_message ? $error_message : __( 'Sending failed for an unknown reason.', 'flow-smtp' ),
		);
	}

	/**
	 * Simple HTML body for the test email.
	 *
	 * @return string
	 */
	private static function test_email_html() {
		$site = esc_html( get_option( 'blogname' ) );

		return '<!DOCTYPE html><html><body style="margin:0;padding:32px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(16,24,40,.08);">'
			. '<div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:28px 32px;">'
			. '<h1 style="margin:0;color:#ffffff;font-size:20px;">FlowSMTP &mdash; It works! 🎉</h1></div>'
			. '<div style="padding:28px 32px;color:#344054;font-size:15px;line-height:1.6;">'
			. '<p>This test email was delivered successfully from <strong>' . $site . '</strong>.</p>'
			. '<p>Your SMTP configuration is correct and outgoing email is flowing through your SMTP server.</p>'
			. '<p style="margin-bottom:0;color:#98a2b3;font-size:13px;">Sent by the FlowSMTP plugin for WordPress.</p>'
			. '</div></div></body></html>';
	}

	/**
	 * Derive a 32-byte encryption key.
	 *
	 * Define FLOWSMTP_ENCRYPTION_KEY in wp-config.php to decouple the key from
	 * the WordPress salts (recommended: survives salt rotation).
	 *
	 * @return string
	 */
	private static function key() {
		if ( defined( 'FLOWSMTP_ENCRYPTION_KEY' ) && FLOWSMTP_ENCRYPTION_KEY ) {
			return hash( 'sha256', FLOWSMTP_ENCRYPTION_KEY, true );
		}
		return hash( 'sha256', wp_salt( 'auth' ) . '|flowsmtp-v2', true );
	}

	/**
	 * Encrypt a secret with authenticated encryption.
	 *
	 * Uses libsodium XSalsa20-Poly1305 when available, otherwise AES-256-GCM
	 * via OpenSSL. Output is prefixed with a format marker for migrations.
	 *
	 * @param string $value Plain text.
	 * @return string
	 */
	public static function encrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'fsm1:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return '';
		}

		return 'fsm2:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret. Falls back to the legacy 0.1.0 XOR format so
	 * existing installs keep working; the value is re-encrypted on next save.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, 'fsm1:' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, 5 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $value, 'fsm2:' ) ) {
			$raw = base64_decode( substr( $value, 5 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? '' : $plain;
		}

		return self::legacy_decrypt( $value );
	}

	/**
	 * Decrypt values stored by FlowSMTP 0.1.0 (XOR + base64). Migration only.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function legacy_decrypt( $value ) {
		$raw = base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		$key = wp_salt( 'auth' );
		$out = '';
		for ( $i = 0, $len = strlen( $raw ); $i < $len; $i++ ) {
			$out .= chr( ord( $raw[ $i ] ) ^ ord( $key[ $i % strlen( $key ) ] ) );
		}
		return $out;
	}
}
