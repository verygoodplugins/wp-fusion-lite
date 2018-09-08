<?php

class WPF_ConvertFox {

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


		$this->slug     = 'convertfox';
		$this->name     = 'ConvertFox';
		$this->supports = array( 'add_fields', 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_ConvertFox_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {	

		if( strpos($url, 'convertfox') !== false && strpos($url, '?email=') === false) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0]->message );

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

	public function get_params( $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'convertfox_key' );
		}

		$this->params = array(
			'timeout'     => 30,
			'headers'     => array( 
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json'
			)
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = "https://api.convertfox.com/tags";
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body_json->errors ) ) {
			return new WP_Error( 'error', 'Unauthorized. Make sure you\'re using an <strong>Access Token</strong> which can be found in Settings >> API and Integrations >> Access Token in your Convertfox account.' );
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

		$request  = "https://api.convertfox.com/tags";
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['tags'] as $tag ) {
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
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';

		$built_in_fields = array();

		foreach ( $convertfox_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request    = "https://api.convertfox.com/users?page=1&per_page=1";
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( isset( $body_json['users'] ) && is_array( $body_json['users'] ) ) {

			foreach ( $body_json['users'] as $field_data ) {

					foreach ($field_data['custom_properties'] as $key => $value) {

						$custom_fields[ $key ] = $key;

				}

			}

		}

		asort( $custom_fields );

		$crm_fields = array( 'Standard Fields' => $built_in_fields, 'Custom Fields' => $custom_fields ); 

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
		$request      = "https://api.convertfox.com/users?email=" . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );


		if ( empty( $body_json['user'] ) ) {
			return false;
		}

		return $body_json['user']['id'];
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

		$tags 		= array();
		$request    = 'https://api.convertfox.com/users/' . $contact_id;
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json->user->tags ) ) {
			return $tags;
		}

		foreach ( $body_json->user->tags as $tag ) {
			$tags[] = $tag->name;
		}

		// Check if we need to update the available tags list
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $tags as $tag_name ) {
			if( !isset( $available_tags[ $tag_name ] ) ) {
				$available_tags[ $tag_name ] = $tag_name;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$url 		= 'https://api.convertfox.com/tags';
		$params 	= $this->params;

		foreach( $tags as $tag ) {

			$update_data = (object) array(
				'users' => array( (object) array( 'id' => $contact_id ) ),
				'name'	=> $tag
			);

			$params['body'] = json_encode( $update_data );

			$response = wp_remote_post( $url, $params );

			if( is_wp_error( $response ) ) {
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

		$url 		= 'https://api.convertfox.com/tags';
		$params 	= $this->params;

		foreach( $tags as $tag ) {

			$update_data = (object) array(
				'users' => array( (object) array( 'id' => $contact_id, 'untag' => true ) ),
				'name'	=> $tag
			);

			$params['body'] = json_encode( $update_data );

			$response = wp_remote_post( $url, $params );

			if( is_wp_error( $response ) ) {
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

		// ConvertFox needs a user ID to create a contact
		$user_id = $data['user_id'];

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$update_data = (object) array(
				'user_id' => $user_id,
				'custom_properties' => array()
		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';


		foreach( $data as $crm_field => $value ) {

			foreach( $convertfox_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data->{$crm_field} = $value;

					continue 2;

				}

			}

			// Custom fields
			$update_data->custom_properties[ $crm_field ] = $value;

		}

		$params           = $this->params;
		$params['body']   = json_encode( $update_data );

		$response = wp_remote_post( 'https://api.convertfox.com/users', $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->user->id;

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

		if( empty( $data ) ) {
			return false;
		}

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		$update_data = (object) array(
				// 'user_id' => $user_id,
			'user_id' => '3423423',
				'custom_properties' => array()
		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';


		foreach( $data as $crm_field => $value ) {

			foreach( $convertfox_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data->{$crm_field} = $value;

					continue 2;

				}

			}

			// Custom fields
			$update_data->custom_properties[ $crm_field ] = $value;

		}

		$params           = $this->params;
		$params['body']   = json_encode( $update_data );

		$response = wp_remote_post( 'https://api.convertfox.com/users', $params );

		if( is_wp_error( $response ) ) {
			return $response;
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

		$url      = 'https://api.convertfox.com/users/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$user_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if( isset( $body_json['user'][ $field_data['crm_field'] ] ) && $field_data['active'] == true ) {

				// First level fields
				$user_meta[ $field_id ] = $body_json['user'][ $field_data['crm_field'] ];

			} else {

				// Custom fields
				foreach( $body_json['user']['custom_properties'] as $custom_key => $custom_value ) {

					if( $custom_key == $field_data['crm_field'] && $field_data['active'] == true ) {

						$user_meta[ $field_id ] = $custom_value;

					}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();
		$page = 1;
		$proceed = true;

		while($proceed == true) {

			$url      = 'https://api.convertfox.com/users?page=' . $page . '&tags=' . $tag;
			$response = wp_remote_get( $url, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $body_json->users as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if(count($body_json->users) < 50) {
				$proceed = false;
			} else {
				$page++;
			}

		}

		return $contact_ids;

	}

}