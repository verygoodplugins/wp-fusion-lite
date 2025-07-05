<?php

class WPF_MooSend_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.38.42
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.38.42
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.38.42
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.38.42
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_moosend_header_begin', array( $this, 'show_field_moosend_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.38.42
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.38.42
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['moosend_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['moosend_api_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'Your API key can be found in your MooSend account under Settings.', 'wp-fusion-lite' ),
			'section'     => 'setup',
			'class'       => 'api_key',
			'type'        => 'api_validate', // api_validate field type causes the Test Connection button to be appended to the input.
			'post_fields' => array( 'moosend_api_key' ), // This tells us which settings fields need to be POSTed when the Test Connection button is clicked.
		);

		if ( $settings['connection_configured'] && wpf_get_option( 'crm' ) === $this->slug ) {

			$new_settings['moosend_default_list'] = array(
				'title'       => __( 'MooSend Mailing List', 'wp-fusion-lite' ),
				'desc'        => __( 'Select a mailing list to use for WP Fusion. If you change the mailing list, you\'ll need to click Refresh Available Tags & Fields (above) to update the available dropdown options.', 'wp-fusion-lite' ),
				'type'        => 'select',
				'placeholder' => 'Select Mailing List',
				'section'     => 'setup',
				'choices'     => isset( $options['moosend_list'] ) ? $options['moosend_list'] : array(),
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}




	/**
	 * Loads standard Autonami_REST field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @param array $options The options.
	 *
	 * @return array The options.
	 * @since 3.38.42
	 */
	public function add_default_fields( $options ) {

		require __DIR__ . '/moosend-fields.php';

		foreach ( $options['contact_fields'] as $field => $data ) {

			if ( isset( $moosend_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $moosend_fields[ $field ] );
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.38.42
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_moosend_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		// Hide Import tab (for now)
		if ( wp_fusion()->crm->slug == 'moosend' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.38.42
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		$api_key    = sanitize_text_field( $_POST['moosend_api_key'] );
		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options = array(
				'moosend_api_key'       => $api_key,
				'crm'                   => $this->slug,
				'connection_configured' => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();
		}
	}
}
