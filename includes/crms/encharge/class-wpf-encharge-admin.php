<?php

class WPF_Encharge_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.43.10
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.43.10
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.43.10
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.43.10
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_encharge_header_begin', array( $this, 'show_field_encharge_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run in the admin when this CRM is selected as active.
	 *
	 * @since 3.43.10
	 */
	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ) );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
	}

	/**
	 * Loads CRM connection information on settings page.
	 *
	 * @since 3.43.10
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['encharge_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['encharge_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'Your API key, you can get it from <a target="_blank" href="https://app.encharge.io/account/info">here</a>.', 'wp-fusion-lite' ),
			'section'     => 'setup',
			'class'       => 'api_key',
			'type'        => 'api_validate', // api_validate field type causes the Test Connection button to be appended to the input.
			'post_fields' => array( 'encharge_key' ), // This tells us which settings fields need to be POSTed when the Test Connection button is clicked.
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads Bento specific settings fields
	 *
	 * @access  public
	 * @since 3.43.10
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Encharge Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://app.encharge.io/settings/site-tracking">Encharge site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_write_key'] = array(
			'title'   => __( 'Write Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Your JavaScript Write Key, it can be found <a target="_blank" href="https://app.encharge.io/account/info">here</a>', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}



	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since  3.43.10
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
	 * @since 3.43.10
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 */
	public function show_field_encharge_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( false === $crm || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.43.10
	 *
	 * @return void
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$api_key = sanitize_text_field( wp_unslash( $_POST['encharge_key'] ) );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.

			$options = array(
				'encharge_key'          => $api_key,
				'crm'                   => $this->slug,
				'connection_configured' => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}
	}
}
