<?php
/**
 * FlowSMTP uninstall: remove options, cron and the log table.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the log table.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'flowsmtp_email_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove options.
delete_option( 'flowsmtp_settings' );
delete_option( 'flowsmtp_db_version' );

// Clear scheduled cleanup.
wp_clear_scheduled_hook( 'flowsmtp_daily_cleanup' );
