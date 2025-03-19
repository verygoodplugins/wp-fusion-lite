<?php

class WPF_Flexie_Admin {

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
		add_action( 'show_field_flexie_header_begin', array( $this, 'show_field_flexie_header_begin' ), 10, 2 );

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
	 * Loads flexie connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['flexie_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
			'desc'    => __( 'Before attempting to connect to Flexie, you\'ll first need to enable API access. You can do this by going to the configuration screen, and selecting API Settings. Turn both <strong>API Enabled</strong> and <strong>Enable Basic HTTP Auth</strong> to On.', 'wp-fusion-lite' ),
		);

		$new_settings['flexie_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the URL for your Flexie account (like http://website.flexie.io/).', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['flexie_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can find your API key in the account settings sidebar under API settings in your Flexie account.' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'flexie_key', 'flexie_url' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads standard flexie field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['user_email']['crm_field'] ) ) {
			$options['contact_fields']['user_email']['crm_field'] = 'email';
		}

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['first_name']['crm_field'] ) ) {
			$options['contact_fields']['first_name']['crm_field'] = 'firstname';
		}

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['last_name']['crm_field'] ) ) {
			$options['contact_fields']['last_name']['crm_field'] = 'lastname';
		}

		return $options;
	}


	/**
	 * Puts a div around the Flexie configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_flexie_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Close out Flexie section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_flexie_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . esc_html( $field['desc'] ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #flexie div

		// Hide Import tab (for now)
		if ( wp_fusion()->crm->slug == 'flexie' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

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

		$flexie_url = isset( $_POST['flexie_url'] ) ? esc_url_raw( wp_unslash( $_POST['flexie_url'] ) ) : false;
		$api_key    = isset( $_POST['flexie_key'] ) ? sanitize_text_field( wp_unslash( $_POST['flexie_key'] ) ) : false;

		$connection = $this->crm->connect( $flexie_url, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['flexie_url']            = $flexie_url;
			$options['flexie_key']            = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
