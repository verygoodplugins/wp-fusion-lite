<?php

class WPF_Groundhogg_Admin {

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
		add_action( 'show_field_groundhogg_header_begin', array( $this, 'show_field_groundhogg_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ), 10, 2 );
	}

	/**
	 * Loads groundhogg connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['groundhogg_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['groundhogg_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'groundhogg_connect' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Set up GH specific settings (Logins Tags Sync and Login Meta Sync aren't necessary with GH since changes are communicated in real time)
	 *
	 * @access  public
	 * @return  array Settings
	 */

	public function configure_settings( $settings, $options ) {

		unset( $settings['login_sync'] );
		unset( $settings['login_meta_sync'] );

		$new_settings = array(
			'gh_default_status' => array(
				'title'   => __( 'Default Status', 'wp-fusion-lite' ),
				'desc'    => __( 'Select a default optin status for new contacts.', 'wp-fusion-lite' ),
				'type'    => 'select',
				'std'     => 2,
				'section' => 'main',
				'choices' => array(
					2 => 'Confirmed',
					1 => 'Unconfimed',
					3 => 'Unsubscribed',
					4 => 'Weekly',
					5 => 'Monthly',
				),
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard Groundhogg field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/groundhogg-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $groundhogg_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $groundhogg_fields[ $field ] );
				}
			}
		}

		return $options;
	}

	/**
	 * Puts a div around the groundhogg configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_groundhogg_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
		echo '<style>#groundhogg_connect {display: none;}</style>';
	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$connection = $this->crm->connect( true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
