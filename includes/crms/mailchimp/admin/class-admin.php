<?php

class WPF_MailChimp_Admin {

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
		add_action( 'show_field_mailchimp_header_begin', array( $this, 'show_field_mailchimp_header_begin' ), 10, 2 );
		add_action( 'show_field_mailchimp_key_end', array( $this, 'show_field_mailchimp_key_end' ), 10, 2 );

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
	 * Loads MailChimp connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mailchimp_header'] = array(
			'title'   => __( 'MailChimp Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['mailchimp_dc'] = array(
			'title'       => __( 'Data Server', 'wp-fusion' ),
			'desc'        => __( 'Your data server is the first part of your MailChimp account URL (like "us1").', 'wp-fusion' ),
			'type'        => 'text',
			'section'     => 'setup',
		);

		$new_settings['mailchimp_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'You can create an API key by navigating to Account &raquo; Extras &raquo; API Keys.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'mailchimp_key', 'mailchimp_dc' )
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

		$new_settings['mc_default_list'] = array(
			'title'       => __( 'MailChimp List', 'wp-fusion' ),
			'desc'        => __( 'Select a list to use for WP Fusion. If you change the list, you may need to Resynchronize from the Setup tab to update your fields and tags.', 'wp-fusion' ),
			'type'        => 'select',
			'placeholder' => 'Select list',
			'section'     => 'main',
			'choices'     => $options['mc_lists']
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard MailChimp field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/mailchimp-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $mailchimp_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $mailchimp_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Puts a div around the MailChimp configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailchimp_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out mailchimp section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_mailchimp_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #MailChimp div
		echo '<table class="form-table">';



	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$data_server 	= sanitize_text_field( $_POST['mailchimp_dc'] );
		$api_key 		= sanitize_text_field( $_POST['mailchimp_key'] );

		$connection = $this->crm->connect( $data_server, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['mailchimp_dc']          = $data_server;
			$options['mailchimp_key']         = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}