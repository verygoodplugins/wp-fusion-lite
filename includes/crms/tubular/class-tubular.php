<?php

class WPF_Tubular {

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

		$this->slug     = 'tubular';
		$this->name     = 'Tubular';
		$this->supports = array( 'add_tags', 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Tubular_Admin( $this->slug, $this->name, $this );
		}

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
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}


	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id'])) {
			return $post_data;
		}

	}

	/**
	 * Formats user entered data to match Tubular field formats
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

		if( strpos($url, 'tubular') !== false ) {

			if( wp_remote_retrieve_response_code( $response ) == 401 ) {

				return new WP_Error( 'error', 'Invalid API key' );

			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0] );

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
		if ( empty( $api_key )) {
			$api_key = wp_fusion()->settings->get( 'tubular_key' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'headers'     => array(
				'Authorization' => 'token ' . $api_key,
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

		$request  = 'https://app.tubular.io/api/company/tags';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
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

		$request  = 'https://app.tubular.io/api/company/tags';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if( ! empty( $body_json['items'] ) ) {

			foreach( $body_json['items'] as $tag ) {

				$available_tags[ $tag['items']['name'] ] = $tag['items']['name'];

			}

		}

		asort( $available_tags );

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
		require dirname( __FILE__ ) . '/admin/tubular-fields.php';

		$built_in_fields = array();

		foreach ( $tubular_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request    = 'https://app.tubular.io/api/company/company_settings/current';
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		// Need to query the first contact to get the custom fields

		if( ! empty( $body_json['global_custom_fields'] ) ) {

			foreach( $body_json['global_custom_fields'] as $field ) {

				$custom_fields[ $field ] = $field;

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

		$request    	= 'https://app.tubular.io/api/company/clients?leads=false&paginate=1&per_page=1&q=' . urlencode( $email_address );
		$response     	= wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if( empty( $body_json['items'] ) ) {
			return false;
		}

		return $body_json['items'][0]['id'];
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
		$request    = 'https://app.tubular.io/api/company/clients/' . $contact_id;
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['tags'] ) ) {
			return false;
		}

		foreach ( $body_json['tags'] as $tag ) {
			$tags[] = $tag['name'];
		}

		// Check if we need to update the available tags list
		// $available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		// foreach( $body_json['tags'] as $tag ) {

		// 	if( !isset( $available_tags[ $tag['id'] ] ) ) {
		// 		$available_tags[ $tag['id'] ] = $tag['name'];
		// 	}

		// }

		// wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		foreach ($tags as $tag) {

			$body = array(
				'name'			=> $tag,
				'object_type'	=> 'client',
				'object_id'		=> $contact_id
			);

			$request      		= 'https://app.tubular.io/api/company/tags/tag';
			$params           	= $this->params;
			$params['body']  	= json_encode( $body );

			$response = wp_remote_post( $request, $params );

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

		foreach ($tags as $tag) {

			$request                = 'https://app.tubular.io/api/company/tags/untag/*?tag_name=' . urlencode($tag) . '&object_id=' . $contact_id . '&object_type=client';
			$params           		= $this->params;
			$params['method'] 		= 'DELETE';
			
			$response     		    = wp_remote_request( $request, $params );

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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$url              = 'https://app.tubular.io/api/company/clients';
		$params           = $this->params;
		$params['body']   = json_encode( $data );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
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

		if( empty( $data ) ) {
			return false;
		}

		// Move custom fields into custom attributes
		$crm_fields = wp_fusion()->settings->get( 'crm_fields', array() );

		foreach( $data as $field => $value ) {

			if( ! isset( $crm_fields['Standard Fields'][ $field ] ) ) {

				if ( ! isset( $data['custom_fields_attributes'] ) ) {
					$data['custom_fields_attributes'] = array();
				}

				$data['custom_fields_attributes'][] = array( 'name' => $field, 'value' => $value );

			}

		}

		$url              	= 'https://app.tubular.io/api/company/clients/' . $contact_id;
		$params           	= $this->params;
		$params['method']   = 'PUT';
		$params['body']   	= json_encode( $data );

		$response = wp_remote_request( $url, $params );

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

		$url      = 'https://app.tubular.io/api/company/clients/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $body_json as $field => $value ) {
			
			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $field == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $value;
				}

			}

		}

		// Custom fields

		if( ! empty( $body_json['custom_field_attributes'] ) ) {

			foreach( $body_json['custom_field_attributes'] as $attribute ) {

				foreach ( $contact_fields as $field_id => $field_data ) {

					if ( $field_data['active'] == true && $attribute['name'] == $field_data['crm_field'] ) {
						$user_meta[ $field_id ] = $attribute['value'];
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

		$request    	= 'https://app.tubular.io/api/company/clients?leads=false&paginate=1&per_page=1&tags_name_filter=' . urlencode( json_encode( array( $tag ) ) );
		$response     	= wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if( ! empty( $body_json ) ) {

			foreach( $body_json as $contact ) {
				$contact_ids[] = $contact['id'];
			}

		}

		return $contact_ids;

	}

}