<?php

class WPF_Loopify_Admin {

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
		add_action( 'show_field_loopify_header_begin', array( $this, 'show_field_loopify_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
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

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && 'loopify' == $_GET['crm'] ) {

			$body = array(
				'grant_type'    => 'authorization_code',
				'code'          => $_GET['code'],
				'client_id'     => $this->crm->client_id,
				'client_secret' => $this->crm->client_secret,
				'redirect_uri'  => admin_url( 'options-general.php?page=wpf-settings' ),
			);

			$params = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'headers'    => array(
					'Content-type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'       => $body,
			);

			$response = wp_remote_post( 'https://auth.loopify.com/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( 'loopify_refresh_token', $response->refresh_token );
			wp_fusion()->settings->set( 'loopify_token', $response->access_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( get_admin_url() . 'options-general.php?page=wpf-settings#setup' );
			exit;

		}

	}


	/**
	 * Loads Loopify connection information on settings page
	 *
 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['loopify_header'] = array(
			'title'   => __( 'Loopify Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options['loopify_refresh_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['loopify_header']['desc']  = '<table class="form-table"><tr>';
			$new_settings['loopify_header']['desc'] .= '<th scope="row"><label>Authorize</label></th>';
			$new_settings['loopify_header']['desc'] .= '<td><a class="button button-primary" href="https://auth.loopify.com/connect/authorize?response_type=code&redirect_uri=' . urlencode( admin_url( 'options-general.php?page=wpf-settings&crm=loopify' ) ) . '&client_id=' . $this->crm->client_id . '&scope=User">Authorize with Loopify</a><br /><span class="description">You\'ll be taken to Loopify to authorize WP Fusion and generate access keys for this site.</td>';
			$new_settings['loopify_header']['desc'] .= '</tr></table></div><table class="form-table">';

		} else {

			$new_settings['loopify_token'] = array(
				'title'   => __( 'Access Token', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['loopify_refresh_token'] = array(
				'title'       => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'desc'        => sprintf( __( 'If your connection with %s is broken you can erase the refresh token and save the settings page to re-authorize with %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name, wp_fusion()->crm->name ),
				'post_fields' => array( 'loopify_token', 'loopify_refresh_token' ),
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}



	/**
	 * Loads standard Loopify field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/loopify-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $loopify_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $loopify_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the Loopify configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_loopify_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$access_token = sanitize_text_field( $_POST['loopify_token'] );

		$connection = $this->crm->connect( $access_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['loopify_token']         = $access_token;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
