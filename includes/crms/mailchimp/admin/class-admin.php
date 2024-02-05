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

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
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

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

		// Add CID back as a field so it can be synced and used with auto login links
		add_filter( 'wpf_meta_fields', array( $this, 'prepare_meta_fields' ), 70 );

	}

	/**
	 * Add CID field for syncing
	 *
	 * @access  public
	 * @return  array Meta fields
	 */

	public function prepare_meta_fields( $meta_fields ) {

		$meta_fields['mailchimp_contact_id'] = array( 'label' => 'Contact ID', 'type' => 'text', 'group' => 'wordpress' );

		return $meta_fields;

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
			'title'   => __( 'MailChimp Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( $settings['connection_configured'] && wpf_get_option( 'crm' ) === $this->slug ) {

			$new_settings['mc_default_list'] = array(
				'title'       => __( 'MailChimp Audience', 'wp-fusion-lite' ),
				'desc'        => __( 'Select an audience to use for WP Fusion. If you change the audience, you\'ll need to click Refresh Available Tags & Fields (below) to update the available dropdown options.', 'wp-fusion-lite' ),
				'type'        => 'select',
				'placeholder' => 'Select Audience',
				'section'     => 'setup',
				'choices'     => isset( $options['mc_lists'] ) ? $options['mc_lists'] : array(),
			);

		}

		$new_settings['mailchimp_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can create an API key by navigating to Account &raquo; Extras &raquo; API Keys.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'mailchimp_key', 'mc_default_list' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads MailChimp specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$new_settings = array(
			'mc_optin' => array(
				'title'   => __( 'Double Optin', 'wp-fusion-lite' ),
				'desc'    => __( 'Send an optin confirmation email from MailChimp when new subscribers are added to your audience.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'std'     => false,
				'section' => 'main',
			),
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
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$api_key      = sanitize_text_field( $_POST['mailchimp_key'] );
		$key_exploded = explode( '-', $api_key );
		$data_server  = $key_exploded[1];

		// Maybe they've changed the list before clicking test.

		if ( ! empty( $_POST['mc_default_list'] ) && wpf_get_option( 'mc_default_list' ) !== $_POST['mc_default_list'] ) {

			wp_fusion()->settings->set( 'mc_default_list', sanitize_text_field( wp_unslash( $_POST['mc_default_list'] ) ) );

		}

		$connection = $this->crm->connect( $data_server, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['mailchimp_dc']          = $data_server;
			$options['mailchimp_key']         = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

	}

}