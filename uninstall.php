<?php

/**
 * Removes the plugin's settings (including the API secret) and cached data
 * when the plugin is deleted from the Plugins screen.
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function evvnt_calendar_uninstall_site() {
	global $wpdb;

	delete_option( 'widget-for-evvnt-calendar-settings' );
	delete_option( 'widget_evvnt_calendar_widget' ); // Saved widget instances.
	delete_option( 'evvnt_calendar_cache_gen' );     // API cache generation counter.
	delete_option( 'evvnt_calendar_rules_sig' );     // Rewrite rule signature.
	delete_option( 'evvnt_calendar_category_tree' ); // Cached category tree.
	wp_clear_scheduled_hook( 'evvnt_calendar_refresh_category_tree' );
	delete_option( 'rewrite_rules' );                // Regenerated without our rules on the next request.

	// Cached category lists: evvnt_calendar_* transients and their timeouts.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_evvnt_calendar_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_evvnt_calendar_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $evvnt_calendar_site_id ) {
		switch_to_blog( $evvnt_calendar_site_id );
		evvnt_calendar_uninstall_site();
		restore_current_blog();
	}
} else {
	evvnt_calendar_uninstall_site();
}
