<?php

class WPF_Customerly {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'customerly';
		$this->name     = 'Customerly';
		$this->supports = array( 'add_tags', 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Customerly_Admin( $this->slug, $this->name, $this );
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

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ), 10, 1 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

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

		$post_data['contact_id'] = $payload->data->user->data->email;

		return $post_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'customerly.io') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {

				// Don't treat it as an error during connect
				if( $body_json->error->message == 'User doesn\'t exist' ) {
					return $response;
				}

				$response = new WP_Error( 'error', $body_json->error->message );

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

	public function get_params( $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) ) {
			$access_key = wp_fusion()->settings->get( 'customerly_key' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'headers'     => array(
				'Authentication' 	=> 'AccessToken: ' . $access_key,
				'Content-Type'		=> 'application/json'
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

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.customerly.io/v1/users';
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

		// Can't sync list tags with Customerly

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		wp_fusion()->settings->get( 'available_tags', $available_tags );

		return $available_tags;

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		$crm_fields = wp_fusion()->settings->get( 'crm_fields', array() );

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/customerly-fields.php';

		foreach ( $customerly_fields as $index => $data ) {
			$crm_fields[ $data['crm_field'] ] = $data['crm_label'];
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

		// Customerly uses emails to identify users

		return $email_address;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		// Customerly doesn't support looking up users by ID

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		$tags = wp_fusion()->user->get_tags( $user_id );

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

		$user_id = wp_fusion()->user->get_user_id( $contact_id );
		$current_tags = wp_fusion()->user->get_tags( $user_id );

		$update_data = array(
			'users' => array(
				array(
					'email'	=> $contact_id,
					'tags' 	=> $current_tags
				)
			) 
		);

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api.customerly.io/v1/users';
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

		$user_id = wp_fusion()->user->get_user_id( $contact_id );
		$current_tags = wp_fusion()->user->get_tags( $user_id );

		$update_data = array( 
			'users' => array( 
				array(
					'email'	=> $contact_id,
					'tags' 	=> $current_tags
				)
			) 
		);

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api.customerly.io/v1/users';
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

		require dirname( __FILE__ ) . '/admin/customerly-fields.php';

		$update_data = array( 'users' => array( array() ) );

		foreach( $data as $crm_field => $value ) {

			foreach( $customerly_fields as $key => $field_data ) {

				// Built in fields
				if( $crm_field == $field_data['crm_field'] ) {
					$update_data['users'][0][ $crm_field ] = $value;
					continue 2;
				}

			}

			if( ! isset( $update_data['users'][0]['attributes'] ) ) {
				$update_data['users'][0]['attributes'] = array();
			}

			$update_data['users'][0]['attributes'][ $crm_field ] = $value;

		}

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api.customerly.io/v1/users';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return $data['email'];

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

		require dirname( __FILE__ ) . '/admin/customerly-fields.php';

		$update_data = array( 'users' => array( array() ) );

		foreach( $data as $crm_field => $value ) {

			foreach( $customerly_fields as $key => $field_data ) {

				// Built in fields
				if( $crm_field == $field_data['crm_field'] ) {
					$update_data['users'][0][ $crm_field ] = $value;
					continue 2;
				}

			}

			if( ! isset( $update_data['users'][0]['attributes'] ) ) {
				$update_data['users'][0]['attributes'] = array();
			}

			$update_data['users'][0]['attributes'][ $crm_field ] = $value;

		}

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api.customerly.io/v1/users';
		$response = wp_remote_post( $request, $params );

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

		// Not supported

		return array();

	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		// Not supported

		return array();


	}


}