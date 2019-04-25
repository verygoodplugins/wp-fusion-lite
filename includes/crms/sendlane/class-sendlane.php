<?php

class WPF_Sendlane {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * API Key
	 */

	public $api_key;

	/**
	 * API Hash
	 */

	public $api_hash;

	/**
	 * API Domain
	 */

	public $api_domain;

	/**
	 * Default List
	 */

	public $list;


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'sendlane';
		$this->name     = 'Sendlane';
		$this->supports = array( 'add_tags', 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Sendlane_Admin( $this->slug, $this->name, $this );
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

		$post_data['contact_id'] = $payload->email;

		return $post_data;

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'sendlane') !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {

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

	public function get_params( $api_key = false, $api_hash = false, $api_domain = false ) {

		if( empty( $api_key ) || empty( $api_hash ) || empty( $api_domain ) ) {

			$api_key = wp_fusion()->settings->get( 'sendlane_key' );
			$api_hash = wp_fusion()->settings->get( 'sendlane_hash' );
			$api_domain = wp_fusion()->settings->get( 'sendlane_domain' );

		}

		$this->params = array(
			'timeout'     => 15,
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'headers'     => array(
				'Content-Type'  	  => 'application/json',
			)
		);

		$this->api_key = $api_key;
		$this->api_hash = $api_hash;
		$this->api_domain = $api_domain;
		$this->list = wp_fusion()->settings->get( 'default_list', false );

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $api_hash = null, $api_domain = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key, $api_hash, $api_domain );
		}

		$args = array(
			'api' 	=> $this->api_key,
			'hash' 	=> $this->api_hash
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/lists' );
		$response = wp_remote_post( $request, $this->params );

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
		$this->sync_lists();
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

		$args = array(
			'api' 	=> $this->api_key,
			'hash' 	=> $this->api_hash
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/tags' );
		$response = wp_remote_post( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $body as $tag ) {
			$available_tags[ $tag->tag_id ] = $tag->tag_name;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}


	/**
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_lists = array();

		$args = array(
			'api' 	=> $this->api_key,
			'hash' 	=> $this->api_hash
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/lists' );
		$response = wp_remote_post( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $body as $list ) {
			$available_lists[ $list->list_id ] = $list->list_name;
		}

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		// Set default

		$default_list = wp_fusion()->settings->get( 'default_list', false );

		if( empty( $default_list ) ) {

			reset( $available_lists );
			$default_list = key( $available_lists );
			wp_fusion()->settings->set( 'default_list', $default_list );

			$this->list = $default_list;

		}

		return $available_lists;

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
		require dirname( __FILE__ ) . '/admin/sendlane-fields.php';

		$crm_fields = array();

		foreach ( $sendlane_fields as $index => $data ) {
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$args = array(
			'api' 		=> $this->api_key,
			'hash' 		=> $this->api_hash,
			'list_id'	=> $this->list,
			'email'		=> $email_address
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/subscriber-exists' );
		$response = wp_remote_post( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( ! isset( $body->subscribe_id ) ) {
			return false;
		}

		return $email_address;
	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Sendlane doesn't support this

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		$user_tags = get_user_meta( $user_id, 'sendlane_tags', true );

		if( empty( $user_tags ) ) {
			$user_tags = array();
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

		$args = array(
			'api' 		=> $this->api_key,
			'hash' 		=> $this->api_hash,
			'email'		=> $contact_id,
			'tag_names' => implode(',', $tags)
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/tag-subscriber-add' );
		$response = wp_remote_post( $request, $this->params );

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

		$args = array(
			'api' 		=> $this->api_key,
			'hash' 		=> $this->api_hash,
			'email'		=> $contact_id,
			'tag_names' => implode(',', $tags)
		);

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/tag-subscriber-remove' );
		$response = wp_remote_post( $request, $this->params );

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

		if ( empty( $data ) ) {
			return null;
		}

		if ( empty( $data['email'] ) ) {
			return false;
		}

		$args = array(
			'api' 		=> $this->api_key,
			'hash' 		=> $this->api_hash,
			'list_id'	=> $this->list
		);

		$args = array_merge( $args, $data );

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/list-subscriber-add' );
		$response = wp_remote_post( $request, $this->params );

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

		if ( empty( $data ) ) {
			return null;
		}

		if ( empty( $data['email'] ) ) {
			return false;
		}

		$args = array(
			'api' 		=> $this->api_key,
			'hash' 		=> $this->api_hash,
			'list_id'	=> $this->list
		);

		$args = array_merge( $args, $data );

		$request  = add_query_arg( $args, 'https://' . $this->api_domain . '/api/v1/list-subscriber-add' );
		$response = wp_remote_post( $request, $this->params );

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

		return false;

	}

}