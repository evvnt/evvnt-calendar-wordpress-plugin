<?php

/**
 * Evvnt Calendar Custom Widget
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 * @author     Derek Graham <derek@evvnt.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'evvnt_calendar_widget',
			__( 'Evvnt Calendar Widget', 'evvnt-calendar' ),
			// No customize_selective_refresh: the embed's init script is printed in the
			// footer, which a partial refresh never sends, so the preview reloads instead.
			array()
		);
	}

	public function form( $instance ) {
		$instance   = wp_parse_args( (array) $instance, Evvnt_Calendar_Public::display_defaults() );
		$categories = Evvnt_Calendar_Publisher_Client::from_settings()->categories();

		// Instances saved by 0.0.1 may hold '' or 'true'; show what they currently render as.
		$detail = (string) $instance['detail_page_enabled'];
		$instance['detail_page_enabled'] = ( '1' === $detail || 'true' === strtolower( $detail ) ) ? '1' : '0';

		$this->select_field(
			'detail_page_enabled',
			__( 'Click on event listings go to', 'evvnt-calendar' ),
			array(
				'1' => __( 'Full event details', 'evvnt-calendar' ),
				'0' => __( "The event's primary link", 'evvnt-calendar' ),
			),
			$instance['detail_page_enabled']
		);

		$this->select_field(
			'config_type',
			__( 'Configuration Type', 'evvnt-calendar' ),
			array(
				'calendar' => __( 'Calendar', 'evvnt-calendar' ),
				'widget'   => __( 'Widget', 'evvnt-calendar' ),
			),
			$instance['config_type']
		);

		$this->checkbox_field( 'virtual', __( 'Show Only Virtual Events', 'evvnt-calendar' ), $instance['virtual'] );
		$this->checkbox_field( 'map', __( 'Show Events on a Map', 'evvnt-calendar' ), $instance['map'] );

		if ( ! empty( $categories ) ) {
			$options = array( '' => __( 'All Categories', 'evvnt-calendar' ) );
			foreach ( $categories as $category ) {
				$options[ (string) $category['id'] ] = $category['name'];
			}
			$this->select_field( 'category_id', __( 'Category', 'evvnt-calendar' ), $options, (string) $instance['category_id'] );
		}
	}

	public function update( $new_instance, $old_instance ) {
		$instance = is_array( $old_instance ) ? $old_instance : array();

		$instance['config_type']         = ( isset( $new_instance['config_type'] ) && 'widget' === $new_instance['config_type'] ) ? 'widget' : 'calendar';
		$instance['detail_page_enabled'] = ( isset( $new_instance['detail_page_enabled'] ) && '0' === (string) $new_instance['detail_page_enabled'] ) ? '0' : '1';
		$instance['virtual']             = empty( $new_instance['virtual'] ) ? '0' : '1';
		$instance['map']                 = empty( $new_instance['map'] ) ? '0' : '1';
		// The category select is only shown when the category list loads; if it was
		// missing from the form, keep the saved category rather than clearing it.
		if ( isset( $new_instance['category_id'] ) ) {
			$category_id             = absint( $new_instance['category_id'] );
			$instance['category_id'] = $category_id ? (string) $category_id : '';
		} else {
			$category_id             = isset( $instance['category_id'] ) ? absint( $instance['category_id'] ) : 0;
			$instance['category_id'] = $category_id ? (string) $category_id : '';
		}
		unset( $instance['seo_optimize'] ); // The discovery loader ignores this option.

		return $instance;
	}

	public function widget( $args, $instance ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'before_widget' => '',
				'after_widget'  => '',
			)
		);

		// before_widget / after_widget are theme-supplied markup.
		$instance = wp_parse_args( (array) $instance, Evvnt_Calendar_Public::display_defaults() );
		// Server mode renders the compact "Widget" type in PHP; the full calendar type is
		// still the discovery embed when placed in a widget area.
		$html = ( Evvnt_Calendar_Public::server_mode() && 'widget' === $instance['config_type'] )
			? Evvnt_Calendar_Public::render_widget( $instance )
			: Evvnt_Calendar_Public::render_embed( $instance );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_widget() / render_embed().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function select_field( $name, $label, array $options, $current ) {
		$id = $this->get_field_id( $name );
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<select name="<?php echo esc_attr( $this->get_field_name( $name ) ); ?>" id="<?php echo esc_attr( $id ); ?>" class="widefat">
				<?php foreach ( $options as $value => $text ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $current, (string) $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	private function checkbox_field( $name, $label, $current ) {
		$id = $this->get_field_id( $name );
		?>
		<p>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $this->get_field_name( $name ) ); ?>" type="checkbox" value="1" <?php checked( '1', (string) $current ); ?> />
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		</p>
		<?php
	}
}
