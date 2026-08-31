<?php
/**
 * Deliverability: DNS health checks (SPF, DKIM, DMARC, MX) for a sending domain.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Deliverability {

	/**
	 * Common DKIM selectors to scan when the user does not provide one.
	 *
	 * @var string[]
	 */
	private static $dkim_selectors = array(
		'default',
		'google',
		'selector1',
		'selector2',
		'k1',
		'k2',
		's1',
		's2',
		'mail',
		'smtp',
		'zoho',
		'pm',
		'mg',
		'krs',
	);

	/**
	 * Run all DNS health checks for a domain.
	 *
	 * @param string $domain        Domain to check (e.g. example.com).
	 * @param string $dkim_selector Optional DKIM selector to check explicitly.
	 * @return array|WP_Error List of checks: id, label, status (pass|warn|fail), detail.
	 */
	public static function check( $domain, $dkim_selector = '' ) {
		$domain = strtolower( trim( (string) $domain ) );

		if ( '' === $domain || ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $domain ) ) {
			return new WP_Error( 'flowsmtp_bad_domain', __( 'Please enter a valid domain (e.g. example.com).', 'flow-smtp' ) );
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return new WP_Error( 'flowsmtp_no_dns', __( 'DNS lookups are not available on this server (dns_get_record is disabled).', 'flow-smtp' ) );
		}

		$checks   = array();
		$checks[] = self::check_spf( $domain );
		$checks[] = self::check_dkim( $domain, $dkim_selector );
		$checks[] = self::check_dmarc( $domain );
		$checks[] = self::check_mx( $domain );

		return $checks;
	}

	/**
	 * SPF check: exactly one v=spf1 TXT record should exist.
	 *
	 * @param string $domain Domain.
	 * @return array
	 */
	private static function check_spf( $domain ) {
		$spf = array();
		foreach ( self::txt_records( $domain ) as $txt ) {
			if ( 0 === stripos( $txt, 'v=spf1' ) ) {
				$spf[] = $txt;
			}
		}

		if ( 1 === count( $spf ) ) {
			return array(
				'id'     => 'spf',
				'label'  => 'SPF',
				'status' => 'pass',
				'detail' => $spf[0],
			);
		}

		if ( count( $spf ) > 1 ) {
			return array(
				'id'     => 'spf',
				'label'  => 'SPF',
				'status' => 'warn',
				'detail' => __( 'Multiple SPF records found. This is invalid per RFC 7208 — merge them into a single v=spf1 record.', 'flow-smtp' ),
			);
		}

		return array(
			'id'     => 'spf',
			'label'  => 'SPF',
			'status' => 'fail',
			/* translators: %s: domain name. */
			'detail' => sprintf( __( 'No SPF record found on %s. Add a TXT record such as "v=spf1 include:<your provider> ~all" so receivers can verify your mail server.', 'flow-smtp' ), $domain ),
		);
	}

	/**
	 * DKIM check: look up the given selector, or scan common selectors.
	 *
	 * @param string $domain   Domain.
	 * @param string $selector Optional selector.
	 * @return array
	 */
	private static function check_dkim( $domain, $selector = '' ) {
		$selector  = sanitize_text_field( $selector );
		$selectors = '' !== $selector ? array( $selector ) : self::$dkim_selectors;
		$found     = array();

		foreach ( $selectors as $sel ) {
			$host = $sel . '._domainkey.' . $domain;

			foreach ( self::txt_records( $host ) as $txt ) {
				if ( false !== stripos( $txt, 'v=dkim1' ) || false !== stripos( $txt, 'k=rsa' ) || false !== stripos( $txt, 'p=' ) ) {
					$found[] = $sel;
					break;
				}
			}

			if ( ! in_array( $sel, $found, true ) ) {
				// Many providers publish DKIM as a CNAME to their own record.
				$cname = @dns_get_record( $host, DNS_CNAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! empty( $cname ) ) {
					$found[] = $sel;
				}
			}
		}

		if ( $found ) {
			return array(
				'id'     => 'dkim',
				'label'  => 'DKIM',
				'status' => 'pass',
				/* translators: %s: comma-separated DKIM selector names. */
				'detail' => sprintf( __( 'DKIM key found (selector: %s).', 'flow-smtp' ), implode( ', ', $found ) ),
			);
		}

		if ( '' !== $selector ) {
			return array(
				'id'     => 'dkim',
				'label'  => 'DKIM',
				'status' => 'fail',
				/* translators: 1: selector, 2: domain. */
				'detail' => sprintf( __( 'No DKIM record found at %1$s._domainkey.%2$s. Check the selector name with your email provider.', 'flow-smtp' ), $selector, $domain ),
			);
		}

		return array(
			'id'     => 'dkim',
			'label'  => 'DKIM',
			'status' => 'warn',
			'detail' => __( 'No DKIM key found under common selectors. If your provider gave you a specific selector, enter it above and re-run — sending without DKIM significantly hurts deliverability.', 'flow-smtp' ),
		);
	}

	/**
	 * DMARC check: TXT at _dmarc.domain with v=DMARC1.
	 *
	 * @param string $domain Domain.
	 * @return array
	 */
	private static function check_dmarc( $domain ) {
		$record = '';
		foreach ( self::txt_records( '_dmarc.' . $domain ) as $txt ) {
			if ( 0 === stripos( $txt, 'v=dmarc1' ) ) {
				$record = $txt;
				break;
			}
		}

		if ( '' === $record ) {
			return array(
				'id'     => 'dmarc',
				'label'  => 'DMARC',
				'status' => 'fail',
				/* translators: %s: domain name. */
				'detail' => sprintf( __( 'No DMARC record found. Add a TXT record at _dmarc.%s such as "v=DMARC1; p=quarantine; rua=mailto:dmarc@%s". Gmail and Yahoo now require DMARC for bulk senders.', 'flow-smtp' ), $domain, $domain ),
			);
		}

		if ( preg_match( '/\bp=\s*none\b/i', $record ) ) {
			return array(
				'id'     => 'dmarc',
				'label'  => 'DMARC',
				'status' => 'warn',
				/* translators: %s: DMARC record. */
				'detail' => sprintf( __( 'DMARC found but policy is p=none (monitor only): %s. Consider p=quarantine or p=reject once you have verified alignment.', 'flow-smtp' ), $record ),
			);
		}

		return array(
			'id'     => 'dmarc',
			'label'  => 'DMARC',
			'status' => 'pass',
			'detail' => $record,
		);
	}

	/**
	 * MX check (informational).
	 *
	 * @param string $domain Domain.
	 * @return array
	 */
	private static function check_mx( $domain ) {
		$mx = @dns_get_record( $domain, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! empty( $mx ) ) {
			$hosts = array();
			foreach ( $mx as $row ) {
				if ( ! empty( $row['target'] ) ) {
					$hosts[] = $row['target'];
				}
			}
			return array(
				'id'     => 'mx',
				'label'  => 'MX',
				'status' => 'pass',
				/* translators: %s: comma-separated MX hosts. */
				'detail' => sprintf( __( 'Mail exchangers found: %s', 'flow-smtp' ), implode( ', ', array_slice( $hosts, 0, 4 ) ) ),
			);
		}

		return array(
			'id'     => 'mx',
			'label'  => 'MX',
			'status' => 'warn',
			'detail' => __( 'No MX records found. The domain cannot receive email, which can hurt sender reputation (bounces, reply failures).', 'flow-smtp' ),
		);
	}

	/**
	 * Get all TXT record strings for a host.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function txt_records( $host ) {
		$records = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $records ) || ! is_array( $records ) ) {
			return array();
		}

		$out = array();
		foreach ( $records as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$out[] = implode( '', $record['entries'] );
			} elseif ( isset( $record['txt'] ) ) {
				$out[] = (string) $record['txt'];
			}
		}

		return $out;
	}
}
