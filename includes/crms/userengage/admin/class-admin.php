<?php

class WPF_UserEngage_Admin {

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
		add_action( 'show_field_userengage_header_begin', array( $this, 'show_field_userengage_header_begin' ), 10, 2 );

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
	 * Loads UserEngage connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['userengage_header'] = array(
			'title'   => __( 'User.com Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['userengage_domain'] = array(
			'title'   => __( 'App Subdomain', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the subdomain for your User.com account. For example if your app URL is <code>https://verygoodplugins.user.com</code>, your app subdomain would be <code>verygoodplugins</code>.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['userengage_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can find your Public API key in your UserEngage account under Settings &raquo; App Settings &raquo; Advanced &raquo; Public REST API keys.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'userengage_key', 'userengage_domain' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads standard UserEngage field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/userengage-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $userengage_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $userengage_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the UserEngage configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_userengage_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );

		if ( wp_fusion()->crm->slug == 'userengage' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

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

		$domain  = sanitize_text_field( wp_unslash( $_POST['userengage_domain'] ) );
		$api_key = sanitize_text_field( wp_unslash( $_POST['userengage_key'] ) );

		$connection = $this->crm->connect( $domain, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['userengage_key']        = $api_key;
			$options['userengage_domain']     = $domain;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
