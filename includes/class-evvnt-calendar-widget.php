<?php

/**
 * Evvnt Calendar Custom Widget
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/includes
 * @author     Derek Graham <derek@evvnt.com>
 */

class Evvnt_Calendar_Widget extends WP_Widget {

  public function __construct() {
    parent::__construct(
  	  'evvnt_calendar_widget',
  		__( 'Evvnt Calendar Widget', 'widget-for-evvnt_calendar' ),
  		array(
  		  'customize_selective_refresh' => true,
  		)
  	);
  }

  public function form($instance) {
    $defaults = array(
      'detail_page_enabled' => '1',
      'config_type'         => 'calendar',
      'virtual'             => '0',
      'map'                 => '0',
      'seo_optimize'        => '0',
      'category_id'         => ''
    );
    extract(wp_parse_args((array) $instance, $defaults));

    $categories = [];
    $cal_options = get_option('widget-for-evvnt-calendar-settings');
    if (isset($cal_options['api_key']) && isset($cal_options['api_secret_key']) && isset($cal_options['publisher_id'])) {
      $args = array(
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $cal_options['api_key'] . ':' . $cal_options['api_secret_key'] )
        )
      );
      $url = 'https://api.evvnt.com/publishers/' . $cal_options['publisher_id'] . '/categories/';
      $response = wp_remote_get($url, $args);
      if (wp_remote_retrieve_response_code($response) == 200) {
        $body = wp_remote_retrieve_body($response);
        $categories = json_decode($body, true);
      }
    }
?>
    <p>
      <label for="<?php echo esc_attr($this->get_field_id('detail_page_enabled')); ?>"><?php _e( 'Click on event listings go to', 'text_domain' ); ?></label>
      <select name="<?php echo $this->get_field_name('detail_page_enabled');?>" id="<?php echo $this->get_field_id('detail_page_enabled'); ?>" class="widefat">
      <?php
            $options = array(
              ''             => __('Select', 'text_domain'),
              '1'      => __('Full event details', 'text_domain'),
              '0' => __("The event's primary link", 'text_domain')
            );
            foreach ( $options as $key => $name ) {
              echo '<option value="'.esc_attr($key).'" id="'.esc_attr($key).'" '.selected($detail_page_enabled, $key, false ).'>'.$name.'</option>';
            } ?>
      </select>
    </p>
    <p>
      <label for="<?php echo $this->get_field_id('config_type'); ?>"><?php _e('Configuration Type', 'text_domain'); ?></label>
      <select name="<?php echo $this->get_field_name('config_type');?>" id="<?php echo $this->get_field_id('config_type'); ?>" class="widefat">
      <?php
      $options = array(
        ''         => __('Select', 'text_domain'),
        'calendar' => __('Calendar', 'text_domain'),
        'widget'   => __('Widget', 'text_domain')
      );
      foreach ( $options as $key => $name ) {
        echo '<option value="'.esc_attr($key).'" id="'.esc_attr($key).'" '.selected($config_type, $key, false ).'>'.$name.'</option>';
      } ?>
      </select>
    </p>
    <p>
      <input id="<?php echo esc_attr($this->get_field_id('virtual'));?>" name="<?php echo esc_attr($this->get_field_name('virtual')); ?>" type="checkbox" value="1" <?php checked('1', $virtual); ?> />
      <label for="<?php echo esc_attr($this->get_field_id('virtual')); ?>"><?php _e('Show Only Virtual Events', 'text_domain'); ?></label>
    </p>
    <p>
      <input id="<?php echo esc_attr($this->get_field_id('map'));?>" name="<?php echo esc_attr($this->get_field_name('map')); ?>" type="checkbox" value="1" <?php checked('1', $map); ?> />
      <label for="<?php echo esc_attr($this->get_field_id('map')); ?>"><?php _e('Show Events on a Map', 'text_domain'); ?></label>
    </p>
    <p>
      <input id="<?php echo esc_attr($this->get_field_id('seo_optimize'));?>" name="<?php echo esc_attr($this->get_field_name('seo_optimize')); ?>" type="checkbox" value="1" <?php checked('1', $seo_optimize); ?> />
      <label for="<?php echo esc_attr($this->get_field_id('seo_optimize')); ?>"><?php _e('Add rich text markup for events (SEO)', 'text_domain'); ?></label>
    </p>
<?php if (!empty($categories)) { ?>
    <p>
      <label for="<?php echo $this->get_field_id('category_id'); ?>"><?php _e('Category', 'text_domain'); ?></label>
      <select name="<?php echo $this->get_field_name('category_id');?>" id="<?php echo $this->get_field_id('category_id'); ?>" class="widefat">
        <option value="" id="All Categories">All Categories</option>
        <?php
        foreach ( $categories as $category ) {
          echo '<option value="'.esc_attr($category['id']).'" id="'.esc_attr($category['name']).'" '.selected($category_id, $key, false ).'>'.$category['name'].'</option>';
        } ?>
      </select>
    </p>
<?php }

  }

  public function update($new_instance, $old_instance) {
    $instance = $old_instance;
    $instance['config_type']         = isset($new_instance['config_type']) ? wp_strip_all_tags($new_instance['config_type']) : 'calendar';
    $instance['detail_page_enabled'] = isset($new_instance['detail_page_enabled']) ? wp_strip_all_tags($new_instance['detail_page_enabled']) : 1;
    $instance['virtual']             = isset($new_instance['virtual']) ? wp_strip_all_tags($new_instance['virtual']) : 0;
    $instance['map']                 = isset($new_instance['map']) ? wp_strip_all_tags($new_instance['map']) : 0;
    $instance['seo_optimize']        = isset($new_instance['seo_optimize']) ? wp_strip_all_tags($new_instance['seo_optimize']) : 0;
    $instance['category_id']         = isset($new_instance['category_id']) ? wp_strip_all_tags($new_instance['category_id']) : '';
    return $instance;
  }

  public function widget($args, $instance) {
    extract($args);
    extract($instance);
    echo $before_widget;
    include plugin_dir_path(dirname(__FILE__)) . 'public/partials/evvnt-calendar-public-display.php';
    echo $after_widget;
  }

}
