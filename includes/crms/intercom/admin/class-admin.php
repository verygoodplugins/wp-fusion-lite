<?php

class WPF_Intercom_Admin {

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
		add_action( 'show_field_intercom_header_begin', array( $this, 'show_field_intercom_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_initialize_options', array( $this, 'maybe_get_tracking_id' ), 10 );
	}

	/**
	 * Loads Intercom specific settings fields.
	 *
	 * @since 3.40.40
	 *
	 */
	public function register_settings( $settings, $options ) {
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Intercom Site Tracking', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#intercom">Intercom site tracking scripts</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$new_settings['site_tracking_id'] = array(
			'type'    => 'hidden',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}


	/**
	 * Gets and saves tracking ID if site tracking is enabled.
	 *
	 * @since 3.40.40
	 */
	public function maybe_get_tracking_id( $options ) {

		if ( ! empty( $options['site_tracking'] ) && empty( $options['site_tracking_id'] ) ) {

			$this->crm->connect();
			$trackid = $this->crm->get_tracking_id();

			if ( empty( $trackid ) ) {
				return $options;
			}

			$options['site_tracking_id'] = $trackid;
			wp_fusion()->settings->set( 'site_tracking_id', $trackid );

		}

		return $options;

	}


	/**
	 * Loads Intercom connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['intercom_header'] = array(
			'title'   => __( 'Intercom Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['intercom_key'] = array(
			'title'       => __( 'Access Token', 'wp-fusion-lite' ),
			'desc'        => __( 'Enter your Intercom access token. You can generate one in the Developer Hub in your Intercom account <a href="https://app.intercom.com/developers/_" target="_blank">here</a>.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'intercom_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard Intercom field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/intercom-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $intercom_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $intercom_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the Intercom configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_intercom_header_begin( $id, $field ) {

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

		$access_key = sanitize_text_field( wp_unslash( $_POST['intercom_key'] ) );

		$connection = $this->crm->connect( $access_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['intercom_key']          = $access_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}


}
