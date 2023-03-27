<?php

class WPF_Engage {

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.40.42
	 */

	public $url = 'https://api.engage.so';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag IDs).
	 * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
	 *
	 * "add_fields" means that custom field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var array
	 * @since 3.40.42
	 */
	public $supports = array( 'events', 'events_multi_key' );

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.40.42
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var string
	 * @since 3.40.42
	 */
	public $edit_url = 'https://app.engage.so/users/%s';

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 * @since 3.40.42
	 */

	public $tag_type = 'List';

	/**
	 * Standard attributes.
	 *
	 * @var array
	 * @since 3.40.42
	 */
	public $standard_attributes = array( 'first_name', 'last_name', 'email', 'number' );

	/**
	 * Get things started
	 *
	 * @since 3.40.42
	 */
	public function __construct() {

		$this->slug = 'engage';
		$this->name = 'Engage';

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-engage-admin.php';
			new WPF_Engage_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.40.42
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.40.42
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = date( 'm/d/Y H:i:s', $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} elseif ( 'multiselect' === $field_type && empty( $value ) ) {

			$value = null;

		} else {

			return $value;

		}

	}

	/**
	 * Formats POST data received from webhooks into standard format.
	 *
	 * @since 3.40.42
	 * 
	 * @param array $post_data The POST data.
	 * @return array The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) ) {
			$post_data['contact_id'] = sanitize_key( $payload->uid );
		}

		return $post_data;

	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.40.42
	 *
	 * @param  string $api_url The api URL.
	 * @param  string $api_key The api key.
	 * @return array  $params The API parameters.
	 */
	public function get_params( $engage_username = null, $engage_password = null ) {

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB.
		if ( empty( $engage_username ) || empty( $engage_password ) ) {
			$engage_username = wpf_get_option( 'engage_username' );
			$engage_password = wpf_get_option( 'engage_password' );
		}

		$auth_key = base64_encode( $engage_username . ':' . $engage_password );

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Basic ' . $auth_key,
				'Content-Type'  => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.40.42
	 *
	 * @return array The default fields in the CRM.
	 */
	public static function get_default_fields() {

		return array(
			'first_name'  => array(
				'crm_label' => 'First Name',
				'crm_field' => 'first_name',
			),
			'last_name'   => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'last_name',
			),
			'user_email'  => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
			),
			'billing_phone' => array(
				'crm_label' => 'Phone',
				'crm_field' => 'number',
			)
		);

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.40.42
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 401 === $response_code ) {

				$response = new WP_Error( 'error', 'Unauthorized. Please confirm your API Keys are correct and try again.' );

			} elseif ( 429 === $response_code ) {

				return new WP_Error( 'error', 'API limits exceeded. Try again later.' );

			} elseif ( $response_code > 201 && $response_code < 404 ) {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );
				$error = 'There has been an error with your request.';

				if ( ! empty( $body_json ) && isset( $body_json->error ) ) {
					$error = $body_json->error;
				}

				$response = new WP_Error( 'error', $error );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'There has been an internal error with the API.', 'wp-fusion-lite' ) );

			}
		}

		return $response;

	}


	/**
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @since  3.40.42
	 *
	 * @param  string $engage_username Public API Key.
	 * @param  string $engage_password Private API key.
	 * @param  bool   $test   				 Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $engage_username = null, $engage_password = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $engage_username, $engage_password );
		}

		$request  = $this->url . '/v1/users';
		$response = wp_safe_remote_get( $request, $this->params );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.40.42
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
	 * @since  3.40.42
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request  = $this->url . '/v1/lists';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if ( ! empty( $response ) ) {
			foreach ( $response->data as $tag ) {
				$tag_id                    = $tag->id;
				$available_tags[ $tag_id ] = sanitize_text_field( $tag->title );
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.40.42
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$request  = $this->url . '/v1/data/attributes';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$crm_meta_fields = array();
		$crm_contact_fields = array();
		$standard_fields = $this->get_default_fields();

		foreach ( $standard_fields as $data ) {
			$crm_contact_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response as $field_data ) {
			$crm_meta_fields[ $field_data->id ] = $field_data->name;
		}

		// Load available fields into $crm_fields like 'field_key' => 'Field Label'.
		asort( $crm_meta_fields );

		$crm_fields = array(
			'Standard Fields' => $crm_contact_fields,
			'Custom Fields'   => $crm_meta_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.40.42
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/v1/users?email=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->data ) ) {
			return false;
		}

		return $response->data[0]->uid;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.40.42
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $request  = $this->url . '/v1/users/' . urlencode( $contact_id );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$tags = array();
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->lists as $list ) {
			$tags[] = $list->id;
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.40.42
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$request        = $this->url . '/v1/users/' . urlencode( $contact_id ) . '/lists';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array ( 'lists' => $tags ) );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.40.42
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$request          = $this->url . '/v1/users/' . urlencode( $contact_id ) . '/lists';
		$params           = $this->get_params();
		$params['method'] = 'DELETE';
		$params['body']   = wp_json_encode( array ( 'lists' => $tags ) );

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.40.42
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$contact_id     = md5(uniqid(rand(), true));
		$request        = $this->url . '/v1/users';
		$params         = $this->get_params();
		$data = [];
		$data['id'] = $contact_id;
		foreach ($contact_data as $key => $value) {
			if (in_array($key, $this->standard_attributes)) {
				$data[$key] = $value;
			} else {
				$data['meta'][$key] = $value;
			}
		}
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.
		return $body->uid;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.40.42
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$request        = $this->url . '/v1/users/' . $contact_id;
		$params         = $this->get_params();
		$params['method'] = 'PUT';
		$data = [];
		foreach ($contact_data as $key => $value) {
			if (in_array($key, $this->standard_attributes)) {
				$data[$key] = $value;
			} else {
				$data['meta'][$key] = $value;
			}
		}
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.40.42
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/v1/users/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta           = array();
		$contact_fields      = wp_fusion()->settings->get( 'contact_fields' );
		$response            = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( $field_data['active'] ) {
				if (isset( $response[ $field_data['crm_field'] ] ) && in_array( $field_data['crm_field'], $this->standard_attributes ) ) {
				$user_meta[ $field_id ] = $response[ $field_data['crm_field'] ];
				} else if ( isset( $response['meta'][ $field_data['crm_field'] ] ) ) {
					$user_meta[ $field_id ] = $response['meta'][ $field_data['crm_field'] ];
				}
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.40.42
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		$request  = $this->url . '/v1/users?lists[]=' . $tag;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// todo: pagination
		$contact_ids = array();
		$response    = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->data as $contact ) {
			$contact_ids[] = $contact->uid;
		}

		return $contact_ids;
	}

	/**
	 * Track event.
	 *
	 * @since  3.40.42
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return; // can't track without an email.
		}

		$contact_id = $this->get_contact_id( $email_address );

		if ( ! $contact_id ) {
			return;
		}

		$body = array(
			'event' => $event,
		);

		if ( is_object( json_decode( $event_data ) ) ) {
			$body['properties'] = json_decode( $event_data );
		} else {
			$body['value'] = $event_data;
		}

		$request        = $this->url . '/v1/users/' . $contact_id . '/events';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			wpf_log( 'error', 0, 'Error tracking event: ' . $response->get_error_message() );
			return $response;
		}

		return true;
	}
}
