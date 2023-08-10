<?php

class WPF_SendinBlue_Admin {

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
		add_action( 'show_field_sendinblue_header_begin', array( $this, 'show_field_sendinblue_header_begin' ), 10, 2 );

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
	 * Loads sendinblue connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['sendinblue_header'] = array(
			'title'   => __( 'Brevo Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['sendinblue_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can find your API v3 key in the <a href="https://account.brevo.com/advanced/api/" target="_blank">API settings</a> of your Brevo account.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'sendinblue_key' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard Brevo field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/sendinblue-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $sendinblue_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $sendinblue_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Add Sendinblue specific settings.
	 *
	 * @since  3.40.5
	 *
	 * @param  array $settings The settings.
	 * @param  array $options  The options in the database.
	 * @return array The settings.
	 */
	public function register_settings( $settings, $options ) {

		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Sendinblue Site Tracking', 'wp-fusion-lite' ),
			'url'     => 'https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#sendinblue',
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable Sendinblue site tracking scripts.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_key'] = array(
			'title'   => __( 'Tracking Client Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Your tracking <code>client_key</code> can be found in the Tracking Code <a href="https://automation.sendinblue.com/parameters" target="_blank">in the Automation settings of your Sendinblue account</a>. For example: <code>l7u0448l6oipghl8v7k92</code>', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}

	/**
	 * Puts a div around the sendinblue configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_sendinblue_header_begin( $id, $field ) {

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

		$api_key = sanitize_text_field( $_POST['sendinblue_key'] );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['sendinblue_key']        = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}

}