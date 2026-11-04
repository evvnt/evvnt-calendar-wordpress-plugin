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
		if ( $route->subcategory_id ) {
			$filters['subCategoryIds'] = $route->subcategory_id;
		}
		if ( '' !== $route->from_date ) {
			$filters['fromDate'] = $route->from_date;
			$filters['toDate']   = $route->to_date;
		}
		if ( ! empty( $atts['virtual'] ) ) {
			$filters['virtual'] = 'true';
		}

		if ( '' !== $route->search ) {
			$response = $this->client->search_events( $route->search, $route->page - 1, Evvnt_Calendar_Discovery_Client::HITS_PER_PAGE, $filters );
			$key      = 'events';
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
			'featured' => ( 1 === $route->page && '' === $route->search && ! empty( $response['rawFeaturedEvents'] ) && is_array( $response['rawFeaturedEvents'] ) ) ? $response['rawFeaturedEvents'] : array(),
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
		$slug      = $selected ? $selected['slug'] : '';
		$count     = count( $listing['raw'] );
		$page_url  = function ( $page ) use ( $base, $slug, $route ) {
			$url = $route->subcategory_id
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
				'not_found'    => 'not_found' === $route->type || ( 'category' === $route->type && ! $pinned && ! $selected ),
				'page'         => $route->page,
				'prev_url'     => $route->page > 1 ? $page_url( $route->page - 1 ) : '',
				'next_url'     => ( $count >= Evvnt_Calendar_Discovery_Client::HITS_PER_PAGE && $route->page < Evvnt_Calendar_Route::MAX_PAGE ) ? $page_url( $route->page + 1 ) : '',
				'search'       => $route->search,
				'atts'         => $atts,
				'search_categories' => ( '' !== $route->search && ! $pinned ) ? $this->category_matches( $route->search, $listing['raw'], $base ) : array(),
				'subcategory'  => $route->subcategory_id ? ( '' !== $route->subcategory_name ? $route->subcategory_name : __( 'Filtered', 'evvnt-calendar' ) ) : '',
				'clear_sub'    => $route->subcategory_id ? Evvnt_Calendar_Route::calendar_url( $base, $slug ) : '',
				'base'         => $base,
				'detail_pages' => ! empty( $atts['detail_page_enabled'] ),
				'submit_url'   => $this->submit_url( $publisher ),
				'submit_label' => $this->settings['submission_label'],
				'calendar_url' => Evvnt_Calendar_Route::calendar_url( $base ),
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
					'url'  => Evvnt_Calendar_Route::subcategory_url( $base, $parent ? $parent['slug'] : '', $category['id'], $category['name'] ),
				);
			}
		}
		return array_slice( array_values( $found ), 0, $limit );
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
	public function search_form( $base, $current = '', array $atts = array() ) {
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
				'suggest' => rest_url( 'evvnt-calendar/v1/suggest' ),
				'base'    => $base,
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
	if(!input||!list)return;
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
	 */
	public function render_detail( Evvnt_Calendar_Event $event, $base ) {
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
			)
		);
	}

	/**
	 * Up to $limit recommended events for an event, chosen as the discovery JS does:
	 * same main category, current event excluded, random selection weighted by
	 * promotion_weight(), then shuffled.
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
			if ( $candidate->object_id() !== $event->object_id() && $candidate->url_id() !== $event->url_id() && ! $candidate->is_expired() ) {
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
				$url = Evvnt_Calendar_Route::subcategory_url( $base, $parent ? $parent['slug'] : '', $category['id'], $category['name'] );
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
