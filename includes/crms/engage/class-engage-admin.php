<?php

class WPF_Engage_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.40.42
	 */
	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.40.42
	 */
	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.40.42
	 */
	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.40.42
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_engage_header_begin', array( $this, 'show_field_engage_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.40.42
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );

	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.40.42
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['engage_header'] = array(
			'title'   => __( 'Engage Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'desc'    => __( 'You can find your API Keys in <a href="https://app.engage.so/settings/account" target="_blank">Settings -> Account</a> on your Engage dashboard.', 'wp-fusion-lite' ),
			'section' => 'setup',
		);

		$new_settings['engage_username'] = array(
			'title'   => __( 'Public Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the Public Key for your Engage account.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['engage_password'] = array(
			'title'       => __( 'Private Key', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'desc'		  => __( 'Enter the Private Key for your Engage account.', 'wp-fusion-lite' ),
			'post_fields' => array( 'engage_username', 'engage_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since 3.40.42
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		$standard_fields = $this->crm->get_default_fields();

		foreach ( $options['contact_fields'] as $field => $data ) {

			if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.40.42
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_engage_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.40.42
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$engage_username = sanitize_text_field( wp_unslash( $_POST['engage_username'] ) );
		$engage_password = sanitize_text_field( wp_unslash( $_POST['engage_password'] ) );

		$connection = $this->crm->connect( $engage_username, $engage_password, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options                          = array();
			$options['engage_username']       = $engage_username;
			$options['engage_password']       = $engage_password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

	}


}
