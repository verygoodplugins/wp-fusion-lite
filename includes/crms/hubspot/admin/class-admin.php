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
		add_action( 'show_field_hubspot_refresh_token_end', array( $this, 'show_field_hubspot_refresh_token_end' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
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

		return $notices;

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		if( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] == 'hubspot' )  {

			$params = array(
				'body'	=> array(
					'grant_type'	=> 'authorization_code',
					'client_id'		=> $this->crm->client_id,
					'client_secret' => $this->crm->client_secret,
					'redirect_uri'	=> get_admin_url() . 'options-general.php?page=wpf-settings&crm=hubspot',
					'code'			=> $_GET['code']
				)
			);

			$params['body']['redirect_uri'] = str_replace('http://', 'https://', $params['body']['redirect_uri']);

			$response = wp_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body->error ) ) {
				return false;
			}

			wp_fusion()->settings->set( 'hubspot_token', $body->access_token );
			wp_fusion()->settings->set( 'hubspot_refresh_token', $body->refresh_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( get_admin_url() . 'options-general.php?page=wpf-settings#setup' );
			exit;

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

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // auth URL must be HTTPs, even if the site isn't
		$auth_url  = 'https://app.hubspot.com/oauth/authorize?redirect_uri=' . urlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=hubspot' ) . '&client_id=' . $this->crm->client_id . '&scope=contacts%20oauth&optional_scope=automation%20e-commerce';
		$auth_url  = apply_filters( 'wpf_hubspot_auth_url', $auth_url );

		if ( empty( $options['hubspot_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['hubspot_header']['desc']  = '<table class="form-table"><tr>';
			$new_settings['hubspot_header']['desc'] .= '<th scope="row"><label>' . __( 'Authorize', 'wp-fusion-lite' ) . '</label></th>';
			$new_settings['hubspot_header']['desc'] .= '<td><a class="button button-primary" href="' . $auth_url . '">' . __( 'Authorize with HubSpot', 'wp-fusion-lite' ) . '</a><br />';
			$new_settings['hubspot_header']['desc'] .= '<span class="description">' . __( 'You\'ll be taken to HubSpot to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ) . '</td>';
			$new_settings['hubspot_header']['desc'] .= '</tr></table>';

			if ( ! is_ssl() ) {
				$new_settings['hubspot_header']['desc'] .= '<p class="wpf-notice notice notice-error">' . __( '<strong>Warning:</strong> Your site is not currently SSL secured (https://). You will not be able to connect to the HubSpot API. Your Site Address must be set to https:// in Settings &raquo; General.', 'wp-fusion-lite' ) . '</p>';
			}

			$new_settings['hubspot_header']['desc'] .= '</div><table class="form-table">';

		} else {

			$new_settings['hubspot_token'] = array(
				'title'   => __( 'Access Token', 'wp-fusion-lite' ),
				'std'     => '',
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['hubspot_refresh_token'] = array(
				'title'       => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'hubspot_token', 'hubspot_refresh_token' ),
				'desc'        => '<a href="' . $auth_url . '">' . sprintf( __( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>',
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

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'HubSpot Site Tracking', 'wp-fusion-lite' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main'
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://knowledge.hubspot.com/articles/kcs_article/account/how-does-hubspot-track-visitors">HubSpot site tracking</a>.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main'
		);

		$site_tracking['site_tracking_id'] = array(
			'std'     => '',
			'type'    => 'hidden',
			'section' => 'main'
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
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out Active Campaign section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_hubspot_refresh_token_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #hubspot div

		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$access_token = sanitize_text_field( $_POST['hubspot_token'] );
		$refresh_token = sanitize_text_field( $_POST['hubspot_refresh_token'] );

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}