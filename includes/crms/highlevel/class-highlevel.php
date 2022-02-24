<?php

class WPF_HighLevel {

	/**
	 * Contains API URL.
	 *
	 * @since 3.36.0
	 * @var string $url The API URL.
	 */

	public $url = 'https://rest.gohighlevel.com/v1/';

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
	 * Lets us link directly to editing a contact record.
	 * Each contact has a unique id other than his account id.
	 * @var string
	 */

	public $edit_url = false;

	/**
	 * Get things started
	 *
	 * @since 3.36.0
	 */

	public function __construct() {

		$this->slug = 'highlevel';
		$this->name = 'HighLevel';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_HighLevel_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @since 3.36.0
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
	}

	/**
	 * Get user edit url
	 *
	 * @param string $email_address
	 * @param integer $user_id
	 * @return string
	 */
	public function get_user_edit_url( $email_address, $user_id ) {
		if ( empty( $email_address ) ) {
			return;
		}

		$edit_url = get_user_meta( $user_id, 'wpf_highlevel_edit_url', true );
		if ( ! empty( $edit_url ) ) {
			return $edit_url;
		}

		$request  = $this->url . 'contacts/lookup?email=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) && 'email: The email address is invalid.' == $response->get_error_message() ) {

			// Contact not found
			return false;

		} elseif ( is_wp_error( $response ) ) {

			// Generic error
			return false;

		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		$contact  = $response->contacts[0];
		$edit_url = 'https://app.gohighlevel.com/location/' . $contact->locationId . '/customers/detail/' . $contact->id . '';
		update_user_meta( $user_id, 'wpf_highlevel_edit_url', $edit_url );
		return $edit_url;
	}

	/**
	 * Format post data.
	 *
	 * Extracts the CRM contact ID from POST data sent by a webhook.
	 *
	 * @since  3.36.0
	 *
	 * @param  array  $post_data  The post data
	 * @return array The post data
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = absint( $payload->contact_id );

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

		if ( 'date' == $field_type || 'datepicker' == $field_type ) {

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( 'checkbox' == $field_type && $value == null ) {

			// Sendinblue only treats false as a No for checkboxes
			return false;

		} elseif ( 'checkbox' == $field_type && ! empty( $value ) ) {

			// Sendinblue only treats true as a Yes for checkboxes
			return true;

		} elseif ( 'tel' == $field_type ) {

			// Format phone. Sendinblue requires a country code and + for phone numbers. With or without dashes is fine

			if ( strpos( $value, '+' ) !== 0 ) {

				// Default to US if no country code is provided

				if ( strpos( $value, '1' ) === 0 ) {

					$value = '+' . $value;

				} else {

					$value = '+1' . $value;

				}
			}

			return $value;

		} elseif ( ! is_array( $value ) && is_numeric( trim( str_replace( array( '-', ' ' ), '', $value ) ) ) ) {

			$length = strlen( trim( str_replace( array( '-', ' ' ), '', $value ) ) );

			// Maybe another phone number

			if ( 10 == $length ) {

				// Let's assume this is a US phone number and needs a +1

				$value = '+1' . $value;

			} elseif ( $length >= 11 && $length <= 13 && strpos( $value, '+' ) === false ) {

				// Let's assume this is a phone number and needs a plus??

				$value = '+' . $value;

			}

			return $value;

		} else {

			return $value;

		}

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

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 == $response_code ) {

				return $response; // Success. Nothing more to do

			} elseif ( 500 == $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

			} else {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->msg ) ) {

					// Single error message

					$response = new WP_Error( 'error', $body_json->msg );

				} else {

					// Multiple errors

					$messages = array();

					foreach ( $body_json as $field => $error ) {
						$messages[] = $field . ': ' . $error->message;
					}

					$response = new WP_Error( 'error', implode( ' ', $messages ) );

				}
			}
		}

		return $response;

	}


	/**
	 * Get user email by contact id.
	 *
	 * @since 3.36.0
	 * @access private
	 *
	 * @param string $contact_id HighLevel user id.
	 * @return string User email.
	 */
	private function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'highlevel_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			$user = $this->load_contact( $contact_id );

			return $user['user_email'];

		}
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

	public function get_params( $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'highlevel_api_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
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

	public function connect( $api_key = null, $test = false ) {

		$params = $this->get_params( $api_key );

		if ( ! $test ) {
			return true;
		}

		$response = wp_safe_remote_get( $this->url . 'contacts/', $params );

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

		$response = wp_safe_remote_get( $this->url . 'tags/', $this->get_params() );

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
		require dirname( __FILE__ ) . '/admin/highlevel-fields.php';

		$built_in_fields = array();

		foreach ( $highlevel_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Custom fields
		$response = wp_safe_remote_get( $this->url . 'custom-fields/', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		$custom_fields = array();

		foreach ( $response->customFields as $field ) { //phpcs:ignore
			$custom_fields[ $field->id ] = $field->name;
		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

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

		$request  = $this->url . 'contacts/lookup?email=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) && 'email: The email address is invalid.' == $response->get_error_message() ) {

			// Contact not found
			return false;

		} elseif ( is_wp_error( $response ) ) {

			// Generic error
			return $response;

		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

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

		$request = $this->url . 'contacts/' . $contact_id . '/tags/';
		$params  = $this->get_params();

		$data = (object) array( 'tags' => $tags );

		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

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

		$request = $this->url . 'contacts/' . $contact_id . '/tags/';
		$params  = $this->get_params();

		$data = (object) array( 'tags' => $tags );

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'DELETE';

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.36.0
	 *
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @param bool  $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */

	public function add_contact( $contact_data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		// Separate the built in fields from custom ones
		$crm_fields = wpf_get_option( 'crm_fields' );

		foreach ( $contact_data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $contact_data['customField'] ) ) {
					$contact_data['customField'] = array();
				}

				$contact_data['customField'][ $key ] = $value;

			}
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $contact_data );

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
	 * @param bool  $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 * @return bool|WP_Error Error if the API call failed.
	 */

	public function update_contact( $contact_id, $contact_data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		// Separate the built in fields from custom ones
		$crm_fields = wpf_get_option( 'crm_fields' );

		foreach ( $contact_data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $contact_data['customField'] ) ) {
					$contact_data['customField'] = array();
				}

				$contact_data['customField'][ $key ] = $value;

			}
		}

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

		// Load the custom fields up into the main response

		if ( isset( $response['customField'] ) ) {

			foreach ( $response['customField'] as $field ) {
				$response[ $field['id'] ] = $field['value'];
			}

			unset( $response['customField'] );
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true == $field_data['active'] && isset( $response[ $field_data['crm_field'] ] ) ) {
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

	public function load_contacts( $tag ) {

		$page        = 1;
		$proceed     = true;
		$contact_ids = array();

		while ( $proceed ) {

			$request  = "{$this->url}contacts/?page={$page}&limit=100&query={$tag}";
			$response = wp_safe_remote_get( $request, $this->get_params() );

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
				$page++;
			}
		}

		return $contact_ids;

	}

}
