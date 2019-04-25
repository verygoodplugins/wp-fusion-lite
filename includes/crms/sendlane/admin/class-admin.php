<?php

class WPF_Sendlane_Admin {

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
		add_action( 'show_field_sendlane_header_begin', array( $this, 'show_field_sendlane_header_begin' ), 10, 2 );
		add_action( 'show_field_sendlane_domain_end', array( $this, 'show_field_sendlane_domain_end' ), 10, 2 );

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

	}


	/**
	 * Loads sendlane connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['sendlane_header'] = array(
			'title'   => __( 'Sendlane Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['sendlane_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'You can find your API key in the <a href="https://app.sendlane.com/developer" target="_blank">developer settings</a> of your Sendlane account.', 'wp-fusion' ),
			'type'        => 'text',
			'section'     => 'setup',
		);

		$new_settings['sendlane_hash'] = array(
			'title'       => __( 'API Hash Key', 'wp-fusion' ),
			'type'        => 'text',
			'section'     => 'setup',
		);

		$new_settings['sendlane_domain'] = array(
			'title'       => __( 'Subdomain', 'wp-fusion' ),
			'type'        => 'api_validate',
			'desc'        => __( 'For example domain.sendlane.com', 'wp-fusion' ),
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'sendlane_key', 'sendlane_hash', 'sendlane_domain' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads ActiveCampaign specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		if( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['default_list'] = array(
			'title'       => __( 'Sendlane List', 'wp-fusion' ),
			'desc'        => __( 'Select a default list to use for WP Fusion.', 'wp-fusion' ),
			'type'        => 'select',
			'placeholder' => 'Select list',
			'section'     => 'main',
			'choices'     => $options['available_lists']
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;

	}



	/**
	 * Loads standard Sendlane field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/sendlane-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $sendlane_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $sendlane_fields[ $field ] );
				}

			}

		}

		return $options;

	}

	/**
	 * Puts a div around the sendlane configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_sendlane_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out mailerlight section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_sendlane_domain_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';

		if( wp_fusion()->crm->slug == 'sendlane' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

		echo '</div>'; // close #sendlane div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_key = sanitize_text_field( $_POST['sendlane_key'] );
		$api_hash = sanitize_text_field( $_POST['sendlane_hash'] );
		$api_domain = sanitize_text_field( $_POST['sendlane_domain'] );

		$connection = $this->crm->connect( $api_key, $api_hash, $api_domain, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['sendlane_key']          = $api_key;
			$options['sendlane_hash']         = $api_hash;
			$options['sendlane_domain']       = $api_domain;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}