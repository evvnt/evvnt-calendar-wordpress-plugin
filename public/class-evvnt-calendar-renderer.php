<?php

/**
 * Server-side rendering of the calendar and event detail views.
 *
 * Templates live in public/templates/ and can be overridden by a theme at
 * {theme}/evvnt-calendar/{name}.php. Each template receives a single $args array.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Renderer {

	/** Category ids shown first in the navigation, as in the discovery JS. */
	const NAV_PRECEDENCE = array( 16, 17, 14, 6, 22 );
	const MAX_FEATURED   = 6;

	private $client;
	private $settings;

	public function __construct( Evvnt_Calendar_Discovery_Client $client, array $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * The publisher object from publisher_settings, or array() if unavailable.
	 */
	public function publisher() {
		$settings = $this->client->publisher_settings();
		return ( ! is_wp_error( $settings ) && isset( $settings['publisher'] ) && is_array( $settings['publisher'] ) ) ? $settings['publisher'] : array();
	}

	/**
	 * Root categories ordered as the discovery JS orders them: precedence ids, then by name.
	 *
	 * @return array[] Each array( 'id' => int, 'name' => string, 'slug' => string ).
	 */
	public function categories() {
		$publisher = $this->publisher();
		$list      = array();
		foreach ( isset( $publisher['root_categories'] ) ? (array) $publisher['root_categories'] : array() as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) || ! isset( $category['name'] ) ) {
				continue;
			}
			$list[ (int) $category['id'] ] = array(
				'id'   => (int) $category['id'],
				// Displayed as the discovery JS does ("Food / Drink" => "Food & Drink"); the slug uses the raw name.
				'name' => str_replace( ' / ', ' & ', (string) $category['name'] ),
				'slug' => Evvnt_Calendar_Route::category_slug( $category['name'] ),
			);
		}
		$ordered = array();
		foreach ( self::NAV_PRECEDENCE as $id ) {
			if ( isset( $list[ $id ] ) ) {
				$ordered[] = $list[ $id ];
				unset( $list[ $id ] );
			}
		}
		uasort(
			$list,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return array_merge( $ordered, array_values( $list ) );
	}

	/**
	 * Category matching a slug, or null.
	 */
	public function category_by_slug( $slug ) {
		foreach ( $this->categories() as $category ) {
			if ( $category['slug'] === $slug ) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * Fetch the events for a calendar route.
	 *
	 * A pinned category_id scopes everything to that category; its navigation (sub-categories)
	 * is not available server-side yet, so category slugs are ignored on pinned calendars.
	 *
	 * @param Evvnt_Calendar_Route $route Route.
	 * @param array                $atts  Normalized attributes (virtual, category_id).
	 * @return array|WP_Error array( 'raw' => array, 'featured' => array, 'selected' => array|null )
	 */
	public function listing( Evvnt_Calendar_Route $route, array $atts ) {
		$pinned   = absint( $atts['category_id'] );
		$selected = ( ! $pinned && 'category' === $route->type ) ? $this->category_by_slug( $route->category_slug ) : null;

		$filters = array();
		if ( $pinned ) {
			$filters['categoryId'] = $pinned;
		} elseif ( $selected ) {
			$filters['categoryId'] = $selected['id'];
		}
		if ( ! empty( $atts['virtual'] ) ) {
			$filters['virtual'] = 'true';
		}

		if ( 1 === $route->page ) {
			$response = $this->client->home_page_events( $filters );
			$key      = 'rawEvents';
		} else {
			$response = $this->client->events( $filters, $route->page - 1 );
			$key      = 'events';
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response[ $key ] ) || ! is_array( $response[ $key ] ) ) {
			return new WP_Error( 'evvnt_calendar_shape', 'Unexpected response from Evvnt.', array( 'status' => 0 ) );
		}
		return array(
			'raw'      => $response[ $key ],
			'featured' => ( 1 === $route->page && ! empty( $response['rawFeaturedEvents'] ) && is_array( $response['rawFeaturedEvents'] ) ) ? $response['rawFeaturedEvents'] : array(),
			'selected' => $selected,
		);
	}

	/**
	 * Render the calendar list for a route.
	 *
	 * @param Evvnt_Calendar_Route $route Route (calendar, category or not_found).
	 * @param array                $atts  Normalized shortcode attributes.
	 * @param string               $base  Permalink of the page holding the calendar.
	 * @return string
	 */
	public function render_calendar( Evvnt_Calendar_Route $route, array $atts, $base ) {
		$listing = $this->listing( $route, $atts );
		if ( is_wp_error( $listing ) ) {
			return $this->template( 'unavailable', array() );
		}

		$publisher = $this->publisher();
		$pinned    = absint( $atts['category_id'] );
		$selected  = $listing['selected'];
		$slug      = $selected ? $selected['slug'] : '';
		$count     = count( $listing['raw'] );

		return $this->template(
			'calendar',
			array(
				'events'       => $this->to_events( $listing['raw'], $publisher ),
				'featured'     => array_slice( $this->to_events( $listing['featured'], $publisher ), 0, self::MAX_FEATURED ),
				'categories'   => $pinned ? array() : $this->categories(),
				'selected'     => $selected,
				'not_found'    => 'not_found' === $route->type || ( 'category' === $route->type && ! $pinned && ! $selected ),
				'page'         => $route->page,
				'prev_url'     => $route->page > 1 ? Evvnt_Calendar_Route::calendar_url( $base, $slug, $route->page - 1 ) : '',
				'next_url'     => ( $count >= Evvnt_Calendar_Discovery_Client::HITS_PER_PAGE && $route->page < Evvnt_Calendar_Route::MAX_PAGE ) ? Evvnt_Calendar_Route::calendar_url( $base, $slug, $route->page + 1 ) : '',
				'base'         => $base,
				'detail_pages' => ! empty( $atts['detail_page_enabled'] ),
				'submit_url'   => $this->submit_url( $publisher ),
				'submit_label' => $this->settings['submission_label'],
				'calendar_url' => Evvnt_Calendar_Route::calendar_url( $base ),
			)
		);
	}

	/**
	 * Render an event detail view.
	 */
	public function render_detail( Evvnt_Calendar_Event $event, $base ) {
		return $this->template(
			'event-detail',
			array(
				'event'        => $event,
				'back_url'     => Evvnt_Calendar_Route::calendar_url( $base ),
				'submit_url'   => $this->submit_url( $this->publisher() ),
				'submit_label' => $this->settings['submission_label'],
			)
		);
	}

	public function render_unavailable() {
		return $this->template( 'unavailable', array() );
	}

	/**
	 * Card markup for one event (used by the calendar template).
	 */
	public function render_card( Evvnt_Calendar_Event $event, $base, $detail_pages, $featured = false ) {
		$url      = $detail_pages ? Evvnt_Calendar_Route::event_url( $base, $event->slug() ) : $event->main_link();
		$external = ! $detail_pages;
		return $this->template(
			'event-card',
			array(
				'event'    => $event,
				'url'      => $url ? $url : Evvnt_Calendar_Route::event_url( $base, $event->slug() ),
				'external' => $external && $url,
				'featured' => $featured,
			)
		);
	}

	/**
	 * Where "add your event" goes: the publisher's own form, or Evvnt's.
	 */
	public function submit_url( array $publisher ) {
		if ( ! empty( $publisher['event_submission_url'] ) && is_string( $publisher['event_submission_url'] ) ) {
			return $publisher['event_submission_url'];
		}
		$settings = $this->client->publisher_settings();
		$partner  = ( ! is_wp_error( $settings ) && ! empty( $settings['api_key'] ) && is_string( $settings['api_key'] ) ) ? $settings['api_key'] : '';
		return add_query_arg(
			array_filter(
				array(
					'partner'      => rawurlencode( $partner ),
					'publisher_id' => $this->client->publisher_id(),
				)
			),
			'https://new.evvnt.com/'
		);
	}

	/**
	 * Render a template, preferring a theme override.
	 *
	 * @param string $name Template name without extension.
	 * @param array  $args Variables for the template.
	 * @return string
	 */
	public function template( $name, array $args ) {
		$name     = sanitize_key( $name );
		$template = locate_template( array( 'evvnt-calendar/' . $name . '.php' ) );
		if ( ! $template ) {
			$template = plugin_dir_path( __FILE__ ) . 'templates/' . $name . '.php';
		}
		/** Filters the template file used for an Evvnt Calendar view. */
		$template = apply_filters( 'evvnt_calendar_template', $template, $name, $args );

		$args['renderer'] = $this;
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	private function to_events( $raw, array $publisher ) {
		$events = array();
		foreach ( (array) $raw as $item ) {
			if ( is_array( $item ) && ! empty( $item['title'] ) ) {
				$events[] = new Evvnt_Calendar_Event( $item, $publisher );
			}
		}
		return $events;
	}
}
