<?php

class WPF_WP_ERP_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access public
	 * @since 3.33
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_wp_erp_header_begin', array( $this, 'show_field_wp_erp_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );

	}

	/**
	 * Loads wp-erp connection information on settings page
	 *
	 * @access public
	 * @return array Settings
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['wp_erp_header'] = array(
			'title'   => __( 'WP ERP Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['wp_erp_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'wp_erp_connect' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard WP_ERP field names and attempts to match them up with standard local ones
	 *
	 * @access public
	 * @return array Options
	 */

	public function add_default_fields( $options ) {

		if ( true == $options['connection_configured'] ) {

			require_once dirname( __FILE__ ) . '/wp-erp-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $fields[ $field ] );
				}
			}
		}

		return $options;

	}

	/**
	 * Puts a div around the wp-erp configuration section so it can be toggled
	 *
	 * @access public
	 * @return mixed HTML Output
	 */

	public function show_field_wp_erp_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
		echo '<style>#wp_erp_connect {display: none;}</style>';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return void
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
