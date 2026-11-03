<?php

/**
 * The public-facing functionality of the plugin.
 *
 * Renders the Evvnt discovery embed for the [evvnt] shortcode and the widget.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Public {

	const SCRIPT_HANDLE = 'evvnt-discovery';
	const SCRIPT_URL    = 'https://discovery.evvnt.com/prd/evvnt_discovery_plugin-latest.min.js';

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_shortcode() {
		add_shortcode( 'evvnt', array( $this, 'build_shortcode' ) );
	}

	/**
	 * Default display options shared by the shortcode and the widget.
	 *
	 * @return array
	 */
	public static function display_defaults() {
		return array(
			'detail_page_enabled' => '1',
			'config_type'         => 'calendar',
			'virtual'             => '0',
			'map'                 => '0',
			'seo_optimize'        => '0', // Accepted for backwards compatibility; the discovery loader ignores it.
			'category_id'         => '',
		);
	}

	public function build_shortcode( $atts ) {
		$atts = shortcode_atts( self::display_defaults(), $atts, 'evvnt' );
		return self::render_embed( $atts );
	}

	/**
	 * Render the discovery embed for one shortcode or widget instance.
	 *
	 * Every value that reaches the inline script is type-cast and JSON-encoded,
	 * so shortcode attributes and settings cannot inject script.
	 *
	 * @param array $atts Raw display options (strings from a shortcode or widget instance).
	 * @return string HTML.
	 */
	public static function render_embed( $atts ) {
		$atts         = wp_parse_args( is_array( $atts ) ? $atts : array(), self::display_defaults() );
		$publisher_id = Evvnt_Calendar_Settings::publisher_id();

		if ( ! $publisher_id ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="evvnt-calendar-unconfigured">' . esc_html__( 'Evvnt Calendar is not configured. Add your Publisher ID under Settings → Evvnt Calendar Settings.', 'evvnt-calendar' ) . '</p>';
			}
			return '';
		}

		$element_id = wp_unique_id( 'evvnt-calendar-' );

		$discovery = array(
			'element'             => '#' . $element_id,
			'detail_page_enabled' => self::to_bool( $atts['detail_page_enabled'] ),
			'widget'              => 'widget' === $atts['config_type'],
			'virtual'             => self::to_bool( $atts['virtual'] ),
			'map'                 => self::to_bool( $atts['map'] ),
		);

		$category_id = absint( $atts['category_id'] );
		if ( $category_id ) {
			$discovery['category_id'] = $category_id;
		}

		$settings = Evvnt_Calendar_Settings::get();
		$config   = array(
			'publisher_id' => $publisher_id,
			'discovery'    => $discovery,
			'submission'   => array(
				'partner_name' => (string) $settings['partner_name'],
				'text'         => (string) $settings['submission_label'],
			),
		);

		wp_enqueue_script( self::SCRIPT_HANDLE, self::SCRIPT_URL, array(), null, true );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.evvnt_require&&evvnt_require("evvnt/discovery_plugin").init(' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ');'
		);

		// The <noscript> fallback also keeps the markup non-empty, which the block-based
		// widget editor requires before it shows a preview. The discovery app renders
		// into a shadow root, so this content is never displayed alongside the calendar.
		return '<div id="' . esc_attr( $element_id ) . '" class="evvnt-calendar"><noscript>'
			. esc_html__( 'Please enable JavaScript to view the events calendar.', 'evvnt-calendar' )
			. '</noscript></div>';
	}

	/**
	 * Interpret '1', 'true', 1 and true as on; everything else as off.
	 */
	private static function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( (string) $value );
	}
}
