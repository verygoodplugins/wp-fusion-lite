<?php

class WPF_Kartra_Admin {

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

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_kartra_header_begin', array( $this, 'show_field_kartra_header_begin' ), 10, 2 );

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
	}


	/**
	 * Loads Kartra connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['kartra_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['kartra_api_key'] = array(
			'title'   => __( 'API Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the API Key for your Kartra account. You can find your key under <a href="https://app.kartra.com/integrations/api/key" target="_blank">the My Integrations menu</a>.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['kartra_api_password'] = array(
			'title'       => __( 'API Password', 'wp-fusion-lite' ),
			'desc'        => __( 'The API password will be displayed next to your API Key.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'kartra_api_key', 'kartra_api_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Adds Kartra list fields option
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		if ( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['kartra_lists'] = array(
			'title'       => __( 'Lists', 'wp-fusion-lite' ),
			'desc'        => __( 'New users will be automatically added to the selected lists.', 'wp-fusion-lite' ),
			'type'        => 'multi_select',
			'placeholder' => 'Select lists',
			'section'     => 'main',
			'choices'     => $options['available_lists'],
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		if ( ! isset( $settings['create_users']['unlock']['kartra_lists'] ) ) {
			$settings['create_users']['unlock'][] = 'kartra_lists';
		}

		$settings['kartra_lists']['disabled'] = ( wpf_get_option( 'create_users' ) == 0 ? true : false );

		return $settings;
	}


	/**
	 * Loads standard Kartra field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/kartra-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $kartra_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $kartra_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Kartra configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_kartra_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );

		// Hide Import tab (for now)
		if ( wp_fusion()->crm->slug == 'kartra' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

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

		$api_key      = sanitize_text_field( $_POST['kartra_api_key'] );
		$api_password = sanitize_text_field( $_POST['kartra_api_password'] );

		$connection = $this->crm->connect( $api_key, $api_password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['kartra_api_key']        = $api_key;
			$options['kartra_api_password']   = $api_password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
