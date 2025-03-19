<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_HighLevel {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'highlevel';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'HighLevel';

	/**
	 * Contains API URL.
	 *
	 * @since 3.36.0
	 * @var string $url The API URL.
	 */

	public $url = 'https://services.leadconnectorhq.com/';

	/**
	 * Lets core plugin know which features are supported by the CRM.
	 *
	 * @since 3.36.0
	 * @var array $supports The supported features.
	 */

	public $supports = array( 'add_tags' );

	/**
	 * API parameters.
	 *
	 * @since 3.36.0
	 * @var array $params The API parameters.
	 */

	public $params = array();

	/**
	 * Highlevel OAuth.
	 *
	 * @since 3.41.11
	 * @var string $client_id The client ID.
	 */

	public $client_id = '640f1d950acf1dc6569948aa-lfuxfec7';

	/**
	 * Highlevel OAuth.
	 *
	 * @since 3.41.11
	 * @var string $client_secret The client secret.
	 */
	public $client_secret = '3fb38a9c-0db9-45d8-a184-016b28c2bc02';

	/**
	 * The account location id.
	 *
	 * @var string
	 */
	public $location_id;


	/**
	 * Lets us link directly to editing a contact record.
	 * Each contact has a unique id other than his account id.
	 *
	 * @var string
	 */

	public $edit_url;

	/**
	 * Get things started
	 *
	 * @since 3.36.0
	 */

	public function __construct() {

		if ( ! $this->is_v2() ) {
			$this->url = 'https://rest.gohighlevel.com/v1/';
		}

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_HighLevel_Admin( $this->slug, $this->name, $this );
		}

		// Has to be in constructor so it's available for the ThriveCart integration.
		$this->location_id = wpf_get_option( 'highlevel_location_id' );

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @since 3.36.0
	 */

	public function init() {

		$this->edit_url = 'https://app.gohighlevel.com/v2/location/' . $this->location_id . '/contacts/detail/%s';

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
	}

	/**
	 * Format post data.
	 *
	 * Extracts the CRM contact ID from POST data sent by a webhook.
	 *
	 * @since  3.36.0
	 *
	 * @param  array $post_data  The post data
	 * @return array The post data
	 */

	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = sanitize_text_field( $payload->contact_id );

		if ( ! empty( $post_data['tags'] ) ) {
			$post_data['tags'] = explode( ',', sanitize_text_field( $post_data['tags'] ) );
		}

		return $post_data;
	}


	/**
	 * Format field values to match HighLevel formats.
	 *
	 * @since  3.37.11
	 *
	 * @param  string $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field.
	 * @return mixed  The formatted value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		$field_types = wpf_get_option( 'crm_field_types', array() );

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.
			$value = gmdate( 'Y-m-d', $value );

		} elseif ( 'date' === $field_type && empty( $value ) ) {

			// return ''; // GHL converts empty dates to 1/1/1970. This will prevent them from syncing at all.

			return $value; // as of 3.41.33, GHL now seems to be able to handle empty dates.

		} elseif ( isset( $field_types[ $field ] ) ) {

			// HighLevel will throw an error if phone number is not formatted correctly.

			if ( 'PHONE' === $field_types[ $field ] && ! wpf_validate_phone_number( $value ) ) {

				wpf_log( 'notice', wpf_get_current_user_id(), 'Invalid phone number: <code>' . $value . '</code> for field <code>' . $field . '</code>. Value will not be synced.' );
				$value = ''; // returning an empty string will omit the field from the data.

			}
		} elseif ( is_array( $value ) && ! isset( $field_types[ $field ] ) ) {

			// Text fields will throw an error receiving array data.

			$value = implode( ', ', $value );

		}

		return $value;
	}

	/**
	 * Handle HTTP response.
	 *
	 * Check HTTP Response for errors and return a WP_Error if found.
	 *
	 * @since 3.36.0
	 *
	 * @param object $response The HTTP response.
	 * @param array  $args     The HTTP request arguments.
	 * @param string $url      The HTTP request URL.
	 * @return WP_HTTP_Response|WP_Error The response, or error.
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 === $response_code || 201 === $response_code ) {

				return $response; // Success. Nothing more to do

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

			} else {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->message ) && is_array( $body_json->message ) ) {
					$body_json->message = implode( ' ', $body_json->message );
				}

				if ( ( 403 === $response_code || 401 === $response_code ) && isset( $body_json->message ) && false === strpos( $url, 'token' ) ) {

					if (
						'The token does not have access to this location.' === $body_json->message ||
						strpos( $body_json->message, 'access token' ) !== false ||
						strpos( $body_json->message, 'refresh token' ) !== false ||
						'Invalid JWT' === $body_json->message ||
						( isset( $body_json->error_description ) && false !== strpos( $body_json->error_description, 'expired' ) )
					) {
						// Try to refresh the access token.
						$access_token = $this->refresh_token();
					} else {
						$access_token = false;
					}

					if ( is_wp_error( $access_token ) || empty( $access_token ) ) {
						// translators: %s is the error message.
						return new WP_Error( 'error', sprintf( __( 'Error refreshing access token: %s.', 'wp-fusion-lite' ), $body_json->message ) );
					}

					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} elseif ( isset( $body_json->error_description ) ) {

					$response = new WP_Error( 'error', $body_json->error_description );

				} elseif ( isset( $body_json->message ) ) {

					// Maybe append the metadata.

					if ( isset( $body_json->meta ) ) {
						$body_json->message .= ' <pre>' . print_r( $body_json->meta, true ) . '</pre>';
					}

					$response = new WP_Error( 'error', $body_json->message );

				} elseif ( isset( $body_json->error ) ) {

					// Just error, no message.
					$response = new WP_Error( 'error', $body_json->error );

				}
			}
		}

		return $response;
	}

	/**
	 * Checks the API version based on auth.
	 *
	 * We need to keep this so that https://wpfusion.com/documentation/crm-specific-docs/highlevel-white-labelled-accounts/#overview works.
	 *
	 * @since 3.41.11
	 *
	 * @return bool True if v2, false if v1.
	 */
	public function is_v2() {

		if ( wpf_get_option( 'highlevel_token' ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get the access token for the current location.
	 *
	 * @since 3.44.26
	 *
	 * @return string|WP_Error The access token or error.
	 */
	public function refresh_location_token() {

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . wpf_get_option( 'highlevel_token' ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
				'Version'       => '2021-07-28',
			),
			'body'       => array(
				'companyId'  => wpf_get_option( 'highlevel_company_id' ),
				'locationId' => $this->location_id,
			),
		);

		$response = wp_remote_post( 'https://services.leadconnectorhq.com/oauth/locationToken', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'highlevel_token_' . $this->location_id, $response->access_token );

		return $response->access_token;

	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @since 3.34.11
	 *
	 * @return string|WP_Error The access token, or error.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( 'highlevel_refresh_token' );

		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'error', 'Authorization failed and no refresh token found.' );
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'       => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uri'  => admin_url( 'options-general.php?page=wpf-settings&crm=highlevel' ),
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_safe_remote_post( $this->url . 'oauth/token', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'highlevel_token', $body_json->access_token );
		wp_fusion()->settings->set( 'highlevel_refresh_token', $body_json->refresh_token );

		if ( wpf_get_option( 'highlevel_locations' ) ) {
			// If we need to refresh the location token, do it now.
			$access_token = $this->refresh_location_token();
		} else {
			$access_token = $body_json->access_token;
		}

		$this->get_params( $access_token );

		return $access_token;
	}


	/**
	 * Gets params for API calls
	 *
	 * Adds apiSecret for non-GET requests
	 *
	 * @since 3.36.0
	 *
	 * @return array $params The API params.
	 */

	public function get_params( $access_token = null ) {

		if ( ! $this->is_v2() ) {

			// API key based authorization.
			$access_token = wpf_get_option( 'highlevel_api_key' );

		} elseif ( empty( $access_token ) && empty( wpf_get_option( 'highlevel_locations' ) ) ) {

			// Single account authorization.
			$access_token = wpf_get_option( 'highlevel_token' );

		} elseif ( ! empty( wpf_get_option( 'highlevel_locations' ) ) ) {

			// Sub-accounts, make sure we have the right access token.
			$access_token = wpf_get_option( 'highlevel_token_' . $this->location_id );

			if ( empty( $access_token ) ) {
				$location_access_token = $this->refresh_location_token();

				if ( ! is_wp_error( $location_access_token ) ) {
					$access_token = $location_access_token;
				}
			}
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Version'       => '2021-07-28',
			),
		);

		return $this->params;
	}


	/**
	 * Test the connection
	 *
	 * @access  public
	 * @return  bool|object true or WP_Error object with custom error message if connection fails.
	 */

	public function connect( $access_token = null, $location_id = null, $test = false ) {

		if ( $location_id ) {
			$this->location_id = $location_id;
		}

		$params = $this->get_params( $access_token );

		if ( ! $test ) {
			return true;
		}

		$response = wp_safe_remote_get( $this->url . 'contacts/?locationId=' . $location_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.36.0
	 *
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since 3.36.0
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */

	public function sync_tags() {
		if ( $this->is_v2() ) {
			$response = wp_safe_remote_get( $this->url . 'locations/' . $this->location_id . '/tags/', $this->get_params() );
		} else {
			$response = wp_safe_remote_get( $this->url . 'tags/', $this->get_params() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response       = json_decode( wp_remote_retrieve_body( $response ) );
		$available_tags = array();

		foreach ( $response->tags as $tag ) {
			$available_tags[ $tag->name ] = $tag->name;
		}

		natcasesort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since 3.36.0
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */

	public function sync_crm_fields() {
		// Load built in fields first
		require __DIR__ . '/admin/highlevel-fields.php';

		$built_in_fields = array();

		foreach ( $highlevel_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Custom fields
		if ( $this->is_v2() ) {
			$response = wp_safe_remote_get( $this->url . 'locations/' . $this->location_id . '/customFields', $this->get_params() );
		} else {
			$response = wp_safe_remote_get( $this->url . 'custom-fields/', $this->get_params() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		$custom_fields = array();
		$field_types   = array( 'phone' => 'PHONE' );

		foreach ( $response->customFields as $field ) { //phpcs:ignore
			$custom_fields[ $field->id ] = $field->name;

			if ( 'TEXT' !== $field->{'dataType'} ) {
				$field_types[ $field->id ] = $field->{'dataType'};
			}
		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );
		wp_fusion()->settings->set( 'crm_field_types', $field_types );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since 3.36.0
	 *
	 * @param string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */

	public function get_contact_id( $email_address ) {

		if ( $this->is_v2() ) {
			$request = $this->url . 'contacts/?locationId=' . $this->location_id . '&query=' . urlencode( $email_address );
		} else {
			$request = $this->url . 'contacts/lookup?email=' . urlencode( $email_address );
		}

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return $response->contacts[0]->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.36.0
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */

	public function get_tags( $contact_id ) {

		$request  = $this->url . 'contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response  = json_decode( wp_remote_retrieve_body( $response ) );
		$user_tags = array();

		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( empty( $response->contact->tags ) ) {
			return $user_tags;
		}

		foreach ( $response->contact->tags as $tag ) {

			$user_tags[] = $tag;

			// Update the local storage if it's a new tag
			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
				wp_fusion()->settings->set( 'available_tags', $available_tags );
			}
		}

		return $user_tags;
	}


	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.36.0
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */

	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_params();

		if ( $this->is_v2() ) {

			$user_tags = $this->get_tags( $contact_id );

			if ( is_wp_error( $user_tags ) ) {
				return $user_tags;
			}

			$tags             = array_merge( $user_tags, $tags );
			$data             = array( 'tags' => $tags );
			$params['method'] = 'PUT';
			$params['body']   = wp_json_encode( $data );

			$request  = $this->url . 'contacts/' . $contact_id;
			$response = wp_safe_remote_request( $request, $params );

		} else {

			$request = $this->url . 'contacts/' . $contact_id . '/tags/';
			$data    = (object) array( 'tags' => $tags );

		}

		$params['body'] = wp_json_encode( $data );
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Removes tags from a contact.
	 *
	 * @since 3.36.0
	 *
	 * @param array $tags       A numeric array of tags to remove from the contact.
	 * @param int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */

	public function remove_tags( $tags, $contact_id ) {

		$params = $this->get_params();

		$tags = array_map( 'strtolower', $tags ); // GHL tags are lowercase.

		if ( $this->is_v2() ) {

			$user_tags = $this->get_tags( $contact_id );

			if ( is_wp_error( $user_tags ) ) {
				return $user_tags;
			}

			if ( empty( $user_tags ) ) {
				return true;
			}

			foreach ( $tags as $tag ) {
				$key = array_search( $tag, $user_tags );
				if ( $key !== false ) {
					unset( $user_tags[ $key ] );
				}
			}

			$user_tags = array_values( $user_tags );

			$data             = array( 'tags' => $user_tags );
			$params['method'] = 'PUT';
			$params['body']   = wp_json_encode( $data );
			$request          = $this->url . 'contacts/' . $contact_id;

		} else {
			$request = $this->url . 'contacts/' . $contact_id . '/tags/';

			$data = (object) array( 'tags' => $tags );

			$params['body']   = wp_json_encode( $data );
			$params['method'] = 'DELETE';
		}

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Formats contact data for API updates..
	 *
	 * @since 3.42.3
	 *
	 * @param array $contact_data The unformatted contact data.
	 * @return array The formatted contact data.
	 */
	public function format_contact_data( $contact_data ) {

		if ( $this->is_v2() ) {
			$custom_field_name = 'customFields';
		} else {
			$custom_field_name = 'customField';
		}
		// Separate the built in fields from custom ones.
		$crm_fields = wpf_get_option( 'crm_fields' );

		foreach ( $contact_data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $contact_data[ $custom_field_name ] ) ) {
					$contact_data[ $custom_field_name ] = array();
				}

				if ( $this->is_v2() ) {
					$contact_data[ $custom_field_name ][] = array(
						'id'          => $key,
						'field_value' => $value,
					);
					unset( $contact_data[ $key ] );
				} else {
					$contact_data['customField'][ $key ] = $value;
				}
			}
		}

		return $contact_data;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.36.0
	 *
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$contact_data = $this->format_contact_data( $contact_data );

		$contact_data['locationId'] = $this->location_id;
		$params                     = $this->get_params();
		$params['body']             = wp_json_encode( $contact_data );

		$response = wp_safe_remote_post( $this->url . 'contacts/', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->contact->id;
	}


	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.36.0
	 *
	 * @param int   $contact_id      The ID of the contact to update.
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$contact_data = $this->format_contact_data( $contact_data );

		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $contact_data );

		$request  = $this->url . 'contacts/' . $contact_id;
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.36.0
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */

	public function load_contact( $contact_id ) {

		$request  = $this->url . 'contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		$response = $response['contact'];

		if ( $this->is_v2() ) {
			$custom_field_name = 'customFields';
		} else {
			$custom_field_name = 'customField';
		}

		// Load the custom fields up into the main response.

		if ( isset( $response[ $custom_field_name ] ) ) {

			foreach ( $response[ $custom_field_name ] as $field ) {
				$response[ $field['id'] ] = $field['value'];
			}

			unset( $response[ $custom_field_name ] );
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.36.0
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */

	public function load_contacts( $tag = false ) {

		$page        = 1;
		$proceed     = true;
		$contact_ids = array();
		if ( $this->is_v2() ) {
			$main_request = "{$this->url}contacts/?locationId=" . $this->location_id . '';
		} else {
			$main_request = "{$this->url}contacts/";
		}

		$url = add_query_arg( 'limit', '100', $main_request );

		if ( $tag ) {
			$url = add_query_arg( 'query', $tag, $url );
		}

		while ( $proceed ) {
			$url = add_query_arg( 'page', $page, $url );

			$response = wp_safe_remote_get( $url, $this->get_params() );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->contacts as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if ( count( $response->contacts ) < 100 ) {
				$proceed = false;
			} else {
				++$page;
			}
		}

		return $contact_ids;
	}
}

