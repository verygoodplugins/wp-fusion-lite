<?php

class WPF_BirdSend_Admin {

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
		add_action( 'show_field_birdsend_header_begin', array( $this, 'show_field_birdsend_header_begin' ), 10, 2 );
		add_action( 'show_field_birdsend_refresh_token_end', array( $this, 'show_field_birdsend_token_end' ), 10, 2 );

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

		if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) && $_GET['state'] == 'wpfbirdsend' ) {

			$body = array(
				'grant_type'    => 'authorization_code',
				'code'          => $_GET['code'],
				'client_id'     => $this->crm->client_id,
				'client_secret' => $this->crm->client_secret,
				'redirect_uri'  => 'https://wpfusion.com/parse-birdsend-oauth.php',
			);

			$params = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'       => json_encode( $body ),
			);

			$response = wp_remote_post( 'https://api.birdsend.co/oauth/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( 'birdsend_refresh_token', $response->refresh_token );
			wp_fusion()->settings->set( 'birdsend_token', $response->access_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( get_admin_url() . 'options-general.php?page=wpf-settings#setup' );
			exit;

		}

	}


	/**
	 * Loads BirdSend connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['birdsend_header'] = array(
			'title'   => __( 'BirdSend Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options['birdsend_refresh_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['birdsend_header']['desc'] = '<table class="form-table"><tr>';
			$new_settings['birdsend_header']['desc'] .= '<th scope="row"><label>Authorize</label></th>';
			$new_settings['birdsend_header']['desc'] .= '<td><a class="button button-primary" href="https://wpfusion.com/parse-birdsend-oauth.php?redirect=' .  urlencode( get_admin_url() . './options-general.php?page=wpf-settings' ) . '&action=wpf_get_birdsend_token&client_id=' . $this->crm->client_id . '">Authorize with BirdSend</a><br /><span class="description">You\'ll be taken to BirdSend to authorize WP Fusion and generate access keys for this site.</td>';
			$new_settings['birdsend_header']['desc'] .= '</tr></table></div><table class="form-table">';

		} else {

			$new_settings['birdsend_token'] = array(
				'title'   => __( 'Access Token', 'wp-fusion' ),
				'std'     => '',
				'type'    => 'text',
				'section' => 'setup',
			);

			$new_settings['birdsend_refresh_token'] = array(
				'title'       => __( 'Refresh token', 'wp-fusion' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'birdsend_token', 'birdsend_refresh_token' )
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}



	/**
	 * Loads standard BirdSend field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/birdsend-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $birdsend_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $birdsend_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the BirdSend configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_birdsend_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out BirdSend section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_birdsend_token_end( $id, $field ) {

		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #birdsend div
		echo '<table class="form-table">';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$access_token  = sanitize_text_field( $_POST['birdsend_token'] );
		$refresh_token = sanitize_text_field( $_POST['birdsend_refresh_token'] );

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                           = wp_fusion()->settings->get_all();
			$options['birdsend_token']         = $access_token;
			$options['birdsend_refresh_token'] = $refresh_token;
			$options['crm']                    = $this->slug;
			$options['connection_configured']  = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
