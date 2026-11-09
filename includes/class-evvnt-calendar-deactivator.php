<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 * @author     Derek Graham <derek@evvnt.com>
 */

class Evvnt_Calendar_Deactivator {

	/**
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::reset_rules();
				restore_current_blog();
			}
			return;
		}
		self::reset_rules();
	}

	/**
	 * Drop the calendar's rewrite rules (WordPress regenerates them on the next request) and its
	 * scheduled category-tree refresh.
	 */
	private static function reset_rules() {
		wp_clear_scheduled_hook( 'evvnt_calendar_refresh_category_tree' ); // Background category-tree refresh.
		delete_option( 'evvnt_calendar_rules_sig' );
		delete_option( 'rewrite_rules' );
	}

}
