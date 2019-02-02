<?php

class WPF_Drift {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Drift OAuth stuff
	 */

	public $client_id;

	public $client_secret;


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'drift';
		$this->name     = 'Drift';
		$this->supports = array( 'add_tags' );

		// OAuth
		$this->client_id 		= '1UuW7nNmGLUYhdoNLp5b2VXaoRxyDOqI';
		$this->client_secret 	= 'NuHGdNQNIpbWitAwYjpgwaFdZIzZhvlX';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Drift_Admin( $this->slug, $this->name, $this );
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



	}


	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		// Webhooks not currently supported with Drift

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
			$access_token = wp_fusion()->settings->get( 'drift_token' );
		}

		$this->params = array(
			'timeout'     => 60,
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'headers'     => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-type'	=> 'application/json'
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

		$refresh_token = wp_fusion()->settings->get( 'drift_refresh_token' );

		$params = array(
			'headers'	=> array(
				'Content-type' => 'application/x-www-form-urlencoded'
			),
			'body'		=> array(
				'client_id'		=> $this->client_id,
				'client_secret'	=> $this->client_secret,
				'refresh_token'	=> $refresh_token,
				'grant_type'	=> 'refresh_token'
			)
		);

		$response = wp_remote_post( 'https://driftapi.com/oauth2/token', $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body_json->error ) ) {
			return new WP_Error( 'error', $body_json->error->message );
		}

		wp_fusion()->settings->set( 'drift_token', $body_json->access_token );
		wp_fusion()->settings->set( 'drift_refresh_token', $body_json->refresh_token );

		return $body_json->access_token;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'driftapi') !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( wp_remote_retrieve_response_code( $response ) == 401 ) {

				$access_token = $this->refresh_token();

				if( is_wp_error( $access_token ) ) {
					return $access_token;
				}

				$args['headers']['Authorization'] = 'Bearer ' . $access_token;

				$response = wp_remote_request( $url, $args );

			} elseif( wp_remote_retrieve_response_code( $response ) == 404 ) {

				$response = new WP_Error( 'error', 'The requested resource was not found.' );

			} elseif( wp_remote_retrieve_response_code( $response ) == 400 ) {

				$response = new WP_Error( 'error', 'Validation error: One or more fields are invalid.' );

			} elseif( wp_remote_retrieve_response_code( $response ) == 500 ) {

				$response = new WP_Error( 'error', 'Unexpected Drift server error.' );

			} elseif( wp_remote_retrieve_response_code( $response ) == 429 ) {

				$response = new WP_Error( 'error', 'You have maxed your number of API calls for the provided time window.' );

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

		$request  = 'https://driftapi.com/contacts/attributes';
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

		// Can't currently list tags or list all contacts

		$available_tags = array();

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

		$request  = 'https://driftapi.com/contacts/attributes';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$fields = array();

		foreach( $response->data->properties as $field ) {

			$fields[ $field->name ] = $field->displayName;

		}

		asort( $fields );

		wp_fusion()->settings->set( 'crm_fields', $fields );

		return $fields;

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

		$request  = 'https://driftapi.com/contacts/?email=' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $response ) || empty( $response->data ) ) {
			return false;
		}

		return $response->data[0]->id;

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

		$request  = 'https://driftapi.com/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if( empty( $response->data->attributes->tags ) ) {
			return $tags;
		}

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );
		$needs_update = false;

		foreach( $response->data->attributes->tags as $tag ) {

			$tags[] = $tag->name;

			if( ! in_array( $tag->name, $available_tags ) ) {
				$available_tags[$tag->name] = $tag->name;
				$needs_update = true;
			}

		}

		if( $needs_update ) {

			asort( $available_tags );
			wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$body = array();

		foreach( $tags as $tag ) {
			$body[] = array( 'name' => $tag );
		}

		$params = $this->params;
		$params['body'] = json_encode( $body );

		$request  = 'https://driftapi.com/contacts/' . $contact_id . '/tags';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
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

		$params = $this->params;
		$params['body'] = json_encode( $tags );

		$request  = 'https://driftapi.com/contacts/' . $contact_id . '/tags/delete/_bulk';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$params = $this->params;
		$params['body'] = json_encode( array( 'attributes' => $data ) );

		$request  = 'https://driftapi.com/contacts';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->data->id;

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

		$params 			= $this->params;
		$params['body']		= json_encode( array( 'attributes' => $data ) );
		$params['method'] 	= 'PATCH';

		$request  = 'https://driftapi.com/contacts/' . $contact_id;
		$response = wp_remote_request( $request, $params );

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

		$request  = 'https://driftapi.com/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response      	= json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $response->data ) ) {
			return new WP_Error( 'error', 'Unable to find contact ID ' . $contact_id . ' in Drift.' );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && ! empty( $response->data->attributes->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $response->data->attributes->{ $field_data['crm_field'] };
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

		// Not currently available with Drift

	}


}