<?php

class WPF_Vtiger {

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Contains Vtiger domain
	 */

	public $domain;

	/**
	 * Contains Vtiger user name
	 */

	public $username;

	/**
	 * Contains Vtiger access key
	 */

	public $api_key;

	/**
	 * Contains Vtiger session ID
	 */

	public $session;

	/**
	 * Allows outside interfaces to swap the element type
	 */

	public $element_type;

	/**
	 * User to assign new contacts to
	 */

	public $assigned_user_id;

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

		$this->slug     = 'vtiger';
		$this->name     = 'Vtiger';
		$this->supports = array( 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Vtiger_Admin( $this->slug, $this->name, $this );
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

		add_action( 'init', array( $this, 'test' ) );

	}

	public function test() {

		if( isset( $_GET['vtreg'] ) ) {
			$this->load_contact( '12x8663' );
		}

	}


	/**
	 * Formats POST data received from Webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id']))
			return $post_data;

		if ( isset( $post_data['email'] ) ) {

			$user = get_user_by( 'email', $post_data['email'] );

			if ( $user != false ) {
				$post_data['contact_id'] = get_user_meta( $user->ID, 'vtiger_contact_id', true );
			}

		} else {

			$payload = json_decode( file_get_contents( 'php://input' ) );

			if(is_object($payload)) {
				$post_data['contact_id'] = $payload->eventData->id;
			}

		}

		return $post_data;

	}


	/**
	 * Formats user entered data to match Vtiger field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( "m/d/Y", $value );

			return $date;

		} elseif ( $field_type == 'checkbox' || $field_type == 'checkbox-full' ) {

			if ( empty( $value ) ) {
				//If checkbox is unselected
				return 'off';
			} else {
				// If checkbox is selected
				return 'on';
			}

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

		if( ! empty( $this->domain ) && strpos($url, $this->domain) !== false ) {

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_message = wp_remote_retrieve_response_message( $response );

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body->error ) ) {
				$response = new WP_Error( 'error', $body->error->message );
			}

		}

		return $response;

	}


	/**
	 * Perform login and get session ID
	 *
	 * @access  public
	 * @return  str Session ID
	 */

	public function login() {

		$response = wp_remote_get( $this->domain . '?operation=getchallenge&username=' . $this->username );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$params = $this->params;

		$params['body'] = array(
			'operation'	=> 'login',
			'username' 	=> $this->username,
			'accessKey' => md5( $body->result->token . $this->api_key )
		);

		$response = wp_remote_post( $this->domain, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$this->session = $body->result->sessionName;
		wp_fusion()->settings->set( 'vtiger_session', $body->result->sessionName );

		return $body->result->sessionName;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  bool
	 */

	public function get_params( $domain = null, $username = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $domain ) || empty( $username ) || empty( $api_key ) ) {

			$this->domain 		= trailingslashit( wp_fusion()->settings->get( 'vtiger_domain' ) ) . 'webservice.php';
			$this->username   	= wp_fusion()->settings->get( 'vtiger_username' );
			$this->api_key      = wp_fusion()->settings->get( 'vtiger_key' );
			$this->session      = wp_fusion()->settings->get( 'vtiger_session' );
			
		} else {

			$this->domain 	= trailingslashit( $domain ) . 'webservice.php';
			$this->username = $username;
			$this->api_key  = $api_key;

		}

		$this->element_type = 'Contacts';
		$this->assigned_user_id = '19x22';

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'Content-Type' 	=> 'application/x-www-form-urlencoded',
				'Accept' 		=> 'application/json',
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

	public function connect( $domain = null, $username = null, $api_key = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $domain, $username, $api_key );
		}

		if ( $test == false ) {
			return true;
		}

		$result = $this->login( $domain, $username, $api_key );

		if( is_wp_error( $result ) ) {
			return $result;
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

		// Not supported
		wp_fusion()->settings->set( 'available_tags', array() );

		return array();
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

		$args = array(
			'operation'		=> 'describe',
			'sessionName'	=> $this->session,
			'elementType'	=> $this->element_type
		);

		$response = wp_remote_get( add_query_arg( $args, $this->domain ) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		$crm_fields = array();

		foreach( $body->result->fields as $field ) {

			$crm_fields[ $field->name ] = $field->label;

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
			'operation'		=> 'query',
			'sessionName'	=> $this->session,
			'query'			=> urlencode("SELECT id FROM Contacts WHERE email='" . $email_address . "';")
		);

		$response = wp_remote_get( add_query_arg( $args, $this->domain ) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $body->result ) ) {
			return false;
		}

		return $body->result[0]->id;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return false;

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		return true;

	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

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

		// @todo make dynamic
		$data['assigned_user_id'] = $this->assigned_user_id;

		$params = $this->params;

		$params['body'] = array(
			'operation'		=> 'create',
			'sessionName'	=> $this->session,
			'element'		=> json_encode( $data ),
			'elementType'	=> $this->element_type
		);

		$response = wp_remote_post( $this->domain, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->result->id;

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

		$data['id'] 				= $contact_id;
		$data['assigned_user_id'] 	= $this->assigned_user_id;

		$params = $this->params;

		$params['body'] = array(
			'operation'		=> 'update',
			'sessionName'	=> $this->session,
			'element'		=> json_encode( $data ),
		);

		$response = wp_remote_post( $this->domain, $params );

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

		$args = array(
			'operation'		=> 'retrieve',
			'sessionName'	=> $this->session,
			'id'			=> $contact_id
		);

		$response = wp_remote_get( add_query_arg( $args, $this->domain ) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$user_meta = array();

		// Map contact fields
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $body->result as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset($field_data['crm_field']) && $field_data['crm_field'] == $field_name && $field_data['active'] == true ) {
					$user_meta[ $meta_key ] = $value;
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

		return array();

	}

}