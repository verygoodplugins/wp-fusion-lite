<?php

class WPF_Sendlane_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @since   3.24.0
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_sendlane_header_begin', array( $this, 'show_field_sendlane_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since   3.24.0
	 */
	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ) );
	}


	/**
	 * Loads sendlane connection information on settings page
	 *
	 * @since   3.24.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['sendlane_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( ! empty( $options['connection_configured'] ) ) {

			$new_settings['default_list'] = array(
				'title'       => __( 'Sendlane List', 'wp-fusion-lite' ),
				'desc'        => __( 'Select a default list to use for WP Fusion.', 'wp-fusion-lite' ),
				'type'        => 'select',
				'placeholder' => 'Select list',
				'section'     => 'setup',
				'choices'     => isset( $options['available_lists'] ) ? $options['available_lists'] : array(),
			);

		}

		$new_settings['sendlane_token'] = array(
			'title'       => __( 'Access Token', 'wp-fusion-lite' ),
			'desc'        => __( 'You can create an access token for WP Fusion in the <a href="https://app.sendlane.com/api" target="_blank">API settings</a> of your Sendlane account.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'post_fields' => array( 'sendlane_token' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard Sendlane field names and attempts to match them up with standard local ones.
	 *
	 * @since   3.24.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] ) {

			require_once __DIR__ . '/sendlane-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $sendlane_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $sendlane_fields[ $field ] );
				}
			}
		}

		return $options;
	}

	/**
	 * Puts a div around the sendlane configuration section so it can be toggled
	 *
	 * @since   3.24.0
	 */
	public function show_field_sendlane_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );

		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Verify connection credentials.
	 *
	 * @since 3.24.0
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$api_token = sanitize_text_field( wp_unslash( $_POST['sendlane_token'] ) );

		$connection = $this->crm->connect( $api_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options = array(
				'sendlane_token'        => $api_token,
				'crm'                   => $this->slug,
				'connection_configured' => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
