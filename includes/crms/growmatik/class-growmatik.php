<?php

class WPF_Growmatik {

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.36.0
	 */

	public $url;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 *
	 * @var array
	 * @since 3.36.0
	 */

	public $supports = array();

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.36.0
	 */

	public $params = array();

	/**
	 * Get things started
	 *
	 * @since 3.36.0
	 */

	public function __construct() {

		$this->slug     = 'growmatik';
		$this->name     = 'Growmatik';
		$this->supports = array(); // Tags and Custom attributes should be synced.
		$this->url      = 'https://api.growmatik.ai/public/v1';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Growmatik_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @since 3.36.0
	 */

	public function init() {}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @since 3.36.0
	 *
	 * @param object $response The HTTP response
	 * @param array  $args     The HTTP request arguments
	 * @param string $url      The HTTP request URL
	 * @return object $response The response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 == $response_code ) {
				return $response; // Nothing more to do
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->success ) && false == $body_json->success ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 500 == $response_code ) {
				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion' ) );
			} elseif ( 401 == $response_code ) {
				$response = new WP_Error( 'error', __( 'Invalid API credentials. [error 401]', 'wp-fusion' ) );
			} elseif ( 405 == $response_code ) {
				$response = new WP_Error( 'error', __( 'Method not allowed. [error 405]', 'wp-fusion' ) );
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
	 * @param string $contact_id Growmatik user id.
	 * @return string User email.
	 */
	private function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'growmatik_contact_id',
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

	private function get_user_attributes() {
		$params  = $this->get_params();
		$request = $this->url . '/site/attributes';

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_wp_error( $body_json ) ) {
			return array();
		}

		$attributes['basics'] = array_filter(
			$body_json['data'],
			function( $array ) {
				return $array['type'] === 'basic';
			}
		);

		$keys = array(
			'001' => 'email',
			'003' => 'firstName',
			'004' => 'lastName',
			'005' => 'address',
			'006' => 'phoneNumber',
			'007' => 'country',
			'008' => 'region',
			'009' => 'city',
		);

		foreach ( $attributes['basics'] as $key => $attr ) {
			if ( isset( $keys[ $attr['id'] ] ) ) {
				$attributes['basics'][ $key ]['slug'] = $keys[ $attr['id'] ];
			}
		}

		$attributes['custom'] = array_filter(
			$body_json['data'],
			function( $array ) {
				return $array['type'] === 'custom';
			}
		);

		return $attributes;
	}

	/**
	 * Update user custom attributes.
	 * We use a separate API endpoint and use email to know the user.
	 *
	 * @since 3.36.0
	 * @access private
	 *
	 * @param string $contact_id   Growmatic user id.
	 * @param array  $contact_data Data to push as new user data.
	 * @return bool|WP_Error True on success, WP Error object on failure.
	 */
	private function update_contact_custom_attributes( $contact_id, $contact_data ) {

		$params  = $this->get_params( false );
		$request = $this->url . '/contact/attribute/email/';

		$prepared_data = array();

		foreach ( $contact_data as $name => $value ) {
			if ( ! empty( $value ) ) {
				$prepared_data[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		$params['body']['email'] = $this->get_email_from_cid( $contact_id );
		$params['body']['data']  = $prepared_data;

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_wp_error( $results ) ) {
			return true;
		}

		return $results;
	}


	/**
	 * Update user basic attributes.
	 * Same API call as add contact.
	 *
	 * @since 3.36.0
	 * @access private
	 *
	 * @param string $contact_id      Growmatic user id.
	 * @param array  $contact_data    Data to push as new user data.
	 * @param bool   $map_meta_fields Whether or not fields need to be mapped.
	 * @return bool|WP_Error True on success, WP Error object on failure.
	 */
	private function update_contact_basic_attributes( $contact_id, $contact_data, $map_meta_fields ) {

		$contact_data['id'] = $contact_id;
		$response           = $this->add_contact( $contact_data, $map_meta_fields );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_wp_error( $results ) ) {
			return true;
		}

		return $results;

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

	public function get_params( $get = true, $api_secret = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_secret ) || empty( $api_key ) ) {
			$api_secret = wp_fusion()->settings->get( 'growmatik_api_secret' );
			$api_key    = wp_fusion()->settings->get( 'growmatik_api_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'apiKey' => $api_key,
			),
		);

		if ( ! $get ) {
			$this->params['body'] = array(
				'apiSecret' => $api_secret,
			);
		}

		return $this->params;
	}


	/**
	 * Try dummy post request to make sure API credentials are valid.
	 *
	 * @access  public
	 * @return  bool|object true or WP_Error object with custom error message if connection fails.
	 */

	public function connect( $api_secret = null, $api_key = null ) {

		$params  = $this->get_params( false, $api_secret, $api_key );
		$request = $this->url . '/contacts';

		$params['body']['users'] = array();

		// Post request.
		$response      = wp_remote_post( $request, $params );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			return true;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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

		$params   = $this->get_params();
		$request  = $this->url . '/site/tags/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$available_tags = array();
		$tags           = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $tags->data as $tag ) {
			$available_tags[ strval( $tag->id ) ] = $tag->name;
		}

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
		require dirname( __FILE__ ) . '/admin/growmatik-fields.php';

		$built_in_fields = array();

		foreach ( $growmatik_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Custom fields
		$params = $this->get_params();
		// This route returns all basic and custom attributes.
		$request  = $this->url . '/site/attributes/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$fields = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		foreach ( $fields->data as $field ) {
			// Add custom attributes only.
			if ( 'custom' === $field->type ) {
				$custom_fields[ $field->id ] = $field->name;
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
	 * @since 3.36.0
	 *
	 * @param string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */

	public function get_contact_id( $email_address ) {

		$params = $this->get_params();

		$params['body']['email'] = $email_address;

		$request  = $this->url . '/contact/email/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) && 'Could not load content' == $response->get_error_message() ) {
			return false;
		} elseif ( is_wp_error( $response ) ) {
			return $response;
		}

		$user = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $user->data ) && isset( $user->data->userId ) ) {
			return $user->data->userId;
		} else {
			return false; // Not found
		}

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

		$params = $this->get_params();

		$params['body']['id'] = $contact_id;

		$request  = $this->url . '/contact/tags/id/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_tags = array();
		$tags      = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $tags->data ) ) {
			foreach ( $tags->data as $tag ) {
				$user_tags[] = $tag->id;
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
		$request = $this->url . '/contact/tags/id';
		$params  = $this->get_params( false );

		$params['body']['id']   = $contact_id;
		$params['body']['tags'] = $tags;

		$response = wp_remote_post( $request, $params );

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

		$params  = $this->get_params( false );
		$request = $this->url . '/contact/tags/id/';

		$params['method']        = 'DELETE';
		$params['body']['id']    = $contact_id;
		$params['body']['tags']  = $tags;
		$params['body']['email'] = $this->get_email_from_cid( $contact_id );

		$response = wp_remote_request( $request, $params );

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

		$params  = $this->get_params( false );
		$request = $this->url . '/contact/';

		if ( ! isset( $contact_data['email'] ) ) {
			$contact_data['email'] = isset( $contact_data['user_email'] ) ? $contact_data['user_email'] : '';
		}

		$params['body']['user'] = $contact_data;

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! $results->success ) {
			return false;
		}

		return $results->data->gmId;
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

		$attributes = $this->get_user_attributes();

		// Prepare a list of basic and custom attributes.
		$basic_attributes  = array_column( $attributes['basics'], 'slug' );
		$custom_attributes = wp_list_pluck( $attributes['custom'], 'name', 'id' );

		$contact_basic_data  = array();
		$contact_custom_data = array();

		// Separate contact data to basic and custom. We are updating them separately.
		foreach ( $contact_data as $name => $value ) {
			if ( in_array( $name, $basic_attributes, true ) ) {
				$contact_basic_data[ $name ] = $value;
			} else {
				$contact_custom_data[ $custom_attributes[ $name ] ] = $value;
			}
		}
		// Update user data and custom attributes separately.
		$update_basics  = $this->update_contact_basic_attributes( $contact_id, $contact_basic_data, $map_meta_fields );
		$update_customs = $this->update_contact_custom_attributes( $contact_id, $contact_custom_data );

		return ( $update_basics && $update_customs );
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

		$params  = $this->get_params();
		$request = $this->url . '/contact/id/';

		$params['body']['id'] = $contact_id;

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user = json_decode( wp_remote_retrieve_body( $response ), true );

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( true == $field_data['active'] && isset( $user['data'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $user['data'][ $field_data['crm_field'] ];
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

		// Not currently supported by Growmatik

		return false;

	}

}
