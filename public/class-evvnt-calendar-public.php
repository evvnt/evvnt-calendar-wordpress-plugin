<?php

/**
 * The public-facing functionality of the plugin.
 *
 * Renders the Evvnt discovery embed for the [evvnt] shortcode and the widget.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Public {

	const SCRIPT_HANDLE = 'evvnt-discovery';
	const SCRIPT_URL    = 'https://discovery.evvnt.com/prd/evvnt_discovery_plugin-latest.min.js';

	const STYLE_HANDLE  = 'evvnt-calendar';

	private $plugin_name;
	private $version;

	/** @var Evvnt_Calendar_Renderer|null */
	private $renderer;

	/** @var Evvnt_Calendar_Route|null Route resolved for this request (server mode). */
	private $route;

	/** @var Evvnt_Calendar_Event|null Event resolved for this request (server mode). */
	private $event;

	/** @var string ''|'event_missing'|'unavailable' */
	private $state = '';

	/** @var bool Whether resolve() ran for the current request. */
	private $resolved = false;

	/** @var self|null The instance hooked into WordPress (the widget class renders through it). */
	private static $instance = null;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		self::$instance    = $this;
	}

	/**
	 * The instance hooked into WordPress, or a new one.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self( 'evvnt-calendar', defined( 'EVVNT_CALENDAR_VERSION' ) ? EVVNT_CALENDAR_VERSION : '0' );
		}
		return self::$instance;
	}

	/**
	 * Server-rendered compact widget, for a [evvnt config_type="widget"] shortcode or a
	 * WordPress widget set to the "Widget" configuration type.
	 *
	 * @param array $atts Raw display options.
	 * @return string HTML.
	 */
	public static function render_widget( $atts ) {
		$atts = wp_parse_args( is_array( $atts ) ? $atts : array(), self::display_defaults() );
		$self = self::instance();
		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			$self->enqueue_styles(); // Not in the head on this page: printed in the footer instead.
		}
		Evvnt_Calendar_Tracking::enqueue( $self->renderer()->publisher() );
		return $self->renderer()->render_widget(
			array(
				'detail_page_enabled' => self::to_bool( $atts['detail_page_enabled'] ),
				'virtual'             => self::to_bool( $atts['virtual'] ),
				'category_id'         => absint( $atts['category_id'] ),
			)
		);
	}

	/**
	 * Load the stylesheet in the head on pages that show a server-rendered widget: an active
	 * WordPress widget of the "Widget" type, or a widget shortcode in the post. Hooked on
	 * wp_enqueue_scripts.
	 */
	public function enqueue_widget_styles() {
		if ( ! self::server_mode() || wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}
		/**
		 * Filters whether this page shows a server-rendered widget (so its stylesheet loads in the
		 * head). For widgets placed where the plugin cannot see them, e.g. page builders.
		 *
		 * @param bool $needed Whether a widget was found in a widget area or the post.
		 */
		if ( apply_filters( 'evvnt_calendar_page_has_widget', self::page_has_widget() ) ) {
			$this->enqueue_styles();
		}
	}

	/**
	 * Whether the current page shows a server-rendered widget: a "Widget"-type Evvnt widget,
	 * or a widget shortcode in a block widget, in an active widget area; or a widget shortcode
	 * in the post (including synced patterns it uses).
	 */
	private static function page_has_widget() {
		$shortcode = '/\[evvnt\b[^\]]*config_type=["\']?widget/';
		$evvnt     = (array) get_option( 'widget_evvnt_calendar_widget', array() );
		$blocks    = (array) get_option( 'widget_block', array() );
		foreach ( wp_get_sidebars_widgets() as $sidebar => $ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $ids ) || ! is_active_sidebar( $sidebar ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( preg_match( '/^evvnt_calendar_widget-(\d+)$/', (string) $id, $m ) && isset( $evvnt[ $m[1] ]['config_type'] ) && 'widget' === $evvnt[ $m[1] ]['config_type'] ) {
					return true;
				}
				if ( preg_match( '/^block-(\d+)$/', (string) $id, $m ) && isset( $blocks[ $m[1] ]['content'] ) && preg_match( $shortcode, (string) $blocks[ $m[1] ]['content'] ) ) {
					return true;
				}
			}
		}
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof WP_Post ) {
			$content = $post->post_content;
			if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, 'wp:block' ) ) {
				foreach ( parse_blocks( $content ) as $block ) {
					if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) && 'publish' === get_post_status( (int) $block['attrs']['ref'] ) ) {
						$content .= "\n" . get_post_field( 'post_content', (int) $block['attrs']['ref'] );
					}
				}
			}
			if ( false !== strpos( $content, '[evvnt' ) && preg_match( $shortcode, $content ) ) {
				return true;
			}
		}
		return false;
	}

	public function register_shortcode() {
		add_shortcode( 'evvnt', array( $this, 'build_shortcode' ) );
	}

	/**
	 * Whether pages are rendered on the server (opt-in) rather than by the discovery JS.
	 */
	public static function server_mode() {
		$settings = Evvnt_Calendar_Settings::get();
		return 'server' === $settings['render_mode'] && Evvnt_Calendar_Settings::publisher_id() > 0;
	}

	/**
	 * Attributes of the first calendar-mode [evvnt] shortcode on a post, or null if it has none.
	 *
	 * Looks in the post content and in synced patterns (reusable blocks) it references.
	 * Shortcodes placed elsewhere (templates, widgets, page builders) can be reported with
	 * the evvnt_calendar_page_atts filter so their routes get proper status codes and head tags.
	 *
	 * @param WP_Post $post Post.
	 * @return array|null Normalized array( detail_page_enabled => bool, virtual => bool, category_id => int ).
	 */
	public static function page_calendar_atts( WP_Post $post ) {
		$content = $post->post_content;
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, 'wp:block' ) ) {
			foreach ( parse_blocks( $content ) as $block ) {
				if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
					$synced = get_post( (int) $block['attrs']['ref'] );
					if ( $synced instanceof WP_Post && 'publish' === $synced->post_status ) {
						$content .= "\n" . $synced->post_content;
					}
				}
			}
		}

		$found = null;
		if ( false !== strpos( $content, '[evvnt' ) && preg_match_all( '/' . get_shortcode_regex( array( 'evvnt' ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue; // Escaped [[evvnt]].
				}
				$atts = shortcode_atts( self::display_defaults(), (array) shortcode_parse_atts( $match[3] ), 'evvnt' );
				if ( 'widget' !== $atts['config_type'] ) {
					$found = self::normalize_server_atts( $atts );
					break;
				}
			}
		}

		/**
		 * Filters the calendar shortcode attributes detected for a post (null when none).
		 *
		 * @param array|null $found Normalized attributes or null.
		 * @param WP_Post    $post  Queried post.
		 */
		return apply_filters( 'evvnt_calendar_page_atts', $found, $post );
	}

	private static function normalize_server_atts( array $atts ) {
		$layout = isset( $atts['layout'] ) && in_array( $atts['layout'], array( 'list', 'rows' ), true ) ? $atts['layout'] : '';
		if ( '' === $layout ) {
			$settings = Evvnt_Calendar_Settings::get();
			$layout   = 'rows' === $settings['calendar_layout'] ? 'rows' : 'list';
		}
		return array(
			'detail_page_enabled' => self::to_bool( $atts['detail_page_enabled'] ),
			'virtual'             => self::to_bool( $atts['virtual'] ),
			'category_id'         => absint( $atts['category_id'] ),
			'layout'              => $layout,
		);
	}

	/**
	 * Resolve the calendar route before any output, so the response can carry the right
	 * status code (301/404/503), cache headers and <head> tags. Hooked on template_redirect.
	 */
	public function resolve() {
		if ( ! self::server_mode() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$atts = self::page_calendar_atts( $post );
		if ( null === $atts ) {
			return;
		}

		$this->resolved = true;
		$base           = get_permalink( $post );
		$renderer       = $this->renderer();
		$seo            = new Evvnt_Calendar_Seo();
		$this->route    = Evvnt_Calendar_Route::from_request();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		if ( null !== $this->route->legacy_event_id ) {
			wp_safe_redirect( Evvnt_Calendar_Route::event_url( $base, $this->route->legacy_event_id ), 301 );
			exit;
		}

		if ( 'event' === $this->route->type ) {
			$this->load_event();
			if ( 'event_missing' === $this->state ) {
				status_header( 404 );
				nocache_headers();
				$seo->noindex_only();
				return;
			}
			if ( 'unavailable' === $this->state ) {
				$this->send_unavailable( $seo );
				return;
			}
			if ( $this->event->slug() !== $this->route->event_segment ) {
				wp_safe_redirect( Evvnt_Calendar_Route::event_url( $base, $this->event->slug() ), 301 );
				exit;
			}
			$canonical = Evvnt_Calendar_Route::event_url( $base, $this->event->slug() );
			add_filter( 'body_class', array( $this, 'event_body_class' ) );
			Evvnt_Calendar_Router::enforce_canonical_path( $canonical );
			$seo->for_event( $this->event, $canonical );
			return;
		}

		if ( 'not_found' === $this->route->type ) {
			status_header( 404 );
			$seo->noindex_only();
			return;
		}

		if ( 'categories' === $this->route->type ) {
			// The category list: not on a calendar pinned to one category (nor without categories).
			$url = $renderer->categories_url( $base, $atts );
			if ( '' === $url ) {
				status_header( 404 );
				$seo->noindex_only();
				return;
			}
			Evvnt_Calendar_Router::enforce_canonical_path( $url );
			$seo->for_listing( __( 'All categories', 'evvnt-calendar' ), $url );
			return;
		}

		$category = null;
		$sub      = null;
		if ( 'category' === $this->route->type && ! $atts['category_id'] ) {
			$category = $renderer->category_by_slug( $this->route->category_slug );
			if ( ! $category && $renderer->categories() ) {
				status_header( 404 );
				$seo->noindex_only();
				return;
			}
			if ( $category && '' !== $this->route->subcategory_path ) {
				$sub = $renderer->subcategory( $this->route );
				if ( is_wp_error( $sub ) ) {
					$this->state = 'unavailable';
					$this->send_unavailable( $seo );
					return;
				}
				if ( ! $sub ) {
					status_header( 404 );
					$seo->noindex_only();
					return;
				}
			}
		}

		// A filtered link (…/arts/?subcategory=39|Visual Arts, as the discovery JS makes) moves
		// to the sub-category's own page once the tree knows it.
		if ( $this->route->subcategory_id && ! $sub && ! $atts['category_id'] && '' === $this->route->search && '' === $this->route->from_date ) {
			$node = $renderer->category_tree()->by_id( $this->route->subcategory_id );
			if ( $node && $node['depth'] > 0 && ( ! $category || $category['id'] === $node['root'] ) ) {
				$keep   = array_diff_key( wp_unslash( $_GET ), array_flip( array( Evvnt_Calendar_Route::SUB_VAR, Evvnt_Calendar_Route::PATH_VAR, Evvnt_Calendar_Route::PAGE_VAR, 'page_id', 'p', 'page', 'pagename' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
				$target = Evvnt_Calendar_Renderer::category_url( $node, $base, $this->route->page );
				$keep   = array_filter( $keep, 'is_scalar' );
				wp_safe_redirect( $keep ? add_query_arg( array_map( 'rawurlencode', $keep ), $target ) : $target, 301 );
				exit;
			}
		}
		$category_path = $sub ? $sub['path'] : ( $category ? $category['slug'] : '' );

		// Fetch the list now (it is memoised for the shortcode) so an outage can be a 503.
		$listing = $renderer->listing( $this->route, $atts );
		if ( is_wp_error( $listing ) ) {
			$this->state = 'unavailable';
			$this->send_unavailable( $seo );
			return;
		}
		if ( $this->route->page > 1 && ! $listing['raw'] ) {
			status_header( 404 ); // Past the last page.
			$seo->noindex_only();
			return;
		}

		Evvnt_Calendar_Router::enforce_canonical_path( Evvnt_Calendar_Route::calendar_url( $base, $category_path, $this->route->page ) );

		if ( ( $this->route->subcategory_id && ! $sub ) || '' !== $this->route->search || '' !== $this->route->from_date ) {
			// Sub-category filters and search results are navigation aids: keep them out of search
			// indexes. Sub-category pages (…/category/arts/comedy/) are indexed like categories.
			$seo->noindex_only();
		}

		if ( 'category' === $this->route->type && $atts['category_id'] ) {
			// A pinned calendar has no root-category navigation; consolidate on the page itself.
			$seo->for_listing( '', Evvnt_Calendar_Route::calendar_url( $base, '', $this->route->page ) );
		} elseif ( $category || $this->route->page > 1 || '' !== $this->route->search || '' !== $this->route->from_date ) {
			$title = $sub ? $sub['name'] : ( $category ? $category['name'] : get_the_title( $post ) );
			if ( '' !== $this->route->from_date ) {
				$title = $this->renderer()->dates_label( $this->route->from_date, $this->route->to_date );
			}
			if ( '' !== $this->route->search ) {
				/* translators: %s: search text. */
				$title = sprintf( __( 'Search: %s', 'evvnt-calendar' ), $this->route->search );
			}
			if ( $this->route->page > 1 ) {
				/* translators: 1: listing title, 2: page number. */
				$title = sprintf( __( '%1$s – Page %2$d', 'evvnt-calendar' ), $title, $this->route->page );
			}
			$seo->for_listing( $title, Evvnt_Calendar_Route::calendar_url( $base, $category_path, $this->route->page ) );
		}
	}

	/**
	 * REST endpoint for search suggestions: GET /wp-json/evvnt-calendar/v1/suggest?q=…&base=…
	 * Public and read-only; returns matching categories and up to 5 events. Hooked on rest_api_init.
	 */
	public function register_rest_routes() {
		if ( ! self::server_mode() ) {
			return;
		}
		register_rest_route(
			'evvnt-calendar/v1',
			'/suggest',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'base'     => array( 'type' => 'string' ),
					'category' => array( 'type' => 'integer' ),
					'virtual'  => array( 'type' => 'boolean' ),
				),
				'callback'            => array( $this, 'rest_suggest' ),
			)
		);
	}

	public function rest_suggest( WP_REST_Request $request ) {
		// Public endpoint: at most 60 lookups per minute per visitor, so it cannot be used to
		// hammer the Evvnt API or fill the cache.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit = 'evvnt_calendar_rl_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $limit );
		if ( $count >= (int) apply_filters( 'evvnt_calendar_suggest_rate_limit', 60 ) ) {
			return new WP_Error( 'evvnt_calendar_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		set_transient( $limit, $count + 1, 2 * MINUTE_IN_SECONDS );

		$term = Evvnt_Calendar_Route::clean_search( (string) $request->get_param( 'q' ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term ) ) < 2 ) {
			return rest_ensure_response( array( 'categories' => array(), 'events' => array(), 'all_url' => '' ) );
		}
		// Links are built on the calendar page the request came from, if it is on this site;
		// otherwise on the configured Calendar page, else the home page.
		$base   = (string) $request->get_param( 'base' );
		$config = Evvnt_Calendar_Router::config();
		$home   = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $base || wp_parse_url( $base, PHP_URL_HOST ) !== $home || ! wp_http_validate_url( $base ) ) {
			$settings = Evvnt_Calendar_Settings::get();
			$base     = $settings['calendar_page_id'] ? get_permalink( $settings['calendar_page_id'] ) : home_url( '/' );
		}
		$atts     = array(
			'category_id' => absint( $request->get_param( 'category' ) ),
			'virtual'     => (bool) $request->get_param( 'virtual' ),
		);
		$response = rest_ensure_response( $this->renderer()->suggestions( $term, $base, $atts ) );
		$response->header( 'Cache-Control', 'public, max-age=120' );
		return $response;
	}

	/**
	 * Marks event pages so the stylesheet can give the hero the full page width and hide the
	 * page's own title (the event title is the page heading). Hooked on body_class.
	 */
	public function event_body_class( $classes ) {
		$classes[] = 'evvnt-cal-event-page';
		/**
		 * Whether to hide the theme's page title on event pages (default true).
		 *
		 * @param bool $hide Hide the page title.
		 */
		if ( apply_filters( 'evvnt_calendar_hide_page_title', true ) ) {
			$classes[] = 'evvnt-cal-hide-page-title';
		}
		return $classes;
	}

	/**
	 * Canonical "{urlId}-{slug}" for a requested event segment (used by query-string redirects).
	 * Hooked on evvnt_calendar_canonical_event_segment.
	 */
	public function canonical_event_segment( $segment ) {
		$route = Evvnt_Calendar_Route::from_query( array( Evvnt_Calendar_Route::PATH_VAR => '/event/' . $segment ) );
		if ( 'event' !== $route->type ) {
			return $segment;
		}
		$response = $this->client()->event( $route->event_id );
		if ( is_wp_error( $response ) || empty( $response['rawEvent'] ) || ! is_array( $response['rawEvent'] ) ) {
			return $segment; // The path URL will report the 404/503 itself.
		}
		return ( new Evvnt_Calendar_Event( $response['rawEvent'], $this->renderer()->publisher() ) )->slug();
	}

	/**
	 * Fetch the routed event into $this->event, or set $this->state.
	 */
	private function load_event() {
		$response = $this->client()->event( $this->route->event_id );
		if ( is_wp_error( $response ) || empty( $response['rawEvent'] ) || ! is_array( $response['rawEvent'] ) ) {
			$status      = is_wp_error( $response ) ? Evvnt_Calendar_Cache::error_status( $response ) : 404;
			$this->state = in_array( $status, array( 400, 404, 410 ), true ) ? 'event_missing' : 'unavailable';
			return;
		}
		$extra = $response;
		unset( $extra['rawEvent'] );
		$this->event = new Evvnt_Calendar_Event( $response['rawEvent'], $this->renderer()->publisher(), $extra );
	}

	private function send_unavailable( Evvnt_Calendar_Seo $seo ) {
		status_header( 503 );
		header( 'Retry-After: 300' );
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins honour this.
		}
		$seo->noindex_only();
	}

	public function enqueue_styles() {
		static $done = false;
		if ( $done ) {
			return; // Once per request: the theme colours are added inline only once.
		}
		$done = true;
		wp_enqueue_style( self::STYLE_HANDLE, plugin_dir_url( __FILE__ ) . 'css/evvnt-calendar-public.css', array(), self::asset_version( 'css/evvnt-calendar-public.css', $this->version ) );

		$settings = $this->client()->publisher_settings();
		$theme    = ( ! is_wp_error( $settings ) && isset( $settings['theme'] ) && is_array( $settings['theme'] ) ) ? $settings['theme'] : array();
		$vars     = array();
		foreach ( array(
			'plugin_primary_button_color'      => '--evvnt-cal-primary',
			'plugin_primary_button_text_color' => '--evvnt-cal-primary-text',
		) as $key => $var ) {
			$color = isset( $theme[ $key ] ) && is_string( $theme[ $key ] ) ? sanitize_hex_color( $theme[ $key ] ) : '';
			if ( $color ) {
				$vars[] = $var . ':' . $color;
			}
		}
		if ( $vars ) {
			wp_add_inline_style( self::STYLE_HANDLE, '.evvnt-cal{' . implode( ';', $vars ) . '}' );
		}
	}

	/**
	 * Server-rendered output for one [evvnt] shortcode.
	 */
	private function render_server( array $atts ) {
		$base     = get_permalink();
		$renderer = $this->renderer();

		if ( ! $this->resolved && null === $this->route ) {
			// Rendered where resolve() could not see the shortcode (template, widget, builder):
			// same views, but too late for status codes, redirects or head tags.
			$this->route = Evvnt_Calendar_Route::from_request();
			if ( null !== $this->route->legacy_event_id ) {
				$this->route = Evvnt_Calendar_Route::from_query( array( Evvnt_Calendar_Route::PATH_VAR => '/event/' . $this->route->legacy_event_id ) );
			}
			if ( 'event' === $this->route->type ) {
				$this->load_event();
			}
			wp_enqueue_style( self::STYLE_HANDLE, plugin_dir_url( __FILE__ ) . 'css/evvnt-calendar-public.css', array(), self::asset_version( 'css/evvnt-calendar-public.css', $this->version ) );
		}

		if ( 'unavailable' === $this->state ) {
			return $renderer->render_unavailable();
		}
		// Snowplow analytics, as the discovery JS plugin (page view, event views, ticket clicks).
		Evvnt_Calendar_Tracking::enqueue( $renderer->publisher() );
		if ( 'event_missing' === $this->state ) {
			return $renderer->template( 'event-not-found', array( 'back_url' => Evvnt_Calendar_Route::calendar_url( $base ) ) );
		}
		if ( $this->event ) {
			$html = $renderer->render_detail( $this->event, $base );
			if ( ! $this->resolved && false !== strpos( $html, 'evvnt-cal__hero' ) ) {
				// Rendered too late for the body class that clips the full-bleed hero's 100vw
				// (which includes the scrollbar); clip here so the page cannot scroll sideways.
				$html = '<style>body{overflow-x:clip}</style>' . $html;
			}
			return $html;
		}

		$atts = self::normalize_server_atts( $atts );
		if ( 'categories' === $this->route->type ) {
			if ( '' !== $renderer->categories_url( $base, $atts ) ) {
				return $renderer->render_categories( $base, $atts );
			}
			// A calendar pinned to one category has no category list: say so over its events.
			$not_found       = new Evvnt_Calendar_Route();
			$not_found->type = 'not_found';
			return $renderer->render_calendar( $not_found, $atts, $base );
		}
		return $renderer->render_calendar( $this->route, $atts, $base );
	}

	/**
	 * Rebuild the cached category tree (WP-Cron, scheduled when it goes stale).
	 */
	public function refresh_category_tree() {
		if ( Evvnt_Calendar_Settings::publisher_id() ) {
			$this->renderer()->category_tree()->refresh();
		}
	}

	/**
	 * Version for an asset URL: the plugin version plus the file's modification time, so
	 * browsers fetch a stylesheet again whenever it changes, not only on version bumps.
	 *
	 * @param string $file    Path below public/.
	 * @param string $version Plugin version.
	 * @return string
	 */
	private static function asset_version( $file, $version ) {
		$mtime = @filemtime( plugin_dir_path( __FILE__ ) . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just falls back to the version.
		return $mtime ? $version . '.' . $mtime : $version;
	}

	private function client() {
		static $client = null;
		if ( null === $client ) {
			$client = new Evvnt_Calendar_Discovery_Client( Evvnt_Calendar_Settings::publisher_id(), new Evvnt_Calendar_Cache() );
		}
		return $client;
	}

	private function renderer() {
		if ( null === $this->renderer ) {
			$this->renderer = new Evvnt_Calendar_Renderer( $this->client(), Evvnt_Calendar_Settings::get() );
		}
		return $this->renderer;
	}

	/**
	 * Default display options shared by the shortcode and the widget.
	 *
	 * @return array
	 */
	public static function display_defaults() {
		return array(
			'detail_page_enabled' => '1',
			'config_type'         => 'calendar',
			'virtual'             => '0',
			'map'                 => '0',
			'seo_optimize'        => '0', // Accepted for backwards compatibility; the discovery loader ignores it.
			'category_id'         => '',
			'layout'              => '',  // Server mode: 'list' or 'rows' overrides the Calendar layout setting.
		);
	}

	public function build_shortcode( $atts ) {
		$atts = shortcode_atts( self::display_defaults(), $atts, 'evvnt' );
		if ( self::server_mode() ) {
			return 'widget' === $atts['config_type'] ? self::render_widget( $atts ) : $this->render_server( $atts );
		}
		return self::render_embed( $atts );
	}

	/**
	 * Render the discovery embed for one shortcode or widget instance.
	 *
	 * Every value that reaches the inline script is type-cast and JSON-encoded,
	 * so shortcode attributes and settings cannot inject script.
	 *
	 * @param array $atts Raw display options (strings from a shortcode or widget instance).
	 * @return string HTML.
	 */
	public static function render_embed( $atts ) {
		$atts         = wp_parse_args( is_array( $atts ) ? $atts : array(), self::display_defaults() );
		$publisher_id = Evvnt_Calendar_Settings::publisher_id();

		if ( ! $publisher_id ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="evvnt-calendar-unconfigured">' . esc_html__( 'Evvnt Calendar is not configured. Add your Publisher ID under Settings → Evvnt Calendar Settings.', 'evvnt-calendar' ) . '</p>';
			}
			return '';
		}

		$element_id = wp_unique_id( 'evvnt-calendar-' );

		$discovery = array(
			'element'             => '#' . $element_id,
			'detail_page_enabled' => self::to_bool( $atts['detail_page_enabled'] ),
			'widget'              => 'widget' === $atts['config_type'],
			'virtual'             => self::to_bool( $atts['virtual'] ),
			'map'                 => self::to_bool( $atts['map'] ),
		);

		$category_id = absint( $atts['category_id'] );
		if ( $category_id ) {
			$discovery['category_id'] = $category_id;
		}

		$settings = Evvnt_Calendar_Settings::get();
		$config   = array(
			'publisher_id' => $publisher_id,
			'discovery'    => $discovery,
			'submission'   => array(
				'partner_name' => (string) $settings['partner_name'],
				'text'         => (string) $settings['submission_label'],
			),
		);

		wp_enqueue_script( self::SCRIPT_HANDLE, self::SCRIPT_URL, array(), null, true );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.evvnt_require&&evvnt_require("evvnt/discovery_plugin").init(' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ');'
		);

		// The <noscript> fallback also keeps the markup non-empty, which the block-based
		// widget editor requires before it shows a preview. The discovery app renders
		// into a shadow root, so this content is never displayed alongside the calendar.
		return '<div id="' . esc_attr( $element_id ) . '" class="evvnt-calendar"><noscript>'
			. esc_html__( 'Please enable JavaScript to view the events calendar.', 'evvnt-calendar' )
			. '</noscript></div>';
	}

	/**
	 * Interpret '1', 'true', 1 and true as on; everything else as off.
	 */
	private static function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( (string) $value );
	}
}
