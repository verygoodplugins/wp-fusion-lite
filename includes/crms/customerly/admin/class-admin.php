<?php

class WPF_Customerly_Admin {

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
		add_action( 'show_field_customerly_header_begin', array( $this, 'show_field_customerly_header_begin' ), 10, 2 );
		add_action( 'show_field_customerly_key_end', array( $this, 'show_field_customerly_key_end' ), 10, 2 );

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
	 * Loads Customerly connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['customerly_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['customerly_key'] = array(
			'title'       => __( 'Access Token', 'wp-fusion-lite' ),
			'desc'        => __( 'You can create an access token by going to App Settings &raquo; Integrations &raquo; API in your Customerly account.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'customerly_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard Customerly field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/customerly-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $customerly_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $customerly_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Customerly configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_customerly_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Close out Customerly section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_customerly_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . esc_html( $field['desc'] ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		if ( wp_fusion()->crm->slug == 'customerly' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #customerly div
		echo '<table class="form-table">';
	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$access_key = isset( $_POST['customerly_key'] ) ? sanitize_text_field( wp_unslash( $_POST['customerly_key'] ) ) : false;

		$connection = $this->crm->connect( $access_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['customerly_key']        = $access_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
