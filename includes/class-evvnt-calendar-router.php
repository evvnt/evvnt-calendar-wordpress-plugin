<?php

/**
 * Path-based calendar URLs (server mode with pretty permalinks and a Calendar page set):
 *
 *   /{event-base}/{urlId}-{slug}/                 event detail (event base defaults to "event")
 *   /{calendar-page}/category/{slug}/             calendar filtered by a root category
 *   /{calendar-page}/category/{slug}/page/{n}/    ... page n
 *   /{calendar-page}/page/{n}/                    calendar page n
 *
 * Query-string links used by the discovery JS and Evvnt's syndication
 * (?_evDiscoveryPath=..., ?_ev_id=..., &evPage=N) are 301-redirected to these paths.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Router {

	const EVENT_VAR    = 'evvnt_event';
	const CATEGORY_VAR = 'evvnt_category';
	const PAGE_VAR     = 'evvnt_page';
	const SIG_OPTION   = 'evvnt_calendar_rules_sig';

	/**
	 * Path configuration when path URLs are active, else null.
	 *
	 * @return array|null array( 'page_id' => int, 'page_path' => string, 'event_base' => string )
	 */
	public static function config() {
		static $config = false;
		if ( false !== $config && ! defined( 'EVVNT_CALENDAR_TESTING' ) ) {
			return $config;
		}
		$config = null;

		global $wp_rewrite;
		if ( ! Evvnt_Calendar_Public::server_mode() || ! get_option( 'permalink_structure' ) || ( $wp_rewrite && $wp_rewrite->using_index_permalinks() ) ) {
			return $config; // Plain or /index.php/ permalinks: keep query-string URLs.
		}
		$settings = Evvnt_Calendar_Settings::get();
		$page_id  = absint( $settings['calendar_page_id'] );
		$page     = $page_id ? get_post( $page_id ) : null;
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			return $config;
		}

		/**
		 * Filters the first path segment of event URLs (default "event").
		 *
		 * @param string $base Path segment.
		 */
		$event_base = sanitize_title( apply_filters( 'evvnt_calendar_event_base', 'event' ) );

		$page_path  = trim( get_page_uri( $page ), '/' );
		$event_base = '' !== $event_base ? $event_base : 'event';
		if ( self::base_in_use( $event_base ) ) {
			// Something already lives at /{base}/ (a page, or a post type/taxonomy slug such as
			// The Events Calendar's "event"); nest event URLs under the calendar page instead.
			$event_base = $page_path . '/' . $event_base;
		}

		$config = array(
			'page_id'    => $page_id,
			'page_path'  => $page_path,
			'event_base' => $event_base,
			'front_page' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $page_id,
		);
		return $config;
	}

	/**
	 * Whether a top-level path segment is already used by a page, post type or taxonomy.
	 */
	private static function base_in_use( $base ) {
		if ( get_page_by_path( $base ) ) {
			return true;
		}
		foreach ( array_merge( get_post_types( array(), 'objects' ), get_taxonomies( array(), 'objects' ) ) as $object ) {
			if ( ! empty( $object->rewrite['slug'] ) && trim( $object->rewrite['slug'], '/' ) === $base ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a page URL is the calendar page (path URLs apply only there).
	 *
	 * @param string $base Page URL; '' means the calendar page.
	 */
	public static function is_calendar_base( $base ) {
		$config = self::config();
		if ( ! $config ) {
			return false;
		}
		if ( '' === $base ) {
			return true;
		}
		$strip = function ( $url ) {
			return untrailingslashit( preg_replace( '#[?\#].*$#', '', (string) $url ) );
		};
		return $strip( $base ) === $strip( get_permalink( $config['page_id'] ) ) || ( $config['front_page'] && $strip( $base ) === $strip( home_url( '/' ) ) );
	}

	/**
	 * Register rewrite rules and query vars. Hooked on init.
	 */
	public function add_rules() {
		$config = self::config();
		if ( ! $config ) {
			$this->maybe_flush( '' );
			return;
		}

		add_rewrite_tag( '%' . self::EVENT_VAR . '%', '([^/]+)' );
		add_rewrite_tag( '%' . self::CATEGORY_VAR . '%', '([a-z0-9-]+)' );
		add_rewrite_tag( '%' . self::PAGE_VAR . '%', '([0-9]+)' );

		$page = 'index.php?page_id=' . $config['page_id'];
		// WordPress matches rules against the decoded request path, so decode non-ASCII slugs.
		$path  = preg_quote( rawurldecode( $config['page_path'] ), '#' );
		$event = preg_quote( rawurldecode( $config['event_base'] ), '#' );

		add_rewrite_rule( '^' . $event . '/([^/]+)/?$', $page . '&' . self::EVENT_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $path . '/category/([a-z0-9-]+)/page/([0-9]+)/?$', $page . '&' . self::CATEGORY_VAR . '=$matches[1]&' . self::PAGE_VAR . '=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $path . '/category/([a-z0-9-]+)/?$', $page . '&' . self::CATEGORY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $path . '/page/([0-9]+)/?$', $page . '&' . self::PAGE_VAR . '=$matches[1]', 'top' );

		$this->maybe_flush( md5( wp_json_encode( $config ) . '|1' ) );
	}

	/**
	 * Flush rewrite rules once whenever the URL configuration changes.
	 */
	private function maybe_flush( $signature ) {
		if ( (string) get_option( self::SIG_OPTION, '' ) !== $signature ) {
			update_option( self::SIG_OPTION, $signature, true );
			// Soft flush: WordPress rebuilds the rules when it next parses a request, by which
			// time every plugin and the theme have registered theirs.
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * Keep WordPress from "correcting" our path URLs back to the page permalink.
	 */
	public function redirect_canonical( $redirect_url ) {
		if ( get_query_var( self::EVENT_VAR ) || get_query_var( self::CATEGORY_VAR ) || get_query_var( self::PAGE_VAR ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Redirect path URLs that differ from their canonical form only in the trailing slash.
	 *
	 * @param string $canonical Canonical URL for the request.
	 */
	public static function enforce_canonical_path( $canonical ) {
		if ( ! self::config() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_path   = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path compared only.
		$canonical_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		if ( '' !== $request_path && $request_path !== $canonical_path && untrailingslashit( $request_path ) === untrailingslashit( $canonical_path ) ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passed to wp_safe_redirect.
			wp_safe_redirect( $canonical . $query, 301 );
			exit;
		}
	}

	/**
	 * 301 query-string calendar links to their path URLs. Hooked early on template_redirect.
	 */
	public function redirect_query_urls() {
		$config = self::config();
		if ( ! $config || is_admin() || is_preview() || ! in_array( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET', array( 'GET', 'HEAD' ), true ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to constants.
			return;
		}

		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( ! isset( $query[ Evvnt_Calendar_Route::PATH_VAR ] ) && ! isset( $query['_ev_id'] ) && ! isset( $query['_ev_Id'] ) && ! isset( $query[ Evvnt_Calendar_Route::PAGE_VAR ] ) ) {
			return;
		}

		$route    = Evvnt_Calendar_Route::from_query( $query );
		$is_event = null !== $route->legacy_event_id || 'event' === $route->type;
		$on_page  = (int) get_queried_object_id() === $config['page_id'] || ( $config['front_page'] && is_front_page() );
		// Event links redirect from anywhere (Evvnt links point at publisher.url); calendar
		// state only on the calendar page, so other calendar pages keep their own URLs.
		if ( ! $is_event && ! $on_page ) {
			return;
		}
		if ( null !== $route->legacy_event_id || 'event' === $route->type ) {
			$segment = null !== $route->legacy_event_id ? $route->legacy_event_id : $route->event_segment;
			/**
			 * Filters the event segment a query-string link redirects to, so outdated slugs go
			 * straight to the canonical URL in one redirect.
			 *
			 * @param string $segment Requested "{urlId}-{slug}" (or bare id for legacy links).
			 */
			$target = Evvnt_Calendar_Route::event_url( '', apply_filters( 'evvnt_calendar_canonical_event_segment', $segment ) );
		} elseif ( 'category' === $route->type ) {
			$target = Evvnt_Calendar_Route::calendar_url( '', $route->category_slug, $route->page );
		} else {
			$target = Evvnt_Calendar_Route::calendar_url( '', '', $route->page ); // Calendar, or an unparseable path.
		}

		// Keep unrelated arguments (utm_*, etc.); drop the calendar's own and plain-permalink ids.
		$keep = array_diff_key(
			$query,
			array_flip( array( Evvnt_Calendar_Route::PATH_VAR, Evvnt_Calendar_Route::PAGE_VAR, '_ev_id', '_ev_Id', 'page_id', 'p', 'page', 'pagename' ) )
		);
		$keep = array_filter( $keep, 'is_scalar' );
		if ( $keep ) {
			$target = add_query_arg( array_map( 'rawurlencode', $keep ), $target );
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}
}
