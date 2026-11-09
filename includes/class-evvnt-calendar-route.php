<?php

/**
 * Parses the calendar's URL state.
 *
 * With path URLs active (see Evvnt_Calendar_Router) routes come from rewrite query vars and
 * links are built as paths. Otherwise the discovery JS's query parameters are used:
 *   ?_evDiscoveryPath=/                         calendar
 *   ?_evDiscoveryPath=/{category-slug}          calendar filtered by a root category
 *   ?_evDiscoveryPath=/{root}/{sub}/…           calendar filtered by a sub-category
 *   ?_evDiscoveryPath=/categories               list of every category
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
	const SUB_VAR    = 'subcategory';
	const SEARCH_VAR = 'searchTerm';
	const FROM_VAR   = 'fromDate';
	const TO_VAR     = 'toDate';

	/** Path of the category list (reserved: no category can use this slug). */
	const CATEGORIES_PATH = 'categories';

	/** @var string calendar|category|categories|event|not_found */
	public $type = 'calendar';

	/** @var string Event URL id (text before the first "-"), for event routes. */
	public $event_id = '';

	/** @var string Full "{urlId}-{slug}" segment, for event routes. */
	public $event_segment = '';

	/** @var string Root category slug, for category routes. */
	public $category_slug = '';

	/** @var string Slug path below the root ("performing-arts/comedy"), for sub-category routes, or ''. */
	public $subcategory_path = '';

	/** @var int Sub-category filter (?subcategory=id|name, as the discovery JS), or 0. */
	public $subcategory_id = 0;

	/** @var string Sub-category name from the same parameter, for headings. */
	public $subcategory_name = '';

	/** @var string Date range start (Y-m-d, ?fromDate= as the discovery JS), or ''. */
	public $from_date = '';

	/** @var string Date range end (Y-m-d, ?toDate=), or ''. */
	public $to_date = '';

	/** @var string Search text (?searchTerm=, or ?query= as the discovery JS also accepts), or ''. */
	public $search = '';

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
			$view     = (string) get_query_var( Evvnt_Calendar_Router::VIEW_VAR );
			if ( self::CATEGORIES_PATH === $view ) {
				return self::from_query( array( self::PATH_VAR => '/' . self::CATEGORIES_PATH ) );
			}
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
				foreach ( array( self::SUB_VAR, self::SEARCH_VAR, 'query', self::FROM_VAR, self::TO_VAR ) as $passthrough ) {
					if ( '' === $event && isset( $_GET[ $passthrough ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
						$query[ $passthrough ] = wp_unslash( $_GET[ $passthrough ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in from_query().
					}
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

		$from = isset( $query[ self::FROM_VAR ] ) && is_scalar( $query[ self::FROM_VAR ] ) ? self::clean_date( (string) $query[ self::FROM_VAR ] ) : '';
		$to   = isset( $query[ self::TO_VAR ] ) && is_scalar( $query[ self::TO_VAR ] ) ? self::clean_date( (string) $query[ self::TO_VAR ] ) : '';
		if ( '' !== $from && '' === $to ) {
			$to = $from;
		}
		// A range of 0-31 days only: keeps listings meaningful and the cache bounded.
		if ( '' !== $from && $to >= $from && ( strtotime( $to ) - strtotime( $from ) ) <= 31 * DAY_IN_SECONDS ) {
			$route->from_date = $from;
			$route->to_date   = $to;
		}

		foreach ( array( self::SEARCH_VAR, 'query' ) as $search_var ) {
			if ( isset( $query[ $search_var ] ) && is_scalar( $query[ $search_var ] ) ) {
				$route->search = self::clean_search( (string) $query[ $search_var ] );
				break;
			}
		}

		if ( isset( $query[ self::SUB_VAR ] ) && is_scalar( $query[ self::SUB_VAR ] ) ) {
			// "id|name", or several joined with "^" by the discovery JS (the first is used).
			$first = explode( '^', (string) $query[ self::SUB_VAR ] )[0];
			$parts = explode( '|', $first, 2 );
			if ( ctype_digit( $parts[0] ) && (int) $parts[0] > 0 ) {
				$route->subcategory_id   = (int) $parts[0];
				$route->subcategory_name = isset( $parts[1] ) ? mb_substr( sanitize_text_field( $parts[1] ), 0, 100 ) : '';
			}
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
		} elseif ( array( self::CATEGORIES_PATH ) === $parts ) {
			$route->type = 'categories';
			$route->page = 1;
			return $route;
		} elseif ( 'event' !== $parts[0] && count( $parts ) <= 7 && count( $parts ) === count( preg_grep( '/^[a-z0-9-]{1,100}$/', $parts ) ) ) {
			// A root category, or a sub-category by its slug path below the root.
			$route->type             = 'category';
			$route->category_slug    = $parts[0];
			$route->subcategory_path = implode( '/', array_slice( $parts, 1 ) );
			return $route;
		}

		$route->type = 'not_found';
		return $route;
	}

	/**
	 * Full category slug path of a route ("arts" or "arts/performing-arts"), or ''.
	 */
	public function category_path() {
		return '' === $this->subcategory_path ? $this->category_slug : $this->category_slug . '/' . $this->subcategory_path;
	}

	/**
	 * URL of the calendar (optionally a category and page) on the given page URL.
	 *
	 * @param string $base          Permalink of the page holding the calendar.
	 * @param string $category_slug Optional category slug, or a sub-category's slug path ("arts/comedy").
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
	 * URL of the list of every category on the given page URL.
	 *
	 * @param string $base Permalink of the page holding the calendar.
	 * @return string
	 */
	public static function categories_url( $base ) {
		$config = Evvnt_Calendar_Router::config();
		if ( $config && Evvnt_Calendar_Router::is_calendar_base( $base ) ) {
			return home_url( user_trailingslashit( $config['page_path'] . '/' . self::CATEGORIES_PATH ) );
		}
		return self::build( $base, array( self::PATH_VAR => '/' . self::CATEGORIES_PATH ) );
	}

	/**
	 * A valid calendar date as Y-m-d, or ''.
	 */
	public static function clean_date( $value ) {
		return ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ? $value : '';
	}

	/**
	 * Calendar URL for a date range (?fromDate=…&toDate=…, page N).
	 */
	public static function dates_url( $base, $from, $to, $page = 1 ) {
		$url = self::calendar_url( $base, '', $page );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . self::FROM_VAR . '=' . rawurlencode( $from ) . '&' . self::TO_VAR . '=' . rawurlencode( $to );
	}

	/**
	 * Normalise search text: plain text, single spaces, at most 100 characters.
	 */
	public static function clean_search( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 100 ) : substr( $text, 0, 100 );
	}

	/**
	 * Search results URL on the calendar page (?searchTerm=…, page N).
	 */
	public static function search_url( $base, $search, $page = 1 ) {
		$url = self::calendar_url( $base, '', $page );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . self::SEARCH_VAR . '=' . rawurlencode( $search );
	}

	/**
	 * Calendar URL filtered to a sub-category (?subcategory=id|name on top of calendar_url()).
	 */
	public static function subcategory_url( $base, $category_slug, $sub_id, $sub_name, $page = 1 ) {
		$url = self::calendar_url( $base, $category_slug, $page );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . self::SUB_VAR . '=' . (int) $sub_id . '%7C' . rawurlencode( $sub_name );
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
