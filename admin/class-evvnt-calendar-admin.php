<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/admin
 * @author     Derek Graham <derek@evvnt.com>
 */

class Evvnt_Calendar_Admin {

  protected  $settings_page;
  protected  $settings_page_id = 'settings_page_widget-for-evvnt-calendar-settings';
  protected  $option_group = 'widget-for-evvnt-calendar';
  protected  $option_name = 'widget-for-evvnt-calendar-settings';
  protected  $settings_title;

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
    $this->settings_title = esc_html__( 'Evvnt Calendar Settings', 'widget-for-evvnt_calendar' );
  }

  public function settings_setup() {
    $this->register_settings();
    if ( ! empty( $this->settings_page ) ) {
      add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
      add_action( "admin_enqueue_styles", array( $this, 'enqueue_styles' ) );
      add_filter( 'screen_layout_columns', array( $this, 'screen_layout_column' ), 10, 2 );
      add_action( $this->settings_page_id . '_settings_page_boxes', array( $this, 'add_meta_boxes' ) );
    }
  }

	public function register_settings() {
    register_setting(
        $this->option_group,
        $this->option_name,
        array( $this, 'sanitize_settings' )
    );
    $this->settings_page = add_submenu_page(
                                 'options-general.php',
                                 $this->settings_title,
                                 $this->settings_title,
                                 'manage_options',
                                 $this->option_name,
                                 array( $this, 'settings_page' )
                               );
	}

	public function register_widgets() {
	  register_widget('Evvnt_Calendar_Widget');
	}


  public function sanitize_settings($settings) {
    if (empty($settings)) {
        return $settings;
    }
    $options = get_option($this->option_name);
    if (empty($options)) {
        return $settings;
    }

    // TODO: Add method to validate these values against API?
    if (!isset( $settings['api_key'])) {
      $settings['api_key'] = '';
    }

    if (!isset( $settings['publisher_id'])) {
        $settings['publisher_id'] = '';
    }

    if (!isset( $settings['partner_name'])) {
      $settings['partner_name'] = '';
    }

    if (!isset( $settings['submission_label'])) {
        $settings['submission_label'] = 'Promote your event';
    }

    return $settings;
  }

  public function add_meta_boxes() {
    add_meta_box(
        'settings',
        /* Meta Box ID */
        __( 'Settings', 'widget-for-evvnt-calendar' ),
        /* Title */
        array( $this, 'settings_meta_box' ),
        /* Function Callback */
        $this->settings_page_id,
        /* Screen: Our Settings Page */
        'normal',
        /* Context */
        'default'
    );
  }

  public function settings_page() {
    $hook_suffix = $this->settings_page_id;
    do_action($this->settings_page_id . '_settings_page_boxes', $hook_suffix);
    ?>
      <div class="wrap">
        <h2><?php echo $this->settings_title; ?></h2>
        <?php
          global $pagenow;
          if ( $pagenow !== "options-general.php" ) {
              settings_errors();
          } ?>
        <div class="fs-settings-meta-box-wrap">
          <form id="fs-smb-form" method="post" action="options.php">
            <?php settings_fields( $this->option_group );	?>
            <?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
            <?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
            <div id="poststuff">
              <div id="post-body" class="metabox-holder columns-<?php echo 1 == get_current_screen()->get_columns() ? '1' : '2'; ?>">
              	<div id="postbox-container-1" class="postbox-container">
                  <?php do_meta_boxes( $hook_suffix, 'side', null ); ?>
            		</div>
           			<div id="postbox-container-2" class="postbox-container">
            		  <?php do_meta_boxes( $hook_suffix, 'normal', null ); ?>
            			<?php do_meta_boxes( $hook_suffix, 'advanced', null ); ?>
            		</div>
              </div>
              <br class="clear">
            </div>
          </form>
        </div>
      </div>
    <?php

  }

  public function settings_meta_box() {
    $options = get_option($this->option_name);

    if (!isset($options['submission_label'])) {
      $options['submission_label'] = 'Promote your event';
    }
    ?>
    <table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><?php esc_html_e( 'API Key', 'widget-for-evvnt-calendar' ); ?></th>
          <td>
            <input type="text" name="widget-for-evvnt-calendar-settings[api_key]"
                   id="widget-for-evvnt-calendar-settings-api-key"
                   class="regular-text required"
                   required
                   value="<?php echo $options['api_key']; ?>"
                   >
              <p class="api-key-result"></p>
              <p>
                <span class="description"><?php _e( 'The API key is required to display the Evvnt Calendar Plugin', 'widget-for-evvnt-calendar' ); ?></span>
              </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row"><?php esc_html_e( 'Publisher ID', 'widget-for-evvnt-calendar' ); ?></th>
          <td>
            <input type="text" name="widget-for-evvnt-calendar-settings[publisher_id]"
                   id="widget-for-evvnt-calendar-settings-publisher-id"
                   class="regular-text required"
                   required
                   value="<?php echo $options['publisher_id']; ?>"
                   >
              <p>
                <span class="description"><?php _e( 'This should explain where to find publisher ID', 'widget-for-evvnt-calendar' ); ?></span>
              </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row"><?php esc_html_e( 'Add Event Form Partner Name', 'widget-for-evvnt-calendar' ); ?></th>
          <td>
            <input type="text" name="widget-for-evvnt-calendar-settings[partner_name]"
                   id="widget-for-evvnt-calendar-settings-partner-name"
                   class="regular-text"
                   required
                   value="<?php echo $options['partner_name']; ?>"
                   >
              <p>
                <span class="description"><?php _e( 'The name displayed on the add event form', 'widget-for-evvnt-calendar' ); ?></span>
              </p>
          </td>
        </tr>

        <tr valign="top">
          <th scope="row"><?php esc_html_e( 'Submission button label', 'widget-for-evvnt-calendar' ); ?></th>
          <td>
            <input type="text" name="widget-for-evvnt-calendar-settings[submission_label]"
                   id="widget-for-evvnt-calendar-settings-submission-label"
                   class="regular-text"
                   required
                   value="<?php echo  $options['partner_name']; ?>"
                   >
              <p>
                <span class="description"><?php _e( 'The label displayed on the submit event button', 'widget-for-evvnt-calendar' ); ?></span>
              </p>
          </td>
        </tr>

        <tr>
          <td colspan="2">
            <div id="publishing-action">
              <span class="spinner"></span>
              <?php submit_button(esc_attr__('Save', 'widget-for-evvnt-calendar'), 'primary', 'submit', false ); ?>
            </div>
        </tr>
      </tbody>
    </table>
  	<?php
  }

  public function enqueue_styles() {
    wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/evvnt-calendar-admin.css', array(), $this->version, 'all' );
  }

  public function enqueue_scripts() {
    wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/evvnt-calendar-admin.js', array( 'jquery' ), $this->version, false );
  }
}

