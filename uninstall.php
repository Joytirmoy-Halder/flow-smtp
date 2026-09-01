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
 * Whether the current site opted in to a full data removal.
 *
 * @return bool
 */
function flowsmtp_should_delete_data() {
	$settings = get_option( 'flowsmtp_settings' );

	return is_array( $settings ) && isset( $settings['uninstall_data'] ) && 'delete' === $settings['uninstall_data'];
}

/**
 * Remove all FlowSMTP data for the current site.
 *
 * @return bool Whether anything was removed.
 */
function flowsmtp_uninstall_site() {
	global $wpdb;

	// Opt-in only: keep everything unless the admin asked for a clean removal.
	if ( ! flowsmtp_should_delete_data() ) {
		return false;
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

	return true;
}

if ( is_multisite() ) {
	$flowsmtp_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	$flowsmtp_main_site_opted_in = false;
	$flowsmtp_main_site_id       = function_exists( 'get_main_site_id' ) ? get_main_site_id() : 1;

	foreach ( $flowsmtp_site_ids as $flowsmtp_site_id ) {
		switch_to_blog( (int) $flowsmtp_site_id );

		$flowsmtp_removed = flowsmtp_uninstall_site();
		if ( (int) $flowsmtp_site_id === (int) $flowsmtp_main_site_id ) {
			$flowsmtp_main_site_opted_in = $flowsmtp_removed;
		}

		restore_current_blog();
	}

	// The network settings belong to the network admin, so they are only removed
	// when the main site (where the network admin manages the plugin) opted in.
	if ( $flowsmtp_main_site_opted_in ) {
		delete_site_option( 'flowsmtp_network_settings' );
	}
} else {
	flowsmtp_uninstall_site();
}
