<?php

class WPF_AgileCRM_Admin {

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
		add_action( 'show_field_agilecrm_header_begin', array( $this, 'show_field_agilecrm_header_begin' ), 10, 2 );

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
	 * Loads AgileCRM connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['agilecrm_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['agile_domain'] = array(
			'title'   => __( 'Subdomain', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter your Agile CRM account subdomain (not the full URL).', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['agile_user_email'] = array(
			'title'   => __( 'User Email', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the email address for your AgileCRM account.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['agile_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'The API key will appear under Admin Settings &raquo; API &raquo; REST API.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'agile_domain', 'agile_user_email', 'agile_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads AgileCRM specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'AgileCRM Site Tracking', 'wp-fusion-lite' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://www.agilecrm.com/marketing-automation/web-rules">AgileCRM analytics and web rules</a> scripts.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_acct'] = array(
			'title'   => __( 'Account ID', 'wp-fusion-lite' ),
			'desc'    => __( 'Your account ID can be found in the Tracking Code in your AgileCRM account, under Admin Settings &raquo; Analytics. For example: <code>8g8fejferfqbi4g4mradq09373</code>', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}


	/**
	 * Loads standard AgileCRM field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/agilecrm-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $agilecrm_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $agilecrm_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the AgileCRM configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_agilecrm_header_begin( $id, $field ) {

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

		$agile_domain = isset( $_POST['agile_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['agile_domain'] ) ) : false;
		$user_email   = isset( $_POST['agile_user_email'] ) ? sanitize_email( wp_unslash( $_POST['agile_user_email'] ) ) : false;
		$api_key      = isset( $_POST['agile_key'] ) ? sanitize_text_field( wp_unslash( $_POST['agile_key'] ) ) : false;

		$connection = $this->crm->connect( $agile_domain, $user_email, $api_key, true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		}

		$options = array();

		$options['agile_domain']          = $agile_domain;
		$options['agile_user_email']      = $user_email;
		$options['agile_key']             = $api_key;
		$options['crm']                   = $this->slug;
		$options['connection_configured'] = true;

		wp_fusion()->settings->set_multiple( $options );

		wp_send_json_success();
	}
}
