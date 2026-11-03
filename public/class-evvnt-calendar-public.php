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

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
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
		return array(
			'detail_page_enabled' => self::to_bool( $atts['detail_page_enabled'] ),
			'virtual'             => self::to_bool( $atts['virtual'] ),
			'category_id'         => absint( $atts['category_id'] ),
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
			Evvnt_Calendar_Router::enforce_canonical_path( $canonical );
			$seo->for_event( $this->event, $canonical );
			return;
		}

		if ( 'not_found' === $this->route->type ) {
			status_header( 404 );
			$seo->noindex_only();
			return;
		}

		$category = null;
		if ( 'category' === $this->route->type && ! $atts['category_id'] ) {
			$category = $renderer->category_by_slug( $this->route->category_slug );
			if ( ! $category && $renderer->categories() ) {
				status_header( 404 );
				$seo->noindex_only();
				return;
			}
		}

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

		Evvnt_Calendar_Router::enforce_canonical_path( Evvnt_Calendar_Route::calendar_url( $base, $category ? $category['slug'] : '', $this->route->page ) );

		if ( 'category' === $this->route->type && $atts['category_id'] ) {
			// A pinned calendar has no root-category navigation; consolidate on the page itself.
			$seo->for_listing( '', Evvnt_Calendar_Route::calendar_url( $base, '', $this->route->page ) );
		} elseif ( $category || $this->route->page > 1 ) {
			$title = $category ? $category['name'] : get_the_title( $post );
			if ( $this->route->page > 1 ) {
				/* translators: 1: listing title, 2: page number. */
				$title = sprintf( __( '%1$s – Page %2$d', 'evvnt-calendar' ), $title, $this->route->page );
			}
			$seo->for_listing( $title, Evvnt_Calendar_Route::calendar_url( $base, $category ? $category['slug'] : '', $this->route->page ) );
		}
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
		wp_enqueue_style( self::STYLE_HANDLE, plugin_dir_url( __FILE__ ) . 'css/evvnt-calendar-public.css', array(), $this->version );

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
			wp_enqueue_style( self::STYLE_HANDLE, plugin_dir_url( __FILE__ ) . 'css/evvnt-calendar-public.css', array(), $this->version );
		}

		if ( 'unavailable' === $this->state ) {
			return $renderer->render_unavailable();
		}
		if ( 'event_missing' === $this->state ) {
			return $renderer->template( 'event-not-found', array( 'back_url' => Evvnt_Calendar_Route::calendar_url( $base ) ) );
		}
		if ( $this->event ) {
			return $renderer->render_detail( $this->event, $base );
		}

		return $renderer->render_calendar( $this->route, self::normalize_server_atts( $atts ), $base );
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
		);
	}

	public function build_shortcode( $atts ) {
		$atts = shortcode_atts( self::display_defaults(), $atts, 'evvnt' );
		// The compact "widget" layout is still rendered by the discovery JS in phase 1.
		if ( self::server_mode() && 'widget' !== $atts['config_type'] ) {
			return $this->render_server( $atts );
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
