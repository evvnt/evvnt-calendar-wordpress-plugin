<?php
/**
 * Tests for the server-side rendering classes, run against a WordPress install:
 *
 *   php tests/run-tests.php /path/to/wordpress
 *
 * The plugin must be active in that install. API calls are served from
 * tests/fixtures/*.json (captured from the live discovery API for publisher 5293,
 * with personal data removed); no network requests are made.
 *
 * @package Evvnt_Calendar
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( empty( $argv[1] ) || ! is_file( rtrim( $argv[1], '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/run-tests.php /path/to/wordpress\n" );
	exit( 2 );
}
define( 'EVVNT_CALENDAR_TESTING', true ); // Router::config() re-reads settings on each call.
require rtrim( $argv[1], '/' ) . '/wp-load.php';

if ( ! class_exists( 'Evvnt_Calendar_Event' ) ) {
	fwrite( STDERR, "The evvnt-calendar plugin is not active in that install.\n" );
	exit( 2 );
}

// Start from known settings (query-string URLs); restored at the end.
$evvnt_original_settings = get_option( Evvnt_Calendar_Settings::OPTION );
// Start without a stored category tree (the site's own is restored at the end).
$evvnt_original_tree = get_option( Evvnt_Calendar_Category_Tree::OPTION );
delete_option( Evvnt_Calendar_Category_Tree::OPTION );
delete_transient( Evvnt_Calendar_Category_Tree::LOCK );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js', 'submission_label' => 'Promote your event' ) );

$GLOBALS['evvnt_pass'] = 0;
$GLOBALS['evvnt_fail'] = 0;

function evvnt_check( $name, $actual, $expected ) {
	if ( $actual === $expected ) {
		$GLOBALS['evvnt_pass']++;
		echo "  PASS $name\n";
	} else {
		$GLOBALS['evvnt_fail']++;
		echo "  FAIL $name\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true ) . "\n";
	}
}

function evvnt_fixture( $name ) {
	return json_decode( file_get_contents( __DIR__ . '/fixtures/' . $name . '.json' ), true );
}

/**
 * Fake transport: serves fixtures by URL and records requested URLs.
 */
class Evvnt_Test_Transport {
	public $urls      = array();
	public $overrides = array();

	public function __invoke( $url ) {
		$this->urls[] = $url;
		foreach ( $this->overrides as $pattern => $result ) {
			if ( preg_match( $pattern, $url ) ) {
				return is_callable( $result ) ? $result( $url ) : $result;
			}
		}
		if ( false !== strpos( $url, '/publisher_settings' ) ) {
			return evvnt_fixture( 'publisher_settings' );
		}
		if ( false !== strpos( $url, '/home_page_events' ) ) {
			return evvnt_fixture( 'home_page_events' );
		}
		if ( preg_match( '#/events/3743362\b#', $url ) ) {
			return evvnt_fixture( 'event_3743362' );
		}
		if ( preg_match( '#/events/[^?]+#', $url ) ) {
			return new WP_Error( 'evvnt_calendar_http', 'HTTP 404', array( 'status' => 404 ) );
		}
		if ( false !== strpos( $url, 'categoryId=17' ) ) {
			return evvnt_fixture( 'events_category17' );
		}
		if ( preg_match( '#^https://api\.evvnt\.com/categories/(\d+)$#', $url, $m ) ) {
			// A small category tree: Arts > (Visual Arts, Performing Arts > (Comedy, Theatre, Other), Film / Cinema, Other).
			$tree = array(
				28 => array( array( 'id' => 39, 'name' => 'Visual Arts' ), array( 'id' => 30, 'name' => 'Performing Arts' ), array( 'id' => 7, 'name' => 'Film / Cinema' ), array( 'id' => 29, 'name' => 'Other' ) ),
				30 => array( array( 'id' => 3, 'name' => 'Comedy' ), array( 'id' => 23, 'name' => 'Theatre' ), array( 'id' => 31, 'name' => 'Other' ) ),
				16 => array( array( 'id' => 37, 'name' => 'Live Music' ), array( 'id' => 500, 'name' => 'Page' ), array( 'id' => 501, 'name' => 'Live  Music' ) ),
			);
			return isset( $tree[ (int) $m[1] ] ) ? $tree[ (int) $m[1] ] : array();
		}
		return evvnt_fixture( 'events_page1' );
	}
}

function evvnt_client( $transport ) {
	Evvnt_Calendar_Cache::flush();
	return new Evvnt_Calendar_Discovery_Client( 5293, new Evvnt_Calendar_Cache(), $transport );
}

$publisher = evvnt_fixture( 'publisher_settings' )['publisher'];
$base      = 'http://example.test/?page_id=4';

/* ------------------------------------------------------------------ */
echo "== Route\n";

$r = Evvnt_Calendar_Route::from_query( array() );
evvnt_check( 'empty query is the calendar, page 1', array( $r->type, $r->page ), array( 'calendar', 1 ) );

$r = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/event/3743362-pink-floyd-s-the-dark-side-of-the-moon-immersive-experience' ) );
evvnt_check( 'event route', array( $r->type, $r->event_id, $r->event_segment ), array( 'event', '3743362', '3743362-pink-floyd-s-the-dark-side-of-the-moon-immersive-experience' ) );

$r = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/event/1234t-some-show' ) );
evvnt_check( 'suffixed event id', array( $r->type, $r->event_id ), array( 'event', '1234t' ) );

$r = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife', 'evPage' => '3' ) );
evvnt_check( 'category route with page', array( $r->type, $r->category_slug, $r->page ), array( 'category', 'nightlife', 3 ) );

$r = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts', 'subcategory' => '7|Film / Cinema^9|Other' ) );
evvnt_check( 'subcategory parameter (discovery JS format)', array( $r->type, $r->subcategory_id, $r->subcategory_name ), array( 'category', 7, 'Film / Cinema' ) );
evvnt_check( 'bad subcategory ignored', Evvnt_Calendar_Route::from_query( array( 'subcategory' => '<x>|y' ) )->subcategory_id, 0 );
evvnt_check( 'subcategory_url', Evvnt_Calendar_Route::subcategory_url( $base, 'arts', 7, 'Film / Cinema', 2 ), 'http://example.test/?page_id=4&_evDiscoveryPath=/arts&evPage=2&subcategory=7%7CFilm%20%2F%20Cinema' );
evvnt_check( 'leading-zero event id rejected', Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/event/03743362-x' ) )->type, 'not_found' );
evvnt_check( 'non-numeric evPage is page 1', Evvnt_Calendar_Route::from_query( array( 'evPage' => 'abc' ) )->page, 1 );
evvnt_check( 'evPage is capped', Evvnt_Calendar_Route::from_query( array( 'evPage' => '999999' ) )->page, Evvnt_Calendar_Route::MAX_PAGE );
evvnt_check( 'array evPage is ignored', Evvnt_Calendar_Route::from_query( array( 'evPage' => array( 2 ) ) )->page, 1 );

$r = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/performing-arts/comedy' ) );
evvnt_check( 'sub-category path route', array( $r->type, $r->category_slug, $r->subcategory_path, $r->category_path() ), array( 'category', 'arts', 'performing-arts/comedy', 'arts/performing-arts/comedy' ) );
evvnt_check( 'sub-category path URL (query-string mode)', Evvnt_Calendar_Route::calendar_url( $base, 'arts/performing-arts', 2 ), 'http://example.test/?page_id=4&_evDiscoveryPath=/arts/performing-arts&evPage=2' );
foreach ( array( '/a/B/c', '/a/b/c/d/e/f/g/h', '/event/../../x', '/<script>', '/event/', '/Nightlife', '/event/a b', '/event', '/arts//x' ) as $path ) {
	evvnt_check( "malformed path $path is not_found", Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => $path ) )->type, 'not_found' );
}

$r = Evvnt_Calendar_Route::from_query( array( '_ev_id' => '3743362_x' ) );
evvnt_check( 'legacy _ev_id with _ becomes -', $r->legacy_event_id, '3743362-x' );
evvnt_check( 'bad legacy _ev_id ignored', Evvnt_Calendar_Route::from_query( array( '_ev_id' => '"><x' ) )->legacy_event_id, null );

evvnt_check( 'calendar_url keeps page_id', Evvnt_Calendar_Route::calendar_url( $base, 'live-music', 2 ), 'http://example.test/?page_id=4&_evDiscoveryPath=/live-music&evPage=2' );
evvnt_check( 'calendar_url drops existing route', Evvnt_Calendar_Route::calendar_url( $base . '&_evDiscoveryPath=/x&evPage=9' ), $base );
evvnt_check( 'event_url', Evvnt_Calendar_Route::event_url( $base, '3743362-pink' ), 'http://example.test/?page_id=4&_evDiscoveryPath=/event/3743362-pink' );
evvnt_check( 'category slug matches the JS', Evvnt_Calendar_Route::category_slug( 'Kids / Family' ), 'kids-family' );

/* ------------------------------------------------------------------ */
echo "== Event model (against live fixtures)\n";

$all = array_merge(
	evvnt_fixture( 'home_page_events' )['rawEvents'],
	evvnt_fixture( 'home_page_events' )['rawFeaturedEvents'],
	evvnt_fixture( 'events_page1' )['events'],
	evvnt_fixture( 'events_category17' )['events']
);
$mismatch = array();
foreach ( $all as $raw ) {
	$event = new Evvnt_Calendar_Event( $raw, $publisher );
	// source_broadcast_url is the URL Evvnt itself publishes; our slug must match it exactly.
	if ( ! empty( $raw['source_broadcast_url'] ) && false === strpos( $raw['source_broadcast_url'], '/event/' . $event->slug() ) ) {
		$mismatch[] = $raw['source_broadcast_url'] . ' vs ' . $event->slug();
	}
}
// Two fixture events were renamed after Evvnt published their URL; their slug follows the current title.
evvnt_check(
	'slug matches Evvnt\'s own URL for all ' . count( $all ) . ' fixture events (except 2 renamed)',
	array_map(
		function ( $m ) {
			return (int) preg_replace( '#^.*/event/(\d+).*$#', '$1', $m );
		},
		$mismatch
	),
	array( 3876967, 3479968 )
);
$js_style = new Evvnt_Calendar_Event( array( 'title' => 'Crêpes & Company!', 'sources' => array( 'evvnt' ), 'source_id_s' => '3763745' ), $publisher );
evvnt_check( 'accents transliterated, trailing dash trimmed', $js_style->slug(), '3763745-crepes-company' );
evvnt_check( 'title with no latin characters', ( new Evvnt_Calendar_Event( array( 'title' => '東京', 'sources' => array( 'evvnt' ), 'source_id_s' => '9' ), $publisher ) )->slug(), '9' );

$detail = new Evvnt_Calendar_Event( evvnt_fixture( 'event_3743362' )['rawEvent'], $publisher );
evvnt_check( 'detail url id', $detail->url_id(), '3743362' );
evvnt_check( 'multi-day display date', $detail->display_date(), 'Jul 25 - Nov 28, 2026' );
evvnt_check( 'single price', $detail->price_range(), '£23.00' );
evvnt_check( 'location summary (Global publisher)', $detail->location_summary(), 'Cardiff, United Kingdom' );
evvnt_check( 'evvnt links get ?pid=', $detail->main_link(), 'https://go.evvnt.com/3743362-0?pid=5293' );
evvnt_check( 'expired after end', $detail->is_expired( strtotime( '2027-01-01' ) ), true );
evvnt_check( 'not expired before end', $detail->is_expired( strtotime( '2026-11-01' ) ), false );
evvnt_check( 'hero image prefers webp hero', (bool) preg_match( '#/hero_[^/]+\.webp$#', $detail->hero_image_url() ), true );

$schema = $detail->to_schema( 'https://example.test/e' );
evvnt_check( 'schema basics', array( $schema['@type'], $schema['startDate'], $schema['endDate'], $schema['location']['address']['addressLocality'] ), array( 'Event', '2026-07-25T16:30:00+01:00', '2026-11-28T18:15:00+00:00', 'Cardiff' ) );
evvnt_check( 'schema offer', array( $schema['offers']['price'], $schema['offers']['priceCurrency'] ), array( '23.00', 'GBP' ) );

$single = new Evvnt_Calendar_Event(
	array(
		'title'        => 'Vinyl Night',
		'sources'      => array( 'evvnt' ),
		'source_id_s'  => '3449262',
		'start_time'   => '2026-11-03T17:00:00-05:00',
		'end_time'     => '2026-11-03T22:00:00-05:00',
		'start_time_i' => 1793743200,
		'end_time_i'   => 1793761200,
	),
	$publisher
);
evvnt_check( 'single-day display date in event time zone', $single->display_date(), 'Nov 3, 2026 at 5:00 PM' );
evvnt_check( 'hero date label (single day)', $single->date_label(), 'Tuesday, November 3, 2026' );
evvnt_check( 'hero time label (range)', $single->time_label(), '5:00 PM – 10:00 PM' );
evvnt_check( 'hero date label (multi-day)', $detail->date_label(), 'Jul 25 – Nov 28, 2026' );
evvnt_check( 'hero time label (multi-day)', $detail->time_label(), 'Starts 4:30 PM' );
$sum = function ( $summary, $description ) use ( $publisher ) {
	return ( new Evvnt_Calendar_Event( array( 'title' => 't', 'summary' => $summary, 'description' => $description ), $publisher ) )->summary_adds_information();
};
evvnt_check( 'truncated summary is not repeated', $sum( '*** Limit 9 seats *** Each class --MENUProsciutto wrapped...', "*** Limit 9 seats ***\nEach class\n--\nMENU\nProsciutto wrapped Asparagus" ), false );
evvnt_check( 'distinct summary is shown', $sum( 'BYO Vinyl Night!', 'Bring your favorite record over.' ), true );
evvnt_check( 'no prices => no offer', isset( $single->to_schema( 'u' )['offers'] ), false );

$recurring = new Evvnt_Calendar_Event(
	array(
		'title'      => 'r',
		'recurrence' => array(
			'dates' => array(
				array( 'start_time' => '2030-12-01T19:00:00', 'end_time' => '2030-12-01T21:00:00', 'timezone' => 'America/Chicago' ),
				array( 'start_time' => '2030-11-01T19:00:00', 'end_time' => '2030-11-01T21:00:00', 'timezone' => 'America/Chicago' ),
				array( 'start_time' => '2020-01-01T19:00:00', 'end_time' => '2020-01-01T21:00:00', 'timezone' => 'America/Chicago' ),
			),
		),
	),
	$publisher
);
$dates = $recurring->upcoming_dates( 10, strtotime( '2026-01-01' ) );
evvnt_check( 'recurrence: past dropped, sorted, wall-clock time in its zone', array_map( function ( $d ) { return $d['start']->format( 'Y-m-d H:i T' ); }, $dates ), array( '2030-11-01 19:00 CDT', '2030-12-01 19:00 CST' ) );

$mixed = new Evvnt_Calendar_Event(
	array(
		'title'  => 'img',
		'images' => array(
			array( 'original' => array( 'url' => 'http://insecure.test/wide.jpg', 'width' => 1600, 'height' => 900 ), 'featured' => array( 'url' => 'http://insecure.test/f.jpg' ) ),
			array( 'original' => array( 'url' => 'https://secure.test/tall.jpg', 'width' => 800, 'height' => 1000 ) ),
		),
	),
	$publisher
);
evvnt_check( 'insecure images skipped before choosing', $mixed->card_image_url(), 'https://secure.test/tall.jpg' );

$price = function ( $prices ) use ( $publisher ) {
	return ( new Evvnt_Calendar_Event( array( 'title' => 't', 'prices' => $prices ), $publisher ) )->price_range();
};
evvnt_check( 'free', $price( array( 'Free' => 'USD 0.0' ) ), 'Free' );
evvnt_check( 'range sorted', $price( array( 'GA' => 'USD 15.0', 'Member' => 'USD 13.0' ) ), '$13.00 - $15.00' );
evvnt_check( 'unknown currency kept', $price( array( 'GA' => 'SEK 100' ) ), 'SEK 100' );
evvnt_check( 'no prices', $price( array() ), '' );

$tm = new Evvnt_Calendar_Event(
	array(
		'title'    => 'Big Show',
		'sources'  => array( 'ticketmaster' ),
		'objectID' => 'G5vYZ123',
		'links'    => array( 'Tickets' => 'https://www.ticketmaster.com/event/1' ),
	),
	$publisher
);
evvnt_check( 'ticketmaster url id uses objectID + t', $tm->url_id(), 'G5vYZ123t' );
evvnt_check( 'ticketmaster affiliate link', $tm->main_link(), 'https://ticketmaster.evyy.net/c/374335/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/1' ) . '&subid1=5293&sharedid=Evvnt' );

/* ------------------------------------------------------------------ */
echo "== Cache and client\n";

$transport = new Evvnt_Test_Transport();
$client    = evvnt_client( $transport );
$client->publisher_settings();
$client->publisher_settings();
evvnt_check( 'memoised within a request', count( $transport->urls ), 1 );

$client2 = new Evvnt_Calendar_Discovery_Client( 5293, new Evvnt_Calendar_Cache(), $transport );
$client2->publisher_settings();
evvnt_check( 'served from transient on the next request', count( $transport->urls ), 1 );

$client->events( array( 'categoryId' => 17, 'zeta' => 'z' ), 2 );
evvnt_check( 'list URL is sorted and complete', end( $transport->urls ), 'https://discovery.evvnt.com/api/events?categoryId=17&hitsPerPage=30&multipleEventInstances=true&page=2&publisher_id=5293&zeta=z' );

// Stale-on-error: a fresh cache with a soft TTL of 0 must still return the stored copy when the API fails.
$cache = new Evvnt_Calendar_Cache();
$cache->remember( 'stale-test', 3600, function () { return array( 'v' => 1 ); } );
$cache = new Evvnt_Calendar_Cache();
$out   = $cache->remember( 'stale-test', 0, function () { return new WP_Error( 'x', 'down', array( 'status' => 500 ) ); } );
evvnt_check( 'stale copy served on 500', $out, array( 'v' => 1 ) );
evvnt_check( '500 starts backoff', (bool) get_transient( Evvnt_Calendar_Cache::BACKOFF_KEY ), true );
$cache   = new Evvnt_Calendar_Cache();
$called  = false;
$out     = $cache->remember( 'never-cached', 0, function () use ( &$called ) { $called = true; return array(); } );
evvnt_check( 'backoff skips the API and reports 503', array( $called, Evvnt_Calendar_Cache::error_status( $out ) ), array( false, 503 ) );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );
$cache = new Evvnt_Calendar_Cache();
$out   = $cache->remember( 'stale-test', 0, function () { return new WP_Error( 'x', 'gone', array( 'status' => 404 ) ); } );
evvnt_check( '404 is never hidden by stale data', Evvnt_Calendar_Cache::error_status( $out ), 404 );
$cache  = new Evvnt_Calendar_Cache();
$called = false;
$out    = $cache->remember( 'stale-test', 0, function () use ( &$called ) { $called = true; return array( 'v' => 2 ); } );
evvnt_check( 'recent 404 is remembered (no API call)', array( $called, Evvnt_Calendar_Cache::error_status( $out ) ), array( false, 404 ) );

/* ------------------------------------------------------------------ */
echo "== Renderer\n";

$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$atts      = array( 'detail_page_enabled' => true, 'virtual' => false, 'category_id' => 0, 'layout' => 'list' );

$cats = wp_list_pluck( $renderer->categories(), 'id' );
evvnt_check( 'navigation puts precedence categories first', array_slice( $cats, 0, 2 ), array( 16, 17 ) );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base );
evvnt_check( 'page 1 lists 30 events', substr_count( $html, 'class="evvnt-cal__card"' ), 30 );
evvnt_check( 'page 1 shows featured events', substr_count( $html, 'evvnt-cal__card--featured' ) > 0, true );
evvnt_check( 'full page => next link', false !== strpos( $html, 'rel="next" href="http://example.test/?page_id=4&#038;evPage=2"' ), true );
evvnt_check( 'page 1 has no previous link', false === strpos( $html, 'rel="prev"' ), true );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife', 'evPage' => '2' ) ), $atts, $base );
evvnt_check( 'category page 2 requests page=1 with categoryId=17', (bool) preg_match( '#/events\?categoryId=17&.*page=1&#', end( $transport->urls ) ), true );
evvnt_check( 'category title above featured, list headed "Upcoming Events"', (bool) preg_match( '#<h2 class="evvnt-cal__category-title">\s*Nightlife\s*</h2>.*?<h2 class="evvnt-cal__heading">\s*Upcoming Events#s', $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife' ) ), $atts, $base ) ), true );
$evvnt_sub_html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife', 'subcategory' => '3|Comedy' ) ), $atts, $base );
evvnt_check( 'sub-category shown in the title bar', (bool) preg_match( '#<h2 class="evvnt-cal__category-title">\s*Nightlife <span class="evvnt-cal__subcategory">› Comedy#', $evvnt_sub_html ), true );
evvnt_check( 'home page has no category title bar', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base ), 'evvnt-cal__category-title' ), 0 );
evvnt_check( 'category page 2 has prev link back to page 1', false !== strpos( $html, 'rel="prev" href="http://example.test/?page_id=4&#038;_evDiscoveryPath=/nightlife"' ), true );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife' ) ), array( 'category_id' => 28 ) + $atts, $base );
evvnt_check( 'pinned category scopes the request', (bool) preg_match( '#categoryId=28&#', end( $transport->urls ) ) && false === strpos( end( $transport->urls ), 'subCategoryIds' ), true );
evvnt_check( 'pinned category hides the root navigation', false === strpos( $html, 'evvnt-cal__nav' ), true );
evvnt_check( 'category names shown as in the JS', in_array( 'Food & Drink', wp_list_pluck( $renderer->categories(), 'name' ), true ), true );
evvnt_check( 'listing reports outages as WP_Error', is_wp_error( ( new Evvnt_Calendar_Renderer( evvnt_client( ( function () { $t = new Evvnt_Test_Transport(); $t->overrides['#home_page#'] = new WP_Error( 'x', 'down', array( 'status' => 500 ) ); return $t; } )() ), Evvnt_Calendar_Settings::get() ) )->listing( Evvnt_Calendar_Route::from_query( array() ), $atts ) ), true );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );

$renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife', 'subcategory' => '141|Arts & Crafts' ) ), $atts, $base );
evvnt_check( 'subcategory filter sent as subCategoryIds', (bool) preg_match( '#categoryId=17&.*subCategoryIds=141#', end( $transport->urls ) ), true );

$transport->overrides['#/events\?#'] = array( 'events' => array_slice( evvnt_fixture( 'events_page1' )['events'], 0, 5 ) );
$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'evPage' => '4' ) ), $atts, $base );
evvnt_check( 'short page => no next link', false === strpos( $html, 'rel="next"' ), true );

$down     = new Evvnt_Test_Transport();
$down->overrides['#.#'] = new WP_Error( 'x', 'down', array( 'status' => 502 ) );
$renderer = new Evvnt_Calendar_Renderer( evvnt_client( $down ), Evvnt_Calendar_Settings::get() );
evvnt_check( 'API down => unavailable message', false !== strpos( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base ), 'evvnt-cal--unavailable' ), true );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );

/* ------------------------------------------------------------------ */
echo "== Category rows layout\n";

$evvnt_featured = function ( $events, $n ) {
	return array_map(
		function ( $e ) {
			$e['featured_publisher_ids'] = array( 5293 );
			return $e;
		},
		array_slice( $events, 0, $n )
	);
};
$transport = new Evvnt_Test_Transport();
$transport->overrides['#/events\?categoryId=17&#']  = array( 'events' => $evvnt_featured( evvnt_fixture( 'events_page1' )['events'], 4 ) ); // Only 4 featured: no row.
$transport->overrides['#/events\?categoryId=9&#']   = new WP_Error( 'x', 'down', array( 'status' => 500 ) );                           // Failing row.
$transport->overrides['#/events\?categoryId=16&#']  = array( 'events' => array_merge( $evvnt_featured( evvnt_fixture( 'events_page1' )['events'], 5 ), array_map( function ( $e ) { $e['featured_publisher_ids'] = array(); return $e; }, array_slice( evvnt_fixture( 'events_page1' )['events'], 10, 3 ) ) ) ); // 5 featured + 3 not.
$transport->overrides['#/events\?categoryId=\d+&#'] = array( 'events' => $evvnt_featured( evvnt_fixture( 'events_page1' )['events'], 12 ) );
// Weekend days: each day has 8 events; one recurring event appears on every day.
$evvnt_day_events = function ( $url ) {
	preg_match( '#fromDate=(\d{4}-\d{2}-\d{2})#', $url, $d );
	$all = evvnt_fixture( 'events_page1' )['events'];
	$off = ( (int) substr( $d[1], 8, 2 ) % 3 ) * 8;
	$day = array_slice( $all, $off, 8 );
	foreach ( $day as $i => $e ) {
		$day[ $i ]['title'] = $d[1] . ' event ' . $i;
	}
	$day[0]['title'] = 'Recurring every day';
	return array( 'events' => $day );
};
$transport->overrides['#/events\?fromDate=#'] = $evvnt_day_events;
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$rows_atts = array( 'layout' => 'rows' ) + $atts;
$html      = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $rows_atts, $base );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );
preg_match_all( '#<h2 class="evvnt-cal__heading" id="evvnt-cal-row-([a-z0-9-]+)">([^<]+)</h2>#', $html, $m );
evvnt_check( 'This Weekend row right after Featured', array_slice( $m[1], 0, 2 ), array( 'featured', 'this-weekend' ) );
list( $evvnt_wf, $evvnt_wt ) = Evvnt_Calendar_Renderer::weekend_range();
evvnt_check( 'weekend row queries each day Thursday-Sunday', count( preg_grep( '#/events\?fromDate=(\d{4}-\d{2}-\d{2})&hitsPerPage=10&multipleEventInstances=true&page=0&publisher_id=5293&toDate=\1$#', $transport->urls ) ), ( strtotime( $evvnt_wt ) - strtotime( $evvnt_wf ) ) / DAY_IN_SECONDS + 1 );
evvnt_check( 'weekend row links to the date listing', (bool) preg_match( '#class="evvnt-cal__row-end"><a href="[^"]*fromDate=' . $evvnt_wf . '&(amp;|\#038;)toDate=' . $evvnt_wt . '"[^>]*>View more events this weekend#', $html ), true );
preg_match( '#id="evvnt-cal-row-this-weekend">.*?<ul class="evvnt-cal__row-track"[^>]*>(.*?)</ul>\s*</section>#s', $html, $evvnt_wk );
preg_match_all( '#<h3 class="evvnt-cal__card-title"><a[^>]*>([^<]+)</a>#', $evvnt_wk[1], $evvnt_wk_titles );
$evvnt_dated   = array_values( array_filter( $evvnt_wk_titles[1], function ( $t ) { return (bool) preg_match( '#^\d{4}-#', $t ); } ) );
$evvnt_per_day = array_count_values( array_map( function ( $t ) { return substr( $t, 0, 10 ); }, $evvnt_dated ) );
evvnt_check( 'weekend row: 10 events, recurring event once', array( count( $evvnt_wk_titles[1] ), count( array_keys( $evvnt_wk_titles[1], 'Recurring every day', true ) ) ), array( 10, 1 ) );
evvnt_check( 'weekend row: spread across the days in day order', max( $evvnt_per_day ) - min( $evvnt_per_day ) <= 1 && array_keys( $evvnt_per_day ) === array_values( array_unique( array_map( function ( $t ) { return substr( $t, 0, 10 ); }, $evvnt_dated ) ) ) && array_keys( $evvnt_per_day ) === array_values( array_unique( array_merge( array_keys( $evvnt_per_day ) ) ) ), true );
// Category rows are everything after Featured and This Weekend.
$m[1] = array_merge( array( $m[1][0] ), array_slice( $m[1], 2 ) );
$m[2] = array_merge( array( $m[2][0] ), array_slice( $m[2], 2 ) );
$cat_count = count( $renderer->categories() );
evvnt_check( 'rows view rendered', substr_count( $html, 'evvnt-cal--rows' ), 1 );
evvnt_check( 'Featured row first', $m[1][0], 'featured' );
evvnt_check( 'one row per category with 5+ featured events (4-featured and failed rows left out)', count( $m[1] ) - 1, $cat_count - 2 );
evvnt_check( 'rows follow navigation order (a failing row does not hide later ones)', array_map( 'html_entity_decode', array_slice( $m[2], 1 ) ), array_values( wp_list_pluck( array_filter( $renderer->categories(), function ( $c ) { return ! in_array( $c['id'], array( 17, 9 ), true ); } ), 'name' ) ) );
evvnt_check( 'one featured-only request per category, 10 per row', count( preg_grep( '#/events\?categoryId=\d+&hitsPerPage=10&isFeatured=true&multipleEventInstances=true&page=0&publisher_id=5293$#', $transport->urls ) ), $cat_count );
preg_match( '#id="evvnt-cal-row-category-16">.*?<ul class="evvnt-cal__row-track"[^>]*>(.*?)</ul>\s*</section>#s', $html, $evvnt_row16 );
evvnt_check( 'category rows contain only featured events', substr_count( $evvnt_row16[1], '<article class="evvnt-cal__card' ), 5 );
preg_match( '#id="evvnt-cal-row-featured">.*?<ul class="evvnt-cal__row-track"[^>]*>(.*?)</ul>\s*</section>#s', $html, $evvnt_feat );
evvnt_check( 'rows show at most 10 events', substr_count( $evvnt_feat[1], '<article class="evvnt-cal__card' ) <= 10, true );
evvnt_check( 'row ends with a view-more link', (bool) preg_match( '#class="evvnt-cal__row-end"><a href="[^"]*live-music"[^>]*>View more Live Music events#', $html ), true );
evvnt_check( 'See all links into the category', (bool) preg_match( '#class="evvnt-cal__row-more" href="http://example.test/\?page_id=4&(amp;|\#038;)_evDiscoveryPath=/live-music"#', $html ), true );
evvnt_check( 'row controls hidden until JS shows them', substr_count( $html, '<div class="evvnt-cal__row-controls" hidden>' ), substr_count( $html, '<section class="evvnt-cal__row"' ) );
evvnt_check( 'rows script enqueued', wp_script_is( 'evvnt-calendar-rows', 'enqueued' ), true );
evvnt_check( 'rows rows cached: no new requests on re-render', ( function () use ( $renderer, $rows_atts, $base, $transport ) { $n = count( $transport->urls ); $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $rows_atts, $base ); return count( $transport->urls ) - $n; } )(), 0 );
evvnt_check( 'rows home has no category navigation', substr_count( $html, 'evvnt-cal__nav' ), 0 );
$cat_html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts' ) ), $rows_atts, $base );
evvnt_check( 'category pages keep the list', substr_count( $cat_html, 'evvnt-cal--rows' ), 0 );
evvnt_check( 'rows mode: category page has a home link, no tabs', array( (bool) preg_match( '#class="evvnt-cal__home"><a class="evvnt-cal__back" href="http://example.test/\?page_id=4">#', $cat_html ), substr_count( $cat_html, 'evvnt-cal__nav' ) ), array( true, 0 ) );
$list_html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts' ) ), $atts, $base );
evvnt_check( 'list mode: category page keeps the tabs, no home link', array( substr_count( $list_html, 'evvnt-cal__nav' ), substr_count( $list_html, 'evvnt-cal__home' ) ), array( 1, 0 ) );
evvnt_check( 'page 2 keeps the list', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'evPage' => '2' ) ), $rows_atts, $base ), 'evvnt-cal--rows' ), 0 );
evvnt_check( 'pinned calendars keep the list', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), array( 'category_id' => 28 ) + $rows_atts, $base ), 'evvnt-cal--rows' ), 0 );
evvnt_check( 'wide width by default', $renderer->root_class( 'calendar' ), 'evvnt-cal evvnt-cal--calendar evvnt-cal--wide' );
evvnt_check( 'content-column width option', ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), array( 'calendar_width' => 'content' ) + Evvnt_Calendar_Settings::get() ) )->root_class( 'calendar' ), 'evvnt-cal evvnt-cal--calendar' );

// Every category row failing must not leave a page with only the Featured row.
$down_rows = new Evvnt_Test_Transport();
$down_rows->overrides['#/events\?categoryId=#'] = new WP_Error( 'x', 'down', array( 'status' => 502 ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $down_rows ), Evvnt_Calendar_Settings::get() ) )->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $rows_atts, $base );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );
evvnt_check( 'all category rows failing => list view instead', array( substr_count( $html, 'evvnt-cal--rows' ), substr_count( $html, 'class="evvnt-cal__card"' ) ), array( 0, 30 ) );

// A row that 404s is remembered: no request for it on the next page load.
$gone = new Evvnt_Test_Transport();
$gone->overrides['#/events\?categoryId=16&#'] = new WP_Error( 'x', 'gone', array( 'status' => 404 ) );
( new Evvnt_Calendar_Renderer( evvnt_client( $gone ), Evvnt_Calendar_Settings::get() ) )->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $rows_atts, $base );
$n = count( preg_grep( '#categoryId=16&#', $gone->urls ) );
( new Evvnt_Calendar_Renderer( new Evvnt_Calendar_Discovery_Client( 5293, new Evvnt_Calendar_Cache(), $gone ), Evvnt_Calendar_Settings::get() ) )->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $rows_atts, $base );
evvnt_check( '404 row not requested again within 15 minutes', count( preg_grep( '#categoryId=16&#', $gone->urls ) ), $n );

// With an HTTP filter active (here pre_http_request), the batch goes through the WordPress HTTP API.
$evvnt_seen = array();
$evvnt_pre  = function ( $pre, $args, $url ) use ( &$evvnt_seen ) {
	$evvnt_seen[] = $url;
	return array( 'headers' => array(), 'body' => '{"events":[]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $evvnt_pre, 10, 3 );
$batch = Evvnt_Calendar_Discovery_Client::fetch_many( array( 'https://discovery.evvnt.com/api/events?a=1', 'https://discovery.evvnt.com/api/events?a=2' ) );
remove_filter( 'pre_http_request', $evvnt_pre, 10 );
evvnt_check( 'parallel fetch respects WordPress HTTP filters', array( count( $evvnt_seen ), $batch['https://discovery.evvnt.com/api/events?a=2'] ), array( 2, array( 'events' => array() ) ) );

$evvnt_ref = new ReflectionMethod( 'Evvnt_Calendar_Public', 'normalize_server_atts' );
$evvnt_ref->setAccessible( true );
evvnt_check( 'layout defaults to the setting (list)', $evvnt_ref->invoke( null, Evvnt_Calendar_Public::display_defaults() )['layout'], 'list' );
evvnt_check( 'shortcode layout="rows" overrides the setting', $evvnt_ref->invoke( null, array( 'layout' => 'rows' ) + Evvnt_Calendar_Public::display_defaults() )['layout'], 'rows' );
evvnt_check( 'unknown layout falls back to the setting', $evvnt_ref->invoke( null, array( 'layout' => '<x>' ) + Evvnt_Calendar_Public::display_defaults() )['layout'], 'list' );

/* ------------------------------------------------------------------ */
echo "== Date ranges and This Weekend\n";

$tz  = new DateTimeZone( 'UTC' );
$wk  = function ( $d ) use ( $tz ) { return Evvnt_Calendar_Renderer::weekend_range( new DateTimeImmutable( $d . ' 15:00', $tz ) ); };
evvnt_check( 'Wednesday => the coming Thu-Sun', $wk( '2026-11-04' ), array( '2026-11-05', '2026-11-08' ) );
evvnt_check( 'Monday => that week\'s Thu-Sun', $wk( '2026-11-02' ), array( '2026-11-05', '2026-11-08' ) );
evvnt_check( 'Thursday => today-Sunday', $wk( '2026-11-05' ), array( '2026-11-05', '2026-11-08' ) );
evvnt_check( 'Saturday => today-Sunday', $wk( '2026-11-07' ), array( '2026-11-07', '2026-11-08' ) );
evvnt_check( 'Sunday => just Sunday', $wk( '2026-11-08' ), array( '2026-11-08', '2026-11-08' ) );
evvnt_check( 'Sunday at year end', $wk( '2026-12-27' ), array( '2026-12-27', '2026-12-27' ) );
evvnt_check( 'Tuesday across a month end', $wk( '2026-12-29' ), array( '2026-12-31', '2027-01-03' ) );

$r = Evvnt_Calendar_Route::from_query( array( 'fromDate' => '2026-11-05', 'toDate' => '2026-11-08' ) );
evvnt_check( 'date range parsed', array( $r->from_date, $r->to_date ), array( '2026-11-05', '2026-11-08' ) );
foreach ( array( array( '2026-02-30', '2026-03-01' ), array( '2026-11-08', '2026-11-05' ), array( '2026-01-01', '2026-03-01' ), array( 'x', 'y' ) ) as $bad ) {
	evvnt_check( 'bad range ignored: ' . implode( '..', $bad ), Evvnt_Calendar_Route::from_query( array( 'fromDate' => $bad[0], 'toDate' => $bad[1] ) )->from_date, '' );
}
evvnt_check( 'fromDate alone means that day', Evvnt_Calendar_Route::from_query( array( 'fromDate' => '2026-11-06' ) )->to_date, '2026-11-06' );
evvnt_check( 'dates_url', Evvnt_Calendar_Route::dates_url( $base, '2026-11-05', '2026-11-08', 2 ), 'http://example.test/?page_id=4&evPage=2&fromDate=2026-11-05&toDate=2026-11-08' );

$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
list( $evvnt_wf, $evvnt_wt ) = Evvnt_Calendar_Renderer::weekend_range();
$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'fromDate' => $evvnt_wf, 'toDate' => $evvnt_wt ) ), array( 'layout' => 'rows' ) + $atts, $base );
evvnt_check( 'weekend listing heading', (bool) preg_match( '#<h2 class="evvnt-cal__heading">\s*This Weekend#', $html ), true );
evvnt_check( 'weekend listing: list view, no tabs, home link', array( substr_count( $html, 'evvnt-cal--rows' ), substr_count( $html, 'evvnt-cal__nav' ), substr_count( $html, 'evvnt-cal__home' ) ), array( 0, 0, 1 ) );
evvnt_check( 'weekend listing: featured events at the top', substr_count( $html, 'evvnt-cal__card--featured' ) > 0 && strpos( $html, 'evvnt-cal__card--featured' ) < strpos( $html, 'class="evvnt-cal__list"' ), true );
evvnt_check( 'weekend listing page 1 asks the home endpoint with the dates', (bool) preg_grep( '#/publisher/5293/home_page_events\?fromDate=' . $evvnt_wf . '&.*toDate=' . $evvnt_wt . '$#', $transport->urls ), true );
evvnt_check( 'weekend listing next page keeps the dates', false !== strpos( $html, 'evPage=2&#038;fromDate=' . $evvnt_wf . '&#038;toDate=' . $evvnt_wt ), true );
evvnt_check( 'other ranges show their dates', $renderer->dates_label( '2027-01-02', '2027-01-03' ), 'Events from Jan 2 to Jan 3, 2027' );

/* ------------------------------------------------------------------ */
echo "== Search\n";

evvnt_check( 'searchTerm parsed and cleaned', Evvnt_Calendar_Route::from_query( array( 'searchTerm' => "  Jazz  <b>night</b>\n" ) )->search, 'Jazz night' );
evvnt_check( '?query= accepted too (discovery JS)', Evvnt_Calendar_Route::from_query( array( 'query' => 'comedy' ) )->search, 'comedy' );
evvnt_check( 'search capped at 100 characters', strlen( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => str_repeat( 'a', 300 ) ) )->search ), 100 );
evvnt_check( 'search_url', Evvnt_Calendar_Route::search_url( $base, 'jazz & blues', 2 ), 'http://example.test/?page_id=4&evPage=2&searchTerm=jazz%20%26%20blues' );

$transport = new Evvnt_Test_Transport();
// Featured matches: 4 dates of one series (same event_parent_id) and 5 other events.
$evvnt_sf = array_slice( evvnt_fixture( 'events_page1' )['events'], 0, 9 );
foreach ( $evvnt_sf as $i => $e ) {
	$evvnt_sf[ $i ]['featured_publisher_ids'] = array( 5293 );
	$evvnt_sf[ $i ]['event_parent_id']        = $i < 4 ? 111 : 200 + $i;
}
$transport->overrides['#isFeatured=true.*searchTerm=#'] = array( 'events' => $evvnt_sf );
$transport->overrides['#searchTerm=#'] = evvnt_fixture( 'events_category17' );
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$html      = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => 'music' ) ), array( 'layout' => 'rows' ) + $atts, $base );
evvnt_check( 'search queries /events with searchTerm (page 0, 30 per page)', in_array( 'https://discovery.evvnt.com/api/events?hitsPerPage=30&multipleEventInstances=true&page=0&publisher_id=5293&searchTerm=music', $transport->urls, true ), true );
evvnt_check( 'search results use the list even in rows mode', array( substr_count( $html, 'evvnt-cal--rows' ), substr_count( $html, 'class="evvnt-cal__card"' ) ), array( 0, 30 ) );
evvnt_check( 'results heading', false !== strpos( $html, 'Results for “music”' ), true );
evvnt_check( 'featured matches are fetched for the first page', in_array( 'https://discovery.evvnt.com/api/events?hitsPerPage=18&isFeatured=true&multipleEventInstances=true&page=0&publisher_id=5293&searchTerm=music', $transport->urls, true ), true );
evvnt_check( 'featured matches head the results, one per series, at most 6', array( substr_count( $html, 'evvnt-cal__card--featured' ), strpos( $html, 'evvnt-cal__section--featured' ) < strpos( $html, 'Results for “music”' ) ), array( 6, true ) );
evvnt_check( 'one_per_series keeps the first date of a series', count( Evvnt_Calendar_Renderer::one_per_series( $evvnt_sf ) ), 6 );
$transport->urls = array();
$renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => 'music', 'evPage' => 2 ) ), $atts, $base );
evvnt_check( 'no featured lookup on later pages', count( preg_grep( '#isFeatured=true#', $transport->urls ) ), 0 );
$evvnt_down_featured = new Evvnt_Test_Transport();
$evvnt_down_featured->overrides['#isFeatured=true.*searchTerm=#'] = new WP_Error( 'x', 'down', array( 'status' => 500 ) );
$evvnt_down_featured->overrides['#searchTerm=#']                  = evvnt_fixture( 'events_category17' );
$evvnt_html = ( new Evvnt_Calendar_Renderer( evvnt_client( $evvnt_down_featured ), Evvnt_Calendar_Settings::get() ) )->render_calendar( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => 'jazz' ) ), $atts, $base );
evvnt_check( 'a failed featured lookup leaves the results', array( substr_count( $evvnt_html, 'evvnt-cal__card--featured' ), substr_count( $evvnt_html, 'Results for “jazz”' ) ), array( 0, 1 ) );
evvnt_check( 'results page has a home link', substr_count( $html, 'class="evvnt-cal__home"' ), 1 );
evvnt_check( 'next page keeps the search', false !== strpos( $html, 'evPage=2&#038;searchTerm=music' ), true );
evvnt_check( 'search box keeps the text', (bool) preg_match( '#<input type="search"[^>]*name="searchTerm" value="music"#', $html ), true );
evvnt_check( 'GET form keeps page_id (plain permalinks)', (bool) preg_match( '#<input type="hidden" name="page_id" value="4">#', $html ), true );

$matches = $renderer->category_matches( 'music', evvnt_fixture( 'events_category17' )['events'], $base );
evvnt_check( 'navigation category matched first', $matches[0], array( 'name' => 'Live Music', 'url' => 'http://example.test/?page_id=4&_evDiscoveryPath=/live-music' ) );
evvnt_check( 'sub-categories from matching events are matched too', array_values( array_filter( $renderer->category_matches( 'comedy', evvnt_fixture( 'events_category17' )['events'], $base ), function ( $m ) { return false !== strpos( $m['url'], 'subcategory=3%7CComedy' ); } ) )[0]['name'], 'Comedy' );
evvnt_check( '"&" matches categories shown as "Food & Drink"', wp_list_pluck( $renderer->category_matches( 'food & d', array(), $base ), 'name' ), array( 'Food & Drink' ) );
evvnt_check( 'categories on results page', substr_count( $html, 'evvnt-cal__search-categories' ), 1 );
evvnt_check( 'no category tabs on results page', substr_count( $html, 'evvnt-cal__nav' ), 0 );
$evvnt_names = wp_list_pluck( $renderer->category_matches( 'music', array( array( 'title' => 'x', 'category_names_with_ids' => array( '999|Live Music', '998|Music' ) ) ), $base ), 'name' );
evvnt_check( 'matches de-duplicated by name', count( $evvnt_names ), count( array_unique( array_map( 'strtolower', $evvnt_names ) ) ) );

$transport->overrides['#searchTerm=#'] = array( 'events' => array_merge( array_fill( 0, 4, evvnt_fixture( 'events_page1' )['events'][0] ), array_slice( evvnt_fixture( 'events_page1' )['events'], 1, 8 ) ) );
Evvnt_Calendar_Cache::flush();
$sugg = $renderer->suggestions( 'zz', $base );
evvnt_check( 'suggestions: at most 5 events, each title once', array( count( $sugg['events'] ), count( array_unique( wp_list_pluck( $sugg['events'], 'title' ) ) ) ), array( 5, 5 ) );
evvnt_check( 'suggestions: see-all link', $sugg['all_url'], 'http://example.test/?page_id=4&searchTerm=zz' );
evvnt_check( 'suggestions: event fields', array_keys( $sugg['events'][0] ), array( 'title', 'url', 'date', 'place', 'image' ) );

// Pinned / virtual calendars: suggestions use the same filters, and no category pages are suggested.
$t2 = new Evvnt_Test_Transport();
$t2->overrides['#searchTerm=#'] = evvnt_fixture( 'events_category17' );
$r2 = new Evvnt_Calendar_Renderer( evvnt_client( $t2 ), Evvnt_Calendar_Settings::get() );
$pinned_sugg = $r2->suggestions( 'Comedy', $base, array( 'category_id' => 17, 'virtual' => true ) );
evvnt_check( 'pinned suggestions: filters sent, search lowercased', (bool) preg_grep( '#/events\?categoryId=17&hitsPerPage=15&.*searchTerm=comedy&virtual=true$#', $t2->urls ), true );
evvnt_check( 'pinned suggestions: no categories', $pinned_sugg['categories'], array() );
evvnt_check( 'pinned results page: no category matches', substr_count( $r2->render_calendar( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => 'comedy' ) ), array( 'category_id' => 17 ) + $atts, $base ), 'evvnt-cal__search-categories' ), 0 );

// Visitor-controlled search entries are short-lived and never pause the calendar.
$t3 = new Evvnt_Test_Transport();
$t3->overrides['#searchTerm=#'] = new WP_Error( 'x', 'rate limited', array( 'status' => 429 ) );
Evvnt_Calendar_Cache::flush();
( new Evvnt_Calendar_Discovery_Client( 5293, new Evvnt_Calendar_Cache(), $t3 ) )->search_events( 'zzz' );
evvnt_check( 'search failure does not start the API backoff', (bool) get_transient( Evvnt_Calendar_Cache::BACKOFF_KEY ), false );
( new Evvnt_Calendar_Discovery_Client( 5293, new Evvnt_Calendar_Cache(), $t2 ) )->search_events( 'ttl-check' );
$evvnt_search_key = '_transient_timeout_evvnt_calendar_api_' . (int) get_option( Evvnt_Calendar_Cache::GEN_OPTION, 1 ) . '_' . md5( 'https://discovery.evvnt.com/api/events?hitsPerPage=30&multipleEventInstances=true&page=0&publisher_id=5293&searchTerm=ttl-check' );
evvnt_check( 'search results kept 5 minutes, not a day', ( (int) get_option( $evvnt_search_key ) - time() ) <= 5 * MINUTE_IN_SECONDS, true );

// REST endpoint: rate limited per visitor.
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server' ) );
do_action( 'rest_api_init', rest_get_server() );
$evvnt_limit = function () { return 2; };
add_filter( 'evvnt_calendar_suggest_rate_limit', $evvnt_limit );
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$req = new WP_REST_Request( 'GET', '/evvnt-calendar/v1/suggest' );
	$req->set_param( 'q', 'x' );
	$codes[] = rest_do_request( $req )->get_status();
}
remove_filter( 'evvnt_calendar_suggest_rate_limit', $evvnt_limit );
evvnt_check( 'suggest endpoint rate limited (3rd request at limit 2 => 429)', $codes, array( 200, 200, 429 ) );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js', 'submission_label' => 'Promote your event' ) );

/* ------------------------------------------------------------------ */
echo "== Recommended events\n";

$transport = new Evvnt_Test_Transport();
// 10 featured candidates and 5 that are not (as if the API ignored isFeatured), all
// still upcoming whatever today's date (the fixture's own end dates age).
$evvnt_upcoming = function ( $e, $featured ) {
	$e['featured_publisher_ids'] = $featured ? array( 5293 ) : array();
	$e['end_time_i']             = time() + 30 * DAY_IN_SECONDS;
	if ( ! $featured ) {
		$e['title'] = 'NOT FEATURED ' . $e['title'];
	}
	return $e;
};
$transport->overrides['#isFeatured=true#'] = array(
	'events' => array_merge(
		array_map( function ( $e ) use ( $evvnt_upcoming ) { return $evvnt_upcoming( $e, true ); }, array_slice( evvnt_fixture( 'events_page1' )['events'], 0, 10 ) ),
		array_map( function ( $e ) use ( $evvnt_upcoming ) { return $evvnt_upcoming( $e, false ); }, array_slice( evvnt_fixture( 'events_page1' )['events'], 10, 5 ) )
	),
);
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$viewed    = new Evvnt_Calendar_Event( evvnt_fixture( 'events_page1' )['events'][0], $publisher );
$recs      = $renderer->recommended( $viewed );
evvnt_check( 'recommendations are featured events only', array_filter( $recs, function ( $e ) { return ! $e->is_featured(); } ), array() );
evvnt_check( 'recommendations query matches the discovery JS', in_array( 'https://discovery.evvnt.com/api/events?categoryId=' . $viewed->main_category_id() . '&hitsPerPage=50&isFeatured=true&publisher_id=5293', $transport->urls, true ), true );
evvnt_check( 'six recommendations', count( $recs ), 6 );
evvnt_check( 'viewed event never recommended', in_array( $viewed->object_id(), array_map( function ( $e ) { return $e->object_id(); }, $recs ), true ), false );
evvnt_check( 'no duplicates', count( array_unique( array_map( function ( $e ) { return $e->object_id(); }, $recs ) ) ), count( $recs ) );
$w = function ( $created, $end ) use ( $publisher ) {
	return ( new Evvnt_Calendar_Event( array( 'title' => 't', 'created_time_i' => $created, 'end_time_i' => $end ), $publisher ) )->promotion_weight();
};
evvnt_check( 'weight: promoted one day => 1095', $w( 0, DAY_IN_SECONDS ), 1095 );
evvnt_check( 'weight: promoted a year or more => 3', $w( 0, 400 * DAY_IN_SECONDS ), 3 );
evvnt_check( 'weight: under a day counts as a day', $w( 0, 60 ), 1095 );
evvnt_check( 'fewer candidates than the limit => all of them', count( Evvnt_Calendar_Renderer::choose_weighted( array_slice( $recs, 0, 3 ), 6 ) ), 3 );
$html = $renderer->render_detail( $viewed, $base );
evvnt_check( 'detail page shows the recommended section', substr_count( $html, 'evvnt-cal__recommended-grid' ), 1 );
evvnt_check( 'non-featured event uses the standard layout', array( substr_count( $html, 'evvnt-cal--standard' ), substr_count( $html, 'evvnt-cal__hero-backdrop' ) ), array( 1, 0 ) );

$featured_raw = evvnt_fixture( 'event_3743362' );
$featured     = new Evvnt_Calendar_Event( $featured_raw['rawEvent'], $publisher, array( 'signedStaticMapUrl' => 'https://maps.googleapis.com/maps/api/staticmap?center=1,2%0A%20%20&size=%0A600x480&signature=abc=' ) );
evvnt_check( 'fixture event is featured for 5293', $featured->is_featured(), true );
$fhtml = $renderer->render_detail( $featured, $base );
evvnt_check( 'featured event uses the hero layout', array( substr_count( $fhtml, 'evvnt-cal__hero-backdrop' ), substr_count( $fhtml, 'evvnt-cal--standard' ) ), array( 1, 0 ) );
evvnt_check( 'featured hero shows price with the ticket icon above the button', (bool) preg_match( '#class="evvnt-cal__price"><svg class="evvnt-cal__icon evvnt-cal__icon--ticket".*?evvnt-cal__button--large#s', $fhtml ), true );
add_filter( 'evvnt_calendar_detail_layout', $evvnt_force_standard = function () { return 'standard'; } );
$shtml = $renderer->render_detail( $featured, $base );
remove_filter( 'evvnt_calendar_detail_layout', $evvnt_force_standard );
evvnt_check( 'layout filter can force the standard layout', substr_count( $shtml, 'evvnt-cal--standard' ), 1 );
evvnt_check( 'map panel keeps the signed map URL intact', false !== strpos( $shtml, 'center=1,2%0A%20%20&amp;size=%0A600x480&amp;signature=abc=' ), true );
evvnt_check( 'standard layout: Get Tickets button with arrow', (bool) preg_match( '#evvnt-cal__button--std[^>]*>Get Tickets<svg#', $shtml ), true );
evvnt_check( 'standard layout: price row with ticket icon', (bool) preg_match( '#evvnt-cal__icon--ticket.*?<span>£23\.00</span>#s', $shtml ), true );
evvnt_check( 'CTA carries the discovery attribution', (bool) preg_match( '#utm_source=[^&"]+&(amp;|\#038;)?utm_medium=referral&(amp;|\#038;)?publisher_id=5293#', $shtml ), true );
preg_match( '#<section class="evvnt-cal__hero.*?</section>#s', $fhtml, $evvnt_hero );
evvnt_check( 'featured hero has no share row', false !== strpos( $evvnt_hero[0], 'share' ), false );
foreach ( array( 'featured' => $fhtml, 'standard' => $shtml ) as $evvnt_layout => $evvnt_doc ) {
	evvnt_check( "$evvnt_layout: share row sits just above the Where panel", (bool) preg_match( '#evvnt-cal__share-row.*?</div>\s*<div class="evvnt-cal__panel evvnt-cal__panel--where">#s', $evvnt_doc ), true );
	evvnt_check( "$evvnt_layout: shared body used once", substr_count( $evvnt_doc, 'class="evvnt-cal__body"' ), 1 );
	evvnt_check( "$evvnt_layout: category is a link", (bool) preg_match( '#<dd class="evvnt-cal__categories">\s*<a href="[^"]+">Film &amp; Cinema</a>#', $evvnt_doc ), true );
}
evvnt_check( 'sub-category links to its parent filtered to it', (bool) preg_match( '#_evDiscoveryPath=/arts&(amp;|\#038;)subcategory=7%7CFilm%20%2F%20Cinema#', $fhtml ), true );
evvnt_check( 'non-Google map URLs are ignored', ( new Evvnt_Calendar_Event( array( 'title' => 't' ), $publisher, array( 'signedStaticMapUrl' => 'https://evil.test/x.png' ) ) )->static_map_url(), '' );
$tm_cta = ( new Evvnt_Calendar_Event( array( 'title' => 't', 'sources' => array( 'ticketmaster' ), 'objectID' => 'X1', 'links' => array( 'Tickets' => 'https://www.ticketmaster.com/e' ) ), $publisher ) )->cta();
evvnt_check( 'third-party CTA uses the source brand colour', $tm_cta, array( 'label' => 'Get tickets', 'color' => '#009cde' ) );
evvnt_check( 'description without the repeated summary', ( new Evvnt_Calendar_Event( array( 'title' => 't', 'summary' => 'Intro line.', 'description' => "Intro line.\nThe rest." ), $publisher ) )->description_without_summary(), 'The rest.' );

/* ------------------------------------------------------------------ */
echo "== Recurring series\n";

/**
 * A synthetic series in $zone: $count dates $step apart (each at the given hours), the first
 * $past of them already over, indexed as occurrence "{$prefix}{n}". Dates are relative to now
 * so the tests do not age.
 */
function evvnt_series( array $o ) {
	$o     += array( 'count' => 5, 'step' => '+1 week', 'hours' => array( 19 ), 'past' => 1, 'zone' => 'America/Chicago', 'frequency' => 'weekly', 'week_days' => null, 'monthly_occurrence' => null, 'schedules' => null, 'prefix' => '77', 'viewed' => 2, 'unindexed' => array(), 'rejected' => array() );
	$zone   = new DateTimeZone( $o['zone'] );
	$first  = ( new DateTimeImmutable( '@' . ( time() - ( $o['past'] ? 3 * DAY_IN_SECONDS : -DAY_IN_SECONDS ) ) ) )->setTimezone( $zone );
	$dates  = array();
	$occ    = array();
	$n      = 0;
	for ( $i = 0; count( $dates ) < $o['count']; $i++ ) {
		$day = $first->modify( str_replace( '+1', '+' . $i, $o['step'] ) );
		foreach ( $o['hours'] as $h ) {
			if ( count( $dates ) >= $o['count'] ) {
				break;
			}
			$start   = $day->setTime( $h, 0 );
			$dates[] = array( 'id' => 1000 + $n, 'start_time' => $start->format( 'Y-m-d\TH:i:sP' ), 'end_time' => $start->modify( '+2 hours' )->format( 'Y-m-d\TH:i:sP' ), 'timezone' => $o['zone'], 'schedule_id' => 1 );
			if ( ! in_array( $n, $o['unindexed'], true ) ) {
				$occ[] = array( 'id' => $n === $o['viewed'] ? 'VIEWED' : $o['prefix'] . $n, 'startTime' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ), 'rejected' => in_array( $n, $o['rejected'], true ) );
			}
			$n++;
		}
	}
	$schedule = array( 'id' => 1, 'frequency' => $o['frequency'], 'week_days' => null !== $o['week_days'] ? $o['week_days'] : ( 'weekly' === $o['frequency'] ? array( (int) $first->format( 'w' ) ) : array() ), 'start_date' => $first->format( 'Y-m-d' ), 'monthly_occurrence' => $o['monthly_occurrence'] );
	return array( 'recurrence' => array( 'dates' => array_reverse( $dates ), 'schedules' => null !== $o['schedules'] ? $o['schedules'] : array( $schedule ) ), 'indexed_occurrences' => $occ );
}
function evvnt_series_event( array $o = array(), array $extra = array() ) {
	$s = evvnt_series( $o );
	return new Evvnt_Calendar_Event( $extra + array( 'title' => 'Jazz Night', 'source_id' => 'VIEWED', 'source_id_s' => 'VIEWED', 'sources' => array( 'evvnt' ), 'recurrence' => $s['recurrence'], 'indexed_occurrences' => $s['indexed_occurrences'] ), $GLOBALS['publisher'] );
}

// Pairing, order, past dates.
$ev  = evvnt_series_event( array( 'count' => 5, 'unindexed' => array( 3 ), 'rejected' => array( 4 ) ) );
$rec = $ev->recurrence();
evvnt_check( 'dates are ordered however they arrive', array_map( function ( $d ) { return $d['id']; }, $rec->dates() ), array( '1000', '1001', '1002', '1003', '1004' ) );
evvnt_check( 'each date pairs with the occurrence indexed at the same instant', array_map( function ( $d ) { return $d['occurrence_id']; }, $rec->dates() ), array( '770', '771', 'VIEWED', null, null ) );
evvnt_check( 'upcoming dates leave out the ended one', count( $rec->upcoming_dates() ), 4 );
evvnt_check( 'next date is the first still to come', $rec->next_date()['id'], '1001' );
evvnt_check( 'event is recurring and shows its series', array( $ev->is_recurring(), $ev->shows_recurrence() ), array( true, true ) );
evvnt_check( 'viewed date is the one indexed under this page\'s id', $ev->viewed_date()['id'], '1002' );
evvnt_check( 'highlighted date is this page\'s date while still to come', $ev->highlighted_date()['id'], '1002' );
evvnt_check( 'headline date has no "Next:" when it is not the next date', 0 === strpos( $ev->series_date_label(), 'Next:' ), false );
$past_page = evvnt_series_event( array( 'viewed' => 0 ) );
evvnt_check( 'a past page highlights the next date', $past_page->highlighted_date()['id'], '1001' );
evvnt_check( '"Next:" headline for the next date', 0 === strpos( $past_page->series_date_label(), 'Next: ' ), true );
evvnt_check( 'a one-date event is not a series', evvnt_series_event( array( 'count' => 1, 'past' => 0, 'viewed' => 0 ) )->shows_recurrence(), false );
evvnt_check( 'a series with every date over is not shown', ( new Evvnt_Calendar_Event( array( 'title' => 't' ) + evvnt_series( array( 'count' => 3 ) ), $publisher ) )->recurrence( time() + 400 * DAY_IN_SECONDS )->upcoming_dates(), array() );
evvnt_check( 'no recurrence => no series', ( new Evvnt_Calendar_Event( array( 'title' => 't', 'recurrence' => null ), $publisher ) )->shows_recurrence(), false );
evvnt_check( 'occurrence ids that are not URL-safe are ignored', ( new Evvnt_Calendar_Recurrence( evvnt_series( array( 'count' => 2 ) )['recurrence'], array( array( 'id' => '"><x', 'startTime' => '2020-01-01T00:00:00' ) ) ) )->find_by_occurrence_id( '"><x' ), null );
evvnt_check( 'upcoming_dates() kept for older theme templates', count( $ev->upcoming_dates() ), 4 );

// Daylight saving: a 10am weekly date stays at 10am across the change and still pairs up.
$dst = new Evvnt_Calendar_Recurrence(
	array( 'dates' => array( array( 'id' => 1, 'start_time' => '2030-10-27T10:00:00-04:00', 'end_time' => '2030-10-27T12:00:00-04:00', 'timezone' => 'America/New_York' ), array( 'id' => 2, 'start_time' => '2030-11-03T10:00:00-05:00', 'end_time' => '2030-11-03T12:00:00-05:00', 'timezone' => 'America/New_York' ) ), 'schedules' => array() ),
	array( array( 'id' => 'a', 'startTime' => '2030-10-27T14:00:00' ), array( 'id' => 'b', 'startTime' => '2030-11-03T15:00:00' ) )
);
evvnt_check( 'dates keep the venue\'s hour across a daylight saving change', array_map( function ( $d ) { return $d['start']->format( 'H:i P' ) . ' ' . $d['occurrence_id']; }, $dst->dates() ), array( '10:00 -04:00 a', '10:00 -05:00 b' ) );

// Labels (scheduleLabels.js).
$sched = function ( $frequency, $start = '2026-11-14', $week_days = array(), $monthly = null ) {
	return new Evvnt_Calendar_Recurrence( array( 'dates' => array(), 'schedules' => array( array( 'frequency' => $frequency, 'start_date' => $start, 'week_days' => $week_days, 'monthly_occurrence' => $monthly ) ) ), array() );
};
evvnt_check( 'badge: daily / weekly / monthly', array( $sched( 'daily' )->badge_label(), $sched( 'weekly' )->badge_label(), $sched( 'monthly' )->badge_label() ), array( 'Daily series', 'Weekly series', 'Monthly series' ) );
evvnt_check( 'badge: several schedules => Recurring', ( new Evvnt_Calendar_Recurrence( array( 'dates' => array(), 'schedules' => array( array( 'frequency' => 'daily' ), array( 'frequency' => 'weekly' ) ) ), array() ) )->badge_label(), 'Recurring' );
evvnt_check( 'pattern: weekly on one day', $sched( 'weekly', '2026-11-15', array( 0 ) )->pattern_label(), 'weekly on Sundays' );
evvnt_check( 'pattern: weekly on two days', $sched( 'weekly', '2026-11-15', array( 1, 5 ) )->pattern_label(), 'weekly on Mondays and Fridays' );
evvnt_check( 'pattern: weekly on three days', $sched( 'weekly', '2026-11-15', array( 1, 3, 5 ) )->pattern_label(), 'weekly on Mondays, Wednesdays, and Fridays' );
evvnt_check( 'pattern: weekly with an unknown day => none', $sched( 'weekly', '2026-11-15', array( 9 ) )->pattern_label(), null );
evvnt_check( 'pattern: monthly on the nth weekday', $sched( 'monthly', '2026-11-14', array(), 'weekday' )->pattern_label(), 'monthly on the 2nd Saturday' );
evvnt_check( 'pattern: monthly on the last weekday', $sched( 'monthly', '2026-11-28', array(), 'last_weekday' )->pattern_label(), 'monthly on the last Saturday' );
evvnt_check( 'pattern: monthly on a day of the month', array( $sched( 'monthly', '2026-11-01' )->pattern_label(), $sched( 'monthly', '2026-11-22' )->pattern_label(), $sched( 'monthly', '2026-11-13' )->pattern_label() ), array( 'monthly on the 1st', 'monthly on the 22nd', 'monthly on the 13th' ) );
evvnt_check( 'pattern: no start date => none', $sched( 'daily', '' )->pattern_label(), null );
$lines = new Evvnt_Calendar_Recurrence(
	array( 'dates' => array( array( 'id' => 1, 'start_time' => '2030-12-11T15:00:00-06:00', 'end_time' => '2030-12-11T21:00:00-06:00', 'timezone' => 'America/Chicago' ), array( 'id' => 2, 'start_time' => '2030-12-13T15:00:00-06:00', 'end_time' => '2030-12-13T21:00:00-06:00', 'timezone' => 'America/Chicago' ) ), 'schedules' => array( array( 'frequency' => 'daily', 'start_date' => '2030-12-11' ) ) ),
	array()
);
evvnt_check( 'frequency line with a pattern', $lines->frequency_line(), 'Repeats daily through Dec 13, 2030' );
evvnt_check( 'upcoming dates summary', $lines->upcoming_dates_summary(), '2 dates · Dec 11, 2030 – Dec 13, 2030' );
evvnt_check( 'frequency line without a pattern', ( new Evvnt_Calendar_Recurrence( array( 'dates' => array( array( 'id' => 1, 'start_time' => '2030-12-11T15:00:00-06:00', 'timezone' => 'America/Chicago' ), array( 'id' => 2, 'start_time' => '2030-12-20T15:00:00-06:00', 'timezone' => 'America/Chicago' ) ), 'schedules' => array() ), array() ) )->frequency_line(), 'Repeats through Dec 20, 2030' );

// Which view: 3 chips, 4–18 list, more windowed with a calendar for dense or irregular series.
$views = function ( array $o ) {
	return Evvnt_Calendar_Renderer::choose_date_views( evvnt_series_event( $o + array( 'past' => 0, 'viewed' => 0 ) )->recurrence() );
};
evvnt_check( 'up to 3 dates => chips', $views( array( 'count' => 3 ) ), array( 'default' => 'chips', 'toggled' => null, 'windowed' => false ) );
evvnt_check( '4 dates => list', $views( array( 'count' => 4 ) ), array( 'default' => 'list', 'toggled' => null, 'windowed' => false ) );
evvnt_check( '18 dates => list, not windowed', $views( array( 'count' => 18 ) ), array( 'default' => 'list', 'toggled' => null, 'windowed' => false ) );
evvnt_check( '19 weekly dates => windowed list offering the calendar', $views( array( 'count' => 19 ) ), array( 'default' => 'list', 'toggled' => 'calendar', 'windowed' => true ) );
evvnt_check( '19 monthly dates => windowed list only', $views( array( 'count' => 19, 'step' => '+1 month', 'frequency' => 'monthly' ) ), array( 'default' => 'list', 'toggled' => null, 'windowed' => true ) );
evvnt_check( '19 irregular dates => windowed list offering the calendar', $views( array( 'count' => 19, 'step' => '+1 month', 'schedules' => array() ) ), array( 'default' => 'list', 'toggled' => 'calendar', 'windowed' => true ) );
evvnt_check( '30 daily dates => calendar, offering the list', $views( array( 'count' => 30, 'step' => '+1 day', 'frequency' => 'daily' ) ), array( 'default' => 'calendar', 'toggled' => 'list', 'windowed' => true ) );
evvnt_check( 'two showings on one day => no calendar', $views( array( 'count' => 30, 'step' => '+1 day', 'frequency' => 'daily', 'hours' => array( 14, 19 ) ) ), array( 'default' => 'list', 'toggled' => null, 'windowed' => true ) );

// Rendered detail pages.
$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$html      = $renderer->render_detail( evvnt_series_event( array( 'count' => 4, 'past' => 1, 'unindexed' => array( 3 ) ) ), $base, array() );
evvnt_check( 'series page: badge, Repeats line and chips', array( substr_count( $html, 'evvnt-cal__series-pill' ), substr_count( $html, 'Repeats weekly on' ), substr_count( $html, 'evvnt-cal__series--chips' ), substr_count( $html, 'class="evvnt-cal__series-date ' ) ), array( 1, 1, 1, 3 ) );
evvnt_check( 'chips caption', false !== strpos( $html, 'Tap a date to preview it below' ), true );
evvnt_check( 'the old sidebar Upcoming Dates panel is gone', substr_count( $html, 'evvnt-cal__dates' ), 0 );
evvnt_check( 'series page replaces the single date line', substr_count( $html, 'evvnt-cal__series-next' ), 1 );
evvnt_check( 'other dates link to their own page, back at the dates', (bool) preg_match( '#<a class="evvnt-cal__series-date is-next" href="http://example\.test/\?page_id=4&\#038;_evDiscoveryPath=/event/771-jazz-night\#evvnt-cal-dates"#', $html ), true );
evvnt_check( 'this page\'s date is not a link', (bool) preg_match( '#<span class="evvnt-cal__series-date is-viewed"[^>]*aria-current="page"#', $html ), true );
evvnt_check( 'a date never indexed is not a link', (bool) preg_match( '#<span class="evvnt-cal__series-date is-upcoming"#', $html ), true );
evvnt_check( 'chips have no Viewing/Next badges', substr_count( $html, 'evvnt-cal__series-badge' ), 0 );

$list = $renderer->render_detail( evvnt_series_event( array( 'count' => 10 ) ), $base, array() );
evvnt_check( 'list view: badges on this page\'s date and the next', array( substr_count( $list, 'evvnt-cal__series--list' ), substr_count( $list, '>Viewing<' ), substr_count( $list, '>Next<' ), substr_count( $list, 'evvnt-cal__series-more' ) ), array( 1, 1, 1, 0 ) );
evvnt_check( 'list caption counts the dates', (bool) preg_match( '#evvnt-cal__series-caption">9 dates · #', $list ), true );

$big = evvnt_series_event( array( 'count' => 30 ) );
$win = $renderer->render_detail( $big, $base, array() );
evvnt_check( 'long series: 12 dates and Show all', array( substr_count( $win, 'class="evvnt-cal__series-date ' ), (bool) preg_match( '#href="[^"]*evDates=all\#evvnt-cal-dates"[^>]*>\s*Show all 29 dates#', $win ) ), array( 12, true ) );
evvnt_check( 'long series offers the calendar', (bool) preg_match( '#<a href="[^"]*evView=calendar\#evvnt-cal-dates" rel="nofollow">Calendar</a>#', $win ), true );
$all = $renderer->render_detail( $big, $base, array( 'evDates' => 'all' ) );
evvnt_check( '?evDates=all shows every date, and date links keep it', array( substr_count( $all, 'class="evvnt-cal__series-date ' ), substr_count( $all, 'evvnt-cal__series-more' ), (bool) preg_match( '#/event/771-jazz-night&\#038;evDates=all\#evvnt-cal-dates#', $all ) ), array( 29, 0, true ) );
$cal = $renderer->render_detail( $big, $base, array( 'evView' => 'calendar' ) );
evvnt_check( '?evView=calendar shows month grids', array( substr_count( $cal, 'evvnt-cal__series--calendar' ), substr_count( $cal, 'class="evvnt-cal__series-date ' ) > 0, substr_count( $cal, 'evvnt-cal__datecal-month' ) >= 7 ), array( 1, false, true ) );
evvnt_check( 'calendar days link with the chosen view', (bool) preg_match( '#<td class="is-scheduled is-next">\s*<a href="[^"]*/event/771-jazz-night&\#038;evView=calendar\#evvnt-cal-dates" aria-label="[A-Z][a-z]+day, [A-Z][a-z]+ \d{1,2}, \d{4}, next date">#', $cal ), true );
evvnt_check( 'calendar marks this page\'s day', (bool) preg_match( '#<td class="is-scheduled is-viewed">\s*<span aria-current="date" role="img" aria-label="[^"]+, viewing">#', $cal ), true );
evvnt_check( 'one month is current for paging', substr_count( $cal, ' data-current>' ), 1 );
evvnt_check( 'each month table is named by its heading', (bool) preg_match( '#<h3 class="evvnt-cal__datecal-caption" id="(evvnt-cal-dates-\d{4}-\d{2})">.*?</h3>\s*<table aria-labelledby="\1">#s', $cal ), true );
$all_cal = $renderer->render_detail( $big, $base, array( 'evDates' => 'all', 'evView' => 'calendar' ) );
evvnt_check( 'switching views keeps "show all"', array( (bool) preg_match( '#<a href="[^"]*evDates=all\#evvnt-cal-dates" rel="nofollow">List</a>#', $all_cal ), (bool) preg_match( '#<a href="[^"]*evView=calendar&\#038;evDates=all\#evvnt-cal-dates" rel="nofollow"[^>]*>Calendar</a>#', $all ) ), array( true, true ) );
evvnt_check( 'an unknown ?evView falls back to the default view', substr_count( $renderer->render_detail( $big, $base, array( 'evView' => '<x>' ) ), 'evvnt-cal__series--list' ), 1 );
evvnt_check( 'a view the series does not offer falls back', substr_count( $renderer->render_detail( evvnt_series_event( array( 'count' => 4 ) ), $base, array( 'evView' => 'calendar' ) ), 'evvnt-cal__series--chips' ), 1 );
$daily = $renderer->render_detail( evvnt_series_event( array( 'count' => 40, 'step' => '+1 day', 'frequency' => 'daily' ) ), $base, array() );
evvnt_check( 'dense series opens on the calendar', substr_count( $daily, 'evvnt-cal__series--calendar' ), 1 );
evvnt_check( 'non-series event shows no series parts', array( substr_count( $renderer->render_detail( new Evvnt_Calendar_Event( evvnt_fixture( 'events_page1' )['events'][1], $publisher ), $base, array() ), 'evvnt-cal__series' ) ), array( 0 ) );
$feat = $renderer->render_detail( evvnt_series_event( array( 'count' => 10 ), array( 'featured_publisher_ids' => array( 5293 ) ) ), $base, array() );
evvnt_check( 'featured layout: series badge in the hero and the dates', array( substr_count( $feat, 'evvnt-cal__badge--series' ), substr_count( $feat, 'evvnt-cal__series--list' ) ), array( 1, 1 ) );

// Cards.
$card_raw = array( 'title' => 'Jazz Night', 'source_id' => '771', 'sources' => array( 'evvnt' ), 'start_time' => gmdate( 'c', time() + DAY_IN_SECONDS ), 'start_time_i' => time() + DAY_IN_SECONDS, 'core_metadata' => array( 'recurrence' => evvnt_series( array( 'count' => 4 ) )['recurrence'] ) );
$card     = $renderer->render_card( new Evvnt_Calendar_Event( $card_raw, $publisher ), $base, true );
evvnt_check( 'listing card of a series (core_metadata) shows the repeat icon', (bool) preg_match( '#<span class="evvnt-cal__card-series" title="Weekly series">.*?<span class="screen-reader-text">Weekly series</span>#s', $card ), true );
evvnt_check( 'the repeat icon replaces the calendar icon', substr_count( $card, 'evvnt-cal__icon--calendar' ) + substr_count( $card, '<rect x="3" y="4"' ), 0 );
unset( $card_raw['core_metadata'] );
evvnt_check( 'other cards keep the calendar icon', array( substr_count( $renderer->render_card( new Evvnt_Calendar_Event( $card_raw, $publisher ), $base, true ), 'evvnt-cal__card-series' ) ), array( 0 ) );

/* ------------------------------------------------------------------ */
echo "== Category list page\n";

evvnt_check( '/categories is the category list', Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/categories' ) )->type, 'categories' );
evvnt_check( 'other single segments are still categories', Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts' ) )->type, 'category' );
evvnt_check( 'categories_url (query-string mode)', Evvnt_Calendar_Route::categories_url( $base ), 'http://example.test/?page_id=4&_evDiscoveryPath=/categories' );
$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$html      = $renderer->render_categories( $base, $atts );
preg_match_all( '#<h3 id="evvnt-cal-cat-\d+"><a href="([^"]+)"><span>([^<]+)</span>#', $html, $m );
evvnt_check( 'lists every category A-Z', $m[2], array_map( 'esc_html', wp_list_pluck( call_user_func( function ( $c ) { usort( $c, function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } ); return $c; }, $renderer->categories() ), 'name' ) ) );
$evvnt_by_name = array_column( $renderer->categories(), 'slug', 'name' );
evvnt_check( 'each links to its category page', array_map( 'html_entity_decode', $m[1] ), array_map( function ( $name ) use ( $evvnt_by_name, $base ) { return Evvnt_Calendar_Route::calendar_url( $base, $evvnt_by_name[ html_entity_decode( $name ) ] ); }, $m[2] ) );
evvnt_check( 'category list has the search box and a home link', array( substr_count( $html, 'class="evvnt-cal__search"' ), substr_count( $html, 'class="evvnt-cal__home"' ) ), array( 1, 1 ) );
$list = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base );
evvnt_check( 'calendar footer links to the category list', (bool) preg_match( '#<a class="evvnt-cal__all-categories" href="http://example\.test/\?page_id=4&\#038;_evDiscoveryPath=/categories">Browse all categories#', $list ), true );
evvnt_check( 'a calendar pinned to one category has no category-list link', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), array( 'category_id' => 16 ) + $atts, $base ), 'evvnt-cal__all-categories' ), 0 );
evvnt_check( 'rows layout footer links too', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), array( 'layout' => 'rows' ) + $atts, $base ), 'evvnt-cal__all-categories' ), 1 );

/* ------------------------------------------------------------------ */
echo "== Category tree and sub-category pages\n";

delete_option( Evvnt_Calendar_Category_Tree::OPTION );
wp_clear_scheduled_hook( Evvnt_Calendar_Category_Tree::CRON_HOOK );
$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$tree      = $renderer->category_tree();
evvnt_check( 'before the first build: roots only, and a build is scheduled', array( $tree->is_built(), count( $tree->nodes() ), (bool) wp_next_scheduled( Evvnt_Calendar_Category_Tree::CRON_HOOK ) ), array( false, count( $renderer->categories() ), true ) );
evvnt_check( 'page views never walk the whole tree', count( preg_grep( '#api\.evvnt\.com#', $transport->urls ) ), 0 );
$comedy = $tree->by_path( 'arts/performing-arts/comedy' );
evvnt_check( 'one sub-category path resolves before the build (by walking it)', array( $comedy['id'], $comedy['depth'], wp_list_pluck( $comedy['trail'], 'name' ) ), array( 3, 2, array( 'Arts', 'Performing Arts' ) ) );
evvnt_check( '...with one request per level', array_values( preg_grep( '#api\.evvnt\.com#', $transport->urls ) ), array( 'https://api.evvnt.com/categories/28', 'https://api.evvnt.com/categories/30' ) );
evvnt_check( 'unknown sub-category path => null', $tree->by_path( 'arts/performing-arts/opera' ), null );
evvnt_check( 'sub-category links fall back to the filtered parent before the build', $renderer->subcategory_link( 3, 'Comedy', 'arts', $base ), 'http://example.test/?page_id=4&_evDiscoveryPath=/arts&subcategory=3%7CComedy' );

$stored = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh();
$renderer = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$tree     = $renderer->category_tree();
$nodes    = $tree->nodes();
evvnt_check( 'background build stores every category', array( $stored['complete'], $tree->is_built(), count( $nodes ) ), array( true, true, count( $renderer->categories() ) + 10 ) );
evvnt_check( 'the stored tree is not autoloaded', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Evvnt_Calendar_Category_Tree::OPTION ) ), 'off' );
evvnt_check( 'paths from the root', array( $nodes[3]['path'], $nodes[31]['path'], $nodes[29]['path'], $nodes[7]['path'] ), array( 'arts/performing-arts/comedy', 'arts/performing-arts/other', 'arts/other', 'arts/film-cinema' ) );
evvnt_check( 'reserved and duplicate sibling slugs get the id', array( $nodes[500]['path'], $nodes[37]['path'], $nodes[501]['path'] ), array( 'live-music/page-500', 'live-music/live-music', 'live-music/live-music-501' ) );
evvnt_check( 'names shown as the discovery JS does', $nodes[7]['name'], 'Film & Cinema' );
evvnt_check( 'by_id gives the trail', wp_list_pluck( $tree->by_id( 3 )['trail'], 'name' ), array( 'Arts', 'Performing Arts' ) );
$nested = $tree->nested();
$arts   = array_values( array_filter( $nested, function ( $n ) { return 28 === $n['id']; } ) )[0];
evvnt_check( 'nested A-Z at every depth', array( wp_list_pluck( $arts['children'], 'name' ), wp_list_pluck( $arts['children'][2]['children'], 'name' ) ), array( array( 'Film & Cinema', 'Other', 'Performing Arts', 'Visual Arts' ), array( 'Comedy', 'Other', 'Theatre' ) ) );
evvnt_check( 'a fresh tree schedules nothing', ( wp_clear_scheduled_hook( Evvnt_Calendar_Category_Tree::CRON_HOOK ) || true ) && ! ( ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->nodes() && wp_next_scheduled( Evvnt_Calendar_Category_Tree::CRON_HOOK ) ), true );
set_transient( Evvnt_Calendar_Category_Tree::LOCK, 1, 60 );
evvnt_check( 'only one build at a time', $tree->refresh(), null );
delete_transient( Evvnt_Calendar_Category_Tree::LOCK );

// Incomplete builds are kept but retried sooner; a day-old tree is refreshed in the background.
$failing = new Evvnt_Test_Transport();
$failing->overrides['#api\.evvnt\.com/categories/30$#'] = new WP_Error( 'x', 'down', array( 'status' => 500 ) );
$part = ( new Evvnt_Calendar_Renderer( evvnt_client( $failing ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh();
evvnt_check( 'a failed branch marks the build incomplete but keeps what the last build knew of it', array( $part['complete'], isset( $part['nodes'][30] ), isset( $part['nodes'][3] ) ), array( false, true, true ) );
$gone = new Evvnt_Test_Transport();
$gone->overrides['#api\.evvnt\.com/categories/39$#'] = new WP_Error( 'x', 'gone', array( 'status' => 404 ) );
evvnt_check( 'a category that 404s just has no children', ( new Evvnt_Calendar_Renderer( evvnt_client( $gone ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh()['complete'], true );
// No earlier tree: the failed branch is missing, so its paths are walked live instead of 404ing.
delete_option( Evvnt_Calendar_Category_Tree::OPTION );
( new Evvnt_Calendar_Renderer( evvnt_client( $failing ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh();
$walk_tree = ( new Evvnt_Calendar_Renderer( evvnt_client( new Evvnt_Test_Transport() ), Evvnt_Calendar_Settings::get() ) )->category_tree();
evvnt_check( 'an incomplete tree walks paths it is missing', $walk_tree->by_path( 'arts/performing-arts/theatre' )['id'], 23 );
evvnt_check( '...and still knows unknown paths are unknown', $walk_tree->by_path( 'arts/performing-arts/opera' ), null );
$down_tree = ( new Evvnt_Calendar_Renderer( evvnt_client( $failing ), Evvnt_Calendar_Settings::get() ) )->category_tree();
evvnt_check( 'a failing category API => unavailable, not not-found', is_wp_error( $down_tree->by_path( 'arts/performing-arts/theatre' ) ), true );
$down_renderer = new Evvnt_Calendar_Renderer( evvnt_client( $failing ), Evvnt_Calendar_Settings::get() );
evvnt_check( '...so the sub-category page says the calendar is unavailable', array( is_wp_error( $down_renderer->listing( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/performing-arts/theatre' ) ), $atts ) ), substr_count( $down_renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/performing-arts/theatre' ) ), $atts, $base ), 'evvnt-cal--unavailable' ) ), array( true, 1 ) );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );
( new Evvnt_Calendar_Renderer( evvnt_client( $failing ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh(); // Incomplete again, for the retry check.
$aged       = get_option( Evvnt_Calendar_Category_Tree::OPTION );
$aged['at'] = time() - Evvnt_Calendar_Category_Tree::RETRY_AFTER - 1;
update_option( Evvnt_Calendar_Category_Tree::OPTION, $aged, false );
wp_clear_scheduled_hook( Evvnt_Calendar_Category_Tree::CRON_HOOK );
( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->nodes();
evvnt_check( 'an incomplete tree is retried after 15 minutes', (bool) wp_next_scheduled( Evvnt_Calendar_Category_Tree::CRON_HOOK ), true );
( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh();
$aged       = get_option( Evvnt_Calendar_Category_Tree::OPTION );
$aged['at'] = time() - DAY_IN_SECONDS - 1;
update_option( Evvnt_Calendar_Category_Tree::OPTION, $aged, false );
wp_clear_scheduled_hook( Evvnt_Calendar_Category_Tree::CRON_HOOK );
$stale_tree = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree();
evvnt_check( 'a day-old tree is still used while it refreshes', array( count( $stale_tree->nodes() ) > count( $renderer->categories() ), (bool) wp_next_scheduled( Evvnt_Calendar_Category_Tree::CRON_HOOK ) ), array( true, true ) );
$aged['sig'] = 'other';
update_option( Evvnt_Calendar_Category_Tree::OPTION, $aged, false );
evvnt_check( 'a tree for other root categories is not used', ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->is_built(), false );
( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->category_tree()->refresh();

// Sub-category pages.
$transport = new Evvnt_Test_Transport();
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$route     = Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/performing-arts/comedy' ) );
$html      = $renderer->render_calendar( $route, $atts, $base );
evvnt_check( 'sub-category page lists its events (category + sub-category filter)', count( preg_grep( '#categoryId=28&.*subCategoryIds=3(&|$)#', $transport->urls ) ) > 0, true );
evvnt_check( 'sub-category page title', (bool) preg_match( '#<h2 class="evvnt-cal__category-title">\s*Comedy#', $html ), true );
evvnt_check( 'breadcrumbs link up to the root', (bool) preg_match( '#<nav class="evvnt-cal__breadcrumbs"[^>]*>\s*<ol>\s*<li><a href="http://example\.test/\?page_id=4&\#038;_evDiscoveryPath=/arts">Arts</a></li>\s*<li><span class="evvnt-cal__crumb-sep" aria-hidden="true">›</span> <a href="http://example\.test/\?page_id=4&\#038;_evDiscoveryPath=/arts/performing-arts">Performing Arts</a></li>#u', $html ), true );
evvnt_check( 'sub-category page: next page keeps the path', false !== strpos( $html, '_evDiscoveryPath=/arts/performing-arts/comedy&#038;evPage=2' ), true );
evvnt_check( 'sub-category page: no filter chip', substr_count( $html, 'evvnt-cal__subcategory' ), 0 );
// Pagination on filtered and sub-category pages, with any PHP warning failing the test.
$evvnt_warnings = array();
set_error_handler( function ( $no, $msg ) use ( &$evvnt_warnings ) { $evvnt_warnings[] = $msg; return true; } );
$filtered = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts', 'subcategory' => '999|Unknown' ) ), $atts, $base );
$sub_with_filter = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/performing-arts', 'subcategory' => '999|Unknown' ) ), $atts, $base );
restore_error_handler();
evvnt_check( 'no PHP warnings rendering filtered and sub-category pages', $evvnt_warnings, array() );
evvnt_check( 'filtered page: next page keeps the filter', false !== strpos( $filtered, '_evDiscoveryPath=/arts&#038;evPage=2&#038;subcategory=999%7CUnknown' ), true );
evvnt_check( 'sub-category page: next page is its own path', false !== strpos( $sub_with_filter, '_evDiscoveryPath=/arts/performing-arts&#038;evPage=2' ), true );
evvnt_check( 'unknown sub-category path => not found notice', substr_count( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/arts/nope' ) ), $atts, $base ), 'evvnt-cal__notice' ), 1 );
evvnt_check( 'event category links go to sub-category pages once the tree is built', $renderer->subcategory_link( 7, 'Film / Cinema', 'arts', $base ), 'http://example.test/?page_id=4&_evDiscoveryPath=/arts/film-cinema' );
evvnt_check( 'search matches sub-categories in the tree', in_array( array( 'name' => 'Theatre', 'url' => 'http://example.test/?page_id=4&_evDiscoveryPath=/arts/performing-arts/theatre' ), $renderer->category_matches( 'theat', array(), $base ), true ), true );
$cats = $renderer->render_categories( $base, $atts );
evvnt_check( 'category list nests sub-categories at every depth', (bool) preg_match( '#<h3 id="evvnt-cal-cat-28"><a href="[^"]+/arts"><span>Arts</span>.*?</h3>\s*<ul>.*?<li><a href="[^"]+/arts/performing-arts">Performing Arts</a><ul><li><a href="[^"]+/arts/performing-arts/comedy">Comedy</a></li>#s', $cats ), true );
evvnt_check( 'category list links every category', substr_count( $cats, '<a href="http://example.test/?page_id=4&#038;_evDiscoveryPath=/' ), count( $renderer->category_tree()->nodes() ) );

/* ------------------------------------------------------------------ */
echo "== Compact widget\n";

update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'submission_label' => 'Promote your event' ) );
$evvnt_widget_raw = array_map(
	function ( $e ) {
		$e['featured_publisher_ids'] = array( 5293 );
		$e['end_time_i']             = time() + 30 * DAY_IN_SECONDS; // Upcoming whatever today's date.
		return $e;
	},
	array_slice( evvnt_fixture( 'events_page1' )['events'], 0, 30 )
);
$transport = new Evvnt_Test_Transport();
$transport->overrides['#/widget_events\?#'] = array( 'rawEvents' => $evvnt_widget_raw );
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$wa        = array( 'detail_page_enabled' => true, 'virtual' => false, 'category_id' => 9 );
$html      = $renderer->render_widget( $wa );
evvnt_check( 'widget queries widget_events as the JS widget does', in_array( 'https://discovery.evvnt.com/api/publisher/5293/widget_events?categoryId=9&hitsPerPage=30&multipleEventInstances=true&publisher_id=5293&widgetNumber=30', $transport->urls, true ), true );
preg_match_all( '#<li( class="evvnt-cal__widget-ad")?>#', $html, $m );
evvnt_check( 'with a vertical widget promotion: 3 events and 1 ad, never first', array( count( $m[0] ), count( array_filter( $m[1] ) ), '' === $m[1][0] ), array( 4, 1, true ) );
evvnt_check( 'ad links to the submission form with utm parameters', (bool) preg_match( '#class="evvnt-cal__widget-ad">\s*<a href="https://new\.evvnt\.com/\?[^"]*utm_source=5293&\#038;utm_medium=widget&\#038;utm_campaign=promote_asset"#', $html ), true );
evvnt_check( 'widget parts: create button, search box, more events, credit', array( substr_count( $html, 'evvnt-cal__widget-create' ), substr_count( $html, 'evvnt-cal__search--widget' ), substr_count( $html, 'placeholder="Search for events"' ), substr_count( $html, 'evvnt-cal__widget-more' ), substr_count( $html, 'powered_by_evvnt.png' ) ), array( 1, 1, 1, 1, 1 ) );
evvnt_check( 'without a calendar page, links go to the publisher calendar (as the JS widget)', (bool) preg_match( '#<a class="evvnt-cal__widget-item" href="https://evvnt\.com/events/\?_evDiscoveryPath=/event/\d+-[a-z0-9-]+"( data-evvnt-view="[^"]+")?>#', $html ), true );
evvnt_check( 'More Events goes to the publisher calendar', (bool) preg_match( '#<a class="evvnt-cal__button evvnt-cal__widget-more" href="https://evvnt\.com/events/">#', $html ), true );
evvnt_check( 'widget is never the wide breakout', substr_count( $html, 'evvnt-cal--wide' ), 0 );
evvnt_check( 'tiles: blurred backdrop, inset image, no price, no time', array( substr_count( $html, 'evvnt-cal__widget-backdrop' ), substr_count( $html, 'evvnt-cal__widget-image' ), substr_count( $html, 'evvnt-cal__icon--ticket' ), preg_match( '#<time datetime="[^"]+">[^<]*\d:\d\d#', $html ) ), array( 3, 3, 0, 0 ) );
$evvnt_day   = new Evvnt_Calendar_Event( array( 'title' => 't', 'start_time' => '2030-11-07T19:30:00-06:00', 'start_time_i' => strtotime( '2030-11-07T19:30:00-06:00' ), 'end_time_i' => strtotime( '2030-11-07T22:00:00-06:00' ) ), $publisher );
$evvnt_range = new Evvnt_Calendar_Event( array( 'title' => 't', 'start_time' => '2030-11-07T19:30:00-06:00', 'end_time' => '2030-12-24T19:30:00-06:00', 'start_time_i' => strtotime( '2030-11-07T19:30:00-06:00' ), 'end_time_i' => strtotime( '2030-12-24T19:30:00-06:00' ) ), $publisher );
evvnt_check( 'short date label: no time', array( $evvnt_day->short_date_label(), $evvnt_range->short_date_label() ), array( 'Thu, Nov 7, 2030', 'Nov 7 – Dec 24, 2030' ) );

$evvnt_widget_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Widget cal', 'post_content' => '[evvnt]' ) );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'calendar_page_id' => $evvnt_widget_page ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
preg_match_all( '#<a class="evvnt-cal__widget-item" href="([^"]+)"#', $html, $m );
evvnt_check( 'with a calendar page, events open on this site', array( count( $m[1] ) > 0, count( array_filter( $m[1], function ( $u ) { return 0 === strpos( html_entity_decode( $u ), home_url( '/' ) ); } ) ) === count( $m[1] ) ), array( true, true ) );
evvnt_check( '...and More Events goes to the calendar page', false !== strpos( $html, 'evvnt-cal__widget-more" href="' . esc_url( Evvnt_Calendar_Route::calendar_url( get_permalink( $evvnt_widget_page ) ) ) . '"' ), true );
wp_delete_post( $evvnt_widget_page, true );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'submission_label' => 'Promote your event' ) );

$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->render_widget( array( 'detail_page_enabled' => false, 'virtual' => true, 'category_id' => 0 ) );
evvnt_check( 'virtual option filters the widget too', count( preg_grep( '#/widget_events\?.*virtual=true#', $transport->urls ) ), 1 );
evvnt_check( 'detail pages off => events open their own link in a new tab', (bool) preg_match( '#<a class="evvnt-cal__widget-item" href="(?!https://evvnt\.com/events/)[^"]+" rel="nofollow noopener" target="_blank"( data-evvnt-view="[^"]+")?>#', $html ), true );

$no_ads = new Evvnt_Test_Transport();
$no_ads->overrides['#/widget_events\?#']     = array( 'rawEvents' => $evvnt_widget_raw );
$no_ads->overrides['#/publisher_settings#'] = call_user_func( function () { $p = evvnt_fixture( 'publisher_settings' ); $p['publisher']['promotional_assets'] = array(); $p['publisher']['event_submission_url'] = 'https://example.org/submit'; return $p; } );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $no_ads ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
preg_match_all( '#<li( class="evvnt-cal__widget-ad")?>#', $html, $m );
evvnt_check( 'without a promotion: 4 events, no ad', array( count( $m[0] ), count( array_filter( $m[1] ) ) ), array( 4, 0 ) );
evvnt_check( "the publisher's own submission form is used as is", substr_count( $html, 'href="https://example.org/submit"' ), 1 );

$few = new Evvnt_Test_Transport();
$few->overrides['#/widget_events\?#'] = array( 'rawEvents' => array_slice( $evvnt_widget_raw, 0, 1 ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $few ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
preg_match_all( '#<li( class="evvnt-cal__widget-ad")?>#', $html, $m );
evvnt_check( 'one event: the ad goes after it', array_map( 'strlen', $m[1] ), array( 0, strlen( ' class="evvnt-cal__widget-ad"' ) ) );

$down = new Evvnt_Test_Transport();
$down->overrides['#/widget_events\?#'] = new WP_Error( 'x', 'down', array( 'status' => 500 ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $down ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );
evvnt_check( 'API down => the widget says so (and still offers More Events)', array( substr_count( $html, 'Events are unavailable right now' ), substr_count( $html, 'evvnt-cal__widget-more' ) ), array( 1, 1 ) );

// The pool lists a featured event twice (featured, then in the top-up): it fills one slot only.
$dupes = new Evvnt_Test_Transport();
$dupes->overrides['#/widget_events\?#'] = array( 'rawEvents' => array_merge( array_slice( $evvnt_widget_raw, 0, 2 ), array_slice( $evvnt_widget_raw, 0, 2 ) ) );
$dupes->overrides['#/publisher_settings#'] = $no_ads->overrides['#/publisher_settings#'];
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $dupes ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
preg_match_all( '#<a class="evvnt-cal__widget-item" href="([^"]+)"#', $html, $m );
evvnt_check( 'the same event never fills two slots', array( count( $m[1] ), count( array_unique( $m[1] ) ) ), array( 2, 2 ) );
evvnt_check( 'unique_events keeps the first of each objectID', count( Evvnt_Calendar_Renderer::unique_events( array( array( 'objectID' => '1' ), array( 'objectID' => '1' ), array( 'objectID' => '2' ), array( 'title' => 'no id' ) ) ) ), 3 );

// An event with no image gets Evvnt's placeholder, as in the JS widget.
$noimg = new Evvnt_Test_Transport();
$noimg->overrides['#/widget_events\?#'] = array( 'rawEvents' => array( array_diff_key( $evvnt_widget_raw[0], array( 'images' => 1 ) ) + array( 'images' => array() ) ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $noimg ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
evvnt_check( 'no image => placeholder image', substr_count( $html, 'images/placeholder-event-image.jpg' ) >= 1, true );

// Live suggestions only when the widget links into this site's calendar.
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
evvnt_check( 'off-site calendar => plain search box (no suggestions to this site)', array( substr_count( $html, 'data-suggest=""' ), substr_count( $html, 'role="combobox"' ), substr_count( $html, 'evvnt-cal__suggest"' ) ), array( 1, 0, 0 ) );
$evvnt_widget_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Widget cal 2', 'post_content' => '[evvnt]' ) );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'calendar_page_id' => $evvnt_widget_page ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
evvnt_check( 'calendar on this site => live suggestions', array( (bool) preg_match( '#data-suggest="http[^"]+/evvnt-calendar/v1/suggest"#', $html ), substr_count( $html, 'role="combobox"' ) ), array( true, 1 ) );
wp_delete_post( $evvnt_widget_page, true );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'submission_label' => 'Promote your event' ) );

$hostile_widget = new Evvnt_Test_Transport();
$hostile_widget->overrides['#/widget_events\?#'] = array( 'rawEvents' => array( array( 'title' => '<script>alert(1)</script>', 'sources' => array( 'evvnt' ), 'source_id_s' => '5', 'start_time' => '2030-01-01T10:00:00+00:00', 'start_time_i' => 1893492000, 'images' => array( array( 'url' => 'javascript:alert(2)' ) ) ) ) );
$html = ( new Evvnt_Calendar_Renderer( evvnt_client( $hostile_widget ), Evvnt_Calendar_Settings::get() ) )->render_widget( $wa );
evvnt_check( 'widget escapes event data', array( substr_count( $html, '<script>alert' ), substr_count( $html, 'javascript:' ) ), array( 0, 0 ) );

// The shortcode and the WordPress widget use it in server mode (API calls answered by a filter).
$evvnt_fake_http = function ( $pre, $args, $url ) use ( $evvnt_widget_raw ) {
	if ( false !== strpos( $url, 'discovery.evvnt.com' ) ) {
		$body = false !== strpos( $url, '/publisher_settings' ) ? evvnt_fixture( 'publisher_settings' ) : array( 'rawEvents' => $evvnt_widget_raw );
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
};
add_filter( 'pre_http_request', $evvnt_fake_http, 10, 3 );
Evvnt_Calendar_Cache::flush();
$evvnt_public = new Evvnt_Calendar_Public( 'evvnt-calendar', '0.1.0' );
evvnt_check( '[evvnt config_type="widget"] is server-rendered', substr_count( $evvnt_public->build_shortcode( array( 'config_type' => 'widget', 'category_id' => '9' ) ), 'evvnt-cal--widget' ), 1 );
ob_start();
( new Evvnt_Calendar_Widget() )->widget( array( 'before_widget' => '<section>', 'after_widget' => '</section>' ), array( 'config_type' => 'widget', 'category_id' => '9', 'detail_page_enabled' => '1', 'virtual' => '0', 'map' => '0' ) );
$evvnt_out = ob_get_clean();
evvnt_check( 'WordPress widget of the Widget type is server-rendered', array( substr_count( $evvnt_out, '<section><div class="evvnt-cal evvnt-cal--widget">' ), substr_count( $evvnt_out, 'evvnt_require' ) ), array( 1, 0 ) );
ob_start();
( new Evvnt_Calendar_Widget() )->widget( array(), array( 'config_type' => 'calendar' ) );
evvnt_check( 'a calendar-type WordPress widget is still the discovery embed', substr_count( ob_get_clean(), 'class="evvnt-calendar"' ), 1 );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js' ) );
evvnt_check( 'JS mode keeps the JS widget', substr_count( $evvnt_public->build_shortcode( array( 'config_type' => 'widget' ) ), 'class="evvnt-calendar"' ), 1 );
// Head stylesheet: only for widgets in active widget areas, block widgets with the shortcode, or the post.
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server' ) );
$evvnt_saved_sidebars = get_option( 'sidebars_widgets' );
$evvnt_saved_evvnt    = get_option( 'widget_evvnt_calendar_widget' );
$evvnt_saved_blocks   = get_option( 'widget_block' );
$evvnt_has_widget     = new ReflectionMethod( 'Evvnt_Calendar_Public', 'page_has_widget' );
$evvnt_has_widget->setAccessible( true );
$evvnt_sidebar_id     = array_values( array_diff( array_keys( (array) $GLOBALS['wp_registered_sidebars'] ), array( 'wp_inactive_widgets' ) ) )[0];
update_option( 'widget_evvnt_calendar_widget', array( 7 => array( 'config_type' => 'widget' ), 8 => array( 'config_type' => 'calendar' ), '_multiwidget' => 1 ) );
update_option( 'widget_block', array( 9 => array( 'content' => '<!-- wp:shortcode -->[evvnt config_type="widget" category_id="9"]<!-- /wp:shortcode -->' ), '_multiwidget' => 1 ) );
$evvnt_set_sidebars = function ( $active, $inactive ) use ( $evvnt_sidebar_id ) {
	update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => $inactive, $evvnt_sidebar_id => $active, 'array_version' => 3 ) );
	wp_cache_delete( 'alloptions', 'options' );
	$GLOBALS['_wp_sidebars_widgets'] = array(); // WordPress caches the layout per request.
};
$evvnt_set_sidebars( array( 'evvnt_calendar_widget-7' ), array() );
evvnt_check( 'active Widget-type widget => stylesheet in the head', $evvnt_has_widget->invoke( null ), true );
$evvnt_set_sidebars( array( 'evvnt_calendar_widget-8' ), array( 'evvnt_calendar_widget-7' ) );
evvnt_check( 'Widget-type widget only in Inactive widgets => no', $evvnt_has_widget->invoke( null ), false );
$evvnt_set_sidebars( array( 'block-9' ), array() );
evvnt_check( 'widget shortcode in a block widget => yes', $evvnt_has_widget->invoke( null ), true );
update_option( 'sidebars_widgets', $evvnt_saved_sidebars );
$GLOBALS['_wp_sidebars_widgets'] = array();
false === $evvnt_saved_evvnt ? delete_option( 'widget_evvnt_calendar_widget' ) : update_option( 'widget_evvnt_calendar_widget', $evvnt_saved_evvnt );
false === $evvnt_saved_blocks ? delete_option( 'widget_block' ) : update_option( 'widget_block', $evvnt_saved_blocks );
wp_cache_delete( 'alloptions', 'options' );

remove_filter( 'pre_http_request', $evvnt_fake_http, 10 );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js', 'submission_label' => 'Promote your event' ) );
Evvnt_Calendar_Cache::flush();

/* ------------------------------------------------------------------ */
echo "== Shortcode detection\n";

$fake_post = function ( $content ) {
	return new WP_Post( (object) array( 'ID' => 0, 'post_content' => $content, 'post_status' => 'publish' ) );
};
evvnt_check( 'plain [evvnt]', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( 'x [evvnt] y' ) ), array( 'detail_page_enabled' => true, 'virtual' => false, 'category_id' => 0, 'layout' => 'list' ) );
evvnt_check( 'first calendar shortcode wins over widget', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( '[evvnt config_type="widget"] [evvnt category_id="17" virtual="1"]' ) ), array( 'detail_page_enabled' => true, 'virtual' => true, 'category_id' => 17, 'layout' => 'list' ) );
evvnt_check( 'widget-only page is not routed', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( '[evvnt config_type="widget"]' ) ), null );
evvnt_check( 'escaped [[evvnt]] ignored', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( 'Use [[evvnt]] to embed' ) ), null );
$synced = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'cal', 'post_content' => '<!-- wp:shortcode -->[evvnt category_id="9"]<!-- /wp:shortcode -->' ) );
evvnt_check( 'shortcode inside a synced pattern', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( '<!-- wp:block {"ref":' . $synced . '} /-->' ) )['category_id'], 9 );
wp_delete_post( $synced, true );

/* ------------------------------------------------------------------ */
echo "== Escaping of hostile API data\n";

$hostile = new Evvnt_Calendar_Event(
	array(
		'title'            => '<script>alert("t")</script>',
		'summary'          => '<img src=x onerror=alert(1)>',
		'description_html' => '<p>ok</p><img src=x onerror="alert(2)"><a href="javascript:alert(3)">x</a><script>alert(4)</script>',
		'organiser_name'   => '"><svg onload=alert(5)>',
		'sources'          => array( 'evvnt' ),
		'source_id_s'      => '1',
		'start_time'       => '2030-01-01T10:00:00+00:00',
		'start_time_i'     => 1893492000,
		'links'            => array( 'Tickets' => 'javascript:alert(6)' ),
		'images'           => array( array( 'original' => array( 'url' => 'javascript:alert(7)' ), 'hero' => array( 'url' => 'http://insecure.test/x.jpg' ) ) ),
		'venue'            => array( 'name' => '</dd><script>alert(8)</script>', 'town' => 'T' ),
		'prices'           => array( 'x' => '<b>USD</b> 1' ),
	),
	$publisher
);
$renderer = new Evvnt_Calendar_Renderer( evvnt_client( new Evvnt_Test_Transport() ), Evvnt_Calendar_Settings::get() );
$html     = $renderer->render_detail( $hostile, $base ) . $renderer->render_card( $hostile, $base, false );
evvnt_check( 'detail hero renders with blurred backdrop and one h1', array( substr_count( $html, 'evvnt-cal__hero-backdrop' ) <= 1, substr_count( $html, '<h1' ) ), array( true, 1 ) );
evvnt_check( 'no live <script> from API data', (bool) preg_match( '#<script#i', $html ), false );
evvnt_check( 'no event-handler attributes', (bool) preg_match( '#<[^>]+\son(error|load)=#i', $html ), false );
evvnt_check( 'no javascript: URLs', false !== stripos( $html, 'javascript:' ), false );
evvnt_check( 'non-https images dropped', false !== strpos( $html, 'insecure.test' ), false );
$json = wp_json_encode( $hostile->to_schema( 'u' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
evvnt_check( 'JSON-LD cannot close its script tag', false !== stripos( $json, '</script' ), false );

/* ------------------------------------------------------------------ */
echo "== Path URLs\n";

$saved_settings  = get_option( Evvnt_Calendar_Settings::OPTION );
$saved_structure = get_option( 'permalink_structure' );
$cal_page        = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'whats-on', 'post_title' => 'What\'s on', 'post_content' => '[evvnt]' ) );
update_option( 'permalink_structure', '/%postname%/' );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'calendar_page_id' => $cal_page ) );
$GLOBALS['wp_rewrite']->init();

evvnt_check( 'config active', Evvnt_Calendar_Router::config()['page_path'], 'whats-on' );
evvnt_check( 'event path URL', Evvnt_Calendar_Route::event_url( 'ignored', '3763745-crepes-company' ), home_url( '/event/3763745-crepes-company/' ) );
evvnt_check( 'calendar path URL', Evvnt_Calendar_Route::calendar_url( '' ), home_url( '/whats-on/' ) );
evvnt_check( 'category page path URL', Evvnt_Calendar_Route::calendar_url( '', 'nightlife', 3 ), home_url( '/whats-on/category/nightlife/page/3/' ) );
evvnt_check( 'page path URL', Evvnt_Calendar_Route::calendar_url( '', '', 2 ), home_url( '/whats-on/page/2/' ) );
evvnt_check( 'category list path URL', Evvnt_Calendar_Route::categories_url( '' ), home_url( '/whats-on/categories/' ) );

evvnt_check( 'other calendar pages keep their own query-string URLs', Evvnt_Calendar_Route::calendar_url( 'http://example.test/music/', 'jazz' ), 'http://example.test/music/?_evDiscoveryPath=/jazz' );
evvnt_check( 'calendar page permalink as base => path', Evvnt_Calendar_Route::calendar_url( get_permalink( $cal_page ), '', 2 ), home_url( '/whats-on/page/2/' ) );

$clash = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'event', 'post_title' => 'Event' ) );
evvnt_check( 'existing /event/ page => events nested under the calendar page', Evvnt_Calendar_Route::event_url( '', '1-x' ), home_url( '/whats-on/event/1-x/' ) );
wp_delete_post( $clash, true );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $cal_page );
evvnt_check( 'calendar as front page => "All events" is the home URL', Evvnt_Calendar_Route::calendar_url( '' ), home_url( '/' ) );
evvnt_check( 'calendar as front page => category paths still under the page', Evvnt_Calendar_Route::calendar_url( '', 'music' ), home_url( '/whats-on/category/music/' ) );
update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', 0 );

set_query_var( Evvnt_Calendar_Router::EVENT_VAR, '3763745-crepes-company' );
$r = Evvnt_Calendar_Route::from_request();
evvnt_check( 'route from event query var', array( $r->type, $r->event_id ), array( 'event', '3763745' ) );
set_query_var( Evvnt_Calendar_Router::EVENT_VAR, '' );
set_query_var( Evvnt_Calendar_Router::CATEGORY_VAR, 'nightlife' );
set_query_var( Evvnt_Calendar_Router::PAGE_VAR, '2' );
$r = Evvnt_Calendar_Route::from_request();
evvnt_check( 'route from category + page query vars', array( $r->type, $r->category_slug, $r->page ), array( 'category', 'nightlife', 2 ) );
set_query_var( Evvnt_Calendar_Router::CATEGORY_VAR, '' );
set_query_var( Evvnt_Calendar_Router::PAGE_VAR, '' );
set_query_var( Evvnt_Calendar_Router::VIEW_VAR, 'categories' );
evvnt_check( 'route from the category-list query var', Evvnt_Calendar_Route::from_request()->type, 'categories' );
set_query_var( Evvnt_Calendar_Router::VIEW_VAR, '' );

update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js', 'calendar_page_id' => $cal_page ) );
evvnt_check( 'JS mode keeps query-string URLs', Evvnt_Calendar_Router::config(), null );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'calendar_page_id' => $cal_page ) );
update_option( 'permalink_structure', '' );
evvnt_check( 'plain permalinks keep query-string URLs', Evvnt_Calendar_Router::config(), null );

wp_delete_post( $cal_page, true );
update_option( Evvnt_Calendar_Settings::OPTION, $saved_settings );
update_option( 'permalink_structure', $saved_structure );

/* ------------------------------------------------------------------ */
echo "== Snowplow tracking\n";

update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server' ) );
$evvnt_pub   = array( 'id' => 5293, 'name' => 'Pub', 'url' => 'https://evvnt.com/events/' );
$evvnt_track = function ( array $raw ) use ( $evvnt_pub ) {
	return Evvnt_Calendar_Tracking::event_data( new Evvnt_Calendar_Event( $raw + array( 'title' => 'T', 'objectID' => 'o1' ), $evvnt_pub ) );
};
evvnt_check(
	'event ids as the discovery trackingNormalizer: evvnt => marketing, geotix => core, others by name, core events by core_metadata',
	array(
		$evvnt_track( array( 'sources' => array( 'evvnt' ), 'source_id_s' => '3827419', 'event_parent_id' => 2867780 ) ),
		$evvnt_track( array( 'sources' => array( 'geotix' ), 'source_id_s' => 'g1', 'event_parent_id' => 'g0' ) ),
		$evvnt_track( array( 'sources' => array( 'ticketmaster' ), 'source_id_s' => 'tm1', 'event_parent_id' => 'tm0' ) ),
		$evvnt_track( array( 'sources' => array( 'evvnt' ), 'source_id_s' => 'x', 'event_parent_id' => 'y', 'core_metadata' => array( 'occurrence_id' => 77, 'event_id' => 7 ) ) ),
	),
	array(
		array( 'source' => 'marketing', 'sourceOccurrenceId' => '3827419', 'sourceEventId' => '2867780' ),
		array( 'source' => 'core', 'sourceOccurrenceId' => 'g1', 'sourceEventId' => 'g0' ),
		array( 'source' => 'ticketmaster', 'sourceOccurrenceId' => 'tm1', 'sourceEventId' => 'tm0' ),
		array( 'source' => 'core', 'sourceOccurrenceId' => '77', 'sourceEventId' => '7' ),
	)
);
$evvnt_ev   = new Evvnt_Calendar_Event( array( 'title' => 'T', 'objectID' => 'o1', 'sources' => array( 'evvnt' ), 'source_id_s' => '3827419', 'event_parent_id' => 2867780, 'links' => array( 'Tickets' => 'https://go.evvnt.com/3827419-0' ), 'start_time_i' => time() + DAY_IN_SECONDS, 'end_time_i' => time() + 2 * DAY_IN_SECONDS ), $evvnt_pub );
$evvnt_attr = function ( $html, $name ) {
	return preg_match( '#' . $name . '="([^"]+)"#', $html, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) : null;
};
evvnt_check( 'view attribute carries viewedFrom and the event ids', $evvnt_attr( Evvnt_Calendar_Tracking::view_attrs( $evvnt_ev, 'featured' ), 'data-evvnt-view' ), array( 'viewedFrom' => 'featured', 'source' => 'marketing', 'sourceOccurrenceId' => '3827419', 'sourceEventId' => '2867780' ) );
evvnt_check( 'click attribute: main link without attribution, event-cta id, event ids', $evvnt_attr( Evvnt_Calendar_Tracking::click_attrs( $evvnt_ev ), 'data-evvnt-click' ), array( 'targetUrl' => 'https://go.evvnt.com/3827419-0?pid=5293', 'elementId' => 'event-cta', 'event' => array( 'source' => 'marketing', 'sourceOccurrenceId' => '3827419', 'sourceEventId' => '2867780' ) ) );

$renderer = new Evvnt_Calendar_Renderer( evvnt_client( new Evvnt_Test_Transport() ), Evvnt_Calendar_Settings::get() );
evvnt_check(
	'cards: featured => "featured", others => "event_list", recommendations untracked',
	array(
		$evvnt_attr( $renderer->render_card( $evvnt_ev, 'http://example.test/cal/', true, true ), 'data-evvnt-view' )['viewedFrom'],
		$evvnt_attr( $renderer->render_card( $evvnt_ev, 'http://example.test/cal/', true ), 'data-evvnt-view' )['viewedFrom'],
		strpos( $renderer->render_card( $evvnt_ev, 'http://example.test/cal/', true, false, '' ), 'data-evvnt-view' ),
	),
	array( 'featured', 'event_list', false )
);
$evvnt_detail = $renderer->render_detail( $evvnt_ev, 'http://example.test/cal/', array() );
evvnt_check( 'event page: one event_page view on the root, ticket buttons tracked as clicks', array( substr_count( $evvnt_detail, 'data-evvnt-view=' ), $evvnt_attr( $evvnt_detail, 'data-evvnt-view' )['viewedFrom'], substr_count( $evvnt_detail, 'data-evvnt-click=' ) > 0, substr_count( $evvnt_detail, 'data-evvnt-click=' ) === substr_count( $evvnt_detail, 'target="_blank" data-evvnt-click=' ) ), array( 1, 'event_page', true, true ) );

Evvnt_Calendar_Tracking::enqueue( $evvnt_pub );
Evvnt_Calendar_Tracking::enqueue( $evvnt_pub );
$evvnt_before = wp_scripts()->get_data( Evvnt_Calendar_Tracking::SCRIPT_HANDLE, 'before' );
$evvnt_config = Evvnt_Calendar_Tracking::config( $evvnt_pub );
evvnt_check(
	'enqueue: plugin script then Snowplow tag (v3), config added once',
	array(
		wp_script_is( Evvnt_Calendar_Tracking::SCRIPT_HANDLE, 'enqueued' ),
		wp_scripts()->registered[ Evvnt_Calendar_Tracking::SNOWPLOW_HANDLE ]->deps,
		false !== strpos( wp_scripts()->registered[ Evvnt_Calendar_Tracking::SNOWPLOW_HANDLE ]->src, 'public/js/vendor/snowplow/sp.js' ),
		is_array( $evvnt_before ) ? count( array_filter( $evvnt_before ) ) : 0, // WordPress keeps a leading false.
		file_exists( dirname( __DIR__ ) . '/public/js/vendor/snowplow/sp.js' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/public/js/vendor/snowplow/sp.js', false, null, 0, 200 ), 'v3.' ),
	),
	array( true, array( Evvnt_Calendar_Tracking::SCRIPT_HANDLE ), true, 1, true )
);
evvnt_check(
	'tracker settings and contexts as the discovery plugin',
	array( $evvnt_config['collector'], $evvnt_config['trackerName'], $evvnt_config['tracker']['appId'], $evvnt_config['tracker']['eventMethod'], $evvnt_config['tracker']['stateStorageStrategy'], $evvnt_config['environment']['schema'], $evvnt_config['environment']['data']['service'], $evvnt_config['plugin']['schema'], $evvnt_config['plugin']['data'] ),
	array( 'https://com-evvnt-prod1.collector.snplow.net', 'plugin-tracker', 'discovery-plugin', 'beacon', 'cookieAndLocalStorage', 'iglu:com.evvnt/environment/jsonschema/1-0-0', 'Discovery Plugin', 'iglu:com.evvnt/discovery_plugin/jsonschema/1-1-0', array( 'provider' => 'marketing', 'publisherId' => 5293, 'url' => 'https://evvnt.com/events/', 'orientation' => 'vertical' ) )
);
wp_dequeue_script( Evvnt_Calendar_Tracking::SCRIPT_HANDLE );
wp_dequeue_script( Evvnt_Calendar_Tracking::SNOWPLOW_HANDLE );

add_filter( 'evvnt_calendar_tracking_enabled', '__return_false' );
evvnt_check( 'tracking filtered off: no attributes', array( Evvnt_Calendar_Tracking::view_attrs( $evvnt_ev, 'event_list' ), Evvnt_Calendar_Tracking::click_attrs( $evvnt_ev ) ), array( '', '' ) );
remove_filter( 'evvnt_calendar_tracking_enabled', '__return_false' );

/* ------------------------------------------------------------------ */
update_option( Evvnt_Calendar_Settings::OPTION, $evvnt_original_settings );
if ( false !== $evvnt_original_tree ) {
	update_option( Evvnt_Calendar_Category_Tree::OPTION, $evvnt_original_tree, false );
} else {
	delete_option( Evvnt_Calendar_Category_Tree::OPTION );
}
wp_clear_scheduled_hook( Evvnt_Calendar_Category_Tree::CRON_HOOK );
Evvnt_Calendar_Cache::flush();
echo "== RESULT: {$GLOBALS['evvnt_pass']} passed, {$GLOBALS['evvnt_fail']} failed\n";
exit( $GLOBALS['evvnt_fail'] ? 1 : 0 );
