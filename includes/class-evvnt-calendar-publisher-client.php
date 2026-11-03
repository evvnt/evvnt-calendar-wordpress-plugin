<?php

/**
 * Client for the authenticated Evvnt Publisher API (api.evvnt.com).
 *
 * Used server-side only; the secret key never reaches the browser.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Publisher_Client {

	const HOST              = 'https://api.evvnt.com';
	const TIMEOUT           = 5;
	const CATEGORIES_TTL    = HOUR_IN_SECONDS;
	const TRANSIENT_PREFIX  = 'evvnt_calendar_';

	private $api_key;
	private $api_secret_key;
	private $publisher_id;

	public function __construct( $api_key, $api_secret_key, $publisher_id ) {
		$this->api_key        = (string) $api_key;
		$this->api_secret_key = (string) $api_secret_key;
		$this->publisher_id   = absint( $publisher_id );
	}

	/**
	 * Build a client from the saved settings.
	 *
	 * @return self
	 */
	public static function from_settings() {
		$settings = Evvnt_Calendar_Settings::get();
		return new self( $settings['api_key'], $settings['api_secret_key'], $settings['publisher_id'] );
	}

	/**
	 * Whether all credentials needed for an API call are present.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->api_key && '' !== $this->api_secret_key && $this->publisher_id > 0;
	}

	/**
	 * Check the credentials against the API (uncached).
	 *
	 * @return bool
	 */
	public function credentials_valid() {
		if ( ! $this->has_credentials() ) {
			return false;
		}
		$response = $this->request( $this->categories_url() );
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * The publisher's categories as a list of array( 'id' => int, 'name' => string ).
	 * Cached for an hour. Returns an empty array when unavailable.
	 *
	 * @return array
	 */
	public function categories() {
		if ( ! $this->has_credentials() ) {
			return array();
		}

		$key    = self::TRANSIENT_PREFIX . 'categories_' . md5( $this->publisher_id . '|' . $this->api_key . '|' . $this->api_secret_key );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->request( $this->categories_url() );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$categories = array();
		foreach ( $decoded as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['id'], $category['name'] ) ) {
				continue;
			}
			$id = absint( $category['id'] );
			if ( ! $id ) {
				continue;
			}
			$categories[] = array(
				'id'   => $id,
				'name' => (string) $category['name'],
			);
		}

		set_transient( $key, $categories, self::CATEGORIES_TTL );
		return $categories;
	}

	private function categories_url() {
		return self::HOST . '/publishers/' . $this->publisher_id . '/categories/';
	}

	private function request( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret_key ),
				),
			)
		);
	}
}
