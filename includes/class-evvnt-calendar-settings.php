<?php

/**
 * Typed access to the plugin's stored settings.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Settings {

	/**
	 * Option name. Kept as-is for backwards compatibility with existing installs.
	 */
	const OPTION = 'widget-for-evvnt-calendar-settings';

	/**
	 * Default values for every stored setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'          => '',
			'api_secret_key'   => '',
			'publisher_id'     => '',
			'partner_name'     => '',
			'submission_label' => 'Promote your event',
			'render_mode'      => 'js', // 'js' (discovery app in the browser) or 'server'.
			'calendar_page_id' => 0,    // Page holding the calendar; enables path URLs in server mode.
			'calendar_layout'  => 'list', // Server mode home view: 'list' (featured + list) or 'rows' (category rows).
			'calendar_width'   => 'wide', // 'wide' (theme's wide width) or 'content' (the content column).
		);
	}

	/**
	 * The stored settings merged over the defaults. Always returns an array.
	 *
	 * @return array
	 */
	public static function get() {
		$options = get_option( self::OPTION, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		return wp_parse_args( $options, self::defaults() );
	}

	/**
	 * The configured publisher ID as a positive integer, or 0 when not configured.
	 *
	 * @return int
	 */
	public static function publisher_id() {
		$settings = self::get();
		return absint( $settings['publisher_id'] );
	}
}
