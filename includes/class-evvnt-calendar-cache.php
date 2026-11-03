<?php

/**
 * Transient cache for API responses with a soft TTL, stale-on-error and backoff.
 *
 * Entries are stored for a day (hard expiry) and refreshed once older than the
 * caller's soft TTL. When a refresh fails, the stale copy is served and further
 * requests back off for a few minutes so a struggling API is not hammered.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Cache {

	const PREFIX      = 'evvnt_calendar_api_';
	const BACKOFF_KEY = 'evvnt_calendar_backoff';
	const GEN_OPTION  = 'evvnt_calendar_cache_gen';
	const HARD_TTL    = DAY_IN_SECONDS;
	const BACKOFF_TTL = 5 * MINUTE_IN_SECONDS;
	const MISSING_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Per-request memo so each URL is fetched at most once per page load.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Return cached data for $source, refreshing it with $fetch when older than $soft_ttl.
	 *
	 * @param string   $source   Cache identity (the request URL).
	 * @param int      $soft_ttl Seconds before a refresh is attempted.
	 * @param callable $fetch    Returns array data or WP_Error (error data may hold 'status').
	 * @return array|WP_Error
	 */
	public function remember( $source, $soft_ttl, callable $fetch ) {
		$key = self::PREFIX . (int) get_option( self::GEN_OPTION, 1 ) . '_' . md5( $source );

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$hit = get_transient( $key );

		// A recent "not found" is remembered so dead links do not reach the API on every request.
		if ( is_array( $hit ) && isset( $hit['missing'], $hit['at'] ) && ( time() - (int) $hit['at'] ) < self::MISSING_TTL ) {
			return $this->memo[ $key ] = new WP_Error( 'evvnt_calendar_http', 'Not found.', array( 'status' => (int) $hit['missing'] ) );
		}

		$valid = is_array( $hit ) && isset( $hit['at'] ) && array_key_exists( 'data', $hit );

		if ( $valid && ( time() - (int) $hit['at'] ) < $soft_ttl ) {
			return $this->memo[ $key ] = $hit['data'];
		}

		if ( get_transient( self::BACKOFF_KEY ) ) {
			return $this->memo[ $key ] = $valid
				? $hit['data']
				: new WP_Error( 'evvnt_calendar_backoff', 'Evvnt API temporarily unavailable.', array( 'status' => 503 ) );
		}

		$data = $fetch();

		if ( is_wp_error( $data ) ) {
			$status = self::error_status( $data );
			if ( 0 === $status || 429 === $status || $status >= 500 ) {
				set_transient( self::BACKOFF_KEY, 1, self::BACKOFF_TTL );
			}
			if ( in_array( $status, array( 400, 404, 410 ), true ) ) {
				// Never hide a real "not found" behind stale data; remember it briefly instead.
				set_transient(
					$key,
					array(
						'missing' => $status,
						'at'      => time(),
					),
					self::MISSING_TTL
				);
				return $this->memo[ $key ] = $data;
			}
			if ( $valid ) {
				return $this->memo[ $key ] = $hit['data'];
			}
			return $this->memo[ $key ] = $data;
		}

		set_transient(
			$key,
			array(
				'data' => $data,
				'at'   => time(),
			),
			self::HARD_TTL
		);
		return $this->memo[ $key ] = $data;
	}

	/**
	 * Invalidate every cached response by bumping the generation number.
	 */
	public static function flush() {
		update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 1 ) + 1, false );
		delete_transient( self::BACKOFF_KEY );
	}

	/**
	 * HTTP status carried by an error from the client (0 for network/parse errors).
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	public static function error_status( $error ) {
		$data = $error->get_error_data();
		return ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
	}
}
