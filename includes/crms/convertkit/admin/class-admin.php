<?php

class WPF_ConvertKit_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_convertkit_header_begin', array( $this, 'show_field_convertkit_header_begin' ), 10, 2 );
		add_action( 'show_field_ck_secret_end', array( $this, 'show_field_ck_secret_end' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function init() {

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_filter( 'validate_field_ck_update_tag', array( $this, 'validate_webhooks' ), 10, 2 );
		add_filter( 'validate_field_ck_add_tag', array( $this, 'validate_webhooks' ), 10, 2 );
		add_filter( 'validate_field_ck_notify_unsubscribe', array( $this, 'validate_unsubscribe_webhook' ), 10, 2 );

	}


	/**
	 * Loads ConvertKit connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['convertkit_header'] = array(
			'title'   => __( 'ConvertKit Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['ck_secret'] = array(
			'title'       => __( 'API Secret', 'wp-fusion' ),
			'desc'        => __( 'Enter the API Secret for your ConvertKit account (you can find your API Secret in the ConvertKit Account page).', 'wp-fusion' ),
			'std'         => '',
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'ck_secret' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads ConvertKit specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$settings['access_key_desc'] = array(
			'std'     => 0,
			'type'    => 'paragraph',
			'section' => 'main',
			'desc'    => __( 'Configuring the fields below allows ConvertKit to add new users to your site and update existing users when specific tags are applied from within ConvertKit. Read our <a href="https://wpfusion.com/documentation/webhooks/convertkit-webhooks/" target="_blank">documentation</a> for more information.', 'wp-fusion' ),
		);

		$new_settings = array();

		$new_settings['ck_update_tag'] = array(
			'title' 	=> __( 'Update Trigger', 'wp-fusion' ),
			'desc'		=> __( 'When this tag is applied to a contact in ConvertKit, their tags and meta data will be updated in WordPress.', 'wp-fusion' ),
			'type'		=> 'assign_tags',
			'section'	=> 'main',
			'placeholder' => 'Select a tag',
			'action'	=> 'update',
			'limit'		=> 1
			);

		$new_settings['ck_update_tag_rule_id'] = array(
			'std'		=> false,
			'type'		=> 'hidden',
			'section'	=> 'main'
			);

		$new_settings['ck_add_tag'] = array(
			'title' 	=> __( 'Import Trigger', 'wp-fusion' ),
			'desc'		=> __( 'When this tag is applied to a contact in ConvertKit, they will be imported as a new WordPres user.', 'wp-fusion' ),
			'type'		=> 'assign_tags',
			'section'	=> 'main',
			'placeholder' => 'Select a tag',
			'action'	=> 'add',
			'limit'		=> 1
			);

		$new_settings['ck_add_tag_rule_id'] = array(
			'std'		=> false,
			'type'		=> 'hidden',
			'section'	=> 'main'
			);

		$new_settings['ck_import_notification'] = array(
			'title'   => __( 'Enable Notifications', 'wp-fusion' ),
			'desc'    => __( 'Send a welcome email to new users containing their username and a password reset link.', 'wp-fusion' ),
			'type'    => 'checkbox',
			'section' => 'main',
			'std'	  => 0
			);

		$settings = wp_fusion()->settings->insert_setting_after( 'access_key', $settings, $new_settings );

		$new_settings = array();

		$new_settings['ck_header'] = array(
			'title'   => __( 'ConvertKit Settings', 'wp-fusion' ),
			'type'    => 'heading',
			'section' => 'advanced'
		);

		$new_settings['ck_notify_unsubscribe'] = array(
			'title' 	=> __( 'Notify on Unsubscribe', 'wp-fusion' ),
			'desc'		=> __( 'Send a notification email when a subscriber with a WordPress user account unsubscribes. See <a href="https://wpfusion.com/documentation/crm-specific-docs/convertkit-unsubscribe-notifications/">the documentation</a> for more info.', 'wp-fusion' ),
			'type'		=> 'checkbox',
			'section'	=> 'advanced',
			'std'		=> 0,
			'unlock'	=> array( 'ck_notify_email' ),
			'action'    => 'unsubscribe',
		);

		$new_settings['ck_unsubscribe_rule_id'] = array(
			'std'		=> false,
			'type'		=> 'hidden',
			'section'	=> 'advanced'
			);

		$new_settings['ck_notify_email'] = array(
			'title' 	=> __( 'Notification Email', 'wp-fusion' ),
			'desc'		=> __( 'The notification will be sent to this email.', 'wp-fusion' ),
			'type'		=> 'text',
			'section'	=> 'advanced',
			'std'		=> get_option( 'admin_email' ),
			'disabled'  => ( isset( $options['ck_notify_unsubscribe'] ) && $options['ck_notify_unsubscribe'] == true ? false : true ),
		);

		$settings = wp_fusion()->settings->insert_setting_before( 'advanced_header', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */

	public function validate_webhooks( $input, $setting ) {

		$type = $setting['action'];
		$tag = 0;
		$prev_tag = 0;

		if(is_array($input)) {
			$tag = $input[0];
		}

		$prev_value = wp_fusion()->settings->get('ck_' . $type .'_tag');

		if(is_array($prev_value)) {
			$prev_tag = $prev_value[0];
		}

		// If no changes have been made, quit early
		if($tag == $prev_tag) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wp_fusion()->settings->get('ck_' . $type .'_tag_rule_id');


		if(!empty($rule_id)) {
			wp_fusion()->crm->destroy_webhook($rule_id);
			add_filter( 'validate_field_ck_' . $type . '_tag_rule_id', function() { return false; } );
		}

		// Abort if tag has been removed and no new one provided
		if(empty($tag)) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook($type, $tag);

		// If there was an error, make the user select the tag again
		if($rule_id == false)
			return false;

		add_filter( 'validate_field_ck_' . $type . '_tag_rule_id', function() use (&$rule_id) { return $rule_id; } );

		return $input;

	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */

	public function validate_unsubscribe_webhook( $input, $setting ) {

		$prev_value = wp_fusion()->settings->get( 'ck_notify_unsubscribe' );

		// If no changes have been made, quit early
		if ( $input == $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wp_fusion()->settings->get( 'ck_unsubscribe_rule_id' );

		if ( ! empty( $rule_id ) ) {

			wp_fusion()->crm->destroy_webhook( $rule_id );

			add_filter( 'validate_field_ck_unsubscribe_rule_id', function() {
				return false;
			} );

		}

		// Abort if setting is switched off
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook( 'unsubscribe', false );

		// If there was an error, make the user select the option again
		if ( false == $rule_id ) {
			return false;
		}

		add_filter( 'validate_field_ck_unsubscribe_rule_id', function() use ( &$rule_id ) {
			return $rule_id;
		} );

		return $input;

	}


	/**
	 * Loads standard ConvertKit field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/convertkit-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $convertkit_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $convertkit_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Puts a div around the ConvertKit configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_convertkit_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out Active Campaign section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_ck_secret_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #convertkit div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_secret = sanitize_text_field( $_POST['ck_secret'] );

		$connection = $this->crm->connect( $api_secret, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['ck_secret']             = $api_secret;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}