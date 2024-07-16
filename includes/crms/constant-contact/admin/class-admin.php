<?php

class WPF_Constant_Contact_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since  3.40.0
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since  3.40.0
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since  3.40.0
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since  3.40.0
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_constant_contact_header_begin', array( $this, 'show_field_constant_contact_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}

		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since  3.40.0
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );

		if ( ! wpf_get_option( 'updated_cc_api' ) ) {
			add_action( 'admin_notices', array( $this, 'update_api_notice' ) );
		}

	}

	/**
	 * Display a notice to update the API settings.
	 *
	 * @since  3.40.0
	 */
	public function update_api_notice() {

		?>
		<div class="notice notice-error">
			<p><?php printf( esc_html__( 'The Constant Contact API integration has been updated. Please %1$sre-authorize the connection%2$s to continue using WP Fusion.', 'wp-fusion-lite' ), '<a href="' . esc_url( $this->get_oauth_url() ) . '">', '</a>' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Gets the OAuth URL for the initial connection. Remove if not using OAuth.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a constant_contact app.
	 *
	 * @since  3.40.0
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=' . $this->slug ),
			'action'   => "wpf_get_{$this->slug}_token",
			'version'  => WP_FUSION_VERSION,
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );

	}

	/**
	 * Listen for an OAuth response and maybe complete setup. Remove if not using OAuth.
	 *
	 * @since  3.40.0
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] === $this->slug ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$body = array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => "https://wpfusion.com/oauth/?action=wpf_get_{$this->slug}_token",
			);

			$params = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'body'       => $body,
				'headers'    => array(
					'Authorization' => 'Basic ' . base64_encode( $this->crm->client_id . ':' . $this->crm->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
			);

			$response = wp_safe_remote_post( 'https://authz.constantcontact.com/oauth2/default/v1/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( "{$this->slug}_refresh_token", $response->refresh_token );
			wp_fusion()->settings->set( "{$this->slug}_token", $response->access_token );
			wp_fusion()->settings->set( 'crm', $this->slug );
			wp_fusion()->settings->set( 'updated_cc_api', true );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since  3.40.0
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['constant_contact_header'] = array(
			'title'   => __( 'Contstant Contact CRM Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		// OR, Option 2, OAuth based authentication. Remove if not using OAuth.

		if ( empty( $options[ "{$this->slug}_refresh_token" ] ) && ! isset( $_GET['code'] ) ) {

			$new_settings[ "{$this->slug}_auth" ] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
			);

		} else {

			$new_settings[ "{$this->slug}_token" ] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings[ "{$this->slug}_refresh_token" ] = array(
				'title'          => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'post_fields'    => array( "{$this->slug}_token", "{$this->slug}_refresh_token" ),
				'desc'           => '<a href="' . esc_url( $this->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>. ',
				'input_disabled' => true,
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since  3.40.0
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		$standard_fields = $this->crm->get_default_fields();

		foreach ( $options['contact_fields'] as $field => $data ) {

			if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since  3.40.0
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_constant_contact_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since  3.40.0
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		$access_token = $_POST[ "{$this->slug}_token" ];

		$connection = $this->crm->connect( $access_token, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options                          = wp_fusion()->settings->get_all();
			$options[ "{$this->slug}_token" ] = $access_token;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
