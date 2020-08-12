<?php

class WPF_Salesflare {

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

		$this->slug     = 'salesflare';
		$this->name     = 'Salesflare';
		$this->supports = array( );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Salesflare_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		// add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		// add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'salesflare') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {
				$response = new WP_Error( 'error', $body_json->message );
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
			$api_key = wp_fusion()->settings->get( 'salesflare_key' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'headers'     => array(
				'Authorization' 	  => 'Bearer ' . $api_key,
				'Content-Type'  	  => 'application/json'
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

		$request  = 'https://api.salesflare.com/contacts?limit=1';
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

		$request  = 'https://api.salesflare.com/tags';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $row ) {
			$available_tags[ $row['id'] ] = $row['name'];
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
		require dirname( __FILE__ ) . '/admin/salesflare-fields.php';

		$built_in_fields = array();

		foreach ( $salesflare_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request    = "https://api.salesflare.com/customfields/contacts";
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( isset( $body_json[0] ) && is_array( $body_json[0] ) ) {

			foreach ( $body_json as $field_data ) {

				$custom_fields[ $field_data['api_field'] ] = $field_data['name'];

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
		$request      = 'https://api.salesflare.com/contacts?search=' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

		if ( empty( $body_json[0]['id'] ) ) {
			return false;
		}

		return $body_json[0]['id'];
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
		$request    = 'https://api.salesflare.com/contacts/' . $contact_id;
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return false;
		}

		foreach ( $body_json['tags'] as $row ) {
			$tags[] = $row['id'];
		}

		// Check if we need to update the available tags list
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $body_json['tags'] as $row ) {
			if( !isset( $available_tags[ $row['id'] ] ) ) {
				$available_tags[ $row['id'] ] = $row['name'];
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

		foreach ($tags as $tag) {

			$tag_name = wp_fusion()->user->get_tag_label($tag);

			$request      		= 'https://api.salesflare.com/contacts/' . $contact_id;
			$params           	= $this->params;
			$params['method'] 	= 'PUT';
			$params['body']  	= json_encode(array('tags' => array(array('name' => $tag_name))));

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

			$request                = 'https://api.salesflare.com/contacts/'.$contact_id;
			$params           		= $this->params;
			$params['method'] 		= 'PUT';
			$params['body']  		= json_encode(array('tags' => array(array('id' => $tag, '_dirty' => true, '_deleted' => true))));

			$response     		    = wp_remote_post( $request, $params );

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

		$update_data = array();

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/salesflare-fields.php';

		foreach( $data as $crm_field => $value ) {

			foreach( $salesflare_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					if( strpos($crm_field, '+') == false ) {

						// If there is NO "+" sign in the field
						$update_data[$crm_field] = $value;

					} else {

						// This means that we've found that field in salesflare-fields.php, and it's a complex field

						$exploded_field = explode('+', $crm_field);

						if ( $exploded_field[0] == 'addresses' ) {

							if( ! isset( $update_data['addresses'] ) ) {

								$update_data['addresses'] =  array( array('type' => $exploded_field[1], $exploded_field[2] => $value, '_dirty' => true) );

							} else {

								$found_address = false;
								foreach( $update_data['addresses'] as $i => $address ) {


									if( $address['type'] == $exploded_field[1] ) {

										$found_address = true;
										$update_data['addresses'][$i][$exploded_field[2]] = $value;

									}

								}

								if( ! $found_address ) {
	
									$update_data['addresses'][] = array( 'type' => $exploded_field[1], $exploded_field[2] => $value, '_dirty' => true );

								}

							}

						}

					}

				}

			}

		}

		$url              = 'https://api.salesflare.com/contacts';
		$params           = $this->params;
		$params['method'] = 'POST';
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

		$update_data = array( '_dirty' => true );

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/salesflare-fields.php';

		foreach( $data as $crm_field => $value ) {

			foreach( $salesflare_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					if( strpos($crm_field, '+') == false ) {

						// If there is NO "+" sign in the field
						$update_data[$crm_field] = $value;

					} else {

						// This means that we've found that field in salesflare-fields.php, and it's a complex field

						$exploded_field = explode('+', $crm_field);

						if ( $exploded_field[0] == 'addresses' ) {

							if( ! isset( $update_data['addresses'] ) ) {

								$update_data['addresses'] = array( array( 'type' => $exploded_field[1], '_dirty' => true ) );

							} else {

								foreach( $update_data['addresses'] as $i => $address ) {

									if( $address['type'] == $exploded_field[1] ) {

										$update_data['addresses'][$i][$exploded_field[2]] = $value;

									}

								}

							}

						}

					}

				}

			}

		}

		// Custom fields
		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		if( ! empty( $crm_fields['Custom Fields'] ) ) {

			foreach( $crm_fields['Custom Fields'] as $key => $label ) {

				if( ! empty( $data[ $key ] ) ) {

					if( ! isset( $update_data['custom'] ) ) {
						$update_data['custom'] = array();
					}

					$update_data['custom'][ $key ] = $data[ $key ];

				}

			}

		}

		$url              = 'https://api.salesflare.com/contacts/' . $contact_id;
		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = json_encode( $update_data );

		$response = wp_remote_post( $url, $params );

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

		$url      = 'https://api.salesflare.com/contacts/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}


		$loaded_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		// Base fields

		foreach( $body_json as $prop => $value ) {

			if( is_array( $value ) ) {
				continue;
			}

			$loaded_meta[ $prop ] = $value;

		}


		// Address fields

		foreach( $body_json['addresses'] as $address ) {

			$type = $address['type'];

			foreach( $address as $prop => $value ) {

				$loaded_meta[ 'addresses+' . $type . '+' . $prop ] = $value;

			}

		}


		// Custom fields

		foreach( $body_json['custom'] as $prop => $value ) {

			$loaded_meta[ $prop ] = $value;

		}

		// Set missing fields
		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
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

		$url     = 'https://api.salesflare.com/contacts?tag=' . $tag;
		$results = wp_remote_get( $url, $this->params );

		if( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		foreach ( $body_json as $row => $contact ) {
			$contact_ids[] = $contact['id'];
		}

		return $contact_ids;

	}

}