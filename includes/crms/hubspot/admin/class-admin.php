<?php

class WPF_HubSpot_Admin {

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
		add_action( 'show_field_hubspot_header_begin', array( $this, 'show_field_hubspot_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ), 1 );

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function init() {

		add_filter( 'wpf_compatibility_notices', array( $this, 'compatibility_notices' ) );
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}


	/**
	 * Compatibility checks
	 *
	 * @access public
	 * @return array Notices
	 */

	public function compatibility_notices( $notices ) {

		if ( is_plugin_active( 'leadin/leadin.php' ) ) {

			$notices['hs-plugin'] = 'The <strong>HubSpot for WordPress</strong> plugin is active. For best compatibility with WP Fusion it\'s recommended to deactivate support for Non-HubSpot Forms at Forms &raquo; Non-HubSpot Forms <a href="' . admin_url( 'admin.php?page=leadin_settings' ) . '">in the settings</a>.';

		}

		$notices['marketing-consent'] = sprintf( __( '<strong>Heads up!</strong> If you haven\'t done so already, we recommend %1$senabling marketing contacts%2$s for the WP Fusion integration in HubSpot.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-hubspot-to-wordpress/#marketing-contacts" target="_blank">', '</a>' );

		return $notices;

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] == 'hubspot' ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$params = array(
				'user-agent' => 'WP Fusion; ' . home_url(),
				'headers'    => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'       => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->crm->client_id,
					'client_secret' => $this->crm->client_secret,
					'redirect_uri'  => 'https://wpfusion.com/oauth/?action=wpf_get_hubspot_token',
					'code'          => $code,
				),
			);

			$response = wp_safe_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! isset( $body->access_token ) ) {

				wpf_log( 'error', 0, 'Error requesting access token: <pre>' . print_r( $body, true ) . '</pre>' );
				return false;

			} else {

				wp_fusion()->settings->set( 'hubspot_token', $body->access_token );
				wp_fusion()->settings->set( 'hubspot_refresh_token', $body->refresh_token );
				wp_fusion()->settings->set( 'crm', $this->slug );

				wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
				exit;
			}
		}

	}


	/**
	 * Loads HubSpot connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['hubspot_header'] = array(
			'title'   => __( 'HubSpot Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // auth URL must be HTTPs, even if the site isn't.

		$auth_url = 'https://wpfusion.com/oauth/?redirect=' . urlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=hubspot' ) . '&action=wpf_get_hubspot_token&client_id=' . $this->crm->client_id;
		$auth_url = apply_filters( 'wpf_hubspot_auth_url', $auth_url );

		if ( empty( $options['hubspot_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['hubspot_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $auth_url,
				'name'    => $this->name,
				'slug'    => $this->slug,
			);

		} else {

			$new_settings['hubspot_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['hubspot_refresh_token'] = array(
				'title'          => __( 'Refresh Token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'post_fields'    => array( 'hubspot_token', 'hubspot_refresh_token' ),
				'desc'           => '<a href="' . esc_url( $auth_url ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>',
				'input_disabled' => true,
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads HubSpot specific settings fields
	 *
	 * @access  public
	 * @return  array Settings
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option.
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'HubSpot Site Tracking', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://knowledge.hubspot.com/articles/kcs_article/account/how-does-hubspot-track-visitors">HubSpot site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_id'] = array(
			'std'     => '',
			'type'    => 'hidden',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}


	/**
	 * Loads standard HubSpot field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/hubspot-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $hubspot_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $hubspot_fields[ $field ] );
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

	public function show_field_hubspot_header_begin( $id, $field ) {

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

		$access_token  = sanitize_text_field( wp_unslash( $_POST['hubspot_token'] ) );
		$refresh_token = sanitize_text_field( wp_unslash( $_POST['hubspot_refresh_token'] ) );

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

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
