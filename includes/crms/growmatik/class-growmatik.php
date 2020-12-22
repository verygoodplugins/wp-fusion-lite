<?php

class WPF_Growmatik {

	/**
	 * Contains API url
	 */

	public $url;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
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

	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {}


	/**
	 * Get user email by contact id.
	 *
	 * @param $contact_id Growmatik user id.
	 * @access private
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

			$user = $this->get_contact_by_id( $contact_id );

			return $user['email'];

		}
	}


	/**
	 * Get a user by contact id.
	 *
	 * @param $contact_id Growmatik user id.
	 * @access private
	 * @return array User data from Growmatik API.
	 */
	private function get_contact_by_id( $contact_id ) {

		$params  = $this->get_params();
		$request = $this->url . '/contact/id/';

		$params['body']['id'] = $contact_id;

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user = json_decode( wp_remote_retrieve_body( $response ), true );

		return $user['data'];
	}


	/**
	 * Get all site tags.
	 *
	 * @access private
	 * @return array $available_tags List of available tags as id => lable.
	 */
	private function get_site_tags() {
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

		return $available_tags;
	}


	/**
	 * Update user custom attributes.
	 * We use a separate API endpoint and use email to know the user.
	 *
	 * @param $contact_id Growmatic user id.
	 * @param $contact_data Data to push as new user data.
	 * @access private
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
	 * @param $contact_id Growmatic user id.
	 * @param $contact_data Data to push as new user data.
	 * @param $map_meta_fields
	 * @access private
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
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $get = true, $api_secret = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_secret ) || empty( $api_key ) ) {
			$api_secret = wp_fusion()->settings->get( 'growmatik_api_secret' );
			$api_key    = wp_fusion()->settings->get( 'growmatik_api_key' );
		}

		$params = array(
			'headers' => array(
				'apiKey' => $api_key,
			),
		);

		if ( ! $get ) {
			$params['body'] = array(
				'apiSecret' => $api_secret,
			);
		}

		return $params;
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

		if ( 500 == $response_code ) {
			return new WP_Error( $response_code, __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );
		}

		if ( 401 == $response_code ) {
			return new WP_Error( $response_code, __( 'Invalid API credentials. [error 401]', 'wp-fusion-lite' ) );
		}

		return new WP_Error( $response_code, __( 'Unknown Error', 'wp-fusion-lite' ) );
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
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
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		$available_tags = $this->get_site_tags();

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		$params   = $this->get_params();
		$request  = $this->url . '/site/attributes/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$fields = json_decode( wp_remote_retrieve_body( $response ) );

		$crm_fields = array();

		foreach ( $fields->data as $field ) {
			$crm_fields[ $field->id ] = $field->name;
		}

		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$params = $this->get_params();

		$params['body']['email'] = $email_address;

		$request  = $this->url . '/contact/email/';
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $user->data ) && isset( $user->data->userId ) ) {
			return $user->data->userId;
		}

		return new WP_Error( 404, __( 'User not found. [error 404]', 'wp-fusion-lite' ) );
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
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
				$user_tags[ strval( $tag->id ) ] = $tag->name;
			}
		}

		return $user_tags;
	}


	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {
		$request = $this->url . '/contact/tags/id';
		$params  = $this->get_params( false );

		$params['body']['tags'] = $tags;
		$params['body']['id']   = $contact_id;

		$response = wp_remote_post( $request, $params );

		$response_body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response_body->success ) && $response_body->success ) {
			return true;
		}

		return false;
	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$params  = $this->get_params( false );
		$request = $this->url . '/contact/tags/id/';

		$available_tags = $this->get_site_tags();

		$tags_to_remove = array_intersect( $available_tags, $tags );

		$params['method']       = 'DELETE';
		$params['body']['id']   = $contact_id;
		$params['body']['tags'] = array_keys( $tags_to_remove );

		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response_body->success ) && $response_body->success ) {
			return true;
		}

		return false;
	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $contact_data, $map_meta_fields = false ) {

		$params = $this->get_params( false );

		$request = $this->url . '/contact/';

		if ( ! isset( $contact_data['email'] ) ) {
			$contact_data['email'] = isset( $contact_data['user_email'] ) ? $contact_data['user_email'] : '';
		}

		$contact_data['id'] = isset( $contact_data['id'] ) ? $contact_data['id'] : 0;

		if ( $map_meta_fields ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
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

		return $results->data->userId;
	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $contact_data, $map_meta_fields = true ) {
		$update_basics  = $this->update_contact_basic_attributes( $contact_id, $contact_data, $map_meta_fields );
		$update_customs = $this->update_contact_custom_attributes( $contact_id, $contact_data );

		return ( $update_basics && $update_customs );
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$user = $this->get_contact_by_id( $contact_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( true == $field_data['active'] && isset( $user[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $user[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}
}
