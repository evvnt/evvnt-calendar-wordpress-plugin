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
	/** Evvnt's main API: the category tree (as the discovery plugin's MarketingClient). */
	const CATEGORIES_HOST = 'https://api.evvnt.com';
	/** Parallel requests per batch when walking the category tree. */
	const BATCH_SIZE = 20;
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
	 * Events for the compact widget, as the discovery JS widget requests them: the publisher's
	 * featured events first, topped up with other events, 30 in all (the JS then picks a few).
	 *
	 * @param array $filters categoryId, virtual.
	 * @return array|WP_Error { rawEvents }
	 */
	public function widget_events( array $filters ) {
		return $this->get(
			'/publisher/' . $this->publisher_id . '/widget_events',
			array_merge(
				$filters,
				array(
					'multipleEventInstances' => 'true',
					'hitsPerPage'            => 30,
					'widgetNumber'           => 30,
				)
			),
			30 * MINUTE_IN_SECONDS // The API itself caches these for 30 minutes.
		);
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

	/**
	 * Events matching search text (as the discovery JS's search: GET /events?searchTerm=…).
	 *
	 * @param string $term Search text.
	 * @param int    $page Zero-based page.
	 * @param int    $per  Results per page.
	 * @param array  $filters Extra filters (e.g. categoryId, virtual).
	 * @return array|WP_Error { events }
	 */
	public function search_events( $term, $page = 0, $per = self::HITS_PER_PAGE, array $filters = array() ) {
		// Search text is visitor-controlled: lowercase it (fewer near-duplicate cache entries),
		// keep entries only briefly, and never let a failed search pause the whole calendar.
		$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $term ) : strtolower( (string) $term );
		return $this->get(
			'/events',
			array_merge(
				$filters,
				array(
					'searchTerm'             => $term,
					'multipleEventInstances' => 'true',
					'hitsPerPage'            => max( 1, min( 50, (int) $per ) ),
					'page'                   => max( 0, (int) $page ),
				)
			),
			2 * MINUTE_IN_SECONDS,
			true,
			array(
				'hard_ttl' => 5 * MINUTE_IN_SECONDS,
				'backoff'  => false,
			)
		);
	}

	/**
	 * Candidate "Recommended Events" for an event: up to 50 of the publisher's featured events
	 * in its main category (the discovery JS's query, GET /events?categoryId=..&hitsPerPage=50,
	 * limited to featured events with isFeatured=true).
	 *
	 * @param int $category_id Root category id (main_category_path[0]); 0 for any.
	 * @return array|WP_Error { events }
	 */
	public function recommended_events( $category_id ) {
		return $this->get(
			'/events',
			array(
				'categoryId'  => absint( $category_id ) ? absint( $category_id ) : null,
				'hitsPerPage' => 50,
				'isFeatured'  => 'true',
			),
			30 * MINUTE_IN_SECONDS
		);
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
	private function get( $path, array $params, $ttl, $with_publisher = true, array $cache_options = array() ) {
		if ( ! $this->publisher_id ) {
			return new WP_Error( 'evvnt_calendar_unconfigured', 'No publisher ID configured.', array( 'status' => 400 ) );
		}
		$url       = $this->url( $path, $params, $with_publisher );
		$transport = $this->transport;
		return $this->cache->remember(
			$url,
			$ttl,
			function () use ( $url, $transport ) {
				return $transport ? call_user_func( $transport, $url ) : self::fetch( $url );
			},
			$cache_options
		);
	}

	/**
	 * Request URL for an endpoint (publisher_id added, empty values dropped, keys sorted
	 * so equal queries share a cache entry, as the JS client does).
	 */
	private function url( $path, array $params, $with_publisher = true ) {
		if ( $with_publisher ) {
			$params['publisher_id'] = $this->publisher_id;
		}
		$params = array_filter(
			$params,
			function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
		ksort( $params );
		return self::HOST . $path . ( $params ? '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) : '' );
	}

	/**
	 * Upcoming events for several categories at once (the "category rows" layout).
	 * Uncached categories are fetched in parallel; each row is cached like a list page.
	 *
	 * @param int[] $category_ids Root category ids.
	 * @param int   $per_row      Events per category.
	 * @param array $filters      Extra filters (e.g. virtual).
	 * @return array Map of category id => array of raw events, or WP_Error for that row.
	 */
	public function events_by_category( array $category_ids, $per_row = 12, array $filters = array() ) {
		$queries = array();
		foreach ( array_unique( array_map( 'absint', $category_ids ) ) as $id ) {
			if ( $id ) {
				$queries[ $id ] = array_merge( $filters, array( 'categoryId' => $id ) );
			}
		}
		return $this->events_batch( $queries, $per_row );
	}

	/**
	 * Events starting on each of several days (fromDate = toDate = day), fetched in parallel.
	 *
	 * @param string[] $days    Y-m-d dates.
	 * @param int      $per_day Events per day.
	 * @param array    $filters Extra filters (e.g. virtual).
	 * @return array Map of day => array of raw events, or WP_Error for that day.
	 */
	public function events_by_day( array $days, $per_day = 6, array $filters = array() ) {
		$queries = array();
		foreach ( $days as $day ) {
			$queries[ $day ] = array_merge(
				$filters,
				array(
					'fromDate' => $day,
					'toDate'   => $day,
				)
			);
		}
		return $this->events_batch( $queries, $per_day );
	}

	/**
	 * Run several /events queries, fetching uncached ones in parallel. Each result is cached
	 * like a list page (10 minutes).
	 *
	 * @param array $queries Map of key => filters.
	 * @param int   $per     Events per query.
	 * @return array Map of key => array of raw events, or WP_Error for that query.
	 */
	public function events_batch( array $queries, $per ) {
		if ( ! $this->publisher_id || ! $queries ) {
			return array();
		}
		$ttl  = 10 * MINUTE_IN_SECONDS;
		$urls = array();
		foreach ( $queries as $key => $filters ) {
			$urls[ $key ] = $this->url(
				'/events',
				array_merge(
					$filters,
					array(
						'multipleEventInstances' => 'true',
						'hitsPerPage'            => (int) $per,
						'page'                   => 0,
					)
				)
			);
		}

		$missing = array();
		foreach ( $urls as $url ) {
			if ( ! $this->cache->is_fresh( $url, $ttl ) ) {
				$missing[] = $url;
			}
		}
		$fetched = array();
		if ( $missing && ! get_transient( Evvnt_Calendar_Cache::BACKOFF_KEY ) ) {
			if ( $this->transport ) {
				foreach ( $missing as $url ) {
					$fetched[ $url ] = call_user_func( $this->transport, $url );
				}
			} else {
				$fetched = self::fetch_many( $missing );
			}
		}

		$results   = array();
		$transport = $this->transport;
		foreach ( $urls as $key => $url ) {
			if ( isset( $fetched[ $url ] ) && ! is_wp_error( $fetched[ $url ] ) ) {
				// Already fetched in parallel: store it directly. Going through remember() would
				// let a backoff started by another query's failure discard this good response.
				$data = $this->cache->put( $url, $fetched[ $url ] );
			} else {
				$data = $this->cache->remember(
					$url,
					$ttl,
					function () use ( $url, $fetched, $transport ) {
						if ( isset( $fetched[ $url ] ) ) {
							return $fetched[ $url ];
						}
						return $transport ? call_user_func( $transport, $url ) : self::fetch( $url );
					}
				);
			}
			$results[ $key ] = ( ! is_wp_error( $data ) && isset( $data['events'] ) && is_array( $data['events'] ) ) ? $data['events'] : $data;
		}
		return $results;
	}

	/**
	 * Fetch several URLs in parallel with WordPress's bundled Requests library.
	 * Falls back to sequential wp_safe_remote_get() when an HTTP proxy is configured
	 * (proxy settings only apply to the WordPress HTTP API).
	 *
	 * @param string[] $urls URLs on self::HOST.
	 * @return array Map of url => array|WP_Error.
	 */
	/**
	 * Direct sub-categories of several categories (GET api.evvnt.com/categories/{id}, which
	 * answers [{ id, name }] for one id), fetched in parallel batches and not cached here:
	 * Evvnt_Calendar_Category_Tree caches the assembled tree.
	 *
	 * @param int[] $ids Category ids.
	 * @return array Each id => array of { id, name } or WP_Error.
	 */
	public function category_children( array $ids ) {
		$urls = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id ) {
				$urls[ $id ] = self::CATEGORIES_HOST . '/categories/' . $id;
			}
		}
		$results = array();
		foreach ( array_chunk( $urls, self::BATCH_SIZE, true ) as $chunk ) {
			if ( $this->transport ) {
				$fetched = array();
				foreach ( $chunk as $url ) {
					$fetched[ $url ] = call_user_func( $this->transport, $url );
				}
			} else {
				$fetched = self::fetch_many( array_values( $chunk ) );
			}
			foreach ( $chunk as $id => $url ) {
				$results[ $id ] = isset( $fetched[ $url ] ) ? $fetched[ $url ] : new WP_Error( 'evvnt_calendar_http', 'Request failed.', array( 'status' => 0 ) );
			}
		}
		return $results;
	}

	/**
	 * Direct sub-categories of one category, cached for a day (resolving one URL before the
	 * full tree is built).
	 *
	 * @param int $id Category id.
	 * @return array|WP_Error [{ id, name }]
	 */
	public function category_children_cached( $id ) {
		$url       = self::CATEGORIES_HOST . '/categories/' . absint( $id );
		$transport = $this->transport;
		return $this->cache->remember(
			$url,
			DAY_IN_SECONDS,
			function () use ( $url, $transport ) {
				return $transport ? call_user_func( $transport, $url ) : self::fetch( $url );
			},
			array( 'backoff' => false )
		);
	}

	public static function fetch_many( array $urls ) {
		$results = array();
		$class   = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
		// The batch bypasses the WordPress HTTP API, so use it only when nothing would change how
		// WordPress makes requests: proxies, blocked external hosts, or request filters.
		$policy = defined( 'WP_PROXY_HOST' )
			|| ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL )
			|| has_filter( 'pre_http_request' )
			|| has_filter( 'http_request_args' )
			|| has_filter( 'https_ssl_verify' )
			|| has_action( 'http_api_curl' );
		if ( '' === $class || $policy || count( $urls ) < 2 ) {
			foreach ( $urls as $url ) {
				$results[ $url ] = self::fetch( $url );
			}
			return $results;
		}

		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array( 'Accept' => 'application/json' ),
			);
		}
		try {
			$responses = $class::request_multiple(
				$requests,
				array(
					'timeout'   => self::TIMEOUT,
					'useragent' => 'evvnt-calendar-wordpress/' . ( defined( 'EVVNT_CALENDAR_VERSION' ) ? EVVNT_CALENDAR_VERSION : 'dev' ) . '; ' . home_url( '/' ),
					'verify'    => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				)
			);
		} catch ( Exception $e ) {
			foreach ( $urls as $url ) {
				$results[ $url ] = self::fetch( $url );
			}
			return $results;
		}

		foreach ( $urls as $url ) {
			$response = isset( $responses[ $url ] ) ? $responses[ $url ] : null;
			if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
				$results[ $url ] = new WP_Error( 'evvnt_calendar_http', is_object( $response ) && method_exists( $response, 'getMessage' ) ? $response->getMessage() : 'Request failed.', array( 'status' => 0 ) );
				continue;
			}
			if ( 200 !== (int) $response->status_code ) {
				$results[ $url ] = new WP_Error( 'evvnt_calendar_http', 'Evvnt API returned HTTP ' . (int) $response->status_code, array( 'status' => (int) $response->status_code ) );
				continue;
			}
			$data            = json_decode( $response->body, true );
			$results[ $url ] = is_array( $data ) ? $data : new WP_Error( 'evvnt_calendar_json', 'Evvnt API returned invalid JSON.', array( 'status' => 0 ) );
		}
		return $results;
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
