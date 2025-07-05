<?php

class WPF_EmailOctopus {

	/**
	 * CRM name.
	 *
	 * @var string
	 * @since 3.41.8
	 */
	public $name = 'Email Octopus';

	/**
	 * CRM slug.
	 *
	 * @var string
	 * @since 3.41.8
	 */
	public $slug = 'emailoctopus';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.41.8
	 */

	public $url = 'https://emailoctopus.com/api/1.6/';

	/**
	 * API Key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Default contact list.
	 *
	 * @var string
	 */
	public $default_list;


	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * @var array
	 * @since 3.41.8
	 */

	public $supports = array( 'add_tags' );

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * Not supported by EmailOctopus, we can't get the account slug over the API.
	 *
	 * @var string
	 * @since 3.41.8
	 */
	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @since 3.41.8
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-emailoctopus-admin.php';
			new WPF_EmailOctopus_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$this->api_key      = wpf_get_option( 'emailoctopus_api_key' );
		$this->default_list = wpf_get_option( 'eo_default_list' );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.41.8
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		if ( ! empty( $this->default_list ) ) {
			$this->edit_url = 'https://emailoctopus.com/lists/' . $this->default_list . '/contacts/%s';
		}
	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.41.8
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {
			return gmdate( 'Y-m-d', $value );
		} else {
			return $value;
		}
	}

	/**
	 * Formats POST data received from webhooks into standard format.
	 *
	 * @TODO EmailOctopus batches webhooks and they can contain multiple subsceribers,
	 * we'll need to update this to create a background process in case there are
	 * multiple subscribers in the payload.
	 *
	 * @since  3.41.8
	 *
	 * @param array $post_data The data read out of the webhook URL.
	 * @return array $post_data The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( $payload ) {

			$post_data['contact_id'] = $payload[0]->id;
			$post_data['tags']       = $payload[0]->contact_tags;

		}

		return $post_data;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.41.8
	 *
	 * @return array  $params The API parameters.
	 */
	public function get_params() {

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-Type' => 'application/json',
			),
		);

		return $params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.41.8
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 404 === $response_code ) {

				$response = new WP_Error( 'not_found', $body_json->error->message );

			} elseif ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

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
	 * @since  3.41.8
	 *
	 * @param  string $api_key The API Key.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$response = $this->sync_lists( $api_key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.41.8
	 */
	public function sync() {

		$this->sync_tags();

		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}

	/**
	 * Sync crm lists.
	 *
	 * @since 3.41.8
	 * @return array|WP_Error Either the available lists in the CRM, or a WP_Error.
	 */
	public function sync_lists( $api_key = false ) {

		if ( ! $api_key ) {
			$api_key = $this->api_key;
		}

		$request  = $this->url . 'lists/?api_key=' . $api_key;
		$params   = $this->get_params();
		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$available_lists = array();

		foreach ( $body->data as $list ) {
			$available_lists[ $list->id ] = $list->name;
		}

		wp_fusion()->settings->set( 'eo_lists', $available_lists );

		// Set default.
		$default_list = wpf_get_option( 'eo_default_list', false );

		if ( empty( $default_list ) ) {

			reset( $available_lists );

			$default_list = key( $available_lists );
			wp_fusion()->settings->set( 'eo_default_list', $default_list );

			$this->default_list = $default_list;

		}

		return $available_lists;
	}

	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.41.8
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request = $this->url . 'lists/' . $this->default_list . '/tags/?api_key=' . $this->api_key;
		$params  = $this->get_params();

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json->data ) ) {
			return false;
		}

		$available_tags = array();

		foreach ( $body_json->data as $tag ) {
			$available_tags[ $tag->tag ] = $tag->tag;
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.41.8
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first.
		require __DIR__ . '/admin/emailoctopus-fields.php';

		foreach ( $emailoctopus_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$params   = $this->get_params();
		$request  = $this->url . 'lists/' . $this->default_list . '/?api_key=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		if ( ! empty( $response ) && ! empty( $response->fields ) ) {
			foreach ( $response->fields as $field ) {
				// Skip built in fields.
				if ( in_array( $field->tag, array_keys( $built_in_fields ) ) ) {
					continue;
				}
				$custom_fields[ $field->tag ] = $field->label;
			}
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
	 * @since  3.41.8
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$params  = $this->get_params();
		$request = $this->url . 'lists/' . $this->default_list . '/contacts/' . md5( strtolower( $email_address ) ) . '/?api_key=' . $this->api_key;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {

			if ( 'not_found' === $response->get_error_code() ) {
				return false;
			} else {
				return $response;
			}
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.41.8
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$params  = $this->get_params();
		$request = $this->url . 'lists/' . $this->default_list . '/contacts/' . $contact_id . '/?api_key=' . $this->api_key;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response  = json_decode( wp_remote_retrieve_body( $response ) );
		$user_tags = array();

		if ( ! empty( $response->tags ) ) {
			$user_tags = $response->tags;
		}

		return $user_tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.41.8
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params  = $this->get_params();
		$request = $this->url . 'lists/' . $this->default_list . '/contacts/' . $contact_id;

		$apply_tags = array();
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$apply_tags[ $tag ] = true;
			}
		}
		$data = array(
			'api_key' => $this->api_key,
			'tags'    => $apply_tags,
		);

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PUT';
		$response         = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.41.8
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params  = $this->get_params();
		$request = $this->url . 'lists/' . $this->default_list . '/contacts/' . $contact_id;

		$remove_tags = array();
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$remove_tags[ $tag ] = false;
			}
		}
		$data = array(
			'api_key' => $this->api_key,
			'tags'    => $remove_tags,
		);

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PUT';
		$response         = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.41.8
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$params      = $this->get_params();
		$update_data = array();

		foreach ( $contact_data as $key => $value ) {
			if ( 'email_address' === $key ) {
				$update_data['email_address'] = $value;
			} elseif ( 'tags' === $key ) {
				$update_data['tags'] = $value;
			} else {
				$update_data['fields'][ $key ] = $value;
			}
		}

		$update_data['api_key'] = $this->api_key;
		$params['body']         = wp_json_encode( $update_data );
		$request                = $this->url . 'lists/' . $this->default_list . '/contacts';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.41.8
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$params      = $this->get_params();
		$update_data = array();

		foreach ( $contact_data as $key => $value ) {
			if ( 'email_address' === $key ) {
				$update_data['email_address'] = $value;
			} elseif ( 'tags' === $key ) {
				$update_data['tags'] = $value;
			} else {
				$update_data['fields'][ $key ] = $value;
			}
		}

		$update_data['api_key'] = $this->api_key;
		$params['body']         = wp_json_encode( $update_data );
		$params['method']       = 'PUT';
		$request                = $this->url . 'lists/' . $this->default_list . '/contacts/' . $contact_id;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.41.8
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$params  = $this->get_params();
		$request = $this->url . 'lists/' . $this->default_list . '/contacts/' . $contact_id . '/?api_key=' . $this->api_key;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $response['fields'] as $key => $value ) {
			$response[ $key ] = $value;
			unset( $response['fields'] );
		}

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
	 * @since 3.41.8
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		$contact_ids = array();
		$params      = $this->get_params();
		$request     = $this->url . 'lists/' . $this->default_list . '/tags/' . $tag . '/contacts/?api_key=' . $this->api_key . '&limit=100';
		$response    = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $contact ) {
				$contact_ids[] = $contact->id;
			}
		}

		return $contact_ids;
	}
}
