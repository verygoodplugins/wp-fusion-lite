<?php

class WPF_Emercury_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.37.8
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.37.8
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.37.8
	 */

	private $crm;

	/**
	 * Get things started
	 *
	 * @since 3.37.8
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_emercury_header_begin', array( $this, 'show_field_emercury_header_begin' ), 10, 2 );

		// AJAX callback to test the connection
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.37.8
	 */

	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active
		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.37.8
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['emercury_header'] = array(
			'title'   => __( 'Emercury CRM Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['emercury_email'] = array(
			'title'   => __( 'Email', 'wp-fusion-lite' ),
			'desc'    => __( 'Your Emercury CRM account email.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['emercury_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'Your API key. You can generate an API key in the <a href="https://panel.emercury.net/#settings/developer/" target="_blank">Developer Settings</a> panel in your Emercury account.', 'wp-fusion-lite' ),
			'section'     => 'setup',
			'class'       => 'api_key',
			'type'        => 'api_validate', // api_validate field type causes the Test Connection button to be appended to the input.
			'post_fields' => array( 'emercury_email', 'emercury_key' ), // This tells us which settings fields need to be POSTed when the Test Connection button is clicked.
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		if ( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['emercury_list_header'] = array(
			'title'   => __( 'Emercury Lists', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$new_settings['emercury_list'] = array(
			'title'       => __( 'Lists', 'wp-fusion-lite' ),
			'desc'        => __( 'Select a default list to use for WP Fusion.', 'wp-fusion-lite' ),
			'type'        => 'select',
			'placeholder' => 'Select list',
			'section'     => 'main',
			'choices'     => $options['available_lists'],
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'general_desc', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard Emercury field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/emercury-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $emercury_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $emercury_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.37.8
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */

	public function show_field_emercury_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.37.8
	 *
	 * @return mixed JSON response.
	 */

	public function test_connection() {

		$api_key   = sanitize_text_field( $_POST['emercury_key'] );
		$api_email = sanitize_email( $_POST['emercury_email'] );

		$connection = $this->crm->connect( $api_email, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed

			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials

			$options                          = wp_fusion()->settings->get_all();
			$options['emercury_key']          = $api_key;
			$options['emercury_email']        = $api_email;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
