<?php

class WPF_Intercom {

	/**
	 * (deprecated)
	 */

	public $app;

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

		$this->slug     = 'intercom';
		$this->name     = 'Intercom';
		$this->supports = array( 'add_fields', 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Intercom_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		//add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_woocommerce_customer_data', array( $this, 'set_country_names' ), 10, 2 );

	}

	/**
	 * Formats POST data received from webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id']))
			return $post_data;

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = $payload->data->item->user->id;

		return $post_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'intercom') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors['0']->code );

			}

		}

		return $response;

	}


	/**
	 * Use full country names instead of abbreviations with WooCommerce
	 *
	 * @access public
	 * @return array Customer data
	 */

	public function set_country_names( $customer_data, $order ) {

		if( isset( $customer_data['billing_country'] ) ) {
			$customer_data['billing_country'] = WC()->countries->countries[ $customer_data['billing_country'] ];
		}

		if( isset( $customer_data['shipping_country'] ) ) {
			$customer_data['shipping_country'] = WC()->countries->countries[ $customer_data['shipping_country'] ];
		}

		return $customer_data;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) ) {
			$access_key = wp_fusion()->settings->get( 'intercom_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'Accept' 			=> 'application/json',
				'Authorization'   	=> 'Bearer ' . $access_key,
				'Content-Type' 		=> 'application/json',
				'Intercom-Version'  => '1.4',
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

	public function connect( $access_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		$request  = 'https://api.intercom.io/me';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->errors ) ) {
			return new WP_Error( $response->errors[0]->code, $response->errors[0]->message );
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

		$request  = 'https://api.intercom.io/tags';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $response->tags as $tag ) {
			$available_tags[$tag->name] = $tag->name;
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

		$crm_fields = array(
			'email'		=> 'Email',
			'phone'		=> 'Phone',
			'name'		=> 'Name'
		);

		$request  = 'https://api.intercom.io/data_attributes/customer';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) && $response->get_error_message() == 'intercom_version_invalid' ) {

			// Try v1.4 API
			$request  = 'https://api.intercom.io/data_attributes';
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}
			
		} elseif( is_wp_error( $response ) ) {

			return $response;

		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $response->data_attributes as $field ) {

			if( $field->api_writable == true && 'customer' == $field->model ) {
				$crm_fields[ $field->name ] = $field->label;
			}

		}

		asort($crm_fields);

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

		$request      = 'https://api.intercom.io/users?email=' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->id ) ) {

			return $response->id;

		} elseif( isset( $response->users ) && ! empty( $response->users ) ) {

			return $response->users[0]->id;

		} else {

			return false;

		}
		
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

		$user_tags = array();

		$request      = 'https://api.intercom.io/users/' . $contact_id;
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		if( empty( $response['tags']['tags'] ) ) {
			return false;
		}

		foreach( $response['tags']['tags'] as $tag ) {
			$user_tags[] = $tag['name'];
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url 		= 'https://api.intercom.io/tags';
		$params 	= $this->params;

		foreach( $tags as $tag ) {

			$params['body'] = json_encode( array( 'name' => $tag, 'users' => array( array( 'id' => $contact_id ) ) ) );

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

		$url 		= 'https://api.intercom.io/tags';
		$params 	= $this->params;

		foreach( $tags as $tag ) {

			$params['body'] = json_encode( array( 'name' => $tag, 'users' => array( array( 'id' => $contact_id, 'untag' => true ) ) ) );

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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// General cleanup and restructuring
		$body = array( 'email' => $data['email'] );
		unset( $data['email'] );

		if( isset( $data['phone'] ) ) {
			$body['phone'] = $data['phone'];
			unset( $data['phone'] );
		}

		if( isset( $data['name'] ) ) {
			$body['name'] = $data['name'];
			unset( $data['name'] );
		}

		if( ! empty( $data ) ) {

			// All other custom fields
			$body['custom_attributes'] = $data;

		}

		$url 				= 'https://api.intercom.io/users';
		$params 			= $this->params;
		$params['body'] 	= json_encode( $body );

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

		// General cleanup and restructuring
		$body = array( 'id' => $contact_id );

		if( isset( $data['email'] ) ) {
			$body['email'] = $data['email'];
			unset( $data['email'] );
		}

		if( isset( $data['phone'] ) ) {
			$body['phone'] = $data['phone'];
			unset( $data['phone'] );
		}

		if( isset( $data['name'] ) ) {
			$body['name'] = $data['name'];
			unset( $data['name'] );
		}
		
		if( ! empty( $data ) ) {

			// All other custom fields
			$body['custom_attributes'] = $data;

		}

		$url 				= 'https://api.intercom.io/users';
		$params 			= $this->params;
		$params['body'] 	= json_encode( $body );

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

		$url      = 'https://api.intercom.io/users/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			// Core fields
			if ( $field_data['active'] == true && isset( $body_json[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json[ $field_data['crm_field'] ];
			}

			// Custom attributes
			if ( $field_data['active'] == true && isset( $body_json['custom_attributes'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json['custom_attributes'][ $field_data['crm_field'] ];
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

	public function load_contacts( $tag_query ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();
		$param       = false;
		$proceed     = true;

		while ( $proceed == true ) {

			$url = 'https://api.intercom.io/users/scroll/';

			if ( false !== $param ) {
				$url .= '?scroll_param=' . $param;
			}

			$response = wp_remote_get( $url, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->users ) ) {

				$param = $response->scroll_param;

				foreach ( $response->users as $user ) {

					foreach ( $user->tags->tags as $tag ) {

						if ( $tag->name == $tag_query ) {
							$contact_ids[] = $user->id;
							break;
						}

					}

				}

			} else {

				$proceed = false;

			}

		}

		return $contact_ids;

	}

}