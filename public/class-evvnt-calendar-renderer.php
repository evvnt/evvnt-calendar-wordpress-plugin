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
	const ROW_SIZE       = 10; // Events per row before the "View more" tile.
	const ROW_MIN        = 5; // Category rows need at least this many featured events.
	const WIDGET_NUMBER  = 4; // Events in the compact widget (the discovery JS widget's default).

	private $client;
	private $settings;
	private $tree;

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
	 * The publisher's full category tree (roots and every sub-category).
	 *
	 * @return Evvnt_Calendar_Category_Tree
	 */
	public function category_tree() {
		if ( null === $this->tree ) {
			$this->tree = new Evvnt_Calendar_Category_Tree( $this->client, $this->categories(), $this->client->publisher_id() );
		}
		return $this->tree;
	}

	/**
	 * The sub-category a route's slug path names (with 'trail', its ancestors root first), null
	 * when it names none, or WP_Error when the category API is failing.
	 *
	 * @param Evvnt_Calendar_Route $route Category route with a subcategory_path.
	 * @return array|null|WP_Error
	 */
	public function subcategory( Evvnt_Calendar_Route $route ) {
		if ( 'category' !== $route->type || '' === $route->subcategory_path ) {
			return null;
		}
		return $this->category_tree()->by_path( $route->category_path() );
	}

	/**
	 * URL of any category's page: a root's own page, or a sub-category's page by its slug path.
	 *
	 * @param array  $node Category from the tree ( path ).
	 * @param string $base Calendar page URL.
	 * @param int    $page 1-based page.
	 * @return string
	 */
	public static function category_url( array $node, $base, $page = 1 ) {
		return Evvnt_Calendar_Route::calendar_url( $base, $node['path'], $page );
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
		// A sub-category page (…/category/arts/performing-arts/): its category in the tree.
		$sub = ( $selected && '' !== $route->subcategory_path ) ? $this->subcategory( $route ) : null;
		if ( is_wp_error( $sub ) ) {
			return $sub; // Unknown for now: unavailable, not "not found".
		}

		$filters = array();
		if ( $pinned ) {
			$filters['categoryId'] = $pinned;
		} elseif ( $selected ) {
			$filters['categoryId'] = $selected['id'];
		}
		if ( $sub ) {
			$filters['subCategoryIds'] = $sub['id'];
		} elseif ( $route->subcategory_id ) {
			$filters['subCategoryIds'] = $route->subcategory_id;
		}
		if ( '' !== $route->from_date ) {
			$filters['fromDate'] = $route->from_date;
			$filters['toDate']   = $route->to_date;
		}
		if ( ! empty( $atts['virtual'] ) ) {
			$filters['virtual'] = 'true';
		}

		$search_featured = array();
		if ( '' !== $route->search ) {
			$response = $this->client->search_events( $route->search, $route->page - 1, Evvnt_Calendar_Discovery_Client::HITS_PER_PAGE, $filters );
			$key      = 'events';
			if ( 1 === $route->page && ! is_wp_error( $response ) ) {
				// Featured matches head the first page, as featured events do on other listings
				// (discovery's home_page_events runs the same query with isFeatured). Search returns
				// each date of a series, so fetch extra and keep one per series, as those listings
				// do. Optional: a failure here only leaves the section out.
				$featured        = $this->client->search_events( $route->search, 0, 3 * self::MAX_FEATURED, $filters + array( 'isFeatured' => 'true' ) );
				$search_featured = ( ! is_wp_error( $featured ) && isset( $featured['events'] ) && is_array( $featured['events'] ) ) ? self::one_per_series( $featured['events'] ) : array();
			}
		} elseif ( 1 === $route->page ) {
			// Also for date ranges: featured events in that range come first, as on other listings.
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
			'featured' => '' !== $route->search ? $search_featured : ( ( 1 === $route->page && ! empty( $response['rawFeaturedEvents'] ) && is_array( $response['rawFeaturedEvents'] ) ) ? $response['rawFeaturedEvents'] : array() ),
			'selected' => $selected,
			'sub'      => $sub,
		);
	}

	/**
	 * The compact widget, as the discovery JS widget (WidgetPortrait) shows it: "Create an Event",
	 * a search box, four events chosen from the publisher's featured events (weighted, as its
	 * FeaturedEventChooser) with a promotional ad in one slot when the publisher has one,
	 * "More Events" and the evvnt credit. Links go to the site's calendar page, or the
	 * publisher's calendar URL when the site has none.
	 *
	 * @param array $atts { detail_page_enabled, virtual, category_id }.
	 * @return string
	 */
	public function render_widget( array $atts ) {
		$publisher = $this->publisher();
		$base      = $this->widget_base( $publisher );
		$detail    = ! empty( $atts['detail_page_enabled'] );

		// One slot is an ad when the publisher has a vertical widget promotion (and room for it).
		$assets = array();
		foreach ( isset( $publisher['promotional_assets'] ) ? (array) $publisher['promotional_assets'] : array() as $asset ) {
			if ( is_array( $asset ) && isset( $asset['asset_unit'], $asset['image_url'] ) && 'vertical_widget' === $asset['asset_unit'] && is_string( $asset['image_url'] ) && 0 === strpos( $asset['image_url'], 'https://' ) ) {
				$assets[] = $asset;
			}
		}
		$show_ad = self::WIDGET_NUMBER > 2 && $assets;
		$count   = $show_ad ? self::WIDGET_NUMBER - 1 : self::WIDGET_NUMBER;

		$filters = array_filter(
			array(
				'categoryId' => ! empty( $atts['category_id'] ) ? absint( $atts['category_id'] ) : null,
				'virtual'    => ! empty( $atts['virtual'] ) ? 'true' : null,
			)
		);
		$response    = $this->client->widget_events( $filters );
		$unavailable = is_wp_error( $response ) || ! isset( $response['rawEvents'] ) || ! is_array( $response['rawEvents'] );
		$events      = $unavailable ? array() : self::choose_weighted( $this->to_events( self::unique_events( $response['rawEvents'] ), $publisher ), $count );

		$items = array();
		foreach ( $events as $event ) {
			$url     = $detail ? Evvnt_Calendar_Route::event_url( $base, $event->slug() ) : $event->main_link();
			$items[] = array(
				'type'     => 'event',
				'event'    => $event,
				// As the JS widget: every card has an image, Evvnt's placeholder when the event has none.
				'image'    => $event->card_image_url() ? $event->card_image_url() : plugins_url( 'active_network' === $event->main_source() ? 'images/active_network_placeholder.jpeg' : 'images/placeholder-event-image.jpg', __FILE__ ),
				'url'      => $url ? $url : Evvnt_Calendar_Route::event_url( $base, $event->slug() ),
				'external' => ! $detail && $url,
				'tracking' => Evvnt_Calendar_Tracking::view_attrs( $event, Evvnt_Calendar_Tracking::VIEWED_FROM_LIST ),
			);
		}
		if ( $show_ad && $items ) {
			// Anywhere but first, as the JS widget places it.
			$position = count( $items ) > 1 ? wp_rand( 1, count( $items ) - 1 ) : 1;
			array_splice(
				$items,
				$position,
				0,
				array(
					array(
						'type'  => 'ad',
						'image' => $assets[ wp_rand( 0, count( $assets ) - 1 ) ]['image_url'],
						'url'   => $this->promote_url( $publisher, 'widget' ),
					),
				)
			);
		}

		return $this->template(
			'widget',
			array(
				'items'        => $items,
				'unavailable'  => $unavailable,
				'base'         => $base,
				'atts'         => $atts,
				'more_url'     => Evvnt_Calendar_Route::calendar_url( $base ),
				// Live suggestions link into this site's calendar, so only when the widget does too.
				'suggest'      => wp_parse_url( $base, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ),
				'create_url'   => $this->submit_url( $publisher ),
				'powered_logo' => plugins_url( 'images/powered_by_evvnt.png', __FILE__ ),
			)
		);
	}

	/**
	 * Raw events with repeats of the same event (objectID) removed: the widget pool lists a
	 * featured event both as featured and again among the events that top it up.
	 *
	 * @param array $raw Raw events.
	 * @return array
	 */
	public static function unique_events( array $raw ) {
		$seen = array();
		$kept = array();
		foreach ( $raw as $item ) {
			$id = ( is_array( $item ) && isset( $item['objectID'] ) && is_scalar( $item['objectID'] ) ) ? (string) $item['objectID'] : '';
			if ( '' !== $id && isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$kept[]      = $item;
		}
		return $kept;
	}

	/**
	 * Calendar the widget links into: the site's Calendar page in server mode, else the
	 * publisher's calendar URL (where the discovery JS widget links), else the home page.
	 *
	 * @param array $publisher Publisher settings.
	 * @return string
	 */
	public function widget_base( array $publisher ) {
		$page_id = isset( $this->settings['calendar_page_id'] ) ? absint( $this->settings['calendar_page_id'] ) : 0;
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return get_permalink( $page_id );
		}
		if ( ! empty( $publisher['url'] ) && is_string( $publisher['url'] ) && wp_http_validate_url( $publisher['url'] ) ) {
			return $publisher['url'];
		}
		return home_url( '/' );
	}

	/**
	 * Where a promotional ad links: the publisher's own submission form, else Evvnt's with
	 * utm parameters (the discovery JS's useAddEventUrl).
	 *
	 * @param array  $publisher Publisher settings.
	 * @param string $medium    utm_medium.
	 * @return string
	 */
	public function promote_url( array $publisher, $medium ) {
		if ( ! empty( $publisher['event_submission_url'] ) && is_string( $publisher['event_submission_url'] ) ) {
			return $publisher['event_submission_url'];
		}
		return add_query_arg(
			array(
				'utm_source'   => rawurlencode( (string) $this->client->publisher_id() ),
				'utm_medium'   => rawurlencode( $medium ),
				'utm_campaign' => 'promote_asset',
			),
			$this->submit_url( $publisher )
		);
	}

	/**
	 * Raw events with only the first date of each series (same event_parent_id) kept.
	 *
	 * @param array $raw Raw events.
	 * @return array
	 */
	public static function one_per_series( array $raw ) {
		$seen = array();
		$kept = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$parent = isset( $item['event_parent_id'] ) && is_scalar( $item['event_parent_id'] ) && '' !== (string) $item['event_parent_id'] ? 'p' . $item['event_parent_id'] : null;
			if ( null !== $parent && isset( $seen[ $parent ] ) ) {
				continue;
			}
			if ( null !== $parent ) {
				$seen[ $parent ] = true;
			}
			$kept[] = $item;
		}
		return $kept;
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
		if ( isset( $atts['layout'] ) && 'rows' === $atts['layout'] && 'calendar' === $route->type && 1 === $route->page && ! absint( $atts['category_id'] ) && ! $route->subcategory_id && '' === $route->search && '' === $route->from_date ) {
			return $this->render_rows( $route, $atts, $base );
		}
		$listing = $this->listing( $route, $atts );
		if ( is_wp_error( $listing ) ) {
			return $this->template( 'unavailable', array() );
		}

		$publisher = $this->publisher();
		$pinned    = absint( $atts['category_id'] );
		$rows_mode = isset( $atts['layout'] ) && 'rows' === $atts['layout'] && ! $pinned;
		$selected  = $listing['selected'];
		$sub       = isset( $listing['sub'] ) ? $listing['sub'] : null;
		$slug      = $sub ? $sub['path'] : ( $selected ? $selected['slug'] : '' );
		$count     = count( $listing['raw'] );
		$page_url  = function ( $page ) use ( $base, $slug, $route, $sub ) {
			$url = ( $route->subcategory_id && ! $sub )
				? Evvnt_Calendar_Route::subcategory_url( $base, $slug, $route->subcategory_id, $route->subcategory_name, $page )
				: Evvnt_Calendar_Route::calendar_url( $base, $slug, $page );
			if ( '' !== $route->search ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . Evvnt_Calendar_Route::SEARCH_VAR . '=' . rawurlencode( $route->search );
			}
			if ( '' !== $route->from_date ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . Evvnt_Calendar_Route::FROM_VAR . '=' . $route->from_date . '&' . Evvnt_Calendar_Route::TO_VAR . '=' . $route->to_date;
			}
			return $url;
		};

		return $this->template(
			'calendar',
			array(
				'events'       => $this->to_events( $listing['raw'], $publisher ),
				'featured'     => array_slice( $this->to_events( $listing['featured'], $publisher ), 0, self::MAX_FEATURED ),
				// In the rows layout the rows are the navigation: list pages get a home link instead.
				// Rows layout and search results have no category tabs (the rows / matches replace them).
				'categories'   => ( $pinned || $rows_mode || '' !== $route->search || '' !== $route->from_date ) ? array() : $this->categories(),
				'home_url'     => ( $rows_mode || '' !== $route->search || '' !== $route->from_date ) ? Evvnt_Calendar_Route::calendar_url( $base ) : '',
				'dates_label'  => '' !== $route->from_date ? $this->dates_label( $route->from_date, $route->to_date ) : '',
				'selected'     => $selected,
				'sub'          => $sub,
				// Links up the sub-category's ancestors, root first.
				'breadcrumbs'  => $sub ? array_map(
					function ( $node ) use ( $base ) {
						return array(
							'name' => $node['name'],
							'url'  => self::category_url( $node, $base ),
						);
					},
					$sub['trail']
				) : array(),
				'not_found'    => 'not_found' === $route->type || ( 'category' === $route->type && ! $pinned && ( ! $selected || ( '' !== $route->subcategory_path && ! $sub ) ) ),
				'page'         => $route->page,
				'prev_url'     => $route->page > 1 ? $page_url( $route->page - 1 ) : '',
				'next_url'     => ( $count >= Evvnt_Calendar_Discovery_Client::HITS_PER_PAGE && $route->page < Evvnt_Calendar_Route::MAX_PAGE ) ? $page_url( $route->page + 1 ) : '',
				'search'       => $route->search,
				'atts'         => $atts,
				'search_categories' => ( '' !== $route->search && ! $pinned ) ? $this->category_matches( $route->search, $listing['raw'], $base ) : array(),
				'subcategory'  => ( $route->subcategory_id && ! $sub ) ? ( '' !== $route->subcategory_name ? $route->subcategory_name : __( 'Filtered', 'evvnt-calendar' ) ) : '',
				'clear_sub'    => ( $route->subcategory_id && ! $sub ) ? Evvnt_Calendar_Route::calendar_url( $base, $slug ) : '',
				'base'         => $base,
				'detail_pages' => ! empty( $atts['detail_page_enabled'] ),
				'submit_url'   => $this->submit_url( $publisher ),
				'submit_label' => $this->settings['submission_label'],
				'calendar_url' => Evvnt_Calendar_Route::calendar_url( $base ),
				'categories_url' => $this->categories_url( $base, $atts ),
			)
		);
	}

	/**
	 * Calendar home as horizontally scrolling rows: Featured, then one row per category
	 * (in navigation order) with a "See all" link into that category.
	 */
	public function render_rows( Evvnt_Calendar_Route $route, array $atts, $base ) {
		$listing   = $this->listing( $route, $atts ); // Memoised: also used by resolve() for status codes.
		$publisher = $this->publisher();
		$filters   = ! empty( $atts['virtual'] ) ? array( 'virtual' => 'true' ) : array();
		/**
		 * Minimum number of featured events a category needs to get a row.
		 *
		 * @param int $minimum Default 5.
		 */
		$minimum = max( 1, (int) apply_filters( 'evvnt_calendar_row_min_featured', self::ROW_MIN ) );
		$detail    = ! empty( $atts['detail_page_enabled'] );
		$rows      = array();

		$featured_raw = ! is_wp_error( $listing ) ? $listing['featured'] : array();
		if ( $featured_raw ) {
			$rows[] = array(
				'id'     => 'featured',
				'title'  => __( 'Featured Events', 'evvnt-calendar' ),
				'url'    => '',
				'events' => array_slice( $this->to_events( $featured_raw, $publisher ), 0, self::ROW_SIZE ),
			);
		}

		// "This Weekend": everything on from Thursday to Sunday (or from today, Thursday-Sunday).
		list( $weekend_from, $weekend_to ) = self::weekend_range();
		$weekend = $this->weekend_events( $weekend_from, $weekend_to, $filters, $publisher );
		if ( $weekend ) {
			$rows[] = array(
				'id'     => 'this-weekend',
				'title'  => __( 'This Weekend', 'evvnt-calendar' ),
				'url'    => Evvnt_Calendar_Route::dates_url( $base, $weekend_from, $weekend_to ),
				'more'   => __( 'View more events this weekend', 'evvnt-calendar' ),
				'events' => $weekend,
			);
		}

		$categories    = $this->categories();
		// Category rows show only the publisher's featured events in each category.
		$by_id         = $this->client->events_by_category( wp_list_pluck( $categories, 'id' ), self::ROW_SIZE, array( 'isFeatured' => 'true' ) + $filters );
		$category_rows = 0;
		foreach ( $categories as $category ) {
			$raw    = isset( $by_id[ $category['id'] ] ) ? $by_id[ $category['id'] ] : null;
			$events = is_array( $raw ) ? array_values(
				array_filter(
					$this->to_events( $raw, $publisher ),
					function ( $event ) {
						return $event->is_featured();
					}
				)
			) : array();
			if ( count( $events ) < $minimum ) {
				continue; // Too few featured events (or the row failed): leave the category out.
			}
			$rows[] = array(
				'id'     => 'category-' . $category['id'],
				'title'  => $category['name'],
				'url'    => Evvnt_Calendar_Route::calendar_url( $base, $category['slug'] ),
				'events' => $events,
			);
			++$category_rows;
		}

		if ( ! $category_rows ) {
			// No category row could be built (API trouble, or no categories): the list view
			// still shows everything listing() fetched rather than a lone Featured row.
			return is_wp_error( $listing ) ? $this->template( 'unavailable', array() ) : $this->render_calendar( $route, array( 'layout' => 'list' ) + $atts, $base );
		}

		self::enqueue_rows_script();
		return $this->template(
			'calendar-rows',
			array(
				'rows'         => $rows,
				'atts'         => $atts,
				'base'         => $base,
				'detail_pages' => $detail,
				'submit_url'   => $this->submit_url( $publisher ),
				'submit_label' => $this->settings['submission_label'],
				'calendar_url' => Evvnt_Calendar_Route::calendar_url( $base ),
				'categories_url' => $this->categories_url( $base, $atts ),
			)
		);
	}

	/**
	 * Link to the list of every category, or '' on a calendar pinned to one category (or a
	 * publisher with none).
	 *
	 * @param string $base Calendar page URL.
	 * @param array  $atts Normalized shortcode attributes.
	 * @return string
	 */
	public function categories_url( $base, array $atts ) {
		return ( empty( $atts['category_id'] ) && $this->categories() ) ? Evvnt_Calendar_Route::categories_url( $base ) : '';
	}

	/**
	 * The list of every category, A–Z, each with its sub-categories nested below it (A–Z, at
	 * every depth), each linking to its page. Until the tree is first built in the background,
	 * the root categories only.
	 *
	 * @param string $base Calendar page URL.
	 * @param array  $atts Normalized shortcode attributes (for the search box's filters).
	 * @return string
	 */
	public function render_categories( $base, array $atts = array() ) {
		$add_urls   = function ( array $nodes ) use ( &$add_urls, $base ) {
			foreach ( $nodes as $i => $node ) {
				$nodes[ $i ]['url']      = self::category_url( $node, $base );
				$nodes[ $i ]['children'] = $add_urls( $node['children'] );
			}
			return $nodes;
		};
		$categories = $add_urls( $this->category_tree()->nested() );
		return $this->template(
			'categories',
			array(
				'categories'   => $categories,
				'base'         => $base,
				'atts'         => $atts,
				'home_url'     => Evvnt_Calendar_Route::calendar_url( $base ),
				'submit_url'   => $this->submit_url( $this->publisher() ),
				'submit_label' => $this->settings['submission_label'],
			)
		);
	}

	/**
	 * Categories whose names match search text: the publisher's navigation categories (each
	 * has a calendar page) first, then sub-categories found on matching events (linked to
	 * their parent's page filtered to them).
	 *
	 * @param string $term   Search text.
	 * @param array  $raw    Raw matching events (may be empty).
	 * @param string $base   Calendar page URL.
	 * @param int    $limit  Maximum matches.
	 * @return array[] Each array( 'name' => string, 'url' => string ).
	 */
	public function category_matches( $term, array $raw, $base, $limit = 8 ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}
		$matches = function ( $name ) use ( $term ) {
			return false !== stripos( remove_accents( $name, 'en_US' ), remove_accents( $term, 'en_US' ) )
				|| false !== stripos( str_replace( ' / ', ' & ', $name ), $term );
		};
		$found = array();
		$roots = array();
		foreach ( $this->categories() as $category ) {
			$roots[ $category['id'] ] = $category;
			if ( $matches( $category['name'] ) ) {
				$found[ 'c' . $category['id'] ] = array(
					'name' => $category['name'],
					'url'  => Evvnt_Calendar_Route::calendar_url( $base, $category['slug'] ),
				);
			}
		}
		foreach ( $this->to_events( $raw, array() ) as $event ) {
			$parent = isset( $roots[ $event->main_category_id() ] ) ? $roots[ $event->main_category_id() ] : null;
			foreach ( $event->categories() as $category ) {
				if ( isset( $roots[ $category['id'] ] ) || isset( $found[ 'c' . $category['id'] ] ) || ! $matches( $category['name'] ) ) {
					continue;
				}
				$label = strtolower( str_replace( ' / ', ' & ', $category['name'] ) );
				foreach ( $found as $existing ) {
					if ( strtolower( $existing['name'] ) === $label ) {
						continue 2; // Same name as a category already listed (e.g. a sub-category "Live Music").
					}
				}
				$found[ 'c' . $category['id'] ] = array(
					'name' => str_replace( ' / ', ' & ', $category['name'] ),
					'url'  => $this->subcategory_link( $category['id'], $category['name'], $parent ? $parent['slug'] : '', $base ),
				);
			}
		}
		// Then any other sub-category in the tree with a matching name (once it is built).
		foreach ( $this->category_tree()->nodes() as $node ) {
			if ( count( $found ) >= $limit ) {
				break;
			}
			if ( $node['depth'] < 1 || isset( $found[ 'c' . $node['id'] ] ) || ! $matches( $node['name'] ) ) {
				continue;
			}
			foreach ( $found as $existing ) {
				if ( strtolower( $existing['name'] ) === strtolower( $node['name'] ) ) {
					continue 2;
				}
			}
			$found[ 'c' . $node['id'] ] = array(
				'name' => $node['name'],
				'url'  => self::category_url( $node, $base ),
			);
		}
		return array_slice( array_values( $found ), 0, $limit );
	}

	/**
	 * Link to a sub-category: its own page when the tree knows it, else its parent's page
	 * filtered to it (?subcategory=id|name, as the discovery JS links).
	 *
	 * @param int    $id          Sub-category id.
	 * @param string $name        Its name.
	 * @param string $parent_slug Root category slug for the filtered fallback ('' for the calendar).
	 * @param string $base        Calendar page URL.
	 * @return string
	 */
	public function subcategory_link( $id, $name, $parent_slug, $base ) {
		$node = $this->category_tree()->by_id( $id );
		return ( $node && $node['depth'] > 0 )
			? self::category_url( $node, $base )
			: Evvnt_Calendar_Route::subcategory_url( $base, $parent_slug, $id, $name );
	}

	/**
	 * Live suggestions for the search box: matching categories and up to 5 events.
	 *
	 * @param string $term Search text (2+ characters).
	 * @param string $base Calendar page URL for links.
	 * @return array { categories: array[], events: array[], all_url: string }
	 */
	public function suggestions( $term, $base, array $atts = array() ) {
		$pinned  = isset( $atts['category_id'] ) ? absint( $atts['category_id'] ) : 0;
		$filters = array_filter(
			array(
				'categoryId' => $pinned ? $pinned : null,
				'virtual'    => ! empty( $atts['virtual'] ) ? 'true' : null,
			)
		);
		// Fetch extra: recurring events come back once per date, and suggestions show each once.
		$response  = $this->client->search_events( $term, 0, 15, $filters );
		$raw       = ( ! is_wp_error( $response ) && isset( $response['events'] ) && is_array( $response['events'] ) ) ? $response['events'] : array();
		$publisher = $this->publisher();
		$events    = array();
		$seen      = array();
		foreach ( $this->to_events( $raw, $publisher ) as $event ) {
			$key = strtolower( $event->title() );
			if ( isset( $seen[ $key ] ) || count( $events ) >= 5 ) {
				continue;
			}
			$seen[ $key ] = true;
			$events[]     = array(
				'title' => $event->title(),
				'url'   => Evvnt_Calendar_Route::event_url( $base, $event->slug() ),
				'date'  => $event->display_date(),
				'place' => $event->location_summary(),
				'image' => $event->card_image_url(),
			);
		}
		return array(
			// A calendar pinned to one category has no category pages to suggest.
			'categories' => $pinned ? array() : $this->category_matches( $term, $raw, $base, 6 ),
			'events'     => $events,
			'all_url'    => Evvnt_Calendar_Route::search_url( $base, $term ),
		);
	}

	/**
	 * The search form (with live suggestions when JavaScript runs).
	 *
	 * @param string $base    Calendar page URL (the form's target).
	 * @param string $current Current search text.
	 * @return string
	 */
	public function search_form( $base, $current = '', array $atts = array(), array $options = array() ) {
		$action = Evvnt_Calendar_Route::calendar_url( $base );
		// A GET form drops the action's own query string (e.g. ?page_id= on plain permalinks): keep it as hidden fields.
		$hidden = array();
		$query  = wp_parse_url( $action, PHP_URL_QUERY );
		if ( $query ) {
			wp_parse_str( $query, $hidden );
			$action = strtok( $action, '?' );
		}
		self::enqueue_search_script();
		return $this->template(
			'search-form',
			array(
				'action'  => $action,
				'hidden'  => array_filter( $hidden, 'is_scalar' ),
				'value'   => $current,
				'suggest' => ( isset( $options['suggest'] ) && ! $options['suggest'] ) ? '' : rest_url( 'evvnt-calendar/v1/suggest' ),
				'base'    => $base,
				// The compact widget's box: its own placeholder and look.
				'placeholder' => isset( $options['placeholder'] ) ? (string) $options['placeholder'] : __( 'Search events and categories', 'evvnt-calendar' ),
				'variant'     => isset( $options['variant'] ) ? sanitize_html_class( $options['variant'] ) : '',
				// Suggestions use the same filters as this calendar's results.
				'filters' => array_filter(
					array(
						'category' => ! empty( $atts['category_id'] ) ? absint( $atts['category_id'] ) : null,
						'virtual'  => ! empty( $atts['virtual'] ) ? 1 : null,
					)
				),
			)
		);
	}

	/**
	 * Live suggestions for search forms: an accessible combobox (ARIA 1.2 pattern) fed by the
	 * plugin's REST endpoint. Without JavaScript the form still submits to the results page.
	 */
	public static function enqueue_search_script() {
		if ( wp_script_is( 'evvnt-calendar-search', 'enqueued' ) ) {
			return;
		}
		wp_register_script( 'evvnt-calendar-search', false, array(), EVVNT_CALENDAR_VERSION, true );
		wp_enqueue_script( 'evvnt-calendar-search' );
		wp_add_inline_script( 'evvnt-calendar-search', self::search_script() );
	}

	private static function search_script() {
		$labels = array(
			'categories' => __( 'Categories', 'evvnt-calendar' ),
			'events'     => __( 'Events', 'evvnt-calendar' ),
			/* translators: %s: search text. */
			'all'        => __( 'See all results for “%s”', 'evvnt-calendar' ),
			'none'       => __( 'No matches yet. Press Enter to search.', 'evvnt-calendar' ),
			/* translators: %d: number of suggestions (singular form). */
			'one'        => _n( '%d suggestion available.', '%d suggestions available.', 1, 'evvnt-calendar' ),
			/* translators: %d: number of suggestions (plural form). */
			'many'       => _n( '%d suggestion available.', '%d suggestions available.', 2, 'evvnt-calendar' ),
		);
		return 'window.evvntCalSearchLabels=' . wp_json_encode( $labels ) . ';' . <<<'JS'
(function(){
var L=window.evvntCalSearchLabels;
function chars(s){return Array.from?Array.from(s).length:s.length;}
document.querySelectorAll(".evvnt-cal__search").forEach(function(form){
	var input=form.querySelector("input[type=search]"),list=form.querySelector(".evvnt-cal__suggest"),live=form.querySelector(".evvnt-cal__suggest-status");
	if(!input||!list||!form.getAttribute("data-suggest"))return;
	var timer=null,ctrl=null,active=-1,last="",cache={};
	function esc(s){var d=document.createElement("div");d.textContent=s==null?"":String(s);return d.innerHTML.replace(/"/g,"&quot;");}
	function safeUrl(u){return /^https?:\/\//i.test(String(u||""))?String(u):"";}
	function options(){return list.querySelectorAll("[role=option]");}
	function close(){list.hidden=true;input.setAttribute("aria-expanded","false");input.removeAttribute("aria-activedescendant");active=-1;}
	function cancel(){clearTimeout(timer);if(ctrl&&ctrl.abort)ctrl.abort();ctrl=null;}
	function setActive(i){var o=options();if(!o.length)return;active=(i+o.length)%o.length;o.forEach(function(el,n){el.setAttribute("aria-selected",n===active?"true":"false");});input.setAttribute("aria-activedescendant",o[active].id);o[active].scrollIntoView({block:"nearest"});}
	function render(q,data){
		if(document.activeElement!==input){close();return;}
		var html="",n=0,g=0,id=input.id+"-opt-";
		function opt(url,inner,cls){url=safeUrl(url);if(!url)return "";return '<li role="option" id="'+id+(n++)+'" aria-selected="false" class="'+cls+'" data-url="'+esc(url)+'">'+inner+"</li>";}
		function group(label,items){var gid=input.id+"-grp-"+(g++);return '<li role="group" aria-labelledby="'+gid+'" class="evvnt-cal__suggest-groupwrap"><div role="presentation" id="'+gid+'" class="evvnt-cal__suggest-group">'+esc(label)+'</div><ul role="none">'+items+"</ul></li>";}
		var cats="",evs="";
		(data.categories||[]).forEach(function(c){cats+=opt(c.url,'<span class="evvnt-cal__suggest-cat">'+esc(c.name)+"</span>","evvnt-cal__suggest-item evvnt-cal__suggest-item--cat");});
		(data.events||[]).forEach(function(e){var img=safeUrl(e.image);evs+=opt(e.url,(img?'<img src="'+esc(img)+'" alt="" loading="lazy">':'<span class="evvnt-cal__suggest-noimg"></span>')+'<span class="evvnt-cal__suggest-text"><span class="evvnt-cal__suggest-title">'+esc(e.title)+'</span><span class="evvnt-cal__suggest-meta">'+esc([e.date,e.place].filter(Boolean).join(" · "))+"</span></span>","evvnt-cal__suggest-item");});
		if(cats)html+=group(L.categories,cats);
		if(evs)html+=group(L.events,evs);
		var found=n;
		html+=opt(data.all_url,esc(L.all.replace("%s",function(){return q;})),"evvnt-cal__suggest-item evvnt-cal__suggest-item--all");
		if(!n){close();if(live)live.textContent="";return;}
		list.innerHTML=html;list.hidden=false;input.setAttribute("aria-expanded","true");input.removeAttribute("aria-activedescendant");active=-1;
		if(live){live.textContent=found?(found===1?L.one:L.many).replace("%d",found):L.none;}
	}
	function lookup(){
		var q=input.value.trim();if(chars(q)<2){cancel();close();last=q;return;}
		if(q===last&&!list.hidden)return;last=q;
		if(cache[q]){render(q,cache[q]);return;}
		cancel();ctrl=window.AbortController?new AbortController():null;
		var s=form.getAttribute("data-suggest"),url=s+(s.indexOf("?")<0?"?":"&")+"q="+encodeURIComponent(q)+"&base="+encodeURIComponent(form.getAttribute("data-base"));
		["category","virtual"].forEach(function(k){var v=form.getAttribute("data-"+k);if(v)url+="&"+k+"="+encodeURIComponent(v);});
		fetch(url,{credentials:"same-origin",signal:ctrl?ctrl.signal:undefined}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&input.value.trim()===q){cache[q]=d;render(q,d);}}).catch(function(){});
	}
	input.addEventListener("input",function(){clearTimeout(timer);timer=setTimeout(lookup,200);});
	input.addEventListener("keydown",function(e){
		if(list.hidden&&e.key==="ArrowDown"){last="";lookup();return;}
		if(e.key==="ArrowDown"){e.preventDefault();setActive(active+1);}
		else if(e.key==="ArrowUp"){if(!list.hidden){e.preventDefault();setActive(active<0?-1:active-1);}}
		else if(e.key==="Escape"){if(!list.hidden){e.preventDefault();cancel();close();}}
		else if(e.key==="Enter"&&active>=0&&!list.hidden){e.preventDefault();window.location.href=options()[active].getAttribute("data-url");}
	});
	list.addEventListener("mousedown",function(e){e.preventDefault();});
	list.addEventListener("click",function(e){var o=e.target.closest("[role=option]");if(o){window.location.href=o.getAttribute("data-url");}});
	input.addEventListener("blur",function(){cancel();setTimeout(close,120);});
	document.addEventListener("pointerdown",function(e){if(!form.contains(e.target)){cancel();close();}});
	input.addEventListener("focus",function(){if(chars(input.value.trim())>=2){last="";lookup();}});
});
})();
JS;
	}

	/**
	 * Events for the weekend row, spread across its days: each day is fetched (in parallel),
	 * places are shared round-robin, repeats of the same event on several days are skipped,
	 * and the row is shown in day order.
	 *
	 * @return Evvnt_Calendar_Event[]
	 */
	public function weekend_events( $from, $to, array $filters, array $publisher ) {
		$days = array();
		for ( $day = new DateTimeImmutable( $from ); $day->format( 'Y-m-d' ) <= $to; $day = $day->modify( '+1 day' ) ) {
			$days[] = $day->format( 'Y-m-d' );
		}
		$by_day = $this->client->events_by_day( $days, self::ROW_SIZE, $filters );
		$queues = array();
		foreach ( $days as $day ) {
			$queues[ $day ] = isset( $by_day[ $day ] ) && is_array( $by_day[ $day ] ) ? $this->to_events( $by_day[ $day ], $publisher ) : array();
		}
		// Round-robin, one event per day per round, so places are shared as evenly as the days
		// allow (10 over Thu-Sun = 3, 3, 2, 2); a recurring event is only taken once.
		$picked = array_fill_keys( $days, array() );
		$seen   = array();
		$count  = 0;
		while ( $count < self::ROW_SIZE && array_filter( $queues ) ) {
			foreach ( $days as $day ) {
				while ( $queues[ $day ] ) {
					$event = array_shift( $queues[ $day ] );
					$key   = strtolower( $event->title() );
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ]     = true;
						$picked[ $day ][] = $event;
						++$count;
						break;
					}
				}
				if ( $count >= self::ROW_SIZE ) {
					break;
				}
			}
		}
		return $picked ? array_merge( ...array_values( $picked ) ) : array();
	}

	/**
	 * The coming weekend, Thursday to Sunday, in the site's time zone. From Thursday to Sunday
	 * it runs from today to Sunday.
	 *
	 * @param DateTimeImmutable|null $now For tests.
	 * @return string[] array( from Y-m-d, to Y-m-d )
	 */
	public static function weekend_range( $now = null ) {
		$today = ( $now ? $now : new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		$dow   = (int) $today->format( 'N' ); // 1 = Monday … 7 = Sunday.
		$from  = $dow >= 4 ? $today : $today->modify( '+' . ( 4 - $dow ) . ' days' );
		$to    = $today->modify( '+' . ( 7 - $dow ) . ' days' );
		return array( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
	}

	/**
	 * Heading for a date-range listing: "This Weekend" for the coming weekend, else the dates.
	 */
	public function dates_label( $from, $to ) {
		if ( array( $from, $to ) === self::weekend_range() ) {
			return __( 'This Weekend', 'evvnt-calendar' );
		}
		$zone = wp_timezone();
		$f    = wp_date( 'M j', strtotime( $from . ' 12:00' ), $zone );
		$t    = wp_date( 'M j, Y', strtotime( $to . ' 12:00' ), $zone );
		/* translators: 1: start date, 2: end date. */
		return $from === $to ? wp_date( 'l, M j, Y', strtotime( $from . ' 12:00' ), $zone ) : sprintf( __( 'Events from %1$s to %2$s', 'evvnt-calendar' ), $f, $t );
	}

	/**
	 * Previous/next buttons for the rows (progressive enhancement: rows scroll and swipe
	 * natively; the buttons are only shown when JavaScript runs and a row overflows).
	 */
	public static function enqueue_rows_script() {
		if ( wp_script_is( 'evvnt-calendar-rows', 'enqueued' ) ) {
			return;
		}
		wp_register_script( 'evvnt-calendar-rows', false, array(), EVVNT_CALENDAR_VERSION, true );
		wp_enqueue_script( 'evvnt-calendar-rows' );
		wp_add_inline_script(
			'evvnt-calendar-rows',
			'(function(){var reduce=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;'
			. 'document.querySelectorAll(".evvnt-cal__row").forEach(function(row){'
			. 'var track=row.querySelector(".evvnt-cal__row-track"),ctl=row.querySelector(".evvnt-cal__row-controls");if(!track||!ctl)return;'
			. 'var prev=ctl.querySelector("[data-dir=\"-1\"]"),next=ctl.querySelector("[data-dir=\"1\"]"),rtl=getComputedStyle(track).direction==="rtl",s=rtl?-1:1;'
			. 'function update(){if(!track.firstElementChild)return;var t=track.getBoundingClientRect(),f=track.firstElementChild.getBoundingClientRect(),l=track.lastElementChild.getBoundingClientRect();'
			. 'ctl.hidden=track.scrollWidth-track.clientWidth<=2;'
			. 'prev.disabled=rtl?f.right<=t.right+2:f.left>=t.left-2;next.disabled=rtl?l.left>=t.left-2:l.right<=t.right+2;}'
			. '[prev,next].forEach(function(b){b.addEventListener("click",function(){track.scrollBy({left:s*track.clientWidth*0.85*Number(b.getAttribute("data-dir")),behavior:reduce?"auto":"smooth"});});});'
			. 'track.addEventListener("scroll",update,{passive:true});window.addEventListener("resize",update);update();});})();'
		);
	}

	/**
	 * Class list for a view's root element, including the configured width.
	 *
	 * @param string $view Modifier, e.g. "calendar" or "detail".
	 * @return string
	 */
	public function root_class( $view ) {
		$classes = array( 'evvnt-cal', 'evvnt-cal--' . sanitize_html_class( $view ) );
		if ( 'content' !== ( isset( $this->settings['calendar_width'] ) ? $this->settings['calendar_width'] : 'wide' ) ) {
			$classes[] = 'evvnt-cal--wide';
		}
		return implode( ' ', $classes );
	}

	/**
	 * Render an event detail view.
	 *
	 * @param Evvnt_Calendar_Event $event Event.
	 * @param string               $base  Calendar page URL.
	 * @param array|null           $query Request query arguments (default $_GET), for the
	 *                                    Upcoming dates view state.
	 * @return string
	 */
	public function render_detail( Evvnt_Calendar_Event $event, $base, $query = null ) {
		if ( null === $query ) {
			$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state (evView, evDates), whitelisted below.
		}
		/**
		 * Filters which detail layout an event uses: 'featured' (full-width hero) for events
		 * the publisher features, 'standard' (as the discovery plugin) for the rest.
		 *
		 * @param string               $layout 'featured' or 'standard'.
		 * @param Evvnt_Calendar_Event $event  Event.
		 */
		$layout = apply_filters( 'evvnt_calendar_detail_layout', $event->is_featured() ? 'featured' : 'standard', $event );
		return $this->template(
			'featured' === $layout ? 'event-detail-featured' : 'event-detail',
			array(
				'event'        => $event,
				'url'          => Evvnt_Calendar_Route::event_url( $base, $event->slug() ),
				'recommended'  => $this->recommended( $event ),
				'base'         => $base,
				'back_url'     => Evvnt_Calendar_Route::calendar_url( $base ),
				'submit_url'   => $this->submit_url( $this->publisher() ),
				'submit_label' => $this->settings['submission_label'],
				'dates'        => $this->upcoming_dates_view( $event, $base, is_array( $query ) ? $query : array() ),
			)
		);
	}

	/* ---------- upcoming dates of a recurring series ---------- */

	const VIEW_CHIPS    = 'chips';
	const VIEW_LIST     = 'list';
	const VIEW_CALENDAR = 'calendar';

	/** Thresholds from the discovery plugin's UpcomingDates (EventDetail/UpcomingDates/index.js). */
	const MAX_DATES_FOR_CHIPS                 = 3;
	const MAX_DATES_BEFORE_WINDOWING          = 18;
	const DATES_IN_WINDOW                     = 12;
	const DATES_PER_MONTH_TO_OPEN_ON_CALENDAR = 12;
	const DATES_PER_MONTH_TO_OFFER_CALENDAR   = 4;

	/** Anchor of the Upcoming dates section; links between dates land back on it. */
	const DATES_ANCHOR = 'evvnt-cal-dates';

	/**
	 * Which views a series offers, as discovery's chooseViews(): up to 3 dates as chips; up to
	 * 18 as a list; more as a list windowed to 12 ("Show all N dates"), opening on (or offering)
	 * a month calendar when the dates are dense or irregular and no day has two.
	 *
	 * @param Evvnt_Calendar_Recurrence $recurrence Series.
	 * @return array { default, toggled (view or null), windowed }
	 */
	public static function choose_date_views( Evvnt_Calendar_Recurrence $recurrence ) {
		$count = count( $recurrence->upcoming_dates() );
		if ( $count <= self::MAX_DATES_FOR_CHIPS ) {
			return array( 'default' => self::VIEW_CHIPS, 'toggled' => null, 'windowed' => false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		if ( $count <= self::MAX_DATES_BEFORE_WINDOWING ) {
			return array( 'default' => self::VIEW_LIST, 'toggled' => null, 'windowed' => false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		$can_calendar = ! $recurrence->has_several_dates_on_one_day();
		$per_month    = $recurrence->upcoming_dates_per_month();
		if ( $can_calendar && $per_month >= self::DATES_PER_MONTH_TO_OPEN_ON_CALENDAR ) {
			return array( 'default' => self::VIEW_CALENDAR, 'toggled' => self::VIEW_LIST, 'windowed' => true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		$offers = $can_calendar && ( $per_month >= self::DATES_PER_MONTH_TO_OFFER_CALENDAR || null === $recurrence->pattern_label() );
		return array( 'default' => self::VIEW_LIST, 'toggled' => $offers ? self::VIEW_CALENDAR : null, 'windowed' => true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Everything the event-dates template needs to show a series' upcoming dates, or null when
	 * the event is not a recurring series with dates to come.
	 *
	 * The view the visitor chose (?evView=list|calendar) and "show all" (?evDates=all) are
	 * carried in the query string, as in the discovery plugin, so they survive moving between
	 * dates; each date links to its own event page, back at this section.
	 *
	 * @param Evvnt_Calendar_Event $event Event being viewed.
	 * @param string               $base  Calendar page URL.
	 * @param array                $query Request query arguments.
	 * @return array|null
	 */
	public function upcoming_dates_view( Evvnt_Calendar_Event $event, $base, array $query ) {
		if ( ! $event->shows_recurrence() ) {
			return null;
		}
		$recurrence = $event->recurrence();
		$views      = self::choose_date_views( $recurrence );
		$chosen     = isset( $query['evView'] ) && is_string( $query['evView'] ) ? $query['evView'] : '';
		$shown      = in_array( $chosen, array_filter( array( $views['default'], $views['toggled'] ) ), true ) ? $chosen : $views['default'];
		$link_query = $shown === $views['default'] ? array() : array( 'evView' => $shown );
		$all        = $views['windowed'] && isset( $query['evDates'] ) && 'all' === $query['evDates'];
		$upcoming   = $recurrence->upcoming_dates();
		$viewed     = $event->viewed_date();
		$next       = $recurrence->next_date();

		$date_query = self::VIEW_LIST === $shown && $all ? $link_query + array( 'evDates' => 'all' ) : $link_query;
		$link       = function ( $date ) use ( $event, $base, $date_query ) {
			return self::dates_link( Evvnt_Calendar_Route::event_url( $base, $event->slug( $date['occurrence_id'] ) ), $date_query );
		};
		$here       = Evvnt_Calendar_Route::event_url( $base, $event->slug() );

		$items = array();
		foreach ( ( self::VIEW_LIST === $shown && $views['windowed'] && ! $all ) ? array_slice( $upcoming, 0, self::DATES_IN_WINDOW ) : $upcoming as $date ) {
			$tone    = Evvnt_Calendar_Recurrence::same( $date, $viewed ) ? 'viewed' : ( Evvnt_Calendar_Recurrence::same( $date, $next ) ? 'next' : 'upcoming' );
			$items[] = array(
				'tone'     => $tone,
				'day'      => $event->format( $date['start'], 'D, M j' ),
				'time'     => $event->format( $date['start'], 'g:i A' ),
				'datetime' => $date['start']->format( DATE_ATOM ),
				'url'      => ( $date['occurrence_id'] && 'viewed' !== $tone ) ? $link( $date ) : '',
			);
		}

		$toggle = array();
		if ( $views['toggled'] ) {
			foreach ( array( self::VIEW_LIST => __( 'List', 'evvnt-calendar' ), self::VIEW_CALENDAR => __( 'Calendar', 'evvnt-calendar' ) ) as $view => $label ) {
				$toggle[] = array(
					'label'   => $label,
					'pressed' => $view === $shown,
					// "Show all" survives switching views, as discovery's evDates query does.
					'url'     => self::dates_link( $here, ( $view === $views['default'] ? array() : array( 'evView' => $view ) ) + ( $all ? array( 'evDates' => 'all' ) : array() ) ),
				);
			}
		}

		return array(
			'id'           => self::DATES_ANCHOR,
			'view'         => $shown,
			'caption'      => self::VIEW_CHIPS === $shown ? __( 'Tap a date to preview it below · full details always on this one page', 'evvnt-calendar' ) : $recurrence->upcoming_dates_summary(),
			'toggle'       => $toggle,
			'items'        => self::VIEW_CALENDAR === $shown ? array() : $items,
			'total'        => count( $upcoming ),
			'show_all_url' => ( self::VIEW_LIST === $shown && $views['windowed'] && ! $all ) ? self::dates_link( $here, $link_query + array( 'evDates' => 'all' ) ) : '',
			'months'       => self::VIEW_CALENDAR === $shown ? $this->date_months( $event, $link ) : array(),
		);
	}

	/**
	 * Month grids for the calendar view, from the month of the next date to the month of the
	 * last; each day with dates links to the first of them that has its own page (not this one).
	 *
	 * @param Evvnt_Calendar_Event $event Event being viewed.
	 * @param callable             $link  URL of a date's page.
	 * @return array[] { label, key, current, weeks: [ [ { day, date, label, scheduled, next, viewed, url } | null ] ] }
	 */
	private function date_months( Evvnt_Calendar_Event $event, callable $link ) {
		$recurrence  = $event->recurrence();
		$viewed      = $event->viewed_date();
		$next        = $recurrence->next_date();
		$highlighted = $event->highlighted_date();
		$by_day      = array();
		foreach ( $recurrence->upcoming_dates() as $date ) {
			$by_day[ $date['start']->format( 'Y-m-d' ) ][] = $date;
		}

		$utc        = new DateTimeZone( 'UTC' );
		$month      = new DateTimeImmutable( $next['start']->format( 'Y-m-01' ), $utc );
		$last       = new DateTimeImmutable( $recurrence->last_date()['start']->format( 'Y-m-01' ), $utc );
		$current    = $highlighted ? $highlighted['start']->format( 'Y-m' ) : $month->format( 'Y-m' );
		$week_start = (int) get_option( 'start_of_week', 0 );
		$months     = array();
		for ( $guard = 0; $month <= $last && $guard < 60; $guard++, $month = $month->modify( '+1 month' ) ) {
			$cells = array_fill( 0, ( (int) $month->format( 'w' ) - $week_start + 7 ) % 7, null );
			for ( $day = 1, $days = (int) $month->format( 't' ); $day <= $days; $day++ ) {
				$key   = $month->format( 'Y-m-' ) . sprintf( '%02d', $day );
				$dates = isset( $by_day[ $key ] ) ? $by_day[ $key ] : array();
				$cell  = array(
					'day'       => $day,
					'date'      => $key,
					'label'     => wp_date( 'l, F j, Y', ( new DateTimeImmutable( $key, $utc ) )->getTimestamp(), $utc ),
					'scheduled' => (bool) $dates,
					'next'      => false,
					'viewed'    => false,
					'url'       => '',
				);
				foreach ( $dates as $date ) {
					$cell['next']   = $cell['next'] || Evvnt_Calendar_Recurrence::same( $date, $next );
					$cell['viewed'] = $cell['viewed'] || Evvnt_Calendar_Recurrence::same( $date, $viewed );
					if ( '' === $cell['url'] && $date['occurrence_id'] && ! Evvnt_Calendar_Recurrence::same( $date, $viewed ) ) {
						$cell['url'] = $link( $date );
					}
				}
				$cells[] = $cell;
			}
			while ( count( $cells ) % 7 ) {
				$cells[] = null;
			}
			$months[] = array(
				'key'     => $month->format( 'Y-m' ),
				'label'   => wp_date( 'F Y', $month->getTimestamp(), $utc ),
				'current' => $month->format( 'Y-m' ) === $current,
				'weeks'   => array_chunk( $cells, 7 ),
			);
		}
		if ( $months && ! array_filter( wp_list_pluck( $months, 'current' ) ) ) {
			$months[0]['current'] = true;
		}
		return $months;
	}

	/** Weekday headings for the calendar view, from the site's first day of the week. */
	public static function calendar_weekdays() {
		global $wp_locale;
		$start = (int) get_option( 'start_of_week', 0 );
		$names = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$day     = ( $start + $i ) % 7;
			$names[] = array(
				'short' => $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day ) ) : gmdate( 'D', strtotime( "Sunday +{$day} days" ) ),
				'full'  => $wp_locale ? $wp_locale->get_weekday( $day ) : gmdate( 'l', strtotime( "Sunday +{$day} days" ) ),
			);
		}
		return $names;
	}

	/** $url with view-state query arguments, landing on the Upcoming dates section. */
	private static function dates_link( $url, array $args ) {
		foreach ( $args as $key => $value ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
		return $url . '#' . self::DATES_ANCHOR;
	}

	/** Enqueue the month paging script for the calendar view (once per page). */
	public static function enqueue_dates_script() {
		if ( wp_script_is( 'evvnt-calendar-dates', 'enqueued' ) ) {
			return;
		}
		wp_register_script( 'evvnt-calendar-dates', false, array(), EVVNT_CALENDAR_VERSION, true );
		wp_enqueue_script( 'evvnt-calendar-dates' );
		// Without JavaScript every month is shown, one under another.
		wp_add_inline_script(
			'evvnt-calendar-dates',
			'(function(){document.querySelectorAll(".evvnt-cal__datecal").forEach(function(cal){'
			. 'var months=[].slice.call(cal.querySelectorAll(".evvnt-cal__datecal-month")),nav=cal.querySelector(".evvnt-cal__datecal-nav");if(!nav||months.length<2)return;'
			. 'var prev=nav.querySelector("[data-dir=\'-1\']"),next=nav.querySelector("[data-dir=\'1\']"),label=nav.querySelector(".evvnt-cal__datecal-label"),i=Math.max(0,months.findIndex(function(m){return m.hasAttribute("data-current");}));'
			. 'function show(){months.forEach(function(m,j){m.hidden=j!==i;});label.textContent=months[i].getAttribute("data-label");prev.disabled=i===0;next.disabled=i===months.length-1;}'
			. 'prev.addEventListener("click",function(){if(i>0){i--;show();}});next.addEventListener("click",function(){if(i<months.length-1){i++;show();}});'
			. 'nav.hidden=false;cal.classList.add("evvnt-cal__datecal--paged");show();});})();'
		);
	}

	/**
	 * Up to $limit recommended events for an event: the publisher's featured events in the
	 * same main category, current and past events excluded, chosen at random weighted by
	 * promotion_weight() and shuffled, as the discovery JS's FeaturedEventChooser does.
	 * Fewer (or none, hiding the section) when the category has fewer featured events.
	 *
	 * @param Evvnt_Calendar_Event $event Event being viewed.
	 * @param int                  $limit Number of events.
	 * @return Evvnt_Calendar_Event[]
	 */
	public function recommended( Evvnt_Calendar_Event $event, $limit = 6 ) {
		$response = $this->client->recommended_events( $event->main_category_id() );
		if ( is_wp_error( $response ) || empty( $response['events'] ) || ! is_array( $response['events'] ) ) {
			return array();
		}
		$publisher  = $this->publisher();
		$candidates = array();
		foreach ( $this->to_events( $response['events'], $publisher ) as $candidate ) {
			// Featured events only (checked here too, in case the API filter is ever ignored).
			if ( $candidate->is_featured() && $candidate->object_id() !== $event->object_id() && $candidate->url_id() !== $event->url_id() && ! $candidate->is_expired() ) {
				$candidates[] = $candidate;
			}
		}
		return self::choose_weighted( $candidates, $limit );
	}

	/**
	 * Weighted random sample without replacement, then shuffled (FeaturedEventChooser.choose()).
	 *
	 * @param Evvnt_Calendar_Event[] $events Candidates.
	 * @param int                    $limit  Number to pick.
	 * @return Evvnt_Calendar_Event[]
	 */
	public static function choose_weighted( array $events, $limit ) {
		$chosen = array();
		if ( count( $events ) <= $limit ) {
			$chosen = $events;
		} else {
			while ( count( $chosen ) < $limit && $events ) {
				$total = 0;
				foreach ( $events as $candidate ) {
					$total += $candidate->promotion_weight();
				}
				$pick = wp_rand( 1, max( 1, $total ) );
				foreach ( $events as $index => $candidate ) {
					$pick -= $candidate->promotion_weight();
					if ( $pick <= 0 ) {
						$chosen[] = $candidate;
						unset( $events[ $index ] );
						break;
					}
				}
			}
		}
		shuffle( $chosen );
		return array_values( $chosen );
	}

	/**
	 * Inline SVG icon (decorative; text always accompanies it).
	 *
	 * @param string $name calendar|clock|pin|ticket|external|mail|back.
	 * @param int    $size Pixel size.
	 * @return string
	 */
	public static function icon( $name, $size = 20 ) {
		if ( 'ticket' === $name ) {
			// The ticket-with-star glyph used by the discovery plugin, recoloured to follow the text.
			return '<svg class="evvnt-cal__icon evvnt-cal__icon--ticket" viewBox="0 0 18 18" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true" focusable="false"><path d="M6.76 12L9 10.425L11.2 12L10.36 9.45L12.6 7.8H9.88L9 5.25L8.12 7.8H5.4L7.6 9.45L6.76 12ZM2.6 15C2.16 15 1.78333 14.8531 1.47 14.5594C1.15667 14.2656 1 13.9125 1 13.5V10.9688C1 10.8313 1.04667 10.7125 1.14 10.6125C1.23333 10.5125 1.35333 10.45 1.5 10.425C1.82 10.325 2.08333 10.1438 2.29 9.88125C2.49667 9.61875 2.6 9.325 2.6 9C2.6 8.675 2.49667 8.38125 2.29 8.11875C2.08333 7.85625 1.82 7.675 1.5 7.575C1.35333 7.55 1.23333 7.4875 1.14 7.3875C1.04667 7.2875 1 7.16875 1 7.03125V4.5C1 4.0875 1.15667 3.73438 1.47 3.44063C1.78333 3.14687 2.16 3 2.6 3H15.4C15.84 3 16.2167 3.14687 16.53 3.44063C16.8433 3.73438 17 4.0875 17 4.5V7.03125C17 7.16875 16.9533 7.2875 16.86 7.3875C16.7667 7.4875 16.6467 7.55 16.5 7.575C16.18 7.675 15.9167 7.85625 15.71 8.11875C15.5033 8.38125 15.4 8.675 15.4 9C15.4 9.325 15.5033 9.61875 15.71 9.88125C15.9167 10.1438 16.18 10.325 16.5 10.425C16.6467 10.45 16.7667 10.5125 16.86 10.6125C16.9533 10.7125 17 10.8313 17 10.9688V13.5C17 13.9125 16.8433 14.2656 16.53 14.5594C16.2167 14.8531 15.84 15 15.4 15H2.6ZM2.6 13.5H15.4V11.5875C14.9067 11.3125 14.5167 10.9469 14.23 10.4906C13.9433 10.0344 13.8 9.5375 13.8 9C13.8 8.4625 13.9433 7.96563 14.23 7.50938C14.5167 7.05313 14.9067 6.6875 15.4 6.4125V4.5H2.6V6.4125C3.09333 6.6875 3.48333 7.05313 3.77 7.50938C4.05667 7.96563 4.2 8.4625 4.2 9C4.2 9.5375 4.05667 10.0344 3.77 10.4906C3.48333 10.9469 3.09333 11.3125 2.6 11.5875V13.5Z"/></svg>';
		}
		$paths = array(
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
			'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			'pin'      => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
			'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/>',
			'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
			'back'     => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
			'arrow'    => '<path d="M5 12h14M12 5l7 7-7 7"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'repeat'   => '<path d="m17 1 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="m7 23-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
			'prev'     => '<path d="m15 18-6-6 6-6"/>',
			'next'     => '<path d="m9 18 6-6-6-6"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="evvnt-cal__icon" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Links for every category of an event: a navigation (root) category goes to its calendar
	 * page; a sub-category goes to its parent's page (or the calendar) filtered with
	 * ?subcategory=id|name, as the discovery JS does.
	 *
	 * @return array[] Each array( 'name' => string, 'url' => string ).
	 */
	public function category_links( Evvnt_Calendar_Event $event, $base ) {
		$roots = array();
		foreach ( $this->categories() as $category ) {
			$roots[ $category['id'] ] = $category;
		}
		$parent = isset( $roots[ $event->main_category_id() ] ) ? $roots[ $event->main_category_id() ] : null;
		$links  = array();
		foreach ( $event->categories() as $category ) {
			$name = str_replace( ' / ', ' & ', $category['name'] );
			if ( isset( $roots[ $category['id'] ] ) ) {
				$url = Evvnt_Calendar_Route::calendar_url( $base, $roots[ $category['id'] ]['slug'] );
			} else {
				$url = $this->subcategory_link( $category['id'], $category['name'], $parent ? $parent['slug'] : '', $base );
			}
			$links[] = array(
				'name' => $name,
				'url'  => $url,
			);
		}
		return $links;
	}

	public function render_unavailable() {
		return $this->template( 'unavailable', array() );
	}

	/**
	 * Card markup for one event (used by the calendar template).
	 *
	 * @param Evvnt_Calendar_Event $event        Event.
	 * @param string               $base         Calendar page URL.
	 * @param bool                 $detail_pages Whether the card links to the event's page here.
	 * @param bool                 $featured     Featured styling.
	 * @param string|null          $viewed_from  Where the view is tracked as seen (an
	 *                                           Evvnt_Calendar_Tracking::VIEWED_FROM_* value),
	 *                                           '' to not track it; default "featured" for a
	 *                                           featured card, else "event_list".
	 */
	public function render_card( Evvnt_Calendar_Event $event, $base, $detail_pages, $featured = false, $viewed_from = null ) {
		if ( null === $viewed_from ) {
			$viewed_from = $featured ? Evvnt_Calendar_Tracking::VIEWED_FROM_FEATURED : Evvnt_Calendar_Tracking::VIEWED_FROM_LIST;
		}
		$url      = $detail_pages ? Evvnt_Calendar_Route::event_url( $base, $event->slug() ) : $event->main_link();
		$external = ! $detail_pages;
		return $this->template(
			'event-card',
			array(
				'event'    => $event,
				'url'      => $url ? $url : Evvnt_Calendar_Route::event_url( $base, $event->slug() ),
				'external' => $external && $url,
				'featured' => $featured,
				'tracking' => Evvnt_Calendar_Tracking::view_attrs( $event, $viewed_from ),
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
