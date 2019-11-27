<?php

class WPF_GetResponse_Admin {

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
		add_action( 'show_field_getresponse_header_begin', array( $this, 'show_field_getresponse_header_begin' ), 10, 2 );
		add_action( 'show_field_getresponse_key_end', array( $this, 'show_field_getresponse_key_end' ), 10, 2 );

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
	}


	/**
	 * Loads GetResponse connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		if( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['getresponse_list'] = array(
			'title'       => __( 'List', 'wp-fusion' ),
			'desc'        => __( 'New users will be automatically added to the selected list.', 'wp-fusion' ),
			'type'        => 'select',
			'placeholder' => 'Select list',
			'section'     => 'main',
			'choices'     => $options['available_lists'],
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		if ( ! isset( $settings['create_users']['unlock']['getresponse_list'] ) ) {
			$settings['create_users']['unlock'][] = 'getresponse_list';
		}

		$settings['getresponse_list']['disabled'] = ( wp_fusion()->settings->get( 'create_users' ) == 0 ? true : false );

		$new_settings['getresponse_header'] = array(
			'title'   => __( 'GetResponse Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['getresponse_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'You can find your API key in the <a href="https://app.getresponse.com/api/" target="_blank">API settings</a> of your GetResponse account.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'getresponse_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */

	public function validate_import_trigger( $input, $setting ) {

		$prev_value = wp_fusion()->settings->get( 'getresponse_add_tag' );

		// If no changes have been made, quit early
		if ( $input == $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wp_fusion()->settings->get( 'getresponse_add_tag_rule_id' );

		if ( ! empty( $rule_id ) ) {
			wp_fusion()->crm->destroy_webhook( $rule_id );
			add_filter(
				'validate_field_getresponse_add_tag_rule_id', function() {
					return false;
				}
			);
		}

		// Abort if tag has been removed and no new one provided
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook( 'add' );

		// If there was an error, make the user select the tag again
		if ( $rule_id == false ) {
			return false;
		}

		add_filter(
			'validate_field_getresponse_add_tag_rule_id', function() use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;

	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */

	public function validate_update_trigger( $input, $setting ) {

		$prev_value = wp_fusion()->settings->get( 'getresponse_update_trigger' );

		// If no changes have been made, quit early
		if ( $input == $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wp_fusion()->settings->get( 'getresponse_update_trigger_rule_id' );

		if ( ! empty( $rule_id ) ) {
			wp_fusion()->crm->destroy_webhook( $rule_id );
			add_filter(
				'validate_field_getresponse_update_trigger_rule_id', function() {
					return false;
				}
			);
		}

		// Abort if tag has been removed and no new one provided
		if ( $input == false ) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook( 'update' );

		// If there was an error, make the user select the tag again
		if ( $rule_id == false ) {
			return false;
		}

		add_filter(
			'validate_field_getresponse_update_trigger_rule_id', function() use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;

	}


	/**
	 * Loads standard GetResponse field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/getresponse-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $getresponse_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $getresponse_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the GetResponse configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_getresponse_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out getresponse section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_getresponse_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		if ( wp_fusion()->crm->slug == 'getresponse' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}
		echo '</div>'; // close #GetResponse div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_key = sanitize_text_field( $_POST['getresponse_key'] );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['getresponse_key']       = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}
