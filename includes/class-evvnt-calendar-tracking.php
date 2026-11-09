<?php
/**
 * Snowplow analytics for the server-rendered calendar and widget, tracking what the discovery
 * JS plugin tracks (apps/plugin/src/lib/tracker/EvvntTracker.js) with the same tracker
 * settings, schemas and values:
 *
 * - a page view when a calendar (or widget) first scrolls into view;
 * - event views, batched into one "Event Views" / "group_event_view" structured event
 *   (an iglu:com.evvnt/event_view/jsonschema/2-0-0 context per event) sent on user
 *   activity (every 3 seconds) and when the page is hidden. Cards count when they scroll
 *   into view ("event_list", or "featured" for the featured events), widget tiles as
 *   "event_list", and an event page on load ("event_page");
 * - a link click on an event page's ticket button, with the event's
 *   iglu:com.evvnt/event/jsonschema/1-0-0 context.
 *
 * Every event carries the environment and discovery_plugin contexts. The PHP side marks
 * the markup with data attributes; public/js/evvnt-calendar-tracking.js does the tracking
 * with Snowplow's browser tag (public/js/vendor/snowplow/sp.js, v3, as the JS plugin).
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Tracking {

	/** The discovery plugin's production collector. */
	const COLLECTOR_URL = 'https://com-evvnt-prod1.collector.snplow.net';

	const SCRIPT_HANDLE    = 'evvnt-calendar-tracking';
	const SNOWPLOW_HANDLE  = 'evvnt-calendar-snowplow';
	const SNOWPLOW_VERSION = '3.24.6';

	/** Where an event was seen (event_view schema's viewedFrom). */
	const VIEWED_FROM_LIST     = 'event_list';
	const VIEWED_FROM_FEATURED = 'featured';
	const VIEWED_FROM_PAGE     = 'event_page';

	/** The element id the discovery plugin gives its ticket button (link click elementId). */
	const CTA_ELEMENT_ID = 'event-cta';

	/**
	 * Whether pages track at all.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filters whether the server-rendered calendar and widget send Snowplow analytics
		 * (page views, event views and ticket clicks) to Evvnt. Return false to turn
		 * tracking off, e.g. until a visitor consents to analytics cookies.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'evvnt_calendar_tracking_enabled', true );
	}

	/**
	 * The event context the discovery plugin sends (trackingNormalizer.js): the event's
	 * source as named in iglu:com.evvnt/event/jsonschema/1-0-0, and its occurrence and
	 * event ids there.
	 *
	 * @param Evvnt_Calendar_Event $event Event.
	 * @return array { source, sourceOccurrenceId, sourceEventId }
	 */
	public static function event_data( Evvnt_Calendar_Event $event ) {
		$raw  = $event->raw();
		$core = isset( $raw['core_metadata'] ) && is_array( $raw['core_metadata'] ) ? $raw['core_metadata'] : array();
		if ( ! empty( $core['occurrence_id'] ) ) { // An event from Evvnt's core platform.
			return array(
				'source'             => 'core',
				'sourceOccurrenceId' => self::id_string( $core['occurrence_id'] ),
				'sourceEventId'      => self::id_string( isset( $core['event_id'] ) ? $core['event_id'] : '' ),
			);
		}
		$source = $event->main_source();
		if ( 'evvnt' === $source ) {
			$source = 'marketing';
		} elseif ( 'geotix' === $source ) {
			$source = 'core';
		}
		return array(
			'source'             => $source,
			'sourceOccurrenceId' => self::id_string( isset( $raw['source_id_s'] ) ? $raw['source_id_s'] : '' ),
			'sourceEventId'      => self::id_string( isset( $raw['event_parent_id'] ) ? $raw['event_parent_id'] : '' ),
		);
	}

	/**
	 * Attribute marking an element as one view of an event, or '' when tracking is off.
	 *
	 * @param Evvnt_Calendar_Event $event       Event.
	 * @param string               $viewed_from One of the VIEWED_FROM_* values.
	 * @return string Leading space included, escaped.
	 */
	public static function view_attrs( Evvnt_Calendar_Event $event, $viewed_from ) {
		if ( '' === (string) $viewed_from || ! self::enabled() ) {
			return '';
		}
		return ' data-evvnt-view="' . esc_attr( wp_json_encode( array( 'viewedFrom' => (string) $viewed_from ) + self::event_data( $event ) ) ) . '"';
	}

	/**
	 * Attributes marking an event's ticket button, so a click on it is tracked, or '' when
	 * tracking is off. The tracked target is the event's main link without the attribution
	 * parameters, as the discovery plugin reports it.
	 *
	 * @param Evvnt_Calendar_Event $event Event.
	 * @return string Leading space included, escaped.
	 */
	public static function click_attrs( Evvnt_Calendar_Event $event ) {
		if ( ! self::enabled() ) {
			return '';
		}
		return ' data-evvnt-click="' . esc_attr(
			wp_json_encode(
				array(
					'targetUrl' => $event->main_link(),
					'elementId' => self::CTA_ELEMENT_ID,
					'event'     => self::event_data( $event ),
				)
			)
		) . '"';
	}

	/**
	 * Load the tracker on this page (once): Snowplow's tag and the plugin's script, in the
	 * footer, with the tracker settings and contexts.
	 *
	 * @param array $publisher Publisher (from publisher settings): id and url.
	 */
	public static function enqueue( array $publisher ) {
		static $done = false;
		if ( $done || empty( $publisher['id'] ) || ! self::enabled() ) {
			return; // Once per request (and a theme that dequeues the scripts keeps them off).
		}
		$done = true;
		$base = plugin_dir_url( __DIR__ ) . 'public/js/';
		// The plugin's script queues the commands, then the tag loads and runs them.
		wp_enqueue_script( self::SCRIPT_HANDLE, $base . 'evvnt-calendar-tracking.js', array(), self::asset_version(), true );
		wp_enqueue_script( self::SNOWPLOW_HANDLE, $base . 'vendor/snowplow/sp.js', array( self::SCRIPT_HANDLE ), self::SNOWPLOW_VERSION, true );
		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.evvntCalendarTracking=' . wp_json_encode( self::config( $publisher ) ) . ';', 'before' );
		// The tag claims the first namespace in the shared GlobalSnowplowNamespace list: give it
		// ours alone while it loads, then put back any a site's own Snowplow snippet queued (its
		// tag, loading later, claims those).
		wp_add_inline_script( self::SNOWPLOW_HANDLE, 'window.evvntSnowplowSaved=window.GlobalSnowplowNamespace||[];window.GlobalSnowplowNamespace=["evvntSnowplow"];', 'before' );
		wp_add_inline_script( self::SNOWPLOW_HANDLE, 'window.GlobalSnowplowNamespace=window.evvntSnowplowSaved;try{delete window.evvntSnowplowSaved}catch(e){}', 'after' );
	}

	/**
	 * Tracker settings and contexts for the page.
	 *
	 * @param array $publisher Publisher: id and url.
	 * @return array
	 */
	public static function config( array $publisher ) {
		// Production, as the JS plugin's live build, except on local and development sites.
		$env    = function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ? 'development' : 'production';
		$config = array(
			'collector'   => self::COLLECTOR_URL,
			'trackerName' => 'plugin-tracker',
			'tracker'     => array(
				'appId'                => 'discovery-plugin',
				'discoverRootDomain'   => true,
				'eventMethod'          => 'beacon',
				'stateStorageStrategy' => 'cookieAndLocalStorage',
				'cookieSameSite'       => 'Lax',
				'cookieSecure'         => true,
				'contexts'             => array( 'webPage' => true ),
			),
			'environment' => array(
				'schema' => 'iglu:com.evvnt/environment/jsonschema/1-0-0',
				'data'   => array(
					'env'     => $env,
					'service' => 'Discovery Plugin',
					'version' => defined( 'EVVNT_CALENDAR_VERSION' ) ? EVVNT_CALENDAR_VERSION : '',
				),
			),
			// "type" is added per calendar ("calendar") or widget ("widget") on the page.
			'plugin'      => array(
				'schema' => 'iglu:com.evvnt/discovery_plugin/jsonschema/1-1-0',
				'data'   => array(
					'provider'    => 'marketing',
					'publisherId' => isset( $publisher['id'] ) ? (int) $publisher['id'] : 0,
					'url'         => isset( $publisher['url'] ) && is_string( $publisher['url'] ) ? $publisher['url'] : '',
					'orientation' => 'vertical',
				),
			),
		);
		/**
		 * Filters the Snowplow tracker configuration: collector, tracker settings, and the
		 * environment and discovery_plugin contexts sent with every event.
		 *
		 * @param array $config    Configuration.
		 * @param array $publisher Publisher.
		 */
		return apply_filters( 'evvnt_calendar_tracking_config', $config, $publisher );
	}

	/**
	 * Version for the plugin's script URL: plugin version plus file time, so an edit busts
	 * browser caches.
	 */
	private static function asset_version() {
		$file  = dirname( __DIR__ ) . '/public/js/evvnt-calendar-tracking.js';
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		$base  = defined( 'EVVNT_CALENDAR_VERSION' ) ? EVVNT_CALENDAR_VERSION : '';
		return $mtime ? $base . '.' . $mtime : $base;
	}

	/**
	 * An id as the string the discovery plugin sends.
	 *
	 * @param mixed $value Id.
	 * @return string
	 */
	private static function id_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
