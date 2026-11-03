<?php

/**
 * <head> output for server-rendered calendar views: document title, canonical,
 * meta description, Open Graph, robots and Event JSON-LD.
 *
 * When Yoast SEO or Rank Math is active, their own title/canonical/description
 * (and Open Graph) output is filtered instead of printing duplicate tags.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Seo {

	private $title       = '';
	private $description = '';
	private $canonical   = '';
	private $image       = '';
	private $og_type     = 'website';
	private $noindex     = false;
	private $schema      = null;

	/**
	 * Head data for an event detail view.
	 */
	public function for_event( Evvnt_Calendar_Event $event, $url ) {
		$this->title       = $event->title();
		$this->description = $event->short_description( 160 );
		$this->canonical   = $url;
		$this->image       = $event->hero_image_url();
		$this->og_type     = 'article';
		$this->noindex     = $event->is_expired();
		$this->schema      = $event->to_schema( $url );
		$this->register();
	}

	/**
	 * Head data for a category or paged calendar listing.
	 *
	 * @param string $title     Title part (category name, page number), or '' to keep the page title.
	 * @param string $url       Canonical URL.
	 */
	public function for_listing( $title, $url ) {
		$this->title     = $title;
		$this->canonical = $url;
		$this->register();
	}

	/**
	 * Mark the response noindex (missing event, unknown category, outage).
	 */
	public function noindex_only() {
		$this->noindex = true;
		$this->register_robots();
	}

	private function register_robots() {
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		// Yoast and Rank Math print their own robots tag.
		add_filter( 'wpseo_robots_array', array( $this, 'yoast_robots' ) );
		add_filter( 'rank_math/frontend/robots', array( $this, 'rank_math_robots' ) );
	}

	public function yoast_robots( $robots ) {
		if ( $this->noindex && is_array( $robots ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	public function rank_math_robots( $robots ) {
		if ( $this->noindex && is_array( $robots ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	private function register() {
		$this->register_robots();

		if ( '' !== $this->title ) {
			add_filter( 'document_title_parts', array( $this, 'title_parts' ), 20 );
		}

		// Replace core's canonical, which would point every event at the calendar page.
		remove_action( 'wp_head', 'rel_canonical' );

		if ( self::yoast_active() ) {
			add_filter( 'wpseo_canonical', array( $this, 'canonical_url' ) );
			add_filter( 'wpseo_opengraph_url', array( $this, 'canonical_url' ) );
			if ( '' !== $this->title ) {
				add_filter( 'wpseo_title', array( $this, 'full_title' ) );
				add_filter( 'wpseo_opengraph_title', array( $this, 'full_title' ) );
			}
			if ( '' !== $this->description ) {
				add_filter( 'wpseo_metadesc', array( $this, 'meta_description' ) );
				add_filter( 'wpseo_opengraph_desc', array( $this, 'meta_description' ) );
			}
			if ( '' !== $this->image ) {
				add_filter( 'wpseo_opengraph_image', array( $this, 'image_url' ) );
			}
		} elseif ( self::rank_math_active() ) {
			add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical_url' ) );
			if ( '' !== $this->title ) {
				add_filter( 'rank_math/frontend/title', array( $this, 'full_title' ) );
			}
			if ( '' !== $this->description ) {
				add_filter( 'rank_math/frontend/description', array( $this, 'meta_description' ) );
			}
		} elseif ( apply_filters( 'evvnt_calendar_print_head_meta', true, $this ) ) {
			/*
			 * With another SEO plugin active, return false from this filter and use that plugin's
			 * own canonical/description filters; canonical_url(), meta_description(), full_title()
			 * and image_url() on the passed object give the values.
			 */
			add_action( 'wp_head', array( $this, 'print_meta' ), 1 );
		}

		if ( $this->schema ) {
			add_action( 'wp_head', array( $this, 'print_schema' ), 20 );
		}
	}

	public function robots( $robots ) {
		if ( $this->noindex ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['max-image-preview'] );
		}
		return $robots;
	}

	public function title_parts( $parts ) {
		$parts['title'] = $this->title;
		return $parts;
	}

	public function full_title( $title = '' ) {
		return $this->title . ' ' . apply_filters( 'document_title_separator', '-' ) . ' ' . get_bloginfo( 'name', 'display' );
	}

	public function canonical_url( $url = '' ) {
		return $this->canonical;
	}

	public function meta_description( $description = '' ) {
		return $this->description;
	}

	public function image_url( $image = '' ) {
		return $this->image;
	}

	public function print_meta() {
		if ( '' !== $this->canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $this->canonical ) . "\" />\n";
		}
		if ( '' !== $this->description ) {
			echo '<meta name="description" content="' . esc_attr( $this->description ) . "\" />\n";
		}
		if ( '' === $this->title && '' === $this->image ) {
			return;
		}
		$tags = array(
			'og:type'        => $this->og_type,
			'og:title'       => '' !== $this->title ? $this->title : wp_get_document_title(),
			'og:description' => $this->description,
			'og:url'         => $this->canonical,
			'og:site_name'   => get_bloginfo( 'name', 'display' ),
			'og:image'       => $this->image,
		);
		foreach ( $tags as $property => $content ) {
			if ( '' !== (string) $content ) {
				$value = in_array( $property, array( 'og:url', 'og:image' ), true ) ? esc_url( $content ) : esc_attr( $content );
				echo '<meta property="' . esc_attr( $property ) . '" content="' . $value . "\" />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
		}
		echo '<meta name="twitter:card" content="' . ( '' !== $this->image ? 'summary_large_image' : 'summary' ) . "\" />\n";
	}

	public function print_schema() {
		// JSON_HEX_TAG stops event text from closing the script element.
		echo '<script type="application/ld+json">' . wp_json_encode( $this->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
	}

	public static function yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function rank_math_active() {
		return defined( 'RANK_MATH_VERSION' );
	}
}
