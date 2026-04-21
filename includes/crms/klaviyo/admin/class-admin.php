<?php

class WPF_Klaviyo_Admin {

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
		add_action( 'show_field_klaviyo_header_begin', array( $this, 'show_field_klaviyo_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

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
	 * Loads Klaviyo connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['klaviyo_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( has_filter( 'wpf_get_setting_klaviyo_key' ) ) {

			$new_settings['klaviyo_key'] = array(
				'title'       => __( 'API Key', 'wp-fusion-lite' ),
				'desc'        => __( 'Enter your Klaviyo API key. You can generate one in your Klaviyo account under <a href="https://www.klaviyo.com/account#api-keys-tab" target="_blank">API Keys</a>.', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'klaviyo_key' ),
			);

		} elseif ( empty( $options['klaviyo_token'] ) && ! isset( $_GET['code'] ) ) {
			$new_settings['klaviyo_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
				// Translators: %s is the name of the CRM.
				'desc'    => sprintf( __( 'You\'ll be taken to %1$s to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ), $this->crm->name, '<a href="https://www.klaviyo.com/">', '</a>' ),
			);

		} else {
			$new_settings['klaviyo_oauth_status'] = array(
				'title'       => __( 'Connection Status', 'wp-fusion-lite' ),
				'type'        => 'oauth_connection_status',
				'section'     => 'setup',
				'name'        => $this->name,
				'url'         => $this->get_oauth_url(),
				'post_fields' => array( 'klaviyo_token', 'klaviyo_refresh_token' ),
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Completes the OAuth process in the admin.
	 *
	 * @since 3.46.0
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && 'klaviyo' === $_GET['crm'] ) {

			$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$code_verifier = get_option( 'wpf_klaviyo_code_verifier' );

			if ( empty( $code_verifier ) ) {
				wp_die( 'OAuth Error: Code verifier not found. Please try the authorization again.' );
			}

			// Validate verifier length against Klaviyo requirements (43-128 characters).
			$verifier_length = strlen( $code_verifier );
			if ( $verifier_length < 43 || $verifier_length > 128 ) {
				wp_die( 'OAuth Error: Code verifier length invalid. Please try the authorization again.' );
			}

			$access_token = $this->crm->authorize( $code, $code_verifier );

			if ( false === $access_token ) {
				wp_die( 'OAuth Error: Failed to get access token from Klaviyo. Please check <a href="' . esc_url( admin_url( 'tools.php?page=wpf-settings-logs' ) ) . '">the logs</a> for more details.' );
			}

			// Clean up the code verifier.
			delete_option( 'wpf_klaviyo_code_verifier' );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}

	/**
	 * Generates a code verifier for PKCE OAuth flow.
	 *
	 * @since 3.46.0
	 *
	 * @return string The code verifier.
	 */
	private function generate_code_verifier() {

		// Generate 32 random bytes and base64url encode.
		$verifier_bytes = random_bytes( 32 );
		$code_verifier  = rtrim( strtr( base64_encode( $verifier_bytes ), '+/', '-_' ), '=' );

		return $code_verifier;
	}

	/**
	 * Generates a code challenge from a code verifier for PKCE OAuth flow.
	 *
	 * @since 3.46.0
	 *
	 * @param string $verifier The code verifier.
	 * @return string The code challenge.
	 */
	private function generate_code_challenge( $verifier ) {

		// SHA256 hash the UTF-8 encoded code verifier and base64url encode.
		$challenge_bytes = hash( 'sha256', $verifier, true );
		$code_challenge  = rtrim( strtr( base64_encode( $challenge_bytes ), '+/', '-_' ), '=' );

		return $code_challenge;
	}

	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * @since  3.46.0
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {
		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		// Check if we already have a stored code verifier.
		$code_verifier = get_option( 'wpf_klaviyo_code_verifier' );

		// Only generate a new verifier if one doesn't exist or is invalid.
		if ( empty( $code_verifier ) || strlen( $code_verifier ) < 43 || strlen( $code_verifier ) > 128 ) {
			// Generate and store the code verifier locally.
			$code_verifier = $this->generate_code_verifier();

			// Validate code verifier length (43-128 characters as per Klaviyo docs).
			$verifier_length = strlen( $code_verifier );
			if ( $verifier_length < 43 || $verifier_length > 128 ) {
				// Regenerate if invalid.
				$code_verifier = $this->generate_code_verifier();
			}

			update_option( 'wpf_klaviyo_code_verifier', $code_verifier );
		}

		// Generate the code challenge from the verifier.
		$code_challenge = $this->generate_code_challenge( $code_verifier );

		$args = array(
			'redirect'              => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings' ),
			'action'                => "wpf_get_{$this->slug}_token",
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );
	}

	/**
	 * Loads standard Klaviyo field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( ! empty( $options['connection_configured'] ) ) {

			require_once __DIR__ . '/klaviyo-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $klaviyo_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $klaviyo_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Klaviyo configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_klaviyo_header_begin( $id, $field ) {

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

		if ( isset( $_POST['klaviyo_token'] ) ) {
			$access_token = sanitize_text_field( wp_unslash( $_POST['klaviyo_token'] ) );
		} else {
			$access_token = sanitize_text_field( wp_unslash( $_POST['klaviyo_key'] ) );
		}

		$connection = $this->crm->connect( $access_token, $test = true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		}

		$options = array(
			'crm'                   => $this->slug,
			'connection_configured' => true,
		);

		if ( isset( $_POST['klaviyo_token'] ) ) {
			$options['klaviyo_token'] = $access_token;
		} else {
			$options['klaviyo_key'] = $access_token;
		}

		wp_fusion()->settings->set_multiple( $options );

		wp_send_json_success();
	}
}
