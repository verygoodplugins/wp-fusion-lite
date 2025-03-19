<?php

class WPF_Mailjet_Admin {

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
		add_action( 'show_field_mailjet_header_begin', array( $this, 'show_field_mailjet_header_begin' ), 10, 2 );

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
	}


	/**
	 * Loads mailjet connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mailjet_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'desc'    => __( 'You can find your API Key and Secret Key in the <a href="https://app.mailjet.com/account/api_keys" target="_blank">API key management</a> section of your Mailjet account.', 'wp-fusion-lite' ),
			'section' => 'setup',
		);

		$new_settings['mailjet_username'] = array(
			'title'   => __( 'API Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the API Key for your Mailjet account.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['mailjet_password'] = array(
			'title'       => __( 'Secret Key', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'desc'        => __( 'Enter the Secret Key for your Mailjet account.', 'wp-fusion-lite' ),
			'post_fields' => array( 'mailjet_username', 'mailjet_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads standard Mailjet field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/mailjet-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $mailjet_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $mailjet_fields[ $field ] );
				}
			}
		}

		return $options;
	}

	/**
	 * Puts a div around the mailjet configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailjet_header_begin( $id, $field ) {

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

		$mailjet_username = sanitize_text_field( wp_unslash( $_POST['mailjet_username'] ) );
		$mailjet_password = sanitize_text_field( wp_unslash( $_POST['mailjet_password'] ) );

		$connection = $this->crm->connect( $mailjet_username, $mailjet_password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['mailjet_username']      = $mailjet_username;
			$options['mailjet_password']      = $mailjet_password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
