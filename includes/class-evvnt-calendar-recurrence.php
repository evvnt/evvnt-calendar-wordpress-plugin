<?php
/**
 * The dates of a recurring event series, as the discovery plugin's Recurrence model
 * (src/models/Recurrence.js and src/utils/scheduleLabels.js).
 *
 * Built from the event's `recurrence` ({ schedules, dates }) and `indexed_occurrences`
 * ([{ id, startTime, rejected }]) returned by GET /events/{urlId}. Each date carries the
 * id of the occurrence indexed at the same instant (its own event page), or null when it
 * was never indexed or was rejected.
 *
 * @package Evvnt_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Recurrence {

	/** Luxon's "longterm" month: 365.2425 / 12 days. */
	const DAYS_PER_MONTH = 30.436875;

	/**
	 * Schedules the series follows ({ id, frequency, week_days, start_date, monthly_occurrence }).
	 *
	 * @var array[]
	 */
	private $schedules = array();

	/**
	 * Every date, in order: { id, start, end, occurrence_id }.
	 *
	 * @var array[]
	 */
	private $dates = array();

	/**
	 * Dates that have not ended yet.
	 *
	 * @var array[]
	 */
	private $upcoming = array();

	/**
	 * @param mixed    $recurrence          The event's `recurrence`.
	 * @param mixed    $indexed_occurrences The event's `indexed_occurrences`.
	 * @param int|null $now                 Unix time (tests).
	 */
	public function __construct( $recurrence, $indexed_occurrences, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( is_array( $recurrence ) && isset( $recurrence['schedules'] ) && is_array( $recurrence['schedules'] ) ) {
			$this->schedules = array_values( array_filter( $recurrence['schedules'], 'is_array' ) );
		}
		$dates = is_array( $recurrence ) && isset( $recurrence['dates'] ) && is_array( $recurrence['dates'] ) ? $recurrence['dates'] : array();

		$this->dates = self::attach_occurrence_ids( self::parse_dates( $dates ), is_array( $indexed_occurrences ) ? $indexed_occurrences : array() );
		foreach ( $this->dates as $date ) {
			if ( $date['end']->getTimestamp() >= $now ) {
				$this->upcoming[] = $date;
			}
		}
	}

	/**
	 * Dates in the time zone each was published in, oldest first.
	 */
	private static function parse_dates( array $raw ) {
		$dates = array();
		foreach ( $raw as $date ) {
			if ( ! is_array( $date ) ) {
				continue;
			}
			$zone  = self::zone( isset( $date['timezone'] ) ? $date['timezone'] : null );
			$start = self::parse( isset( $date['start_time'] ) ? $date['start_time'] : null, $zone );
			if ( ! $start ) {
				continue;
			}
			$end     = self::parse( isset( $date['end_time'] ) ? $date['end_time'] : null, $zone );
			$dates[] = array(
				'id'            => isset( $date['id'] ) && is_scalar( $date['id'] ) ? (string) $date['id'] : '',
				'start'         => $start,
				'end'           => $end && $end >= $start ? $end : $start,
				'occurrence_id' => null,
			);
		}
		usort(
			$dates,
			function ( $a, $b ) {
				return $a['start']->getTimestamp() - $b['start']->getTimestamp();
			}
		);
		return $dates;
	}

	/**
	 * Pair each date with the (not rejected) occurrence indexed at the same instant. Indexed
	 * start times are UTC without an offset.
	 */
	private static function attach_occurrence_ids( array $dates, array $indexed ) {
		$utc = new DateTimeZone( 'UTC' );
		$ids = array();
		foreach ( $indexed as $occurrence ) {
			if ( ! is_array( $occurrence ) || ! empty( $occurrence['rejected'] ) || ! isset( $occurrence['id'] ) || ! is_scalar( $occurrence['id'] ) ) {
				continue;
			}
			$start = self::parse( isset( $occurrence['startTime'] ) ? $occurrence['startTime'] : null, $utc );
			if ( $start && preg_match( '/^[A-Za-z0-9_-]+$/', (string) $occurrence['id'] ) ) {
				$ids[ $start->getTimestamp() ] = (string) $occurrence['id'];
			}
		}
		foreach ( $dates as $i => $date ) {
			$key = $date['start']->getTimestamp();
			if ( isset( $ids[ $key ] ) ) {
				$dates[ $i ]['occurrence_id'] = $ids[ $key ];
			}
		}
		return $dates;
	}

	private static function zone( $name ) {
		if ( is_string( $name ) && '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	private static function parse( $value, $zone ) {
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

	/* ---------- dates ---------- */

	/** @return array[] Every date of the series. */
	public function dates() {
		return $this->dates;
	}

	/** @return array[] Dates still to come (not yet ended). */
	public function upcoming_dates() {
		return $this->upcoming;
	}

	/** @return array|null First date still to come. */
	public function next_date() {
		return $this->upcoming ? $this->upcoming[0] : null;
	}

	/** @return array|null Last date of the series, past or not. */
	public function last_date() {
		return $this->dates ? $this->dates[ count( $this->dates ) - 1 ] : null;
	}

	/** @return array|null Last date still to come. */
	public function last_upcoming_date() {
		return $this->upcoming ? $this->upcoming[ count( $this->upcoming ) - 1 ] : null;
	}

	/**
	 * The date indexed under an occurrence id (the event page a URL points at).
	 *
	 * @param string $occurrence_id Occurrence (event URL) id.
	 * @return array|null
	 */
	public function find_by_occurrence_id( $occurrence_id ) {
		foreach ( $this->dates as $date ) {
			if ( null !== $date['occurrence_id'] && (string) $occurrence_id === $date['occurrence_id'] ) {
				return $date;
			}
		}
		return null;
	}

	/**
	 * Whether two dates are the same date (same series date id and start).
	 */
	public static function same( $a, $b ) {
		return is_array( $a ) && is_array( $b ) && $a['id'] === $b['id'] && $a['start'] == $b['start']; // phpcs:ignore Universal.Operators.StrictComparisons -- DateTimeImmutable equality.
	}

	/** Two or more upcoming dates fall on one day (in each date's own time zone). */
	public function has_several_dates_on_one_day() {
		$days = array();
		foreach ( $this->upcoming as $date ) {
			$days[ $date['start']->format( 'Y-m-d' ) ] = true;
		}
		return count( $days ) < count( $this->upcoming );
	}

	/** Upcoming dates per month, from the start of the next date's day to the end of the last's. */
	public function upcoming_dates_per_month() {
		$next = $this->next_date();
		$last = $this->last_upcoming_date();
		if ( ! $next ) {
			return 0.0;
		}
		$from   = $next['start']->setTime( 0, 0, 0 )->getTimestamp();
		$to     = $last['start']->setTime( 23, 59, 59 )->getTimestamp() + 0.999;
		$months = ( $to - $from ) / DAY_IN_SECONDS / self::DAYS_PER_MONTH;
		return $months > 0 ? count( $this->upcoming ) / $months : (float) count( $this->upcoming );
	}

	/* ---------- labels ---------- */

	/** @return array|null The one schedule the series follows, if it follows exactly one. */
	public function single_schedule() {
		return 1 === count( $this->schedules ) ? $this->schedules[0] : null;
	}

	/** "Daily series", "Weekly series", "Monthly series", else "Recurring". */
	public function badge_label() {
		$schedule  = $this->single_schedule();
		$frequency = $schedule && isset( $schedule['frequency'] ) ? $schedule['frequency'] : '';
		switch ( $frequency ) {
			case 'daily':
				return __( 'Daily series', 'evvnt-calendar' );
			case 'weekly':
				return __( 'Weekly series', 'evvnt-calendar' );
			case 'monthly':
				return __( 'Monthly series', 'evvnt-calendar' );
		}
		return __( 'Recurring', 'evvnt-calendar' );
	}

	/**
	 * The pattern of the one schedule the series follows ("daily", "weekly on Sundays",
	 * "monthly on the 2nd Saturday"), or null when there is none to name.
	 */
	public function pattern_label() {
		$schedule = $this->single_schedule();
		if ( ! $schedule || empty( $schedule['start_date'] ) ) {
			return null;
		}
		switch ( isset( $schedule['frequency'] ) ? $schedule['frequency'] : '' ) {
			case 'daily':
				return __( 'daily', 'evvnt-calendar' );
			case 'weekly':
				return self::weekly_pattern( $schedule );
			case 'monthly':
				return self::monthly_pattern( $schedule );
		}
		return null;
	}

	private static function weekly_pattern( array $schedule ) {
		$plural = array(
			__( 'Sundays', 'evvnt-calendar' ),
			__( 'Mondays', 'evvnt-calendar' ),
			__( 'Tuesdays', 'evvnt-calendar' ),
			__( 'Wednesdays', 'evvnt-calendar' ),
			__( 'Thursdays', 'evvnt-calendar' ),
			__( 'Fridays', 'evvnt-calendar' ),
			__( 'Saturdays', 'evvnt-calendar' ),
		);
		$days   = isset( $schedule['week_days'] ) && is_array( $schedule['week_days'] ) ? $schedule['week_days'] : array();
		$names  = array();
		foreach ( $days as $day ) {
			if ( ! is_numeric( $day ) || ! isset( $plural[ (int) $day ] ) || (int) $day != $day ) { // phpcs:ignore Universal.Operators.StrictComparisons -- integer check.
				return null;
			}
			$names[] = $plural[ (int) $day ];
		}
		if ( ! $names ) {
			return null;
		}
		/* translators: %s: list of weekdays, e.g. "Mondays and Fridays". */
		return sprintf( __( 'weekly on %s', 'evvnt-calendar' ), self::conjunction( $names ) );
	}

	private static function monthly_pattern( array $schedule ) {
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $schedule['start_date'], new DateTimeZone( 'UTC' ) );
		if ( ! $start || $start->format( 'Y-m-d' ) !== (string) $schedule['start_date'] ) {
			return null;
		}
		$weekday = wp_date( 'l', $start->getTimestamp(), new DateTimeZone( 'UTC' ) );
		$day     = (int) $start->format( 'j' );
		switch ( isset( $schedule['monthly_occurrence'] ) ? $schedule['monthly_occurrence'] : '' ) {
			case 'weekday':
				/* translators: 1: ordinal week of the month, e.g. "2nd", 2: weekday name. */
				return sprintf( __( 'monthly on the %1$s %2$s', 'evvnt-calendar' ), self::ordinal( (int) ceil( $day / 7 ) ), $weekday );
			case 'last_weekday':
				/* translators: %s: weekday name. */
				return sprintf( __( 'monthly on the last %s', 'evvnt-calendar' ), $weekday );
		}
		/* translators: %s: ordinal day of the month, e.g. "14th". */
		return sprintf( __( 'monthly on the %s', 'evvnt-calendar' ), self::ordinal( $day ) );
	}

	/** English ordinal ("1st", "22nd", "13th"), as Intl.PluralRules("en", ordinal). */
	public static function ordinal( $number ) {
		$mod100 = $number % 100;
		$mod10  = $number % 10;
		if ( 1 === $mod10 && 11 !== $mod100 ) {
			$suffix = _x( 'st', 'ordinal suffix for 1, 21, 31…', 'evvnt-calendar' );
		} elseif ( 2 === $mod10 && 12 !== $mod100 ) {
			$suffix = _x( 'nd', 'ordinal suffix for 2, 22…', 'evvnt-calendar' );
		} elseif ( 3 === $mod10 && 13 !== $mod100 ) {
			$suffix = _x( 'rd', 'ordinal suffix for 3, 23…', 'evvnt-calendar' );
		} else {
			$suffix = _x( 'th', 'ordinal suffix for 4, 11…', 'evvnt-calendar' );
		}
		return $number . $suffix;
	}

	/** "A", "A and B", "A, B, and C" (Intl.ListFormat("en", conjunction)). */
	private static function conjunction( array $items ) {
		if ( count( $items ) < 3 ) {
			/* translators: list separator between the last two of two items. */
			return implode( __( ' and ', 'evvnt-calendar' ), $items );
		}
		$last = array_pop( $items );
		/* translators: list separator before the last of three or more items. */
		return implode( __( ', ', 'evvnt-calendar' ), $items ) . __( ', and ', 'evvnt-calendar' ) . $last;
	}

	/** "Repeats daily through Dec 13, 2026", or "Repeats through …" with no pattern. */
	public function frequency_line() {
		$last = $this->last_date();
		if ( ! $last ) {
			return '';
		}
		$through = wp_date( 'M j, Y', $last['start']->getTimestamp(), $last['start']->getTimezone() );
		$pattern = $this->pattern_label();
		return $pattern
			/* translators: 1: pattern, e.g. "weekly on Sundays", 2: date of the last occurrence. */
			? sprintf( __( 'Repeats %1$s through %2$s', 'evvnt-calendar' ), $pattern, $through )
			/* translators: %s: date of the last occurrence. */
			: sprintf( __( 'Repeats through %s', 'evvnt-calendar' ), $through );
	}

	/** "15 dates · Sep 20, 2026 – Dec 27, 2026". */
	public function upcoming_dates_summary() {
		$next = $this->next_date();
		$last = $this->last_upcoming_date();
		if ( ! $next ) {
			return '';
		}
		$count = count( $this->upcoming );
		return sprintf(
			/* translators: 1: number of dates, 2: first date, 3: last date. */
			_n( '%1$s date · %2$s – %3$s', '%1$s dates · %2$s – %3$s', $count, 'evvnt-calendar' ),
			number_format_i18n( $count ),
			wp_date( 'M j, Y', $next['start']->getTimestamp(), $next['start']->getTimezone() ),
			wp_date( 'M j, Y', $last['start']->getTimestamp(), $last['start']->getTimezone() )
		);
	}
}
