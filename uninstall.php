<?php
/**
 * Uninstall routine.
 *
 * Data is KEPT by default — deleting a site's entire email audit trail because
 * someone removed a plugin is rarely what an admin wants. Removal only happens
 * when "Delete all FlowSMTP data on uninstall" was explicitly enabled in the
 * settings.
 *
 * Note: this file runs with none of the plugin's classes loaded, so it is
 * intentionally self-contained.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all FlowSMTP data for the current site.
 */
function flowsmtp_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'flowsmtp_settings' );

	// Opt-in only: keep everything unless the admin asked for a clean removal.
	if ( ! is_array( $settings ) || ! isset( $settings['uninstall_data'] ) || 'delete' !== $settings['uninstall_data'] ) {
		return;
	}

	// Scheduled work.
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'flowsmtp_daily_cleanup' );
		wp_unschedule_hook( 'flowsmtp_retry_email' );
	} else {
		wp_clear_scheduled_hook( 'flowsmtp_daily_cleanup' );
		wp_clear_scheduled_hook( 'flowsmtp_retry_email' );
	}

	// Options and transients.
	delete_option( 'flowsmtp_settings' );
	delete_option( 'flowsmtp_db_version' );
	delete_transient( 'flowsmtp_alert_lock' );

	// Email log table.
	$table = $wpdb->prefix . 'flowsmtp_email_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterised; it is built from $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

if ( is_multisite() ) {
	$flowsmtp_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $flowsmtp_site_ids as $flowsmtp_site_id ) {
		switch_to_blog( (int) $flowsmtp_site_id );
		flowsmtp_uninstall_site();
		restore_current_blog();
	}

	// Network settings mirror the per-site choice: only removed if at least one
	// site opted in to a full cleanup is irrelevant here — the network admin
	// controls this option, so remove it only when nothing is left to configure.
	delete_site_option( 'flowsmtp_network_settings' );
} else {
	flowsmtp_uninstall_site();
}
