<?php

class WPF_Sendlane {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'sendlane';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Sendlane';

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'add_tags', 'add_fields' );

	/**
	 * Default List
	 */

	public $list;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = 'https://app.sendlane.com/contacts/%d';

	/**
	 * Get things started
	 *
	 * @since   3.24.0
	 */
	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/class-sendlane-admin.php';
			new WPF_Sendlane_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @return void
	 */
	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$this->list = wpf_get_option( 'default_list' );
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @return array
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = sanitize_email( $payload->email );

		return $post_data;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since 3.24.0
	 *
	 * @param  HTTP_Response $response HTTP Response.
	 * @param  array         $args     HTTP Request Args.
	 * @param  string        $url      URL.
	 *
	 * @return HTTP_Response|WP_Error HTTP Response or WP_Error
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'sendlane' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			if ( 202 < wp_remote_retrieve_response_code( $response ) ) {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				$message = '';

				if ( isset( $body_json->message ) ) {

					if ( 'The selected email is invalid.' === $body_json->message ) {
						return false; // this one is ok.
					}

					$message = $body_json->message . ' ';
				}

				if ( isset( $body_json->errors ) ) {

					foreach ( $body_json->errors as $error ) {
						$message .= $error[0] . ' ';
					}
				}

				$response = new WP_Error( 'error', $message );

			}
		}

		return $response;
	}

	/**
	 * Gets params for API calls
	 *
	 * @since 3.24.0
	 *
	 * @param  string $api_token API Token.
	 *
	 * @return array Params.
	 */
	public function get_params( $api_token = false ) {

		if ( ! $api_token ) {
			$api_token = wpf_get_option( 'sendlane_token' );
		}

		$this->params = array(
			'timeout'    => 15,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_token,
			),
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @return  bool
	 */
	public function connect( $api_token = false, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/senders', $this->get_params( $api_token ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @return bool
	 */
	public function sync() {

		$this->sync_tags();
		$this->sync_lists();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}

	/**
	 * Gets all available tags and saves them to options
	 *
	 * @return array Lists
	 */
	public function sync_tags() {

		$available_tags = array();

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/tags?limit=1000', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->data as $tag ) {
			$available_tags[ $tag->id ] = $tag->name;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Gets all available lists and saves them to options
	 *
	 * @return array Lists
	 */
	public function sync_lists() {

		$available_lists = array();

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/lists?limit=1000', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->data as $list ) {
			$available_lists[ $list->id ] = $list->name;
		}

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		// Set default.

		if ( ! empty( $available_lists ) && empty( wpf_get_option( 'default_list' ) ) ) {

			reset( $available_lists );
			$default_list = key( $available_lists );
			wp_fusion()->settings->set( 'default_list', $default_list );

			$this->list = $default_list;

		}

		return $available_lists;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @return array CRM Fields
	 */
	public function sync_crm_fields() {

		// Load built in fields to get field types and subtypes.
		require __DIR__ . '/sendlane-fields.php';

		$standard_fields = array();

		foreach ( $sendlane_fields as $index => $data ) {
			$standard_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $standard_fields );

		// Custom fields.

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/custom-fields?limit=1000', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$custom_fields = array();

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->data as $field ) {

			$custom_fields[ $field->id ] = $field->name;

		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $standard_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}

	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @param string $email_address The email address.
	 * @return int Contact ID
	 */
	public function get_contact_id( $email_address ) {

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/contacts/search?email=' . $email_address, $this->get_params() );

		if ( is_wp_error( $response ) || false === $response ) {
			return $response; // error or contact not found.
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->data->id;
	}


	/**
	 * Gets all tags currently applied to the contact.
	 *
	 * @param int $contact_id The contact ID.
	 * @return array The tags.
	 */
	public function get_tags( $contact_id ) {

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/contacts/' . $contact_id, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->data->tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @return bool|WP_Error True if successful, WP_Error if not.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_params();

		$params['body'] = wp_json_encode( array( 'tag_ids' => $tags ) );

		$response = wp_safe_remote_post( "https://api.sendlane.com/v2/contacts/{$contact_id}/tags/", $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Removes tags from a contact.
	 *
	 * @return bool|WP_Error True if successful, WP_Error if not.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag_id ) {

			$response = wp_safe_remote_request( "https://api.sendlane.com/v2/contacts/{$contact_id}/tags/{$tag_id}/", $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @return false|int Contact ID.
	 */
	public function add_contact( $data ) {

		$standard_fields = array( 'first_name', 'last_name', 'email', 'phone' );

		$update_data = array(
			'contacts' => array( array() ),
		);

		foreach ( $data as $key => $value ) {

			if ( in_array( $key, $standard_fields, true ) ) {
				$update_data['contacts'][0][ $key ] = $value;
			} else {

				if ( ! isset( $update_data['contacts'][0]['custom_fields'] ) ) {
					$update_data['contacts'][0]['custom_fields'] = array();
				}

				$update_data['contacts'][0]['custom_fields'][] = array(
					'id'    => $key,
					'value' => $value,
				);
			}
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $update_data );

		$response = wp_safe_remote_post( "https://api.sendlane.com/v2/lists/{$this->list}/contacts", $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// For some reason Sendlane doesn't return the contact ID when adding a new contact, so we have to look it up.

		$contact_id = $this->get_contact_id( $data['email'] );

		if ( false === $contact_id ) {
			wpf_log( 'notice', wpf_get_current_user_id(), 'Subscriber was added to Sendlane and is pending email verification.' );
		}

		return $contact_id;
	}

	/**
	 * Update contact. Sendlane doesn't have an update method, so we just trigger
	 * add_contact() again.
	 *
	 * @since  3.24.0
	 *
	 * @param  int   $contact_id Contact ID.
	 * @param  array $data       Contact data.
	 *
	 * @return bool|WP_Error True if successful, WP_Error if not.
	 */
	public function update_contact( $contact_id, $data ) {

		return $this->add_contact( $data );
	}


	/**
	 * Loads a contact and updates local user meta
	 *
	 * @return array User meta data that was returned
	 */
	public function load_contact( $contact_id ) {

		$response = wp_safe_remote_get( 'https://api.sendlane.com/v2/contacts/' . $contact_id, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$contact_data = array(
			'first_name' => $response->data->first_name,
			'last_name'  => $response->data->last_name,
			'email'      => $response->data->email,
			'phone'      => $response->data->phone,
		);

		foreach ( $response->data->custom_fields as $custom_field ) {
			$contact_data[ $custom_field->id ] = $custom_field->value;
		}

		$user_meta = array();

		// Map contact fields.
		$contact_fields = wpf_get_option( 'contact_fields', array() );

		// Standard fields.
		foreach ( $contact_data as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( $field_data['crm_field'] === $field_name && true === $field_data['active'] ) {
					$user_meta[ $meta_key ] = $value;
				}
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @return array Contact IDs returned
	 */
	public function load_contacts( $tag_id ) {

		$response = wp_safe_remote_get( "https://api.sendlane.com/v2/tags/{$tag_id}/contacts", $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$contact_ids = array();

		foreach ( $response->data as $contact ) {
			$contact_ids[] = $contact->id;
		}

		return $contact_ids;
	}
}
