<?php

class WPF_Zoho_Admin {

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
		add_action( 'show_field_zoho_header_begin', array( $this, 'show_field_zoho_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_action( 'validate_field_zoho_tag_type', array( $this, 'validate_tag_type' ), 10, 3 );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['location'] ) && isset( $_GET['accounts-server'] ) && isset( $_GET['crm'] ) && 'zoho' == $_GET['crm'] ) {

			$location        = sanitize_text_field( wp_unslash( $_GET['location'] ) );
			$code            = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$accounts_server = esc_url_raw( wp_unslash( $_GET['accounts-server'] ) );

			if ( $location == 'eu' ) {
				$client_secret = $this->crm->client_secret_eu;
			} elseif ( $location == 'in' ) {
				$client_secret = $this->crm->client_secret_in;
			} elseif ( $location == 'au' ) {
				$client_secret = $this->crm->client_secret_au;
			} elseif ( $location == 'ca' ) {
				$client_secret = $this->crm->client_secret_ca;
			} else {
				$client_secret = $this->crm->client_secret_us;
			}

			$response = wp_safe_remote_post( $accounts_server . '/oauth/v2/token?code=' . $code . '&client_id=' . $this->crm->client_id . '&grant_type=authorization_code&client_secret=' . $client_secret . '&redirect_uri=https%3A%2F%2Fwpfusionplugin.com%2Fparse-zoho-oauth.php' );

			if ( is_wp_error( $response ) ) {
				wp_fusion()->admin_notices->add_notice( 'Error requesting authorization code: ' . $response->get_error_message() );
				wpf_log( 'error', 0, 'Error requesting authorization code: ' . $response->get_error_message() );
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {
				wp_fusion()->admin_notices->add_notice( 'Error requesting authorization code: ' . $body->error );
				wpf_log( 'error', 0, 'Error requesting authorization code: ' . $body->error );
				return false;
			}

			wp_fusion()->settings->set( 'zoho_location', $location );
			wp_fusion()->settings->set( 'zoho_api_domain', $body->api_domain );
			wp_fusion()->settings->set( 'zoho_token', $body->access_token );
			wp_fusion()->settings->set( 'zoho_refresh_token', $body->refresh_token );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}

	}


	/**
	 * Loads Zoho connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['zoho_header'] = array(
			'title'   => __( 'Zoho Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$auth_url = 'https://wpfusion.com/oauth/?redirect=' . urlencode( admin_url( 'options-general.php?page=wpf-settings&crm=zoho' ) ) . '&action=wpf_get_zoho_token&client_id=' . $this->crm->client_id;
		$auth_url = apply_filters( 'wpf_zoho_auth_url', $auth_url );

		if ( empty( $options['zoho_refresh_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['zoho_header']['desc']  = '<table class="form-table"><tr>';
			$new_settings['zoho_header']['desc'] .= '<th scope="row"><label>Authorize</label></th>';
			$new_settings['zoho_header']['desc'] .= '<td><a class="button button-primary" href="' . esc_url( $auth_url ) . '">Authorize with Zoho</a><br /><span class="description">You\'ll be taken to Zoho to authorize WP Fusion and generate access keys for this site.</td>';
			$new_settings['zoho_header']['desc'] .= '</tr></table></div><table class="form-table">';

		} else {

			if ( ! empty( $options['connection_configured'] ) && 'zoho' === wpf_get_option( 'crm' ) ) {
				$new_settings['zoho_tag_type'] = array(
					'title'   => __( 'Segmentation Type', 'wp-fusion-lite' ),
					'std'     => 'tags',
					'type'    => 'radio',
					'section' => 'setup',
					'choices' => array(
						'tags'        => 'Tags',
						'multiselect' => 'Multi-Select',
					),
					'desc'    => __( 'For more information, see <a href="https://wpfusion.com/documentation/crm-specific-docs/zoho-tags/" target="_blank">Tags with Zoho</a>.', 'wp-fusion-lite' ),
				);

				$new_settings['zoho_multiselect_field'] = array(
					'title'       => __( 'Multi-select Field', 'wp-fusion-lite' ),
					'disabled'    => isset( $options['zoho_tag_type'] ) && 'multiselect' === $options['zoho_tag_type'] ? false : true,
					'type'        => 'select',
					'choices'     => wpf_get_option( 'zoho_multiselect_fields', array() ),
					'placeholder' => __( 'Select a field', 'wp-fusion-lite' ),
					'section'     => 'setup',
				);

			}

			$new_settings['zoho_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['zoho_refresh_token'] = array(
				'title'          => __( 'Refresh Token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'post_fields'    => array( 'zoho_token', 'zoho_refresh_token' ),
				'desc'           => '<a href="' . esc_url( $auth_url ) . '">' . sprintf( __( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->name ) . '</a>',
				'input_disabled' => true,
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Resync tags/multiselect field when the tag type is saved and validate the tag type.
	 *
	 * @since  3.41.16
	 *
	 * @param  string      $input   The input.
	 * @param  array       $setting The setting configuration.
	 * @param  WPF_Options $options The options class.
	 * @return string|WP_Error The input or error on validation failure.
	 */
	public function validate_tag_type( $input, $setting, $options ) {

		if ( ! empty( $options->options['zoho_tag_type'] ) && $input !== $options->options['zoho_tag_type'] ) {

			if ( 'multiselect' === $input && empty( $options->post_data['zoho_multiselect_field'] ) ) {
				return new WP_Error( 'error', 'To use multiselect for tags you must select a multiselect field from the multiselect dropdown on the Setup tab.' );
			}

			// Set these temporarily so sync_tags() works.
			wp_fusion()->settings->options['zoho_tag_type']          = $input;
			wp_fusion()->settings->options['zoho_multiselect_field'] = $options->post_data['zoho_multiselect_field'];

			$result = $this->crm->sync_tags();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Resync tags for all users.
			wp_fusion()->batch->batch_init( 'users_tags_sync' );

		}

		return $input;

	}


	/**
	 * Adds Zoho specific setting fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$new_settings = array();

		if ( ! isset( $options['zoho_layouts'] ) ) {
			$options['zoho_layouts'] = array();
		}

		$new_settings['zoho_layout'] = array(
			'title'       => __( 'Contact Layout', 'wp-fusion-lite' ),
			'desc'        => __( 'Select a layout to be used for new contacts.', 'wp-fusion-lite' ),
			'type'        => 'select',
			'placeholder' => __( 'Select layout', 'wp-fusion-lite' ),
			'section'     => 'main',
			'choices'     => $options['zoho_layouts'],
		);

		if ( ! empty( $options['zoho_users'] ) ) {

			$new_settings['zoho_owner'] = array(
				'title'       => __( 'Contact Owner', 'wp-fusion-lite' ),
				'desc'        => __( 'Select an owner to be used for new contacts.', 'wp-fusion-lite' ),
				'std'         => false,
				'type'        => 'select',
				'placeholder' => __( 'Select owner', 'wp-fusion-lite' ),
				'section'     => 'main',
				'choices'     => $options['zoho_users'],
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'create_users', $settings, $new_settings );

		$new_settings = array(
			'import_notice' => array(
				'desc'    => __( '<strong>Note:</strong> Imports with Zoho use a loose word match on the contact record. That means if your import tag is "gmail", it will also import any contacts with an <em>@gmail.com</em> email address. Please use a unique tag name for imports.', 'wp-fusion-lite' ),
				'type'    => 'paragraph',
				'section' => 'import',
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'import_users', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard Zoho field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( ! empty( $options['connection_configured'] ) ) {

			require_once dirname( __FILE__ ) . '/zoho-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $zoho_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $zoho_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the Zoho configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_zoho_header_begin( $id, $field ) {

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

		$access_token  = sanitize_text_field( wp_unslash( $_POST['zoho_token'] ) );
		$refresh_token = sanitize_text_field( wp_unslash( $_POST['zoho_refresh_token'] ) );

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['zoho_token']            = $access_token;
			$options['zoho_refresh_token']    = $refresh_token;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}


}
