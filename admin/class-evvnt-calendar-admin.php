<?php

/**
 * The admin-specific functionality of the plugin: the settings page and widget registration.
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/admin
 * @author     Derek Graham <derek@evvnt.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Evvnt_Calendar_Admin {

	protected $settings_page;
	protected $settings_page_id = 'settings_page_widget-for-evvnt-calendar-settings';
	protected $option_group     = 'widget-for-evvnt-calendar';
	protected $option_name      = Evvnt_Calendar_Settings::OPTION;

	private $plugin_name;
	private $version;

	/**
	 * Credential check results for this request, keyed by a hash of the credentials.
	 * WordPress runs the sanitize callback twice on the first save (add_option + update_option).
	 *
	 * @var array
	 */
	private $validated = array();

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function settings_setup() {
		$this->register_settings();
		if ( ! empty( $this->settings_page ) ) {
			add_action( $this->settings_page_id . '_settings_page_boxes', array( $this, 'add_meta_boxes' ) );
		}
	}

	public function register_settings() {
		register_setting(
			$this->option_group,
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'show_in_rest'      => false,
			)
		);
		$this->settings_page = add_submenu_page(
			'options-general.php',
			$this->settings_title(),
			$this->settings_title(),
			'manage_options',
			$this->option_name,
			array( $this, 'settings_page' )
		);
	}

	public function register_widgets() {
		register_widget( 'Evvnt_Calendar_Widget' );
	}

	private function settings_title() {
		return __( 'Evvnt Calendar Settings', 'evvnt-calendar' );
	}

	public function sanitize_settings( $input ) {
		// options.php has already unslashed the posted value.
		$input    = is_array( $input ) ? $input : array();
		$previous = Evvnt_Calendar_Settings::get();
		$defaults = Evvnt_Calendar_Settings::defaults();

		$publisher_id = isset( $input['publisher_id'] ) ? absint( $input['publisher_id'] ) : 0;
		$secret       = isset( $input['api_secret_key'] ) ? $this->clean_credential( $input['api_secret_key'] ) : '';

		$clean = array(
			'api_key'          => isset( $input['api_key'] ) ? $this->clean_credential( $input['api_key'] ) : '',
			// The secret field is never pre-filled; leaving it blank keeps the saved secret.
			'api_secret_key'   => '' !== $secret ? $secret : $previous['api_secret_key'],
			'publisher_id'     => $publisher_id ? (string) $publisher_id : '',
			'partner_name'     => isset( $input['partner_name'] ) ? sanitize_text_field( $input['partner_name'] ) : '',
			'submission_label' => ( isset( $input['submission_label'] ) && '' !== trim( $input['submission_label'] ) )
				? sanitize_text_field( $input['submission_label'] )
				: $defaults['submission_label'],
		);

		$client = new Evvnt_Calendar_Publisher_Client( $clean['api_key'], $clean['api_secret_key'], $clean['publisher_id'] );

		if ( ! $client->has_credentials() ) {
			$this->add_error_once(
				'evvnt_calendar_missing_credentials',
				__( 'API Key, API Secret Key and a numeric Publisher ID are all required.', 'evvnt-calendar' )
			);
			return $clean;
		}

		$hash = md5( $clean['api_key'] . '|' . $clean['api_secret_key'] . '|' . $clean['publisher_id'] );
		if ( ! isset( $this->validated[ $hash ] ) ) {
			$this->validated[ $hash ] = $client->credentials_valid();
		}

		if ( ! $this->validated[ $hash ] ) {
			$this->add_error_once(
				'evvnt_calendar_invalid_credentials',
				__( 'Settings saved, but Evvnt could not authenticate these credentials. Please check the API Key, API Secret Key and Publisher ID.', 'evvnt-calendar' )
			);
		}

		return $clean;
	}

	/**
	 * Credentials are opaque: keep them as typed apart from surrounding whitespace and
	 * control characters (sanitize_text_field would alter values containing '%xx' or '<').
	 * They are always escaped on output.
	 */
	private function clean_credential( $value ) {
		return is_scalar( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) ) : '';
	}

	private function add_error_once( $code, $message ) {
		foreach ( get_settings_errors( $this->option_name ) as $error ) {
			if ( $error['code'] === $code ) {
				return;
			}
		}
		add_settings_error( $this->option_name, $code, $message, 'error' );
	}

	public function add_meta_boxes() {
		add_meta_box(
			'settings',
			__( 'Settings', 'evvnt-calendar' ),
			array( $this, 'settings_meta_box' ),
			$this->settings_page_id,
			'normal',
			'default'
		);
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hook_suffix = $this->settings_page_id;
		do_action( $this->settings_page_id . '_settings_page_boxes', $hook_suffix );
		?>
		<div class="wrap">
			<h2><?php echo esc_html( $this->settings_title() ); ?></h2>
			<div class="fs-settings-meta-box-wrap">
				<form id="fs-smb-form" method="post" action="options.php">
					<?php settings_fields( $this->option_group ); ?>
					<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
					<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
					<div id="poststuff">
						<div id="post-body" class="metabox-holder columns-<?php echo 1 === (int) get_current_screen()->get_columns() ? '1' : '2'; ?>">
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
		$options    = Evvnt_Calendar_Settings::get();
		$has_secret = '' !== $options['api_secret_key'];
		$name       = $this->option_name;
		?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>-api-key"><?php esc_html_e( 'API Key', 'evvnt-calendar' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[api_key]"
							id="<?php echo esc_attr( $name ); ?>-api-key"
							class="regular-text required" required autocomplete="off"
							value="<?php echo esc_attr( $options['api_key'] ); ?>">
						<p><span class="description"><?php esc_html_e( 'The API Key is required to load your event categories', 'evvnt-calendar' ); ?></span></p>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>-api-secret-key"><?php esc_html_e( 'API Secret Key', 'evvnt-calendar' ); ?></label></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $name ); ?>[api_secret_key]"
							id="<?php echo esc_attr( $name ); ?>-api-secret-key"
							class="regular-text" autocomplete="new-password"
							<?php echo $has_secret ? '' : 'required'; ?>
							placeholder="<?php echo $has_secret ? esc_attr__( 'Saved — leave blank to keep', 'evvnt-calendar' ) : ''; ?>"
							value="">
						<p><span class="description"><?php esc_html_e( 'The API Secret Key is used server-side to validate your credentials. It is never shown on your site.', 'evvnt-calendar' ); ?></span></p>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>-publisher-id"><?php esc_html_e( 'Publisher ID', 'evvnt-calendar' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" name="<?php echo esc_attr( $name ); ?>[publisher_id]"
							id="<?php echo esc_attr( $name ); ?>-publisher-id"
							class="regular-text required" required
							value="<?php echo esc_attr( $options['publisher_id'] ); ?>">
						<p><span class="description"><?php esc_html_e( 'Your Evvnt Publisher ID', 'evvnt-calendar' ); ?></span></p>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>-partner-name"><?php esc_html_e( 'Add Event Form Partner Name', 'evvnt-calendar' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[partner_name]"
							id="<?php echo esc_attr( $name ); ?>-partner-name"
							class="regular-text"
							value="<?php echo esc_attr( $options['partner_name'] ); ?>">
						<p><span class="description"><?php esc_html_e( 'The name displayed on the add event form', 'evvnt-calendar' ); ?></span></p>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>-submission-label"><?php esc_html_e( 'Submission button label', 'evvnt-calendar' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[submission_label]"
							id="<?php echo esc_attr( $name ); ?>-submission-label"
							class="regular-text"
							value="<?php echo esc_attr( $options['submission_label'] ); ?>">
						<p><span class="description"><?php esc_html_e( 'The label displayed on the submit event button', 'evvnt-calendar' ); ?></span></p>
					</td>
				</tr>

				<tr>
					<td colspan="2">
						<div id="publishing-action">
							<span class="spinner"></span>
							<?php submit_button( __( 'Save', 'evvnt-calendar' ), 'primary', 'submit', false ); ?>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
