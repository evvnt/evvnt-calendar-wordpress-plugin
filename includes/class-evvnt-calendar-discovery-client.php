<?php

/**
 * Client for Evvnt's public discovery API (the API the discovery JS uses).
 *
 * Requests are keyed by publisher ID only; no secret is sent.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Discovery_Client {

	const HOST           = 'https://discovery.evvnt.com/api';
	const TIMEOUT        = 6;
	const HITS_PER_PAGE  = 30;

	private $publisher_id;
	private $cache;

	/**
	 * Optional transport override (used by tests): callable( $url ) => array|WP_Error.
	 *
	 * @var callable|null
	 */
	private $transport;

	public function __construct( $publisher_id, Evvnt_Calendar_Cache $cache, $transport = null ) {
		$this->publisher_id = absint( $publisher_id );
		$this->cache        = $cache;
		$this->transport    = is_callable( $transport ) ? $transport : null;
	}

	public function publisher_id() {
		return $this->publisher_id;
	}

	/**
	 * Publisher name, URL, root categories, theme and submission settings.
	 *
	 * @return array|WP_Error
	 */
	public function publisher_settings() {
		return $this->get( '/publisher/' . $this->publisher_id . '/publisher_settings', array(), 12 * HOUR_IN_SECONDS, false );
	}

	/**
	 * First page of the calendar: events plus featured events.
	 *
	 * @param array $filters Search filters (categoryId, subCategoryIds, ...).
	 * @return array|WP_Error { rawEvents, rawFeaturedEvents, rawEditorsPickEvents, rawFacets }
	 */
	public function home_page_events( array $filters ) {
		return $this->get(
			'/publisher/' . $this->publisher_id . '/home_page_events',
			$this->list_params( $filters, 0 ),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * A further page of events (page is zero-based).
	 *
	 * @param array $filters Search filters.
	 * @param int   $page    Zero-based page.
	 * @return array|WP_Error { events }
	 */
	public function events( array $filters, $page ) {
		return $this->get( '/events', $this->list_params( $filters, $page ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * One event by its URL id (source id plus source suffix, e.g. "3743362" or "1234t").
	 *
	 * @param string $url_id Event URL id.
	 * @return array|WP_Error { rawEvent, editorialContent, signedStaticMapUrl, ... }; 404 status when missing.
	 */
	public function event( $url_id ) {
		return $this->get( '/events/' . rawurlencode( $url_id ), array(), HOUR_IN_SECONDS );
	}

	private function list_params( array $filters, $page ) {
		return array_merge(
			$filters,
			array(
				'multipleEventInstances' => 'true',
				'hitsPerPage'            => self::HITS_PER_PAGE,
				'page'                   => max( 0, (int) $page ),
			)
		);
	}

	/**
	 * GET a discovery endpoint through the cache.
	 *
	 * @param string $path           Path below HOST.
	 * @param array  $params         Query parameters.
	 * @param int    $ttl            Soft TTL in seconds.
	 * @param bool   $with_publisher Whether to add publisher_id to the query.
	 * @return array|WP_Error
	 */
	private function get( $path, array $params, $ttl, $with_publisher = true ) {
		if ( ! $this->publisher_id ) {
			return new WP_Error( 'evvnt_calendar_unconfigured', 'No publisher ID configured.', array( 'status' => 400 ) );
		}
		if ( $with_publisher ) {
			$params['publisher_id'] = $this->publisher_id;
		}
		$params = array_filter(
			$params,
			function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
		ksort( $params ); // Stable cache keys, as the JS client does.

		$url = self::HOST . $path . ( $params ? '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) : '' );

		$transport = $this->transport;
		return $this->cache->remember(
			$url,
			$ttl,
			function () use ( $url, $transport ) {
				return $transport ? call_user_func( $transport, $url ) : self::fetch( $url );
			}
		);
	}

	/**
	 * Perform the HTTP request and decode JSON.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	public static function fetch( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'evvnt-calendar-wordpress/' . ( defined( 'EVVNT_CALENDAR_VERSION' ) ? EVVNT_CALENDAR_VERSION : 'dev' ) . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'evvnt_calendar_http', $response->get_error_message(), array( 'status' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'evvnt_calendar_http', 'Evvnt API returned HTTP ' . $status, array( 'status' => $status ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'evvnt_calendar_json', 'Evvnt API returned invalid JSON.', array( 'status' => 0 ) );
		}
		return $data;
	}
}
