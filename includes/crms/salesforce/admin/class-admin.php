<?php

class WPF_Salesforce_Admin {

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
		add_action( 'show_field_salesforce_header_begin', array( $this, 'show_field_salesforce_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

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
		add_action( 'validate_field_sf_tag_type', array( $this, 'validate_tag_type' ), 10, 3 );
		add_filter( 'wpf_get_setting_crm_tag_type', array( $this, 'get_setting_crm_tag_type' ) );
	}


	/**
	 * Loads Salesforce connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['salesforce_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'url'     => 'https://wpfusion.com/documentation/installation-guides/how-to-connect-salesforce-to-wordpress/',
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options['sf_access_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['salesforce_header']['desc']  = '<table class="form-table"><tr>';
			$new_settings['salesforce_header']['desc'] .= '<th scope="row"><label>' . esc_html__( 'Authorize', 'wp-fusion-lite' ) . '</label></th>';
			$new_settings['salesforce_header']['desc'] .= '<td><a id="dynamics-auth-btn" class="button button-primary" href="' . esc_url( $this->crm->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Authorize with %s', 'wp-fusion-lite' ), $this->name ) . '</a><br />';
			$new_settings['salesforce_header']['desc'] .= '<span class="description">' . sprintf( esc_html__( 'You\'ll be taken to %s to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ), $this->name ) . '</td>';
			$new_settings['salesforce_header']['desc'] .= '</tr></table>';

			if ( ! is_ssl() ) {
				$new_settings['salesforce_header']['desc'] .= '<p class="wpf-notice notice notice-error">' . sprintf( esc_html__( '<strong>Warning:</strong> Your site is not currently SSL secured (https://). You will not be able to connect to the %s API. Your Site Address must be set to https:// in Settings &raquo; General.', 'wp-fusion-lite' ), $this->name ) . '</p>';
			}

			$new_settings['salesforce_header']['desc'] .= '</div><table class="form-table">';

		} else {

			$new_settings['sf_instance_url'] = array(
				'title'   => __( 'Instance URL', 'wp-fusion-lite' ),
				'type'    => 'text',
				'section' => 'setup',
			);

			if ( ! empty( $options['connection_configured'] ) && 'salesforce' === wpf_get_option( 'crm' ) ) {

				$new_settings['sf_tag_type'] = array(
					'title'   => __( 'Segment Type', 'wp-fusion-lite' ),
					'std'     => 'Topics',
					'type'    => 'radio',
					'section' => 'setup',
					'choices' => array(
						'Topics'   => 'Topics',
						'Picklist' => 'Picklist',
						'Personal' => 'Personal tags',
						'Public'   => 'Public tags',
					),
					'desc'    => __( 'For more information, see <a href="https://wpfusion.com/documentation/crm-specific-docs/salesforce-tags/" target="_blank">Tags with Salesforce</a>.', 'wp-fusion-lite' ),
				);

				$new_settings['sf_tag_picklist'] = array(
					'title'    => __( 'Tags Picklist', 'wp-fusion-lite' ),
					'disabled' => isset( $options['sf_tag_type'] ) && 'Picklist' === $options['sf_tag_type'] ? false : true,
					'type'     => 'crm_field',
					'section'  => 'setup',
					'desc'     => __( 'Select a picklist type field to be used for segmentation with WP Fusion. For more information, see <a href="https://wpfusion.com/documentation/crm-specific-docs/salesforce-tags/" target="_blank">Tags with Salesforce</a>.', 'wp-fusion-lite' ),
				);

				$new_settings['sf_object_type'] = array(
					'title'   => __( 'Object Type', 'wp-fusion-lite' ),
					'type'    => 'select',
					'section' => 'setup',
					'choices' => get_option( 'wpf_salesforce_objects', array() ),
					'std'     => $this->crm->object_type,
					'desc'    => __( 'Select an object type to use with WP Fusion.', 'wp-fusion-lite' ),
				);

				$record_types = get_option( 'wpf_salesforce_record_types', array() );

				if ( ! empty( $record_types ) ) {
					$new_settings['sf_record_type'] = array(
						'title'       => __( 'Record Type', 'wp-fusion-lite' ),
						// translators: %s is the object type.
						'desc'        => sprintf( __( 'Select a record type to be used when creating new %s.', 'wp-fusion-lite' ), strtolower( $this->crm->object_type ) . 's' ),
						'section'     => 'setup',
						'type'        => 'select',
						'placeholder' => __( 'Select one', 'wp-fusion-lite' ),
						'choices'     => $record_types,
					);
				}
			}

			$new_settings['sf_access_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['sf_refresh_token'] = array(
				'title'          => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'input_disabled' => true,
				'class'          => 'api_key',
				'post_fields'    => array( 'sf_access_token', 'sf_refresh_token', 'sf_instance_url' ),
				'desc'           => '<a href="' . esc_url( $this->crm->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>. ',
			);

			$new_settings['sf_refresh_token']['desc'] .= __( 'To avoid having to repeatedly re-authorize, make sure the WP Fusion app is <a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-salesforce-to-wordpress/#complete-installation" target="_blank">completely installed</a>.', 'wp-fusion-lite' );

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Adds Salesforce specific setting fields
	 *
	 * @access  public
	 * @since   3.34.3
	 */

	public function register_settings( $settings, $options ) {

		$new_settings['salesforce_account'] = array(
			'title'       => __( 'Default Account', 'wp-fusion-lite' ),
			'desc'        => __( 'You can optionally enter a default account ID here to be used for new contact records. You can see the account ID in the URL when editing any Account record in Salesforce.', 'wp-fusion-lite' ),
			'type'        => 'text',
			'placeholder' => __( 'Account ID', 'wp-fusion-lite' ),
			'section'     => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		if ( isset( $options['sf_tag_type'] ) && 'Picklist' !== $options['sf_tag_type'] ) {

			$new_settings = array();

			$text = __(
				'<strong>Note:</strong> WP Fusion\'s import tool is based around Topics (or Tags) in your CRM.
				However with Salesforce it can be very difficult to bulk-assign topics to contact records.<br /><br />
				We recommend exporting a .csv of your contacts out of Salesforce, and using the 
				<a href="https://wordpress.org/plugins/wp-all-import/" target="_blank">WP All Import plugin</a> to import 
				your users from the .csv. As the users are imported WP Fusion will automatically link them up with
				their corresponding Salesforce contact records, and they will be enabled for sync going forward.'
			);

			$new_settings['sf_import_p'] = array(
				'desc'    => '<div class="alert alert-info">' . $text . '</div>',
				'type'    => 'paragraph',
				'section' => 'import',
			);

			$settings = wp_fusion()->settings->insert_setting_after( 'import_users_p', $settings, $new_settings );

		}

		return $settings;
	}

	/**
	 * Resync tags/topics when the tag type is saved and validate the picklist.
	 *
	 * @since  3.39.4
	 *
	 * @param  string      $input   The input.
	 * @param  array       $setting The setting configuration.
	 * @param  WPF_Options $options The options class.
	 * @return string|WP_Error The input or error on validation failure.
	 */
	public function validate_tag_type( $input, $setting, $options ) {

		if ( ! empty( $options->options['sf_tag_type'] ) && $input !== $options->options['sf_tag_type'] ) {

			if ( 'Picklist' === $input && empty( $options->post_data['sf_tag_picklist'] ) ) {
				return new WP_Error( 'error', 'To use a picklist for tags you must select a picklist from the Tags Picklist dropdown on the Setup tab.' );
			}

			// Set these temporarily so sync_tags() works.
			wp_fusion()->settings->options['sf_tag_type']     = $input;
			wp_fusion()->settings->options['sf_tag_picklist'] = $options->post_data['sf_tag_picklist'];

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
	 * Updates the UI to read "tags" when using the picklist tag selector.
	 *
	 * @since  3.40.7
	 *
	 * @param array $setting The setting.
	 * @return string The setting.
	 */
	public function get_setting_crm_tag_type( $setting ) {

		return $this->crm->tag_type;
	}

	/**
	 * Loads standard Salesforce field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( ! empty( $options['connection_configured'] ) ) {

			require_once __DIR__ . '/salesforce-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $salesforce_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $salesforce_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && 'salesforce' === $_GET['crm'] ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$params = array(
				'body' => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->crm->client_id,
					'client_secret' => $this->crm->client_secret,
					'redirect_uri'  => 'https://wpfusion.com/oauth/?action=wpf_get_salesforce_token&redirect',
					'code'          => $code,
				),
			);

			$url = apply_filters( 'wpf_salesforce_auth_url', 'https://login.salesforce.com/services/oauth2/token' );

			$response = wp_safe_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {
				return false;
			}

			wp_fusion()->settings->set( 'sf_id', $body->id );
			wp_fusion()->settings->set( 'sf_access_token', $body->access_token );
			wp_fusion()->settings->set( 'sf_refresh_token', $body->refresh_token );
			wp_fusion()->settings->set( 'sf_instance_url', $body->instance_url );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}


	/**
	 * Puts a div around the Salesforce configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_salesforce_header_begin( $id, $field ) {

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

		$instance_url = sanitize_text_field( $_POST['sf_instance_url'] );
		$access_token = sanitize_text_field( $_POST['sf_access_token'] );

		$connection = $this->crm->connect( $instance_url, $access_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options = array();

			$options['sf_access_token']       = $access_token;
			$options['sf_instance_url']       = $instance_url;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}
	}
}
