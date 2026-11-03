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
$atts      = array( 'detail_page_enabled' => true, 'virtual' => false, 'category_id' => 0 );

$cats = wp_list_pluck( $renderer->categories(), 'id' );
evvnt_check( 'navigation puts precedence categories first', array_slice( $cats, 0, 2 ), array( 16, 17 ) );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base );
evvnt_check( 'page 1 lists 30 events', substr_count( $html, 'class="evvnt-cal__card"' ), 30 );
evvnt_check( 'page 1 shows featured events', substr_count( $html, 'evvnt-cal__card--featured' ) > 0, true );
evvnt_check( 'full page => next link', false !== strpos( $html, 'rel="next" href="http://example.test/?page_id=4&#038;evPage=2"' ), true );
evvnt_check( 'page 1 has no previous link', false === strpos( $html, 'rel="prev"' ), true );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife', 'evPage' => '2' ) ), $atts, $base );
evvnt_check( 'category page 2 requests page=1 with categoryId=17', (bool) preg_match( '#/events\?categoryId=17&.*page=1&#', end( $transport->urls ) ), true );
evvnt_check( 'category page 2 has prev link back to page 1', false !== strpos( $html, 'rel="prev" href="http://example.test/?page_id=4&#038;_evDiscoveryPath=/nightlife"' ), true );

$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( '_evDiscoveryPath' => '/nightlife' ) ), array( 'category_id' => 28 ) + $atts, $base );
evvnt_check( 'pinned category scopes the request', (bool) preg_match( '#categoryId=28&#', end( $transport->urls ) ) && false === strpos( end( $transport->urls ), 'subCategoryIds' ), true );
evvnt_check( 'pinned category hides the root navigation', false === strpos( $html, 'evvnt-cal__nav' ), true );
evvnt_check( 'category names shown as in the JS', in_array( 'Food & Drink', wp_list_pluck( $renderer->categories(), 'name' ), true ), true );
evvnt_check( 'listing reports outages as WP_Error', is_wp_error( ( new Evvnt_Calendar_Renderer( evvnt_client( ( function () { $t = new Evvnt_Test_Transport(); $t->overrides['#home_page#'] = new WP_Error( 'x', 'down', array( 'status' => 500 ) ); return $t; } )() ), Evvnt_Calendar_Settings::get() ) )->listing( Evvnt_Calendar_Route::from_query( array() ), $atts ) ), true );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );

$transport->overrides['#/events\?#'] = array( 'events' => array_slice( evvnt_fixture( 'events_page1' )['events'], 0, 5 ) );
$html = $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array( 'evPage' => '4' ) ), $atts, $base );
evvnt_check( 'short page => no next link', false === strpos( $html, 'rel="next"' ), true );

$down     = new Evvnt_Test_Transport();
$down->overrides['#.#'] = new WP_Error( 'x', 'down', array( 'status' => 502 ) );
$renderer = new Evvnt_Calendar_Renderer( evvnt_client( $down ), Evvnt_Calendar_Settings::get() );
evvnt_check( 'API down => unavailable message', false !== strpos( $renderer->render_calendar( Evvnt_Calendar_Route::from_query( array() ), $atts, $base ), 'evvnt-cal--unavailable' ), true );
delete_transient( Evvnt_Calendar_Cache::BACKOFF_KEY );

/* ------------------------------------------------------------------ */
echo "== Shortcode detection\n";

$fake_post = function ( $content ) {
	return new WP_Post( (object) array( 'ID' => 0, 'post_content' => $content, 'post_status' => 'publish' ) );
};
evvnt_check( 'plain [evvnt]', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( 'x [evvnt] y' ) ), array( 'detail_page_enabled' => true, 'virtual' => false, 'category_id' => 0 ) );
evvnt_check( 'first calendar shortcode wins over widget', Evvnt_Calendar_Public::page_calendar_atts( $fake_post( '[evvnt config_type="widget"] [evvnt category_id="17" virtual="1"]' ) ), array( 'detail_page_enabled' => true, 'virtual' => true, 'category_id' => 17 ) );
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
