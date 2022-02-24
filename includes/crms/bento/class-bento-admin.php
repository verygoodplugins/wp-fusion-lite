<?php

class WPF_Bento_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.37.31
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.37.31
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.37.31
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.37.31
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_bento_header_begin', array( $this, 'show_field_bento_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.37.31
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );

	}


	/**
	 * Loads Bento specific settings fields
	 *
	 * @access  public
	 * @since 3.37.31
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Bento Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://bentonow.com/docs/bento-js-sdk">Bento site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.37.31
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['bento_header'] = array(
			'title'   => __( 'Bento CRM Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['site_uuid'] = array(
			'title'   => __( 'Site Unique ID', 'wp-fusion-lite' ),
			'desc'    => __( 'Your site unique ID can be found in your Bento account under Settings &raquo; Site Settings &raquo; <strong>Site UUID</strong>.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['bento_api_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'Your API key can be found in your Bento account under Settings &raquo; Get your API key here &raquo; <strong>API Key (Combined)</strong>.', 'wp-fusion-lite' ),
			// https://app.bentonow.com/account/users/709/api_keys.
			'section'     => 'setup',
			'class'       => 'api_key',
			'type'        => 'api_validate', // api_validate field type causes the Test Connection button to be appended to the input.
			'post_fields' => array( 'bento_api_key', 'site_uuid' ), // This tells us which settings fields need to be POSTed when the Test Connection button is clicked.
		);

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
	 * @since  3.37.14
	 */

	public function add_default_fields( $options ) {

		require dirname( __FILE__ ) . '/bento-fields.php';

		foreach ( $options['contact_fields'] as $field => $data ) {

			if ( isset( $bento_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $bento_fields[ $field ] );
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.37.31
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_bento_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		// Hide Import tab (for now)
		if ( wp_fusion()->crm->slug == 'bento' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.37.31
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		$api_key    = sanitize_text_field( $_POST['bento_api_key'] );
		$site_uuid  = sanitize_text_field( $_POST['site_uuid'] );
		$connection = $this->crm->connect( $site_uuid, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options = array(
				'bento_api_key'         => $api_key,
				'site_uuid'             => $site_uuid,
				'crm'                   => $this->slug,
				'connection_configured' => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();
		}
	}

}

