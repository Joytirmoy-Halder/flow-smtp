<?php
/**
 * Plugin Name:       FlowSMTP
 * Plugin URI:        https://github.com/Joytirmoy-Halder/flow-smtp
 * Description:       Modern SMTP mailer for WordPress with email logs, failed-email tracking & resend, and a built-in test email system.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Joytirmoy Halder Joyti
 * Author URI:        https://github.com/Joytirmoy-Halder
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flow-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'FLOWSMTP_VERSION', '0.1.0' );
define( 'FLOWSMTP_FILE', __FILE__ );
define( 'FLOWSMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLOWSMTP_URL', plugin_dir_url( __FILE__ ) );
define( 'FLOWSMTP_OPTION_KEY', 'flowsmtp_settings' );

require_once FLOWSMTP_DIR . 'includes/class-flowsmtp.php';

/**
 * Activation: create the email log table.
 */
function flowsmtp_activate() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = $wpdb->prefix . 'flowsmtp_email_log';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		mail_to TEXT NOT NULL,
		subject TEXT NOT NULL,
		message LONGTEXT NULL,
		headers TEXT NULL,
		attachments TEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		error_message TEXT NULL,
		is_test TINYINT(1) NOT NULL DEFAULT 0,
		retries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql );

	add_option( 'flowsmtp_db_version', FLOWSMTP_VERSION );

	// Sensible defaults on first activation.
	add_option(
		FLOWSMTP_OPTION_KEY,
		array(
			'host'           => '',
			'port'           => 587,
			'encryption'     => 'tls',
			'auth'           => 1,
			'username'       => '',
			'password'       => '',
			'from_email'     => get_option( 'admin_email' ),
			'from_name'      => get_option( 'blogname' ),
			'force_from'     => 1,
			'logging'        => 1,
			'log_retention'  => 30,
		)
	);
}
register_activation_hook( __FILE__, 'flowsmtp_activate' );

/**
 * Boot the plugin.
 */
function flowsmtp() {
	return FlowSMTP::instance();
}
add_action( 'plugins_loaded', 'flowsmtp' );
