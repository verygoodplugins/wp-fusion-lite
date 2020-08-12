<?php

class WPF_UserEngage {

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * API url for the account
	 */

	public $domain;


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

		$this->slug     = 'userengage';
		$this->name     = 'User.com';
		$this->supports = array( 'add_tags', 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_UserEngage_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

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

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = $payload->user->id;

		return $post_data;

	}

	/**
	 * Formats user entered data to match Userengage field formats
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

		if ( strpos( $url, 'userengage' ) !== false ) {

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code == 401 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				$message = '401 error.';

				if ( ! empty( $body ) && isset( $body->detail ) ) {
					$message .= ' ' . $body->detail;
				}

				$response = new WP_Error( 'error', $message );

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

	public function get_params( $domain = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $domain ) ) {
			$domain = wp_fusion()->settings->get( 'userengage_domain' );
		}

		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'userengage_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'Authorization' => 'Token ' . $api_key,
				'Content-Type'  => 'application/json',
			),
		);

		$this->api_url = 'https://' . $domain . '.user.com/api/public/';

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $domain = null, $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $domain, $api_key );
		}

		$request  = $this->api_url . 'users/';
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$request  = $this->api_url . 'tags/';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );
		$ue_tags   = $body_json['results'];

		foreach ( $ue_tags as $tag ) {
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
		require dirname( __FILE__ ) . '/admin/userengage-fields.php';

		$built_in_fields = array();

		foreach ( $userengage_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();
		$request       = 'https://app.userengage.com/api/public/attributes/';
		$response      = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );
		$body_results = $body_json['results'];

		if ( isset( $body_results[0] ) && is_array( $body_results[0] ) ) {

			foreach ( $body_results as $field_data ) {

				$custom_fields[ $field_data['name'] ] = $field_data['name'];

			}
		}

		$custom_fields['user_id'] = 'User ID';

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_info = array();
		$request      = $this->api_url . 'users/search/?email=' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
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
		$request  = $this->api_url . 'users/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( ! empty( $body_json['tags'] ) ) {
			foreach ( $body_json['tags'] as $tag ) {
				$tags[] = $tag['name'];
			}
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

		foreach ( $tags as $tag ) {

			$request        = $this->api_url . 'users/' . $contact_id . '/add_tag/';
			$params         = $this->params;
			$params['body'] = json_encode( array( 'name' => $tag ) );

			$response = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
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

		foreach ( $tags as $tag ) {

			$request          = $this->api_url . 'users/' . $contact_id . '/remove_tag/';
			$params           = $this->params;
			$params['method'] = 'DELETE';

			$response = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$url            = $this->api_url . 'users/';
		$params         = $this->params;
		$params['body'] = json_encode( $data );

		$response = wp_remote_post( $url, $params );

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		if ( empty( $crm_fields['Custom Fields'] ) ) {
			return $body->id;
		}

		$attributes = array();

		foreach ( $crm_fields['Custom Fields'] as $key => $field ) {

			if ( isset( $data[ $key ] ) ) {
				$attributes[ $key ] = $data[ $key ];
			}
		}

		if ( ! empty( $attributes ) ) {
			$url            = $this->api_url . 'users/' . $body->id . '/set_multiple_attributes/';
			$params         = $this->params;
			$params['body'] = json_encode( $attributes );

			$response = wp_remote_post( $url, $params );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

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

		$send = false;

		foreach ( $crm_fields['Standard Fields'] as $key => $crm_field ) {
			if ( isset( $data[ $key ] ) ) {
				$send = true;
			}
		}

		if ( $send ) {

			$url              = $this->api_url . 'users/' . $contact_id;
			$params           = $this->params;
			$params['body']   = json_encode( $data );
			$params['method'] = 'PUT';

			$response = wp_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( empty( $crm_fields['Custom Fields'] ) ) {
			return true;
		}

		foreach ( $crm_fields['Custom Fields'] as $key => $field ) {

			if ( isset( $data[ $key ] ) ) {
				$attributes[ $key ] = $data[ $key ];
			}
		}

		if ( ! empty( $attributes ) ) {

			$url            = $this->api_url . 'users/' . $contact_id . '/set_multiple_attributes/';
			$params         = $this->params;
			$params['body'] = json_encode( $attributes );
			$response       = wp_remote_post( $url, $params );

			$response = json_decode( wp_remote_retrieve_body( $response ) );

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

		$url      = $this->api_url . 'users/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$name                    = $body_json['name'];
		$exploded_name           = explode( ' ', $name );
		$body_json['first_name'] = $exploded_name[0];
		unset( $exploded_name[0] );
		$body_json['last_name'] = implode( ' ', $exploded_name );

		foreach ( $body_json as $key => $field ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && $key == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field;
				}
			}
		}

		foreach ( $body_json['attributes'] as $attribute ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && $attribute['name_std'] == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $attribute['value'];
				}
			}
		}

		return $user_meta;

	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		// not possible
	}


}
