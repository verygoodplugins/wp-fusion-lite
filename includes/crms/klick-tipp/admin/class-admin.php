<?php

class WPF_KlickTipp_Admin {

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
		add_action( 'show_field_klicktipp_header_begin', array( $this, 'show_field_klicktipp_header_begin' ), 10, 2 );

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
	 * Loads Klick-Tipp connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['klicktipp_header'] = array(
			'title'   => __( 'Klick-Tipp Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['klicktipp_user'] = array(
			'title'   => __( 'Username', 'wp-fusion-lite' ),
			'desc'    => __( 'Your Klick-Tipp username.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['klicktipp_pass'] = array(
			'title'       => __( 'Password', 'wp-fusion-lite' ),
			'desc'        => __( 'Your Klick-Tipp password.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'password'    => true,
			'post_fields' => array( 'klicktipp_user', 'klicktipp_pass' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads Klick-Tipp specific settings fields
	 *
	 * @since 3.40.41
	 */
	public function register_settings( $settings, $options ) {

		if ( empty( wpf_get_option( 'double_optin_processes' ) ) ) {
			return $settings;
		}

		$new_settings = array(
			'kt_double_optin_id' => array(
				'title'   => __( 'Double Optin', 'wp-fusion-lite' ),
				'desc'    => __( 'Select the double optin process that the user will go through.', 'wp-fusion-lite' ),
				'type'    => 'select',
				'std'     => key( wpf_get_option( 'double_optin_processes' ) ),
				'choices' => wpf_get_option( 'double_optin_processes' ),
				'section' => 'main',
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard Klick-Tipp field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/klick-tipp-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $klicktipp_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $klicktipp_fields[ $field ] );
				}
			}
		}

		return $options;

	}



	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_klicktipp_header_begin( $id, $field ) {

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

		$username = sanitize_text_field( $_POST['klicktipp_user'] );
		$password = sanitize_text_field( $_POST['klicktipp_pass'] );

		$connection = $this->crm->connect( $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['klicktipp_user']        = $username;
			$options['klicktipp_pass']        = $password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}

}
