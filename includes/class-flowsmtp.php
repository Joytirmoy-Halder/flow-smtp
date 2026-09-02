<?php
/**
 * Core plugin loader.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FlowSMTP {

	/**
	 * Singleton instance.
	 *
	 * @var FlowSMTP|null
	 */
	private static $instance = null;

	/**
	 * Mailer component.
	 *
	 * @var FlowSMTP_Mailer
	 */
	public $mailer;

	/**
	 * API mailer component.
	 *
	 * @var FlowSMTP_API_Mailer
	 */
	public $api_mailer;

	/**
	 * Logger component.
	 *
	 * @var FlowSMTP_Logger
	 */
	public $logger;

	/**
	 * Test mode component.
	 *
	 * @var FlowSMTP_Test_Mode
	 */
	public $test_mode;

	/**
	 * Open & click tracking component.
	 *
	 * @var FlowSMTP_Tracking
	 */
	public $tracking;

	/**
	 * Multisite component (only on networks).
	 *
	 * @var FlowSMTP_Multisite|null
	 */
	public $multisite;

	/**
	 * Admin component.
	 *
	 * @var FlowSMTP_Admin|null
	 */
	public $admin;

	/**
	 * Get the singleton.
	 *
	 * @return FlowSMTP
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init();
	}

	private function includes() {
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-providers.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-deliverability.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-logger.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-mailer.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-api-mailer.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-test-mode.php';
		require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-tracking.php';

		if ( is_admin() ) {
			require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-admin.php';
		}

		if ( is_multisite() ) {
			require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-multisite.php';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once FLOWSMTP_DIR . 'includes/class-flowsmtp-cli.php';
		}
	}

	private function init() {
		$this->logger     = new FlowSMTP_Logger();
		$this->mailer     = new FlowSMTP_Mailer( $this->logger );
		$this->api_mailer = new FlowSMTP_API_Mailer();
		$this->test_mode  = new FlowSMTP_Test_Mode();
		$this->tracking   = new FlowSMTP_Tracking();

		if ( is_multisite() ) {
			$this->multisite = new FlowSMTP_Multisite();
		}

		if ( is_admin() ) {
			$this->admin = new FlowSMTP_Admin( $this->logger );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'flowsmtp', 'FlowSMTP_CLI' );
		}
	}

	/**
	 * Get plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'provider'            => 'custom',
			'mailer_type'         => 'smtp',
			'host'                => '',
			'port'                => 587,
			'encryption'          => 'tls',
			'auth'                => 1,
			'username'            => '',
			'password'            => '',
			'api_key'             => '',
			'api_domain'          => '',
			'fallback'            => 0,
			'fallback_host'       => '',
			'fallback_port'       => 587,
			'fallback_encryption' => 'tls',
			'fallback_auth'       => 1,
			'fallback_username'   => '',
			'fallback_password'   => '',
			'from_email'          => get_option( 'admin_email' ),
			'from_name'           => get_option( 'blogname' ),
			'force_from'          => 1,
			'auto_plaintext'      => 1,
			'test_mode'           => 0,
			'test_mode_action'    => 'redirect',
			'test_mode_to'        => get_option( 'admin_email' ),
			'test_mode_allowlist' => '',
			'track_opens'         => 0,
			'track_clicks'        => 0,
			'logging'             => 1,
			'log_body'            => 1,
			'log_retention'       => 30,
			'auto_retry'          => 1,
			'max_retries'         => 3,
			'alerts'              => 0,
			'alert_email'         => get_option( 'admin_email' ),
			'alert_webhook'       => '',
			'uninstall_data'      => 'keep',
		);

		$settings = wp_parse_args( (array) get_option( FLOWSMTP_OPTION_KEY, array() ), $defaults );

		/**
		 * Filter the effective plugin settings.
		 *
		 * Used by the multisite component to enforce a network-wide connection,
		 * and available to developers for environment-specific overrides.
		 *
		 * @param array $settings Effective settings.
		 */
		return apply_filters( 'flowsmtp_settings', $settings );
	}
}
