<?php
/**
 * Plugin Name:       FlowSMTP
 * Plugin URI:        https://github.com/Joytirmoy-Halder/flow-smtp
 * Description:       Modern SMTP mailer for WordPress with email logs, failed-email tracking & resend, and a built-in test email system.
 * Version:           0.3.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Joytirmoy Halder Joyti
 * Author URI:        https://github.com/Joytirmoy-Halder
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flow-smtp
 * Network:           true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'FLOWSMTP_VERSION', '0.3.2' );
define( 'FLOWSMTP_FILE', __FILE__ );
define( 'FLOWSMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLOWSMTP_URL', plugin_dir_url( __FILE__ ) );
define( 'FLOWSMTP_OPTION_KEY', 'flowsmtp_settings' );

require_once FLOWSMTP_DIR . 'includes/class-flowsmtp.php';

/**
 * Create or upgrade the email log table for the current site.
 *
 * dbDelta() adds any missing columns, so this doubles as the migration path
 * for sites upgrading from an earlier version.
 */
function flowsmtp_create_tables() {
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
		tracking_id VARCHAR(32) NULL,
		opens INT UNSIGNED NOT NULL DEFAULT 0,
		clicks INT UNSIGNED NOT NULL DEFAULT 0,
		first_open_at DATETIME NULL,
		last_open_at DATETIME NULL,
		last_click_at DATETIME NULL,
		last_click_url TEXT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY created_at (created_at),
		KEY tracking_id (tracking_id)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'flowsmtp_db_version', FLOWSMTP_VERSION );
}

/**
 * Seed sensible defaults for the current site.
 */
function flowsmtp_seed_defaults() {
	add_option(
		FLOWSMTP_OPTION_KEY,
		array(
			'host'            => '',
			'port'            => 587,
			'encryption'      => 'tls',
			'auth'            => 1,
			'username'        => '',
			'password'        => '',
			'from_email'      => get_option( 'admin_email' ),
			'from_name'       => get_option( 'blogname' ),
			'force_from'      => 1,
			'auto_plaintext'  => 1,
			'logging'         => 1,
			'log_body'        => 1,
			'log_retention'   => 30,
		)
	);
}

/**
 * Activation: create the email log table and seed defaults.
 *
 * On a network activation this runs for every existing site, so each site gets
 * its own (isolated) email log table.
 *
 * @param bool $network_wide Whether the plugin was activated network-wide.
 */
function flowsmtp_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 0,
				'update_site_meta_cache' => false,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			flowsmtp_create_tables();
			flowsmtp_seed_defaults();
			restore_current_blog();
		}

		return;
	}

	flowsmtp_create_tables();
	flowsmtp_seed_defaults();
}
register_activation_hook( __FILE__, 'flowsmtp_activate' );

/**
 * Run pending database upgrades after a plugin update (no reactivation needed).
 */
function flowsmtp_maybe_upgrade_db() {
	if ( get_option( 'flowsmtp_db_version' ) === FLOWSMTP_VERSION ) {
		return;
	}

	flowsmtp_create_tables();
}
add_action( 'plugins_loaded', 'flowsmtp_maybe_upgrade_db', 5 );

/**
 * Boot the plugin.
 */
function flowsmtp() {
	return FlowSMTP::instance();
}
add_action( 'plugins_loaded', 'flowsmtp' );
