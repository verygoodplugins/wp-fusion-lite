<?php

class WPF_Maropost {

	/**
	 * Contains API params
	 */

	public $params;


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

		$this->slug     = 'maropost';
		$this->name     = 'Maropost';
		$this->supports = array( 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Maropost_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 100, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

	}



	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$maropost_payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = $maropost_payload->contact->id;

		return $post_data;

	}

	/**
	 * Formats user entered data to match Maropost field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

		} else {

			return $value;

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'maropost' ) !== false ) {

			$code = wp_remote_retrieve_response_code( $response );

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error );

			} elseif ( $code == 401 ) {

				$response = new WP_Error( 'error', 'Invalid API key.' );

			} elseif ( $code == 403 ) {

				$response = new WP_Error( 'error', 'Forbidden.' );

			} elseif ( $code == 422 ) {

				if ( ! empty( $body_json ) ) {

					$response = new WP_Error( 'error', 'Unprocessable entity: ' . print_r( $body_json, true ) );

				} else {
					$response = new WP_Error( 'error', 'Unprocessable entity.' );
				}

			}
		}

		return $response;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $account_id = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $account_id ) || empty( $api_key ) ) {
			$account_id = wp_fusion()->settings->get( 'account_id' );
			$api_key    = wp_fusion()->settings->get( 'maropost_key' );
		}

		$this->account_id = $account_id;
		$this->api_key    = $api_key;

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 20,
			'headers'    => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $account_id = null, $api_key = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $account_id, $api_key );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.maropost.com/accounts/' . $this->account_id . '/lists.json?auth_token=' . $this->api_key;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {

			return $response;
		}

		return true;
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
		$this->sync_lists();

		do_action( 'wpf_sync' );

		return true;

	}

	/**
	 * Maropost sometimes requires an email to be submitted when contacts are modified
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'maropost_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {
			return $users[0]->user_email;
		} else {

			// Load contact

			$user_meta = $this->load_contact( $contact_id );

			if ( ! empty( $user_meta['user_email'] ) ) {
				return $user_meta['user_email'];
			}
		}

		return false;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$request  = 'https://api.maropost.com/accounts/' . $this->account_id . '/tags.json?auth_token=' . $this->api_key;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $tag ) {
			$available_tags[ $tag['name'] ] = $tag['name'];
		}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/maropost-fields.php';

		$built_in_fields = array();

		foreach ( $maropost_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();
		$request       = 'http://api.maropost.com/accounts/' . $this->account_id . '/custom_fields.json?auth_token=' . $this->api_key;
		$response      = wp_remote_get( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $field_data ) {

			if ( ! empty( $field_data['display_name'] ) ) {
				$custom_fields[ $field_data['name'] ] = $field_data['display_name'];
			} else {
				$custom_fields[ $field_data['name'] ] = $field_data['name'];
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
	 * Loads lists from CRM and merges with local copy
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$mp_lists = array();

		$request  = 'http://api.maropost.com/accounts/' . $this->account_id . '/lists.json?auth_token=' . $this->api_key;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $list ) {
			$mp_lists[ $list['id'] ] = $list['name'];

		}

		wp_fusion()->settings->set( 'maropost_lists', $mp_lists );

		return $mp_lists;

	}

	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'http://api.maropost.com/accounts/' . $this->account_id . '/contacts/email.json?contact%5Bemail%5D=' . $email_address . '&auth_token=' . $this->api_key;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) || ! isset( $body_json['id'] ) ) {
			return false;
		}

		return $body_json['id'];
	}




	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$tags     = array();
		$mp_lists = wp_fusion()->settings->get( 'mp_list' );

		$request  = 'http://api.maropost.com/accounts/' . $this->account_id . '/lists/' . $mp_lists . '/contacts/' . $contact_id . '.json?auth_token=' . $this->api_key;
		$response = wp_remote_get( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['tags'] ) ) {
			return $tags;
		}

		foreach ( $body_json['tags'] as $tag ) {
			$tags[] = $tag['name'];
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$email = $this->get_email_from_cid( $contact_id );

		$request                           = 'http://api.maropost.com/accounts/' . $this->account_id . '/add_remove_tags.json?auth_token=' . $this->api_key;
		$params['headers']['Content-Type'] = 'application/json';
		$params['body']                    = json_encode(
			array(
				'tags' => array(
					'email'    => $email,
					'add_tags' => $tags,
				),
			)
		);
		$params['method']                  = 'PUT';

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$email = $this->get_email_from_cid( $contact_id );

		$request                           = 'http://api.maropost.com/accounts/' . $this->account_id . '/add_remove_tags.json?auth_token=' . $this->api_key;
		$params['body']                    = json_encode(
			array(
				'tags' => array(
					'email'       => $email,
					'remove_tags' => $tags,
				),
			)
		);
		$params['headers']['Content-Type'] = 'application/json';
		$params['method']                  = 'PUT';

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$mp_list = wp_fusion()->settings->get( 'mp_list' );

		// If no list set, use the first list
		if ( empty( $mp_list ) ) {
			$all_lists = wp_fusion()->settings->get( 'maropost_lists' );
			$mp_list   = array_keys( $all_lists )[0];
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		$standard_fields = $crm_fields['Standard Fields'];
		$custom_fields   = $crm_fields['Custom Fields'];

		$custom_data   = array_intersect_key( $data, $custom_fields );
		$standard_data = array_intersect_key( $data, $standard_fields );

		$field_data = array(
			'contact' => $standard_data,
		);

		if ( ! empty( $custom_data ) ) {
			$field_data['custom_field'] = $custom_data;
		}

		$url            = 'http://api.maropost.com/accounts/' . $this->account_id . '/lists/' . $mp_list . '/contacts.json?auth_token=' . $this->api_key;
		$params         = $this->params;
		$params['body'] = json_encode( $field_data );

		$response = wp_remote_post( $url, $params );

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $body->id;

	}



	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );
		$send       = false;

		$standard_fields = $crm_fields['Standard Fields'];
		$custom_fields   = $crm_fields['Custom Fields'];

		foreach ( $standard_fields as $key => $crm_field ) {
			foreach ( $custom_fields as $cf_key => $cf_field ) {
				if ( isset( $data[ $key ] ) || isset( $data[ $cf_key ] ) ) {
					$send = true;
				}
			}
		}

		$custom_data   = array_intersect_key( $data, $custom_fields );
		$standard_data = array_intersect_key( $data, $standard_fields );

		$field_data = array(
			'contact'      => $standard_data,
			'custom_field' => $custom_data,
		);

		if ( $send == true ) {

			$url                               = 'http://api.maropost.com/accounts/' . $this->account_id . '/contacts/' . $contact_id . '.json?auth_token=' . $this->api_key;
			$params['body']                    = json_encode( $field_data );
			$params['headers']['Content-Type'] = 'application/json';
			$params['method']                  = 'PUT';

			$response = wp_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$mp_list = wp_fusion()->settings->get( 'mp_list' );

		$url      = 'http://api.maropost.com/accounts/' . $this->account_id . '/lists/' . $mp_list . '/contacts/' . $contact_id . '.json?auth_token=' . $this->api_key;
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $body_json as $key => $field ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && $key == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field;
				}
			}
		}

		return $user_meta;

	}


	/**
	 * Gets a list of contact IDs based on list
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();
		$proceed     = true;

		$list = wp_fusion()->settings->get( 'mp_list' );

		if ( $proceed == true ) {

			$url     = 'http://api.maropost.com/accounts/' . $this->account_id . '/lists/' . $list . '/contacts.json?auth_token=' . $this->api_key;
			$results = wp_remote_get( $url );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json as $contact ) {
				$contact_ids[] = $contact['id'];
			}
		}

		if ( count( $body_json ) < 50 ) {
			$proceed = false;
		}

		return $contact_ids;

	}

}
