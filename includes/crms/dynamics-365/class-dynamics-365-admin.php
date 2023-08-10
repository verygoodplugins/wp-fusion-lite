<?php

class WPF_Dynamics_365_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string.
	 * @since 3.38.43
	 */

	private $slug;

	/**
	 * The CRM name.
	 *
	 * @var string
	 * @since 3.38.43
	 */

	private $name;

	/**
	 * The CRM object.
	 *
	 * @var object
	 * @since 3.38.43
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.38.43
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_dynamics_365_rest_header_begin', array( $this, 'show_field_dynamics_365_rest_header_begin' ), 10, 2 );

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
	 * @since 3.38.43
	 */

	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_render_tag_multiselect_args', array( $this, 'import_multiselect_args' ) );

	}

	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since  3.38.20
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=dynamics-365' ),
			'rest_url' => wpf_get_option( 'dynamics_365_rest_url', 'unknown' ),
			'action'   => 'wpf_get_dynamics_token',
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );

	}


	/**
	 * Handle REST API authentication.
	 *
	 * @since 3.38.43
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $this->slug == $_GET['crm'] ) {

			$code     = sanitize_text_field( $_GET['code'] );
			$resource = esc_url_raw( urldecode( $_GET['rest_url'] ) );

			$params = array(
				'headers' => array(
					'Content-type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->crm->client_id,
					'grant_type'    => 'authorization_code',
					'client_secret' => $this->crm->client_secret,
					'resource'      => $resource,
					'redirect_uri'  => $this->crm->callback_url,
					'scope'         => 'openid offline_access https://graph.microsoft.com/user.read',
				),
			);

			$response = wp_remote_post( 'https://login.microsoftonline.com/common/oauth2/token/', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $response->error ) ) {
				return new WP_Error( 'error', $response->error_description );
			}

			// Generate long term token.

			$params['body']['refresh_token'] = $response->refresh_token;
			$params['body']['grant_type']    = 'refresh_token';

			$response = wp_remote_post( 'https://login.microsoftonline.com/common/oauth2/token/', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $response->error ) ) {
				return new WP_Error( 'error', $response->error->message );
			}

			$options = array(
				'dynamics_365_rest_url'      => $resource,
				'dynamics_365_access_token'  => $response->access_token,
				'dynamics_365_refresh_token' => $response->refresh_token,
				'crm'                        => $this->slug,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}

	}



	/**
	 * Registers Dynamics API settings.
	 *
	 * @since  3.38.43
	 *
	 * @param  array $settings The registered settings on the options page.
	 * @param  array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['dynamics_365_rest_header'] = array(
			'title'   => __( 'Dynamics 365 Configuration', 'wp-fusion-lite' ),
			'url'     => 'https://wpfusion.com/documentation/installation-guides/how-to-connect-dynamics-365-marketing-to-wordpress/',
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['dynamics_365_rest_url'] = array(
			'title'   => __( 'CRM URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'class'   => 'wp-dynamics-url',
			'desc'    => __( 'Enter the full URL to your Dynamics 365 CRM instance (it must end with dynamics.com)', 'wp-fusion-lite' ),
		);

		if ( empty( $options['dynamics_365_access_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['dynamics_365_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
				'dis'     => true,
			);

		} else {

			if ( ! empty( $options['connection_configured'] ) && 'dynamics-365' === wpf_get_option( 'crm' ) ) {

				$new_settings['dynamics_365_object_type'] = array(
					'title'   => __( 'Object Type' ),
					'type'    => 'select',
					'section' => 'setup',
					'choices' => array(
						'contacts'  => 'Contacts',
						'leads'     => 'Leads',
						'incidents' => 'Cases',
					),
					'std'     => 'contacts',
					'desc'    => __( 'Select an object type to use with WP Fusion.', 'wp-fusion-lite' ),
				);

			}

			$new_settings['dynamics_365_access_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['dynamics_365_refresh_token'] = array(
				'title'          => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'post_fields'    => array( 'dynamics_365_rest_url', 'dynamics_365_access_token', 'dynamics_365_refresh_token' ),
				'desc'           => '<a href="' . esc_url( $this->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>',
				'input_disabled' => true,
			);
		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.38.43
	 *
	 * @return array The default fields.
	 */
	public static function get_default_fields() {

		$fields = array(
			'first_name'    => array(
				'crm_label' => 'First Name',
				'crm_field' => 'firstname',
			),
			'last_name'     => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'lastname',
			),
			'user_email'    => array(
				'crm_label' => 'Email',
				'crm_field' => 'emailaddress1',
			),
			'billing_phone' => array(
				'crm_label' => 'Phone',
				'crm_field' => 'telephone1',
			),
		);
		return $fields;

	}



	/**
	 * Loads standard Dynamics_365 field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @since  3.38.43
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		if ( ! empty( $options['connection_configured'] ) ) {

			$standard_fields = $this->get_default_fields();

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
				}
			}
		}

		return $options;

	}

	/**
	 * Disable read only tags on the import users dropdown multiselect.
	 *
	 * @since 3.41.19
	 *
	 * @param  array $args The multiselect args.
	 * @return array The multiselect args.
	 */
	public function import_multiselect_args( $args ) {

		if ( isset( $args['field_id'] ) && 'import_users' === $args['field_id'] ) {
			$args['read_only'] = false;
		}

		return $args;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled.
	 *
	 * @since 3.38.43
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_dynamics_365_rest_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.38.43
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {
		check_ajax_referer( 'wpf_settings_nonce' );

		$url           = isset( $_POST['dynamics_365_rest_url'] ) ? sanitize_text_field( wp_unslash( $_POST['dynamics_365_rest_url'] ) ) : false;
		$access_token  = isset( $_POST['dynamics_365_access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dynamics_365_access_token'] ) ) : false;
		$refresh_token = isset( $_POST['dynamics_365_refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dynamics_365_refresh_token'] ) ) : false;

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options = array(
				'dynamics_365_rest_url'      => $url,
				'dynamics_365_access_token'  => $access_token,
				'dynamics_365_refresh_token' => $refresh_token,
				'crm'                        => $this->slug,
				'connection_configured'      => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}


}
