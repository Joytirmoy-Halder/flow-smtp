<?php
/**
 * SMTP provider presets: known hosts, ports, encryption and setup notes.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Providers {

	/**
	 * All provider presets keyed by slug.
	 *
	 * Each preset: label, host, port, encryption, optional username (forced
	 * value hint), note and docs URL. Empty host means "do not autofill".
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		$providers = array(
			'custom'   => array(
				'label'      => __( 'Custom / Other SMTP', 'flow-smtp' ),
				'host'       => '',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => '',
				'docs'       => '',
			),
			'gmail'    => array(
				'label'      => __( 'Gmail / Google Workspace', 'flow-smtp' ),
				'host'       => 'smtp.gmail.com',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => __( 'Use an App Password (requires 2-Step Verification), not your normal account password. Daily sending limits apply (~500 for Gmail, ~2000 for Workspace).', 'flow-smtp' ),
				'docs'       => 'https://support.google.com/accounts/answer/185833',
			),
			'outlook'  => array(
				'label'      => __( 'Outlook / Microsoft 365', 'flow-smtp' ),
				'host'       => 'smtp.office365.com',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => __( 'SMTP AUTH must be enabled for the mailbox (Microsoft 365 admin center → Active users → Mail → Manage email apps). Accounts with security defaults may require an app password.', 'flow-smtp' ),
				'docs'       => 'https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-smtp-authentication',
			),
			'brevo'    => array(
				'label'      => __( 'Brevo (Sendinblue)', 'flow-smtp' ),
				'host'       => 'smtp-relay.brevo.com',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => __( 'Username is your Brevo account email; password is an SMTP key generated under SMTP & API settings.', 'flow-smtp' ),
				'docs'       => 'https://help.brevo.com/hc/en-us/articles/209462765',
			),
			'sendgrid' => array(
				'label'      => __( 'SendGrid', 'flow-smtp' ),
				'host'       => 'smtp.sendgrid.net',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => 'apikey',
				'note'       => __( 'Username is literally "apikey"; the password is a SendGrid API key with Mail Send permission.', 'flow-smtp' ),
				'docs'       => 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api',
			),
			'mailgun'  => array(
				'label'      => __( 'Mailgun', 'flow-smtp' ),
				'host'       => 'smtp.mailgun.org',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => __( 'Use an SMTP user from your sending domain (e.g. postmaster@mg.example.com). EU-region domains use smtp.eu.mailgun.org.', 'flow-smtp' ),
				'docs'       => 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/#send-via-smtp',
			),
			'ses'      => array(
				'label'      => __( 'Amazon SES', 'flow-smtp' ),
				'host'       => 'email-smtp.us-east-1.amazonaws.com',
				'port'       => 587,
				'encryption' => 'tls',
				'note'       => __( 'Replace us-east-1 with your SES region. Use dedicated SMTP credentials (not your AWS keys) and verify your sending domain first.', 'flow-smtp' ),
				'docs'       => 'https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html',
			),
			'zoho'     => array(
				'label'      => __( 'Zoho Mail', 'flow-smtp' ),
				'host'       => 'smtp.zoho.com',
				'port'       => 465,
				'encryption' => 'ssl',
				'note'       => __( 'Use an application-specific password if two-factor authentication is enabled. Data-center specific hosts: smtp.zoho.eu, smtp.zoho.in.', 'flow-smtp' ),
				'docs'       => 'https://www.zoho.com/mail/help/zoho-smtp.html',
			),
			'cpanel'   => array(
				'label'      => __( 'cPanel / Web host email', 'flow-smtp' ),
				'host'       => '',
				'port'       => 465,
				'encryption' => 'ssl',
				'note'       => __( 'Host is usually mail.yourdomain.com — check Email Accounts → Connect Devices in cPanel. Username is the full email address.', 'flow-smtp' ),
				'docs'       => '',
			),
		);

		/**
		 * Filter the provider preset registry.
		 *
		 * @param array $providers Presets keyed by slug.
		 */
		return apply_filters( 'flowsmtp_providers', $providers );
	}

	/**
	 * Whether a provider slug exists.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] );
	}
}
