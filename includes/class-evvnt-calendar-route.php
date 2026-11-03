<?php

/**
 * Parses the calendar's URL state.
 *
 * With path URLs active (see Evvnt_Calendar_Router) routes come from rewrite query vars and
 * links are built as paths. Otherwise the discovery JS's query parameters are used:
 *   ?_evDiscoveryPath=/                         calendar
 *   ?_evDiscoveryPath=/{category-slug}          calendar filtered by a root category
 *   ?_evDiscoveryPath=/event/{urlId}-{slug}     event detail
 *   &evPage=N                                   1-based page of the calendar
 *   ?_ev_id=123                                 legacy event link (redirected)
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Route {

	const PATH_VAR   = '_evDiscoveryPath';
	const PAGE_VAR   = 'evPage';
	const LEGACY_VAR = '_ev_id';
	const MAX_PAGE   = 100;

	/** @var string calendar|category|event|not_found */
	public $type = 'calendar';

	/** @var string Event URL id (text before the first "-"), for event routes. */
	public $event_id = '';

	/** @var string Full "{urlId}-{slug}" segment, for event routes. */
	public $event_segment = '';

	/** @var string Category slug, for category routes. */
	public $category_slug = '';

	/** @var int 1-based page number. */
	public $page = 1;

	/** @var string|null Legacy event id to redirect from (?_ev_id=). */
	public $legacy_event_id = null;

	/**
	 * Route for the current request: rewrite query vars when path URLs are active, else $_GET.
	 *
	 * @return self
	 */
	public static function from_request() {
		if ( Evvnt_Calendar_Router::config() ) {
			$event    = (string) get_query_var( Evvnt_Calendar_Router::EVENT_VAR );
			$category = (string) get_query_var( Evvnt_Calendar_Router::CATEGORY_VAR );
			$page     = (int) get_query_var( Evvnt_Calendar_Router::PAGE_VAR );
			if ( '' !== $event || '' !== $category || $page ) {
				$query = array();
				if ( '' !== $event ) {
					$query[ self::PATH_VAR ] = '/event/' . $event;
				} elseif ( '' !== $category ) {
					$query[ self::PATH_VAR ] = '/' . $category;
				}
				if ( $page && '' === $event ) {
					$query[ self::PAGE_VAR ] = $page;
				}
				return self::from_query( $query );
			}
		}
		return self::from_query( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
	}

	/**
	 * Build a route from request query arguments (unslashed).
	 *
	 * @param array $query Query arguments, e.g. wp_unslash( $_GET ).
	 * @return self
	 */
	public static function from_query( array $query ) {
		$route = new self();

		foreach ( array( '_ev_id', '_ev_Id' ) as $legacy ) {
			if ( isset( $query[ $legacy ] ) && is_scalar( $query[ $legacy ] ) ) {
				$id = str_replace( '_', '-', (string) $query[ $legacy ] );
				if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,199}$/', $id ) ) {
					$route->legacy_event_id = $id;
					return $route;
				}
			}
		}

		if ( isset( $query[ self::PAGE_VAR ] ) && is_scalar( $query[ self::PAGE_VAR ] ) ) {
			$route->page = min( self::MAX_PAGE, max( 1, (int) $query[ self::PAGE_VAR ] ) );
		}

		$path = ( isset( $query[ self::PATH_VAR ] ) && is_scalar( $query[ self::PATH_VAR ] ) ) ? (string) $query[ self::PATH_VAR ] : '';
		$path = trim( rawurldecode( $path ), "/ \t\n\r\0\x0B" );

		if ( '' === $path ) {
			return $route;
		}

		$parts = explode( '/', $path );

		if ( 'event' === $parts[0] && 2 === count( $parts ) ) {
			$segment = $parts[1];
			$id      = strtok( $segment, '-' );
			if ( false !== $id && preg_match( '/^[A-Za-z0-9]{1,64}$/', $id ) && '0' !== $id[0] && strlen( $segment ) <= 300 ) {
				$route->type          = 'event';
				$route->event_id      = $id;
				$route->event_segment = $segment;
				$route->page          = 1;
				return $route;
			}
		} elseif ( 1 === count( $parts ) && 'event' !== $parts[0] && preg_match( '/^[a-z0-9-]{1,100}$/', $parts[0] ) ) {
			$route->type          = 'category';
			$route->category_slug = $parts[0];
			return $route;
		}

		$route->type = 'not_found';
		return $route;
	}

	/**
	 * URL of the calendar (optionally a category and page) on the given page URL.
	 *
	 * @param string $base          Permalink of the page holding the calendar.
	 * @param string $category_slug Optional category slug.
	 * @param int    $page          1-based page.
	 * @return string
	 */
	public static function calendar_url( $base, $category_slug = '', $page = 1 ) {
		$config = Evvnt_Calendar_Router::config();
		if ( $config && Evvnt_Calendar_Router::is_calendar_base( $base ) ) {
			if ( '' === $category_slug && $page <= 1 && $config['front_page'] ) {
				return home_url( '/' );
			}
			$path = $config['page_path'];
			if ( '' !== $category_slug ) {
				$path .= '/category/' . $category_slug;
			}
			if ( $page > 1 ) {
				$path .= '/page/' . (int) $page;
			}
			return home_url( user_trailingslashit( $path ) );
		}

		$args = array();
		if ( '' !== $category_slug ) {
			$args[ self::PATH_VAR ] = '/' . $category_slug;
		}
		if ( $page > 1 ) {
			$args[ self::PAGE_VAR ] = (int) $page;
		}
		return self::build( $base, $args );
	}

	/**
	 * URL of an event detail view on the given page URL.
	 *
	 * @param string $base    Permalink of the page holding the calendar.
	 * @param string $segment "{urlId}-{slug}".
	 * @return string
	 */
	public static function event_url( $base, $segment ) {
		$config = Evvnt_Calendar_Router::config();
		if ( $config ) {
			return home_url( user_trailingslashit( $config['event_base'] . '/' . rawurlencode( $segment ) ) );
		}
		return self::build( $base, array( self::PATH_VAR => '/event/' . $segment ) );
	}

	/**
	 * Append calendar query arguments to the page URL, replacing any existing ones.
	 *
	 * Built by hand (not add_query_arg) so "/" stays unencoded, matching the URLs the
	 * discovery JS produces; values are percent-encoded except for "/".
	 */
	private static function build( $base, array $args ) {
		$url      = remove_query_arg( array( self::PATH_VAR, self::PAGE_VAR, '_ev_id', '_ev_Id' ), $base );
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}
		$url = rtrim( $url, '?&' );
		foreach ( $args as $key => $value ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . str_replace( '%2F', '/', rawurlencode( (string) $value ) );
		}
		return $url . $fragment;
	}

	/**
	 * Slug for a category name, matching the discovery JS.
	 *
	 * @param string $name Category name.
	 * @return string
	 */
	public static function category_slug( $name ) {
		return (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( (string) $name ) ) );
	}
}
