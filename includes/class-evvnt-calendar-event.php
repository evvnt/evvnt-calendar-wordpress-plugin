<?php

/**
 * An event from the discovery API, with the presentation logic the discovery JS uses
 * (URL ids and slugs, dates, prices, images, links, location summary, schema.org data).
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Event {

	const TM_AFFILIATE_ID = '374335';

	/** Source => array( url-id suffix, whether source_id is numeric ). Mirrors EventIds.js. */
	const SOURCES = array(
		'evvnt'          => array( '', true ),
		'migration'      => array( 'm', true ),
		'geotix'         => array( 'x', true ),
		'enmotive'       => array( 'e', false ),
		'goldstar'       => array( 'g', true ),
		'ticketmaster'   => array( 't', false ),
		'bandsintown'    => array( 'n', true ),
		'eventbrite'     => array( 'b', true ),
		'axs'            => array( 's', true ),
		'active_network' => array( 'a', false ),
		'run_sign_up'    => array( 'r', true ),
		'ticket_sign_up' => array( 'u', true ),
		'event_vesta'    => array( 'v', true ),
		'ticket_fairy'   => array( 'f', true ),
		'race_entry'     => array( 'c', true ),
		'meetup'         => array( 'p', true ),
	);

	/** Sources whose end times are unreliable. */
	const NO_END_TIME_SOURCES = array( 'ticketmaster', 'goldstar', 'axs' );

	private $data;
	private $publisher;
	private $extra;
	private $recurrence;

	/**
	 * @param array $data      Raw event (an element of rawEvents/events, or rawEvent).
	 * @param array $publisher The "publisher" object from publisher_settings.
	 * @param array $extra     Other keys from the detail response (editorialContent, ...).
	 */
	public function __construct( array $data, array $publisher = array(), array $extra = array() ) {
		$this->data      = $data;
		$this->publisher = $publisher;
		$this->extra     = $extra;
	}

	/* ---------- identity and URLs ---------- */

	public function object_id() {
		return (string) $this->get( 'objectID', '' );
	}

	/**
	 * Root category of the event (first entry of main_category_path), or 0.
	 */
	public function main_category_id() {
		$path = $this->get( 'main_category_path', array() );
		return ( is_array( $path ) && ! empty( $path[0] ) ) ? absint( $path[0] ) : 0;
	}

	/**
	 * Selection weight for recommendations, as FeaturedEventChooser in the discovery JS:
	 * events promoted for a shorter window are picked more often, so each gets a similar
	 * share of impressions. Promotion is capped at 365 days and floored at one day.
	 */
	public function promotion_weight() {
		$year     = 365 * DAY_IN_SECONDS;
		$promoted = min( (int) $this->get( 'end_time_i', 0 ) - (int) $this->get( 'created_time_i', 0 ), $year );
		$promoted = max( $promoted, DAY_IN_SECONDS );
		return (int) floor( ( $year * 3 ) / $promoted );
	}

	public function main_source() {
		$sources = $this->get( 'sources' );
		return ( is_array( $sources ) && isset( $sources[0] ) ) ? (string) $sources[0] : 'evvnt';
	}

	/**
	 * The id used in URLs and by GET /events/{id}: source id (or objectID) plus source suffix.
	 */
	public function url_id() {
		$source = isset( self::SOURCES[ $this->main_source() ] ) ? self::SOURCES[ $this->main_source() ] : array( '', true );
		$base   = $source[1] ? (string) $this->get( 'source_id_s', $this->get( 'source_id', '' ) ) : (string) $this->get( 'objectID', '' );
		return $base . $source[0];
	}

	/**
	 * "{urlId}-{title-slug}", matching the event URLs Evvnt publishes (source_broadcast_url):
	 * accents transliterated, runs of other characters collapsed to "-", no leading or
	 * trailing "-". Links made by the discovery JS (which keeps stray dashes and drops
	 * accented letters) still resolve and are redirected here.
	 */
	public function slug( $url_id = null ) {
		$url_id = null === $url_id ? $this->url_id() : (string) $url_id;
		$title  = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $this->title(), 'en_US' ) ) ), '-' );
		return '' === $title ? $url_id : $url_id . '-' . $title;
	}

	/* ---------- text ---------- */

	public function title() {
		return (string) $this->get( 'title', '' );
	}

	public function summary() {
		return trim( (string) $this->get( 'summary', '' ) );
	}

	public function description() {
		return trim( (string) $this->get( 'description', '' ) );
	}

	/**
	 * Plain description with a leading copy of the summary removed (fullDescriptionWithoutSummary).
	 */
	public function description_without_summary() {
		$text    = $this->description();
		$summary = $this->summary();
		if ( '' !== $summary && 0 === strpos( $text, $summary ) ) {
			return trim( substr( $text, strlen( $summary ) ) );
		}
		return $text;
	}

	/**
	 * Google static map for the venue, from the detail response (signedStaticMapUrl), or ''.
	 */
	public function static_map_url() {
		$url = isset( $this->extra['signedStaticMapUrl'] ) && is_string( $this->extra['signedStaticMapUrl'] ) ? $this->extra['signedStaticMapUrl'] : '';
		if ( ! preg_match( '#^https://maps\.googleapis\.com/#', $url ) ) {
			return '';
		}
		return $url; // Used exactly as given: the signature covers the whole URL, padding included.
	}

	public function description_html() {
		return (string) $this->get( 'description_html', '' );
	}

	/**
	 * Whether the summary says something the description doesn't. Evvnt summaries are often
	 * just the start of the description, truncated with "...", which would repeat it.
	 */
	public function summary_adds_information() {
		$normalize = function ( $text ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
			$text = preg_replace( '/(\.{3}|…)\s*$/u', '', trim( $text ) );
			return strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', '', $text ) );
		};
		$summary = $normalize( $this->summary() );
		if ( '' === $summary ) {
			return false;
		}
		$body = $normalize( '' !== trim( wp_strip_all_tags( $this->description_html() ) ) ? $this->description_html() : $this->description() );
		return '' === $body || 0 !== strpos( $body, $summary );
	}

	/**
	 * Plain-text description for meta tags and schema, at most $length characters.
	 */
	public function short_description( $length = 300 ) {
		$text = $this->summary() !== '' ? $this->summary() : $this->description();
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
		}
		return $text;
	}

	public function organiser_name() {
		return trim( (string) $this->get( 'organiser_name', '' ) );
	}

	public function artists() {
		$artists = trim( (string) $this->get( 'artists', '' ) );
		return '' === $artists ? array() : preg_split( '/,\s+/', $artists );
	}

	/**
	 * Categories as array( 'id' => int, 'name' => string ), from category_names_with_ids.
	 */
	public function categories() {
		$list = array();
		foreach ( (array) $this->get( 'category_names_with_ids', array() ) as $pair ) {
			$parts = explode( '|', (string) $pair, 2 );
			if ( isset( $parts[1] ) && ctype_digit( $parts[0] ) && '' !== $parts[1] ) {
				$list[ (int) $parts[0] ] = array(
					'id'   => (int) $parts[0],
					'name' => $parts[1],
				);
			}
		}
		return array_values( $list );
	}

	public function category_names() {
		$names = array();
		foreach ( (array) $this->get( 'category_names_with_ids', array() ) as $pair ) {
			$parts = explode( '|', (string) $pair, 2 );
			if ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$names[ $parts[1] ] = true;
			}
		}
		if ( ! $names && $this->get( 'category_name' ) ) {
			$names[ (string) $this->get( 'category_name' ) ] = true;
		}
		return array_keys( $names );
	}

	/* ---------- dates ---------- */

	public function start() {
		return $this->parse_time( $this->get( 'start_time' ) );
	}

	public function end() {
		return $this->parse_time( $this->get( 'end_time' ) );
	}

	public function has_end_time() {
		return ! array_intersect( self::NO_END_TIME_SOURCES, (array) $this->get( 'sources', array() ) ) && null !== $this->end();
	}

	/**
	 * Compact date without a time, for tight spaces: "Thu, Nov 5, 2026", or a range for
	 * events over several days ("Nov 5 – Dec 24, 2026").
	 */
	public function short_date_label() {
		$start = $this->start();
		if ( ! $start ) {
			return '';
		}
		return $this->is_multi_day() ? $this->date_label() : $this->format( $start, 'D, M j, Y' );
	}

	public function is_multi_day() {
		return $this->has_end_time() && ( (int) $this->get( 'end_time_i' ) - (int) $this->get( 'start_time_i' ) ) > DAY_IN_SECONDS;
	}

	/**
	 * Whether the event is over, as the discovery JS decides.
	 *
	 * @param int|null $now Unix time (defaults to now).
	 */
	public function is_expired( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( $this->has_end_time() && $this->get( 'end_time_i' ) ) {
			return (int) $this->get( 'end_time_i' ) < $now;
		}
		if ( $this->get( 'start_time_i' ) ) {
			return (int) $this->get( 'start_time_i' ) < $now;
		}
		return false;
	}

	/**
	 * e.g. "Nov 3, 2026 at 5:00 PM" or "Jul 25 - Nov 28, 2026", in the event's own time zone.
	 */
	public function display_date() {
		$start = $this->start();
		if ( ! $start ) {
			return '';
		}
		if ( ! $this->is_multi_day() ) {
			/* translators: 1: date, 2: time. */
			return sprintf( __( '%1$s at %2$s', 'evvnt-calendar' ), $this->format( $start, 'M j, Y' ), $this->format( $start, 'g:i A' ) );
		}
		$end         = $this->end();
		$start_label = $start->format( 'Y' ) !== $end->format( 'Y' ) ? $this->format( $start, 'M j, Y' ) : $this->format( $start, 'M j' );
		return $start_label . ' - ' . $this->format( $end, 'M j, Y' );
	}

	/**
	 * Long date for the detail hero: "Saturday, November 28, 2026", or "Jul 25 – Nov 28, 2026"
	 * for multi-day events.
	 */
	public function date_label() {
		$start = $this->start();
		if ( ! $start ) {
			return '';
		}
		if ( ! $this->is_multi_day() ) {
			return $this->format( $start, 'l, F j, Y' );
		}
		$end = $this->end();
		return ( $start->format( 'Y' ) !== $end->format( 'Y' ) ? $this->format( $start, 'M j, Y' ) : $this->format( $start, 'M j' ) )
			. ' – ' . $this->format( $end, 'M j, Y' );
	}

	/**
	 * Time range for the detail hero: "5:00 PM – 8:00 PM EST" (zone shown when it has a name),
	 * "Starts 4:30 PM" for multi-day events, or '' when unknown.
	 */
	public function time_label() {
		$start = $this->start();
		if ( ! $start ) {
			return '';
		}
		$zone = $this->format( $start, 'T' );
		$zone = preg_match( '/^[A-Z]{2,5}$/', $zone ) ? ' ' . $zone : '';
		if ( $this->is_multi_day() ) {
			/* translators: %s: start time. */
			return sprintf( __( 'Starts %s', 'evvnt-calendar' ), $this->format( $start, 'g:i A' ) . $zone );
		}
		$end = $this->has_end_time() ? $this->end() : null;
		if ( $end && $end->getTimestamp() > $start->getTimestamp() ) {
			return $this->format( $start, 'g:i A' ) . ' – ' . $this->format( $end, 'g:i A' ) . $zone;
		}
		return $this->format( $start, 'g:i A' ) . $zone;
	}

	/* ---------- recurring series ---------- */

	/**
	 * The series this event is one date of (discovery's Event.recurrence).
	 *
	 * @param int|null $now Unix time (tests).
	 * @return Evvnt_Calendar_Recurrence
	 */
	public function recurrence( $now = null ) {
		if ( null !== $now ) {
			return new Evvnt_Calendar_Recurrence( $this->raw_recurrence(), $this->get( 'indexed_occurrences' ), $now );
		}
		if ( ! isset( $this->recurrence ) ) {
			$this->recurrence = new Evvnt_Calendar_Recurrence( $this->raw_recurrence(), $this->get( 'indexed_occurrences' ) );
		}
		return $this->recurrence;
	}

	/**
	 * The series' { schedules, dates }: top-level on an event page (GET /events/{urlId}), under
	 * core_metadata in listings (which have no indexed_occurrences, so no links between dates).
	 */
	private function raw_recurrence() {
		$recurrence = $this->get( 'recurrence' );
		if ( is_array( $recurrence ) ) {
			return $recurrence;
		}
		$meta = $this->get( 'core_metadata' );
		return is_array( $meta ) && isset( $meta['recurrence'] ) && is_array( $meta['recurrence'] ) ? $meta['recurrence'] : null;
	}

	/** The event is one of several published dates. */
	public function is_recurring() {
		return count( $this->recurrence()->dates() ) > 1;
	}

	/** Show the series (badge, "Repeats …" line, Upcoming dates): recurring with dates to come. */
	public function shows_recurrence() {
		return $this->is_recurring() && (bool) $this->recurrence()->upcoming_dates();
	}

	/** @return array|null The series date this event page is (the date its URL id was indexed as). */
	public function viewed_date() {
		return $this->recurrence()->find_by_occurrence_id( $this->url_id() );
	}

	/** @return array|null The date to headline: this page's date while still to come, else the next. */
	public function highlighted_date() {
		$viewed = $this->viewed_date();
		foreach ( $this->recurrence()->upcoming_dates() as $date ) {
			if ( Evvnt_Calendar_Recurrence::same( $date, $viewed ) ) {
				return $date;
			}
		}
		return $this->recurrence()->next_date();
	}

	/**
	 * Headline date of a series: "Next: Sun, Dec 13, 2026 at 3:00 PM" when it is the next date,
	 * else (this page's own later date) without "Next: ". Empty when not a series.
	 */
	public function series_date_label() {
		$date = $this->shows_recurrence() ? $this->highlighted_date() : null;
		if ( ! $date ) {
			return '';
		}
		/* translators: 1: date, e.g. "Sun, Dec 13, 2026", 2: time. */
		$label = sprintf( __( '%1$s at %2$s', 'evvnt-calendar' ), $this->format( $date['start'], 'D, M j, Y' ), $this->format( $date['start'], 'g:i A' ) );
		/* translators: %s: date and time of the next occurrence. */
		return Evvnt_Calendar_Recurrence::same( $date, $this->recurrence()->next_date() ) ? sprintf( __( 'Next: %s', 'evvnt-calendar' ), $label ) : $label;
	}

	/**
	 * Upcoming dates of a recurring event ({ start, end } DateTimeImmutable pairs), at most $limit.
	 * Kept for theme templates written against earlier versions; see recurrence().
	 */
	public function upcoming_dates( $limit = 10, $now = null ) {
		$dates = array();
		foreach ( $this->recurrence( $now )->upcoming_dates() as $date ) {
			$dates[] = array(
				'start' => $date['start'],
				'end'   => $date['end'],
			);
		}
		return array_slice( $dates, 0, $limit );
	}

	/**
	 * Format a date with WordPress's localized month names, keeping the event's time zone.
	 */
	public function format( DateTimeImmutable $date, $format ) {
		return wp_date( $format, $date->getTimestamp(), $date->getTimezone() );
	}

	/* ---------- place ---------- */

	public function is_virtual() {
		return (bool) $this->get( 'online_only', false );
	}

	public function venue( $field ) {
		$venue = $this->get( 'venue' );
		return ( is_array( $venue ) && isset( $venue[ $field ] ) && is_scalar( $venue[ $field ] ) ) ? trim( (string) $venue[ $field ] ) : '';
	}

	public function venue_name() {
		return $this->is_virtual() ? __( 'Virtual', 'evvnt-calendar' ) : $this->venue( 'name' );
	}

	public function address_lines() {
		return array_values( array_filter( array( $this->venue( 'address_1' ), $this->venue( 'address_2' ), $this->venue( 'town' ), $this->venue( 'post_code' ) ) ) );
	}

	public function country_iso() {
		$country = $this->get( 'country' );
		return ( is_array( $country ) && ! empty( $country['iso_code'] ) ) ? (string) $country['iso_code'] : '';
	}

	/**
	 * Short location for cards, following the publisher's location_type.
	 */
	public function location_summary() {
		if ( $this->is_virtual() ) {
			return __( 'Virtual', 'evvnt-calendar' );
		}
		$type = isset( $this->publisher['location_type'] ) ? $this->publisher['location_type'] : '';
		if ( 'Local' === $type ) {
			$parts = array( $this->venue( 'name' ), $this->venue( 'town' ) );
		} elseif ( 'National' === $type ) {
			$parts = array( $this->venue( 'town' ) );
		} else {
			$parts = array( $this->venue( 'town' ), $this->venue( 'country' ) );
		}
		return implode( ', ', array_filter( $parts ) );
	}

	public function map_url() {
		$parts = array_filter(
			array(
				$this->venue( 'post_code' ),
				$this->venue( 'country' ),
				$this->venue( 'town' ),
				$this->venue( 'address_1' ),
				$this->venue_name(),
			)
		);
		$lat = $this->venue( 'latitude' );
		$lng = $this->venue( 'longitude' );
		if ( '' !== $lat && '' !== $lng ) {
			$parts[] = $lat . ',' . $lng;
		}
		return $parts ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $parts ) ) : '';
	}

	/* ---------- prices ---------- */

	/**
	 * e.g. "£23.00", "Free", "$13.00 - $15.00", or '' when unknown. Mirrors ticketPrices.js.
	 */
	public function price_range() {
		$prices = array();
		foreach ( (array) $this->get( 'prices', array() ) as $value ) {
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$price = trim( (string) $value );
			if ( ! preg_match( '/^\p{Sc}/u', $price ) ) {
				$symbol = self::currency_symbol( $price );
				$amount = self::price_amount( $price );
				if ( 1 === self::strlen( $symbol ) && null !== $amount ) {
					$price = $symbol . number_format( $amount, 2, '.', '' );
				}
			}
			$prices[] = $price;
		}
		if ( ! $prices ) {
			return '';
		}
		if ( 1 === count( $prices ) ) {
			return 0.0 === self::numeric( $prices[0] ) ? __( 'Free', 'evvnt-calendar' ) : $prices[0];
		}
		usort(
			$prices,
			function ( $a, $b ) {
				$x = self::numeric( $a );
				$y = self::numeric( $b );
				return ( null === $x || null === $y ) ? 0 : ( $x <=> $y );
			}
		);
		$low  = $prices[0];
		$high = $prices[ count( $prices ) - 1 ];
		return $low === $high ? $low : $low . ' - ' . $high;
	}

	/**
	 * Lowest price as array( amount, currency ) for schema.org, or null.
	 */
	public function lowest_offer() {
		$best = null;
		foreach ( (array) $this->get( 'prices', array() ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$amount   = self::price_amount( (string) $value );
			$currency = strtoupper( substr( trim( (string) $value ), 0, 3 ) );
			if ( null === $amount || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				continue;
			}
			if ( null === $best || $amount < $best[0] ) {
				$best = array( $amount, $currency );
			}
		}
		return $best;
	}

	private static function currency_symbol( $price ) {
		$code = substr( $price, 0, 3 );
		switch ( $code ) {
			case 'GBP':
				return '£';
			case 'EUR':
				return '€';
			case 'USD':
			case 'AUD':
			case 'CAD':
				return '$';
			default:
				return $code;
		}
	}

	private static function price_amount( $price ) {
		$parts = explode( ' ', trim( $price ) );
		if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
			return (float) $parts[1];
		}
		return is_numeric( $price ) ? (float) $price : null;
	}

	private static function numeric( $price ) {
		return preg_match( '/[0-9]+(\.[0-9]+)?/', str_replace( ',', '', $price ), $m ) ? (float) $m[0] : null;
	}

	private static function strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/* ---------- images ---------- */

	/**
	 * Card image: a roughly 16:9 image's "featured" rendition. Mirrors imageChooser.js.
	 */
	public function card_image_url() {
		$image = $this->best_landscape_image( false );
		return $image ? $this->first_url( $image, array( 'featured_webp', 'featured', 'list_thumb', 'original' ) ) : '';
	}

	/**
	 * Large image for the detail page and og:image.
	 */
	public function hero_image_url() {
		$image = $this->best_landscape_image( true );
		return $image ? $this->first_url( $image, array( 'hero_webp', 'hero', 'original' ) ) : '';
	}

	public function image_urls() {
		$urls = array();
		foreach ( $this->images() as $image ) {
			$url = $this->first_url( $image, array( 'original' ) );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	private function images() {
		$images = $this->get( 'images', array() );
		if ( ! is_array( $images ) ) {
			return array();
		}
		if ( isset( $images['original'] ) ) {
			$images = array( $images );
		}
		return array_values(
			array_filter(
				$images,
				function ( $image ) {
					if ( ! is_array( $image ) || empty( $image['original']['url'] ) || ! is_string( $image['original']['url'] ) ) {
						return false;
					}
					// Insecure images are dropped before choosing, as imageChooser.js does on https sites.
					foreach ( array( 'original', 'thumb' ) as $key ) {
						if ( ! empty( $image[ $key ]['url'] ) && is_string( $image[ $key ]['url'] ) && preg_match( '#^http:#i', $image[ $key ]['url'] ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	private function best_landscape_image( $big ) {
		$images = $this->images();
		foreach ( $images as $image ) {
			$width  = isset( $image['original']['width'] ) ? (float) $image['original']['width'] : 0;
			$height = isset( $image['original']['height'] ) ? (float) $image['original']['height'] : 0;
			if ( $width && $height ) {
				$ratio = $width / $height;
				if ( $ratio > 1.2 && $ratio < 2.5 && ( ! $big || $width > 300 ) ) {
					return $image;
				}
			}
		}
		return $images ? $images[0] : null;
	}

	private function first_url( array $image, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! empty( $image[ $key ]['url'] ) && is_string( $image[ $key ]['url'] ) && preg_match( '#^https://#i', $image[ $key ]['url'] ) ) {
				return $image[ $key ]['url'];
			}
		}
		return '';
	}

	/* ---------- links ---------- */

	/**
	 * Outbound links with ?pid= added for evvnt-sourced events, as the discovery JS does.
	 */
	public function links() {
		$links = array();
		foreach ( (array) $this->get( 'links', array() ) as $label => $url ) {
			if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			if ( 'evvnt' === $this->main_source() && ! empty( $this->publisher['id'] ) && false === strpos( $url, 'pid=' ) ) {
				$url = add_query_arg( 'pid', (int) $this->publisher['id'], $url );
			}
			$links[ (string) $label ] = $url;
		}
		return $links;
	}

	public function tickets_link() {
		$links = $this->links();
		if ( ! empty( $links['Tickets'] ) ) {
			return $links['Tickets'];
		}
		return ! empty( $links['Booking'] ) ? $links['Booking'] : '';
	}

	/**
	 * The primary outbound link (tickets, affiliate-wrapped for Ticketmaster).
	 */
	public function main_link() {
		$links   = $this->links();
		$tickets = $this->tickets_link();
		$pub_id  = isset( $this->publisher['id'] ) ? (int) $this->publisher['id'] : 0;

		if ( 'ticketmaster' === $this->main_source() && $tickets ) {
			$suffix = '&subid1=' . $pub_id . '&sharedid=' . rawurlencode( isset( $this->publisher['name'] ) ? (string) $this->publisher['name'] : '' );
			if ( false !== strpos( $tickets, self::TM_AFFILIATE_ID ) ) {
				return $tickets . $suffix;
			}
			return 'https://ticketmaster.evyy.net/c/' . self::TM_AFFILIATE_ID . '/264167/4272?u=' . rawurlencode( $tickets ) . $suffix;
		}
		if ( 'bandsintown' === $this->main_source() && ! empty( $links['Website'] ) ) {
			return $links['Website'];
		}
		if ( 'meetup' === $this->main_source() && $tickets ) {
			return add_query_arg( 'utm_campaign', $pub_id, $tickets );
		}
		if ( $tickets ) {
			return $tickets;
		}
		return $links ? reset( $links ) : '';
	}

	/**
	 * Ticket button for the standard layout, as EventDetailButton in the discovery JS:
	 * label and (for third-party sources) the source's brand colour.
	 *
	 * @return array( 'label' => string, 'color' => string|null )
	 */
	public function cta() {
		$brands = array(
			'ticketmaster'   => '#009cde',
			'eventbrite'     => '#ff7300',
			'ticketweb'      => '#7e4299',
			'universe'       => '#3A66E5',
			'active_network' => '#e8a21b',
			'axs'            => '#0054A1',
			'enmotive'       => '#ff6c0e',
			'bandsintown'    => '#00B4B5',
			'goldstar'       => '#FEC214',
			'run_sign_up'    => '#ef509c',
			'ticket_sign_up' => '#fbad1d',
			'event_vesta'    => '#A91CB4',
			'ticket_fairy'   => '#ffac4e',
			'race_entry'     => '#0385d4',
			'meetup'         => '#F65858',
		);
		$sub   = (string) $this->get( 'sub_brand', '' );
		$brand = in_array( $sub, array( 'ticketweb', 'universe' ), true ) ? $sub : $this->main_source();
		if ( in_array( $brand, array( 'evvnt', 'geotix' ), true ) ) {
			return array(
				'label' => $this->tickets_link() ? __( 'Get Tickets', 'evvnt-calendar' ) : __( 'More Details', 'evvnt-calendar' ),
				'color' => null,
			);
		}
		return array(
			'label' => 'active_network' === $brand ? __( 'Register', 'evvnt-calendar' ) : __( 'Get tickets', 'evvnt-calendar' ),
			'color' => isset( $brands[ $brand ] ) ? $brands[ $brand ] : null,
		);
	}

	/**
	 * main_link() with the attribution the discovery JS adds to evvnt-sourced events
	 * (utm_source = this site's domain, utm_medium = referral, publisher_id).
	 */
	public function cta_url() {
		$url = $this->main_link();
		if ( '' === $url || ! in_array( $this->main_source(), array( 'evvnt', 'geotix' ), true ) ) {
			return $url;
		}
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return add_query_arg(
			array(
				'utm_source'   => rawurlencode( $host ),
				'utm_medium'   => 'referral',
				'publisher_id' => isset( $this->publisher['id'] ) ? (int) $this->publisher['id'] : null,
			),
			$url
		);
	}

	public function is_affiliate_link() {
		return 'ticketmaster' === $this->main_source() && '' !== $this->tickets_link();
	}

	public function is_featured() {
		return ! empty( $this->publisher['id'] ) && in_array( (int) $this->publisher['id'], array_map( 'intval', (array) $this->get( 'featured_publisher_ids', array() ) ), true );
	}

	/* ---------- schema.org ---------- */

	/**
	 * schema.org Event for JSON-LD.
	 *
	 * @param string $url Canonical URL of the event page.
	 * @return array
	 */
	public function to_schema( $url ) {
		$start = $this->start();
		$end   = $this->has_end_time() ? $this->end() : null;

		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $this->title(),
			'url'                 => $url,
			'description'         => $this->short_description( 500 ),
			'startDate'           => $start ? $start->format( DATE_ATOM ) : null,
			'endDate'             => $end ? $end->format( DATE_ATOM ) : null,
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => $this->is_virtual() ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
			'image'               => $this->image_urls() ? $this->image_urls() : null,
		);

		if ( $this->is_virtual() ) {
			$schema['location'] = array(
				'@type' => 'VirtualLocation',
				'url'   => $this->main_link() ? $this->main_link() : $url,
			);
		} else {
			$schema['location'] = array_filter(
				array(
					'@type'   => 'Place',
					'name'    => $this->venue( 'name' ) ? $this->venue( 'name' ) : null,
					'address' => array_filter(
						array(
							'@type'           => 'PostalAddress',
							'streetAddress'   => trim( $this->venue( 'address_1' ) . ' ' . $this->venue( 'address_2' ) ),
							'addressLocality' => $this->venue( 'town' ),
							'postalCode'      => $this->venue( 'post_code' ),
							'addressCountry'  => $this->country_iso() ? $this->country_iso() : $this->venue( 'country' ),
						)
					),
				)
			);
		}

		$offer = $this->lowest_offer();
		if ( $offer ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'url'           => $this->main_link() ? $this->main_link() : $url,
				'price'         => number_format( $offer[0], 2, '.', '' ),
				'priceCurrency' => $offer[1],
				'availability'  => 'https://schema.org/InStock',
			);
		}

		if ( $this->organiser_name() ) {
			$schema['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $this->organiser_name(),
			);
		}

		$artists = $this->artists();
		if ( $artists ) {
			$schema['performer'] = array_map(
				function ( $name ) {
					return array(
						'@type' => 'PerformingGroup',
						'name'  => $name,
					);
				},
				$artists
			);
		}

		return array_filter(
			$schema,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	/* ---------- helpers ---------- */

	public function raw() {
		return $this->data;
	}

	private function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) && null !== $this->data[ $key ] ? $this->data[ $key ] : $default;
	}

	private function zone( $name ) {
		if ( is_string( $name ) && '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	private function parse_time( $value, $zone = null ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			// A time without an offset is wall-clock time in $zone (as Luxon's fromISO with a zone).
			$date = $zone ? new DateTimeImmutable( $value, $zone ) : new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return null;
		}
		return $zone ? $date->setTimezone( $zone ) : $date;
	}
}
