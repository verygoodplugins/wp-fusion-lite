<?php

class WPF_HubSpot {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * HubSpot OAuth stuff
	 */

	public $client_id;

	public $client_secret;


	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */

	public $tag_type = 'List';


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'hubspot';
		$this->name     = 'HubSpot';
		$this->supports = array();

		// OAuth
		$this->client_id 		= '959bd865-5a24-4a43-a8bf-05a69c537938';
		$this->client_secret 	= '56cc5735-c274-4e43-99d4-3660d816a624';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_HubSpot_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

	}


	/**
	 * Formats user entered data to match HubSpot field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

			return $date;

		} else {

			return $value;

		}

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if( ! empty( $payload ) && isset( $payload->vid ) ) {
			$post_data['contact_id'] = $payload->vid;
		}

		return $post_data;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_token = null ) {

		// Get saved data from DB
		if ( empty( $access_token ) ) {
			$access_token = wp_fusion()->settings->get( 'hubspot_token' );
		}

		$this->params = array(
			'timeout'     => 120,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Accept'  		=> 'application/json'
			)
		);

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  bool
	 */

	public function refresh_token() {

		$refresh_token = wp_fusion()->settings->get( 'hubspot_refresh_token' );

		$params = array(
			'body'	=> array(
				'grant_type'	=> 'refresh_token',
				'client_id'		=> $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uri'	=> get_admin_url() . './options-general.php?page=wpf-settings&crm=hubspot',
				'refresh_token' => $refresh_token
			)
		);

		$response = wp_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$this->get_params( $body_json->access_token );

		wp_fusion()->settings->set( 'hubspot_token', $body_json->access_token );

		return $body_json->access_token;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'api.hubapi') !== false ) {

			$code = wp_remote_retrieve_response_code( $response );
			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( $code == 401 ) {

				if( strpos($body_json->message, 'expired') !== false ) {

					$access_token = $this->refresh_token();
					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_remote_request( $url, $args );

				} else {

					$response = new WP_Error( 'error', 'Invalid API credentials.' );

				}

			} elseif( isset( $body_json->status ) && $body_json->status == 'error' ) {

				$message = $body_json->message;

				if( isset( $body_json->validationResults ) ) {

					$message .= '<ul>';

					foreach( $body_json->validationResults as $result ) {

						$message .= '<li>' . $result->message . '</li>';

					}

					$message .= '</ul>';

				}

				$response = new WP_Error( 'error', $message );

			}

		}

		return $response;

	}



	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_token = null, $refresh_token = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.hubapi.com/contacts/v1/lists/all/contacts/all?count=1';
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

		$this->connect();

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

		$request = 'https://api.hubapi.com/contacts/v1/lists/static?count=250';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if( ! empty( $response->lists ) ) {

			foreach( $response->lists as $list ) {

				$available_tags[ $list->listId ] = $list->name;

			}

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

		$request    = 'https://api.hubapi.com/properties/v1/contacts/properties';
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$built_in_fields = array();
		$custom_fields = array();

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $body_json as $field ) {

			if( $field->readOnlyValue == true ) {
				continue;
			}

			if( empty( $field->createdUserId ) ) {
				$built_in_fields[ $field->name ] = $field->label;
			} else {
				$custom_fields[ $field->name ] = $field->label;
			}

		}

		asort( $built_in_fields );
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

		$request      = 'https://api.hubapi.com/contacts/v1/contact/email/' . urlencode($email_address) . '/profile';
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) && $response->get_error_message() == 'contact does not exist' ) {

			return false;

		} elseif( is_wp_error( $response ) ) {

			return $response;
			
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $body_json ) ) {
			return false;
		}

		return $body_json->vid;

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

		$request      = 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if( empty( $body_json ) || empty( $body_json->{'list-memberships'} ) ) {
			return $tags;
		}

		foreach( $body_json->{'list-memberships'} as $list ) {
			$tags[] = $list->{'static-list-id'};
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

		foreach( $tags as $tag ) {

			$params = $this->params;
			$params['body'] = json_encode( array( 'vids' => array( $contact_id ) ) );

			$request      = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/add';
			$response     = wp_remote_post( $request, $params );

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

		foreach( $tags as $tag ) {

			$params = $this->params;
			$params['body'] = json_encode( array( 'vids' => array( $contact_id ) ) );

			$request      = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/remove';
			$response     = wp_remote_post( $request, $params );

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

		$properties = array();

		foreach( $data as $property => $value ) {
			$properties[] = array( 'property' => $property, 'value' => $value );
		}

		$params 		= $this->params;
		$params['body'] = json_encode( array( 'properties' => $properties ) );

		$request      = 'https://api.hubapi.com/contacts/v1/contact';
		$response     = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		return $body_json->vid;

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

		$properties = array();

		foreach( $data as $property => $value ) {
			$properties[] = array( 'property' => $property, 'value' => $value );
		}

		$params 		= $this->params;
		$params['body'] = json_encode( array( 'properties' => $properties ) );

		$request 		= 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
		$response     	= wp_remote_post( $request, $params );

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

		$request      = 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json->properties->{$field_data['crm_field']} ) ) {
				$user_meta[ $field_id ] = $body_json->properties->{$field_data['crm_field']}->value;
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
		$offset = 0;
		$proceed = true;

		while( $proceed == true ) {

			$request    = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/contacts/all?count=100&offset=' . $offset;
			$response 	= wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( empty( $body_json->contacts ) ) {
				return $contact_ids;
			}

			foreach ( $body_json->contacts as $contact ) {
				$contact_ids[] = $contact->vid;
			}

			if( $body_json->{'has-more'} == false ) {
				$proceed = false;
			} else {
				$offset = $offset + 100;
			}

		}

		return $contact_ids;

	}


}