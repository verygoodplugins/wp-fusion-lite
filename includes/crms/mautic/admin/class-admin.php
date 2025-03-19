<?php

class WPF_Mautic_Admin {

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
		add_action( 'show_field_mautic_header_begin', array( $this, 'show_field_mautic_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_wpf_save_client_credentials', array( $this, 'save_client_credentials' ) );

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

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
	}

	/**
	 * Save ouath client credentials.
	 *
	 * @since 3.40.39
	 */
	public function save_client_credentials() {
		check_ajax_referer( 'wpf_settings_nonce' );
		$options                         = array();
		$options['mautic_url']           = sanitize_text_field( $_POST['url'] );
		$options['mautic_client_id']     = sanitize_text_field( $_POST['client_id'] );
		$options['mautic_client_secret'] = sanitize_text_field( $_POST['client_secret'] );

		wp_fusion()->settings->set_multiple( $options );
		wp_send_json_success( array( 'url' => $this->get_oauth_url( $options['mautic_url'] ) ) );
	}

	/**
	 * Listen for an OAuth response and maybe complete setup. Remove if not using OAuth.
	 *
	 * @since 3.40.39
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] === $this->slug ) {

			$code      = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$admin_url = str_replace( 'http://', 'https://', get_admin_url() );
			$body      = array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->crm->client_id,
				'client_secret' => $this->crm->client_secret,
				'redirect_uri'  => $admin_url . 'options-general.php?page=wpf-settings&crm=mautic',
			);

			$params   = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'body'       => wp_json_encode( $body ),
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
			);
			$url      = trailingslashit( wpf_get_option( 'mautic_url' ) ) . 'oauth/v2/token';
			$response = wp_safe_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( "{$this->slug}_refresh_token", $response->refresh_token );
			wp_fusion()->settings->set( "{$this->slug}_token", $response->access_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}



	/**
	 * Loads mautic connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		if ( wp_fusion()->crm && isset( $options['mautic_username'] ) && $options['mautic_username'] != '' ) {
			// Option 2
			$new_settings['mautic_header'] = array(
				'title'   => __( 'Mautic Configuration Basic Auth', 'wp-fusion-lite' ),
				'type'    => 'heading',
				'section' => 'setup',
				'desc'    => __( 'Before attempting to connect to Mautic, you\'ll first need to enable API access. You can do this by going to the configuration screen, and selecting API Settings. Turn both <strong>API Enabled</strong> and <strong>Enable Basic HTTP Auth</strong> to On.', 'wp-fusion-lite' ),
			);

			$new_settings['mautic_header']['desc'] .= '<br /><br />' . __( '<strong>Note</strong> that if you\'ve just enabled the API for the first time you\'ll probably need to <a href="https://docs.mautic.org/en/troubleshooting#1-clear-the-cache" target="_blank">clear your Mautic caches</a>.', 'wp-fusion-lite' );

			$new_settings['mautic_url'] = array(
				'title'   => __( 'URL', 'wp-fusion-lite' ),
				'desc'    => __( 'Enter the URL for your Mautic account (like https://app.mautic.net/).', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['mautic_username'] = array(
				'title'   => __( 'Username', 'wp-fusion-lite' ),
				'desc'    => __( 'Enter the Username for your Mautic account.', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['mautic_password'] = array(
				'title'       => __( 'Password', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'password'    => true,
				'post_fields' => array( 'mautic_url', 'mautic_username', 'mautic_password' ),
			);

		} else {
			// Option 1.
			$admin_url                     = str_replace( 'http://', 'https://', get_admin_url() );
			$redirect_url                  = $admin_url . 'options-general.php?page=wpf-settings&crm=mautic';
			$new_settings['mautic_header'] = array(
				// translators: %s is the name of the CRM.
				'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
				'type'    => 'heading',
				'section' => 'setup',
				'desc'    => sprintf( __( 'Connect to Mautic using OAuth, get the credentials by folowing <a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-mautic-to-wordpress/" target="_blank">these instructions</a>. Enter this as the Redirect URI while generating the client id: <br> <code>%s</code>', 'wp-fusion-lite' ), $redirect_url ),
			);

			$new_settings['mautic_url'] = array(
				'title'   => __( 'URL', 'wp-fusion-lite' ),
				'desc'    => __( 'Enter the URL for your Mautic account (like https://app.mautic.net/).', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['mautic_client_id'] = array(
				'title'   => __( 'Public Key', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['mautic_client_secret'] = array(
				'title'   => __( 'Secret Key', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			if ( empty( $options[ "{$this->slug}_refresh_token" ] ) && ! isset( $_GET['code'] ) ) {
				$new_settings['mautic_auth'] = array(
					'title'   => __( 'Authorize', 'wp-fusion-lite' ),
					'type'    => 'oauth_authorize',
					'section' => 'setup',
					'url'     => $this->get_oauth_url(),
					'name'    => $this->name,
					'slug'    => $this->slug,
					'dis'     => true,
				);

			} else {

				$new_settings[ "{$this->slug}_token" ] = array(
					'title'   => __( 'Access Token', 'wp-fusion-lite' ),
					'type'    => 'text',
					'section' => 'setup',
				);

				$new_settings[ "{$this->slug}_refresh_token" ] = array(
					'title'       => __( 'Refresh token', 'wp-fusion-lite' ),
					'type'        => 'api_validate',
					'section'     => 'setup',
					'class'       => 'api_key',
					'post_fields' => array( 'mautic_url', 'mautic_token', 'mautic_refresh_token' ),
					'desc'        => '<a href="' . esc_url( $this->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>. ',
				);

			}
		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since 3.40.39
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url( $url = false ) {
		if ( $url === false ) {
			$url = wpf_get_option( $this->slug . '_url' );
		}
		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'client_id'     => wpf_get_option( $this->slug . '_client_id' ),
			'grant_type'    => 'authorization_code',
			'redirect_uri'  => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=mautic' ),
			'response_type' => 'code',
			'state'         => 'wpf_mautic',
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, trailingslashit( $url ) . 'oauth/v2/authorize' ) );
	}


	/**
	 * Loads standard mautic field names and attempts to match them up with standard local ones
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
	 * Loads Mautic specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Mautic Site Tracking', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'For more information on these settings, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#mautic" target="_blank">', '</a>' ),
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://www.mautic.org/docs/en/contacts/contact_monitoring.html">Mautic site tracking</a>.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'advanced_site_tracking' ),
		);

		if ( ! empty( $options['site_tracking'] ) ) {
			$std = true;
		} else {
			$std = false;
		}

		$site_tracking['advanced_site_tracking'] = array(
			'title'   => __( 'Advanced Site Tracking', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Identify logged in users to Mautic, and merge anonymous visitors with contacts after signup. %1$sSee here for more information%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#mautic" target="_blank">', '</a>' ),
			'std'     => $std,
			'type'    => 'checkbox',
			'section' => 'main',
			'tooltip' => __( 'Enabling this option improves tracking page views against identified contacts, but may have problems when caching is used that cause contact records to become merged. For optimal results make sure logged in users are excluded from all page caching.', 'wp-fusion-lite' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}


	/**
	 * Puts a div around the Mautic configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mautic_header_begin( $id, $field ) {

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

		$mautic_url = esc_url_raw( trim( wp_unslash( $_POST['mautic_url'] ) ) );

		if ( isset( $_POST['mautic_username'] ) ) {
			$mautic_username = sanitize_text_field( wp_unslash( $_POST['mautic_username'] ) );
			$mautic_password = sanitize_text_field( wp_unslash( $_POST['mautic_password'] ) );
			$mautic_token    = false;
		} else {
			$mautic_username      = false;
			$mautic_password      = false;
			$mautic_token         = sanitize_text_field( wp_unslash( $_POST['mautic_token'] ) );
			$mautic_refresh_token = sanitize_text_field( wp_unslash( $_POST['mautic_token'] ) );
		}

		$connection = $this->crm->connect( $mautic_url, $mautic_username, $mautic_password, $mautic_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options = array(
				'crm'                   => $this->slug,
				'mautic_url'            => $mautic_url,
				'connection_configured' => true,
			);

			if ( isset( $mautic_username ) ) {
				// Basic auth.
				$options['mautic_username'] = $mautic_username;
				$options['mautic_password'] = $mautic_password;
			} else {
				// Oauth.
				$options['mautic_token']         = $mautic_token;
				$options['mautic_refresh_token'] = $mautic_refresh_token;
			}

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
