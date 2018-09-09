<?php

class WPF_Nimble_Admin {

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
		add_action( 'show_field_nimble_header_begin', array( $this, 'show_field_nimble_header_begin' ), 10, 2 );
		add_action( 'show_field_op_key_end', array( $this, 'show_field_op_key_end' ), 10, 2 );

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

		//add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );

	}


	/**
	 * Loads nimble connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['nimble_header'] = array(
			'title'   => __( 'Nimble Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		// $new_settings['op_url'] = array(
		// 	'title'   => __( 'App ID', 'wp-fusion' ),
		// 	'desc'    => __( 'Enter the App ID for your Nimble account (find it under Settings >> Administration >> Nimble API in your account).', 'wp-fusion' ),
		// 	'std'     => '',
		// 	'type'    => 'text',
		// 	'section' => 'setup'
		// );

		$new_settings['nimble_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'The API key will appear next to the App ID on the API Keys page.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'nimble_key' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	// /**
	//  * Loads standard nimble field names and attempts to match them up with standard local ones
	//  *
	//  * @access  public
	//  * @since   1.0
	//  */

	// public function add_default_fields( $options ) {

	// 	if ( $options['connection_configured'] == true ) {

	// 		require_once dirname( __FILE__ ) . '/nimble-fields.php';

	// 		foreach ( $options['contact_fields'] as $field => $data ) {

	// 			if ( isset( $nimble_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
	// 				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $nimble_fields[ $field ] );
	// 			}

	// 		}

	// 	}

	// 	return $options;

	// }


	/**
	 * Puts a div around the nimble configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_nimble_header_begin( $id, $field ) {

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


	public function show_field_op_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #nimble div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_key = sanitize_text_field( $_POST['nimble_key'] );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['nimble_key']            = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}