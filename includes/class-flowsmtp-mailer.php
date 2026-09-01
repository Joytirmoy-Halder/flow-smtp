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

		// Provide a plain-text alternative for the HTML version (better spam scores).
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
