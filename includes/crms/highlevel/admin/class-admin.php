<?php

class WPF_HighLevel_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @since 3.36.0
	 *
	 * @param string $slug The CRM's slug.
	 * @param string $name The name of the CRM.
	 * @param object $crm  The CRM object.
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_highlevel_header_begin', array( $this, 'show_field_highlevel_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth.
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.36.0
	 */
	public function init() {
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_action( 'wpf_settings_notices', array( $this, 'oauth_warning' ) );
	}


	/**
	 * Check if we need to upgrade to the new OAuth.
	 *
	 * @since 3.41.11
	 */
	public function oauth_warning() {

		if ( ! wpf_get_option( 'highlevel_token' ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			echo wp_kses_post( sprintf( __( '<strong>Heads up:</strong> WP Fusion\'s HighLevel integration has been updated to use OAuth authentication. Please %1$sclick here to re-authorize the connection%2$s and enable a deeper integration with new HighLevel features.', 'wp-fusion-lite' ), '<a href="' . $this->get_oauth_url() . '">', '</a>' ) );

			echo '</p></div>';

		}
	}



	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since  3.41.11
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
	 * @since   3.41.11
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) && 'wpfhighlevel' === $_GET['state'] ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$body = array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->crm->client_id,
				'client_secret' => $this->crm->client_secret,
				'redirect_uri'  => admin_url( 'options-general.php?page=wpf-settings&crm=highlevel' ),
			);

			$params = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'headers'    => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'       => $body,
			);

			$response = wp_safe_remote_post( 'https://services.leadconnectorhq.com/oauth/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( 'highlevel_refresh_token', $response->refresh_token );
			wp_fusion()->settings->set( 'highlevel_token', $response->access_token );
			wp_fusion()->settings->set( 'highlevel_location_id', $response->locationId );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}


	/**
	 * Registers HighLevel API settings
	 *
	 * @since 3.36.0
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$name = wpf_get_option( 'connection_configured' ) ? wp_fusion()->crm->name : __( 'HighLevel', 'wp-fusion-lite' ); // allows white-labelling.

		$new_settings['highlevel_header'] = array(
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( has_filter( 'wpf_get_setting_highlevel_api_key' ) ) {

			// For white-labeled accounts https://wpfusion.com/documentation/crm-specific-docs/highlevel-white-labelled-accounts/#overview.

			$new_settings['highlevel_location_id'] = array(
				'title'   => __( 'Your account location ID', 'wp-fusion-lite' ),
				'desc'    => __( 'Your account location ID which is required for some requests.', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['highlevel_api_key'] = array(
				'title'       => __( 'API Key', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'highlevel_api_key' ),
			);

		} elseif ( empty( $options['highlevel_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['highlevel_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
				// Translators: %s is the name of the CRM.
				'desc'    => sprintf( __( 'You\'ll be taken to %1$s to authorize WP Fusion and generate access keys for this site.<br /><br />If you receive authorization errors, please %2$slog in to %1$s%3$s before attempting the connection.', 'wp-fusion-lite' ), $this->crm->name, '<a href="https://app.gohighlevel.com/">', '</a>' ),
			);

		} else {

			$new_settings['highlevel_location_id'] = array(
				'title'          => __( 'Your account location ID', 'wp-fusion-lite' ),
				'desc'           => __( 'Your account location ID which is required for some requests.', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['highlevel_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['highlevel_refresh_token'] = array(
				'title'          => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'input_disabled' => true,
				'post_fields'    => array( 'highlevel_token', 'highlevel_refresh_token', 'highlevel_location_id' ),
				'desc'           => '<a href="' . esc_url( $this->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>. ',
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads standard field names and attempts to match them up with standard local ones
	 *
	 * @since 3.36.0
	 *
	 * @param array $options The options saved in the database.
	 * @return array $options The options saved in the database.
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/highlevel-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $highlevel_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $highlevel_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.36.0
	 *
	 * @param string $id    The ID of the field
	 * @param array  $field The field properties
	 * @return mixed HTML output
	 */

	public function show_field_highlevel_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Verify connection credentials
	 *
	 * @since 3.36.0
	 *
	 * @return mixed JSON response
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( isset( $_POST['highlevel_token'] ) ) {
			$access_token = sanitize_text_field( wp_unslash( $_POST['highlevel_token'] ) );
		} else {
			$access_token = sanitize_text_field( wp_unslash( $_POST['highlevel_api_key'] ) );
		}

		$location_id = sanitize_text_field( wp_unslash( $_POST['highlevel_location_id'] ) );

		$connection = $this->crm->connect( $access_token, $location_id, $test = true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		}

		$options = array(
			'highlevel_location_id' => $location_id,
			'crm'                   => $this->slug,
			'connection_configured' => true,
		);

		if ( isset( $_POST['highlevel_token'] ) ) {
			$options['highlevel_token'] = $access_token;
		} else {
			$options['highlevel_api_key'] = $access_token;
		}

		wp_fusion()->settings->set_multiple( $options );

		wp_send_json_success();
	}
}
