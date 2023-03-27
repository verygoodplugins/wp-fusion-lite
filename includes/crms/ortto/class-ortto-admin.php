<?php

class WPF_Ortto_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.41.2
	 */
	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.41.2
	 */
	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.41.2
	 */
	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.41.2
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_ortto_header_begin', array( $this, 'show_field_ortto_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.41.2
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );

	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.41.2
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['ortto_header'] = array(
			'title'   => __( 'Ortto Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings[ "{$this->slug}_region" ] = array(
			'title'       => __( 'Account Region', 'wp-fusion-lite' ),
			'desc'        => __( 'The region you chose while signing up.', 'wp-fusion-lite' ),
			'section'     => 'setup',
			'type'        => 'select',
			'placeholder' => __( 'Select Account Region', 'wp-fusion-lite' ),
			'choices'     => array(
				'au'   => 'Australia',
				'eu'   => 'Europe',
				'rest' => 'Rest of the world',
			),
		);

		$new_settings[ "{$this->slug}_api_key" ] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can generate an API key for WP Fusion by following <a target="_blank" href="https://help.ortto.com/user/latest/data-sources/configuring-a-new-data-source/other-integrations/custom-api.html#create-your-custom-api-key">this guide</a>.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( "{$this->slug}_api_key", "{$this->slug}_region" ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since  3.41.2
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] ) {

			require_once dirname( __FILE__ ) . '/ortto-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $ortto_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $ortto_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.41.2
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_ortto_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.41.2
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$region  = isset( $_POST['ortto_region'] ) ? sanitize_text_field( wp_unslash( $_POST['ortto_region'] ) ) : false;
		$api_key = isset( $_POST['ortto_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ortto_api_key'] ) ) : false;

		$connection = $this->crm->connect( $region, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options = array(
				'ortto_api_key'         => $api_key,
				'ortto_region'          => $region,
				'crm'                   => $this->slug,
				'connection_configured' => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

	}


}
