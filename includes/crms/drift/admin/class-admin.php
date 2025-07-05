<?php

class WPF_Drift_Admin {

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
		add_action( 'show_field_drift_header_begin', array( $this, 'show_field_drift_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );
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
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since  3.38.44
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings' ),
			'action'   => "wpf_get_{$this->slug}_token",
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) && $_GET['state'] == 'wpfdrift' ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$params = array(
				'headers' => array(
					'Content-type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'client_id'     => $this->crm->client_id,
					'client_secret' => $this->crm->client_secret,
					'code'          => $code,
					'grant_type'    => 'authorization_code',

				),
			);

			$response = wp_safe_remote_post( 'https://driftapi.com/oauth2/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {
				return false;
			}

			wp_fusion()->settings->set( 'drift_token', $body->access_token );
			wp_fusion()->settings->set( 'drift_refresh_token', $body->refresh_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_safe_redirect( get_admin_url() . 'options-general.php?page=wpf-settings#setup' );
			exit;

		}
	}


	/**
	 * Loads Drift connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['drift_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options['drift_refresh_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['drift_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
			);

		} else {

			$new_settings['drift_token'] = array(
				'title'   => __( 'Access Token', 'wp-fusion-lite' ),
				'std'     => '',
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['drift_refresh_token'] = array(
				'title'       => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'drift_token', 'drift_refresh_token' ),
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}



	/**
	 * Loads standard Drift field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/drift-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $drift_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $drift_fields[ $field ] );
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
	public function show_field_drift_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Close out Active Campaign section
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_drift_refresh_token_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . esc_html( $field['desc'] ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		if ( wp_fusion()->crm->slug == 'drift' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #drift div
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

		$access_token  = isset( $_POST['drift_token'] ) ? sanitize_text_field( wp_unslash( $_POST['drift_token'] ) ) : false;
		$refresh_token = isset( $_POST['drift_refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['drift_refresh_token'] ) ) : false;

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['drift_token']           = $access_token;
			$options['drift_refresh_token']   = $refresh_token;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
