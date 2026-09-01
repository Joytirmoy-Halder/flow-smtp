<?php
/**
 * HTTP API mailer: sends email through a provider REST API instead of SMTP.
 *
 * Useful on hosts that block outbound SMTP ports. Supported providers:
 * SendGrid, Mailgun, Brevo. Fires the standard wp_mail_succeeded /
 * wp_mail_failed actions so logging, retries and alerts work unchanged.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_API_Mailer {

	/**
	 * Providers that support API sending.
	 *
	 * @var string[]
	 */
	const API_PROVIDERS = array( 'sendgrid', 'mailgun', 'brevo' );

	public function __construct() {
		// Runs before the logger's pre_wp_mail resolver (PHP_INT_MAX).
		add_filter( 'pre_wp_mail', array( $this, 'maybe_send' ), 10, 2 );
	}

	/**
	 * Whether a provider can send via API.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function supports( $provider ) {
		return in_array( $provider, self::API_PROVIDERS, true );
	}

	/**
	 * The active API key (constant beats stored setting).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( defined( 'FLOWSMTP_API_KEY' ) && FLOWSMTP_API_KEY ) {
			return (string) FLOWSMTP_API_KEY;
		}
		$settings = FlowSMTP::get_settings();
		return $settings['api_key'] ? FlowSMTP_Mailer::decrypt( $settings['api_key'] ) : '';
	}

	/**
	 * Intercept wp_mail() and send through the provider API when configured.
	 *
	 * @param null|bool $short_circuit Existing short-circuit value.
	 * @param array     $atts          wp_mail() attributes.
	 * @return null|bool Null to fall through to SMTP, true/false when handled.
	 */
	public function maybe_send( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit; // Another plugin got there first.
		}

		// Fallback-connection resends always take the SMTP path.
		if ( function_exists( 'flowsmtp' ) && flowsmtp()->mailer && flowsmtp()->mailer->in_fallback ) {
			return null;
		}

		$settings = FlowSMTP::get_settings();

		if ( 'api' !== $settings['mailer_type'] || ! self::supports( $settings['provider'] ) ) {
			return null;
		}

		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return null; // Not configured; fall back to SMTP path.
		}

		$mail = $this->normalize_atts( $atts, $settings );

		switch ( $settings['provider'] ) {
			case 'sendgrid':
				$result = $this->send_sendgrid( $mail, $api_key );
				break;
			case 'mailgun':
				$result = $this->send_mailgun( $mail, $api_key, $settings['api_domain'] );
				break;
			case 'brevo':
				$result = $this->send_brevo( $mail, $api_key );
				break;
			default:
				return null;
		}

		$mail_data = array(
			'to'          => $mail['to'],
			'subject'     => $mail['subject'],
			'message'     => $mail['message'],
			'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : '',
			'attachments' => $mail['attachments'],
		);

		if ( true === $result ) {
			do_action( 'wp_mail_succeeded', $mail_data );
			return true;
		}

		$error = new WP_Error( 'wp_mail_failed', $result, $mail_data );
		do_action( 'wp_mail_failed', $error );
		return false;
	}

	/**
	 * Normalize wp_mail() attributes into a predictable structure.
	 *
	 * @param array $atts     wp_mail() attributes.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function normalize_atts( $atts, $settings ) {
		$to = isset( $atts['to'] ) ? $atts['to'] : array();
		if ( ! is_array( $to ) ) {
			$to = array_map( 'trim', explode( ',', (string) $to ) );
		}
		$to = array_values( array_filter( $to, 'is_email' ) );

		$headers = isset( $atts['headers'] ) ? $atts['headers'] : array();
		if ( ! is_array( $headers ) ) {
			$headers = preg_split( '/\r\n|\r|\n/', (string) $headers );
		}

		$mail = array(
			'to'          => $to,
			'cc'          => array(),
			'bcc'         => array(),
			'reply_to'    => '',
			'subject'     => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'message'     => isset( $atts['message'] ) ? (string) $atts['message'] : '',
			'is_html'     => false,
			'from_email'  => $settings['from_email'],
			'from_name'   => $settings['from_name'],
			'attachments' => array(),
		);

		foreach ( $headers as $header ) {
			if ( false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );

			switch ( strtolower( $name ) ) {
				case 'content-type':
					if ( false !== stripos( $value, 'text/html' ) ) {
						$mail['is_html'] = true;
					}
					break;
				case 'cc':
					$mail['cc'] = array_merge( $mail['cc'], array_filter( array_map( 'trim', explode( ',', $value ) ), 'is_email' ) );
					break;
				case 'bcc':
					$mail['bcc'] = array_merge( $mail['bcc'], array_filter( array_map( 'trim', explode( ',', $value ) ), 'is_email' ) );
					break;
				case 'reply-to':
					$reply = trim( preg_replace( '/.*</', '', str_replace( '>', '', $value ) ) );
					if ( is_email( $reply ) ) {
						$mail['reply_to'] = $reply;
					}
					break;
				case 'from':
					if ( empty( $settings['force_from'] ) ) {
						if ( preg_match( '/(.*)<(.+)>/', $value, $m ) ) {
							$email = trim( $m[2] );
							if ( is_email( $email ) ) {
								$mail['from_email'] = $email;
								$mail['from_name']  = trim( $m[1], " \t\"" );
							}
						} elseif ( is_email( trim( $value ) ) ) {
							$mail['from_email'] = trim( $value );
						}
					}
					break;
			}
		}

		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		$mail['attachments'] = array_values( array_filter( array_map( 'trim', $attachments ), 'file_exists' ) );

		return $mail;
	}

	/**
	 * Send via SendGrid v3 API.
	 *
	 * @param array  $mail    Normalized mail.
	 * @param string $api_key API key.
	 * @return true|string True or error message.
	 */
	private function send_sendgrid( $mail, $api_key ) {
		$emails = static function ( $list ) {
			return array_map(
				static function ( $email ) {
					return array( 'email' => $email );
				},
				$list
			);
		};

		$personalization = array( 'to' => $emails( $mail['to'] ) );
		if ( $mail['cc'] ) {
			$personalization['cc'] = $emails( $mail['cc'] );
		}
		if ( $mail['bcc'] ) {
			$personalization['bcc'] = $emails( $mail['bcc'] );
		}

		$body = array(
			'personalizations' => array( $personalization ),
			'from'             => array(
				'email' => $mail['from_email'],
				'name'  => $mail['from_name'],
			),
			'subject'          => $mail['subject'],
			'content'          => array(
				array(
					'type'  => $mail['is_html'] ? 'text/html' : 'text/plain',
					'value' => $mail['message'],
				),
			),
		);

		if ( $mail['reply_to'] ) {
			$body['reply_to'] = array( 'email' => $mail['reply_to'] );
		}

		foreach ( $mail['attachments'] as $path ) {
			$body['attachments'][] = array(
				'content'     => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore
				'filename'    => basename( $path ),
				'disposition' => 'attachment',
			);
		}

		$response = wp_remote_post(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->interpret_response( $response, array( 200, 202 ), 'SendGrid' );
	}

	/**
	 * Send via Mailgun messages API (multipart form).
	 *
	 * @param array  $mail    Normalized mail.
	 * @param string $api_key API key.
	 * @param string $domain  Mailgun sending domain.
	 * @return true|string True or error message.
	 */
	private function send_mailgun( $mail, $api_key, $domain ) {
		if ( '' === $domain ) {
			return __( 'Mailgun API: no sending domain configured in FlowSMTP settings.', 'flow-smtp' );
		}

		$fields = array(
			'from'    => $mail['from_name'] ? sprintf( '%s <%s>', $mail['from_name'], $mail['from_email'] ) : $mail['from_email'],
			'to'      => implode( ',', $mail['to'] ),
			'subject' => $mail['subject'],
		);
		$fields[ $mail['is_html'] ? 'html' : 'text' ] = $mail['message'];

		if ( $mail['cc'] ) {
			$fields['cc'] = implode( ',', $mail['cc'] );
		}
		if ( $mail['bcc'] ) {
			$fields['bcc'] = implode( ',', $mail['bcc'] );
		}
		if ( $mail['reply_to'] ) {
			$fields['h:Reply-To'] = $mail['reply_to'];
		}

		$boundary = 'flowsmtp' . md5( uniqid( (string) wp_rand(), true ) );
		$payload  = '';

		foreach ( $fields as $name => $value ) {
			$payload .= "--{$boundary}\r\n";
			$payload .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		foreach ( $mail['attachments'] as $path ) {
			$payload .= "--{$boundary}\r\n";
			$payload .= 'Content-Disposition: form-data; name="attachment"; filename="' . basename( $path ) . "\"\r\n";
			$payload .= "Content-Type: application/octet-stream\r\n\r\n";
			$payload .= (string) file_get_contents( $path ) . "\r\n"; // phpcs:ignore
		}

		$payload .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			'https://api.mailgun.net/v3/' . rawurlencode( $domain ) . '/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ), // phpcs:ignore
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $payload,
			)
		);

		return $this->interpret_response( $response, array( 200 ), 'Mailgun' );
	}

	/**
	 * Send via Brevo transactional API.
	 *
	 * @param array  $mail    Normalized mail.
	 * @param string $api_key API key.
	 * @return true|string True or error message.
	 */
	private function send_brevo( $mail, $api_key ) {
		$emails = static function ( $list ) {
			return array_map(
				static function ( $email ) {
					return array( 'email' => $email );
				},
				$list
			);
		};

		$body = array(
			'sender'  => array(
				'email' => $mail['from_email'],
				'name'  => $mail['from_name'],
			),
			'to'      => $emails( $mail['to'] ),
			'subject' => $mail['subject'],
		);
		$body[ $mail['is_html'] ? 'htmlContent' : 'textContent' ] = $mail['message'];

		if ( $mail['cc'] ) {
			$body['cc'] = $emails( $mail['cc'] );
		}
		if ( $mail['bcc'] ) {
			$body['bcc'] = $emails( $mail['bcc'] );
		}
		if ( $mail['reply_to'] ) {
			$body['replyTo'] = array( 'email' => $mail['reply_to'] );
		}

		foreach ( $mail['attachments'] as $path ) {
			$body['attachment'][] = array(
				'content' => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore
				'name'    => basename( $path ),
			);
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->interpret_response( $response, array( 200, 201, 202 ), 'Brevo' );
	}

	/**
	 * Turn an HTTP response into true or a readable error string.
	 *
	 * @param array|WP_Error $response Response.
	 * @param int[]          $ok_codes Acceptable status codes.
	 * @param string         $provider Provider label for error messages.
	 * @return true|string
	 */
	private function interpret_response( $response, $ok_codes, $provider ) {
		if ( is_wp_error( $response ) ) {
			return sprintf( '%s API: %s', $provider, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, $ok_codes, true ) ) {
			return true;
		}

		$body = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );
		if ( strlen( $body ) > 300 ) {
			$body = substr( $body, 0, 300 ) . '…';
		}

		return sprintf( '%s API: HTTP %d — %s', $provider, $code, $body ? $body : __( 'no response body', 'flow-smtp' ) );
	}
}
