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

foreach ( array( '/a/b/c', '/event/../../x', '/<script>', '/event/', '/Nightlife', '/event/a b', '/event' ) as $path ) {
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
$transport->overrides['#searchTerm=#'] = evvnt_fixture( 'events_category17' );
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$html      = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'searchTerm' => 'music' ) ), array( 'layout' => 'rows' ) + $atts, $base );
evvnt_check( 'search queries /events with searchTerm (page 0, 30 per page)', in_array( 'https://discovery.evvnt.com/api/events?hitsPerPage=30&multipleEventInstances=true&page=0&publisher_id=5293&searchTerm=music', $transport->urls, true ), true );
evvnt_check( 'search results use the list even in rows mode', array( substr_count( $html, 'evvnt-cal--rows' ), substr_count( $html, 'class="evvnt-cal__card"' ) ), array( 0, 30 ) );
evvnt_check( 'results heading', false !== strpos( $html, 'Results for “music”' ), true );
evvnt_check( 'no featured row on results', substr_count( $html, 'evvnt-cal__card--featured' ), 0 );
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
$renderer  = new Evvnt_Calendar_Renderer( evvnt_client( $transport ), Evvnt_Calendar_Settings::get() );
$viewed    = new Evvnt_Calendar_Event( evvnt_fixture( 'events_page1' )['events'][0], $publisher );
$recs      = $renderer->recommended( $viewed );
evvnt_check( 'recommendations query matches the discovery JS', in_array( 'https://discovery.evvnt.com/api/events?categoryId=' . $viewed->main_category_id() . '&hitsPerPage=50&publisher_id=5293', $transport->urls, true ), true );
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

update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'js', 'calendar_page_id' => $cal_page ) );
evvnt_check( 'JS mode keeps query-string URLs', Evvnt_Calendar_Router::config(), null );
update_option( Evvnt_Calendar_Settings::OPTION, array( 'publisher_id' => '5293', 'render_mode' => 'server', 'calendar_page_id' => $cal_page ) );
update_option( 'permalink_structure', '' );
evvnt_check( 'plain permalinks keep query-string URLs', Evvnt_Calendar_Router::config(), null );

wp_delete_post( $cal_page, true );
update_option( Evvnt_Calendar_Settings::OPTION, $saved_settings );
update_option( 'permalink_structure', $saved_structure );

/* ------------------------------------------------------------------ */
update_option( Evvnt_Calendar_Settings::OPTION, $evvnt_original_settings );
Evvnt_Calendar_Cache::flush();
echo "== RESULT: {$GLOBALS['evvnt_pass']} passed, {$GLOBALS['evvnt_fail']} failed\n";
exit( $GLOBALS['evvnt_fail'] ? 1 : 0 );
