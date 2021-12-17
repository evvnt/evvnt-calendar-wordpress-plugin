<?php

/*
Plugin Name: Evvnt Calendar
Plugin URI: https://evvnt.com/
Description: Provides Evvnt Discovery Plugin for Wordpress
Version: 0.0.1
Author: Evvnt, Inc.
Author URI: https://evvnt.com/
License: GPLv2 or later
Text Domain: evvnt
*/

/*
Evvnt-Calendar is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Evvnt-Calendar is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Evvnt-Calendar. If not, see evvnt.com.
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'EVVNT_CALENDAR_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-evvnt-calendar-activator.php
 */
function activate_evvnt_calendar() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-evvnt-calendar-activator.php';
	Evvnt_Calendar_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-evvnt-calendar-deactivator.php
 */
function deactivate_evvnt_calendar() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-evvnt-calendar-deactivator.php';
	Evvnt_Calendar_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_evvnt_calendar' );
register_deactivation_hook( __FILE__, 'deactivate_evvnt_calendar' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-evvnt-calendar.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 */
function run_evvnt_calendar() {
	$plugin = new Evvnt_Calendar();
	$plugin->run();
}
run_evvnt_calendar();
