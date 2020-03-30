<?php

class WPF_Mailjet {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

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

		$this->slug     = 'mailjet';
		$this->name     = 'Mailjet';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Mailjet_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 10, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

	}

	/**
	 * Formats user entered data to match Mailjet field formats
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

		if( strpos($url, 'mailjet') !== false && $args['User-Agent'] == 'WP Fusion; ' . home_url() ) {

			$response_code = wp_remote_retrieve_response_code( $response ) ;

			if ( $response_code == 401 ) {

				$response = new WP_Error( 'error', 'Unauthorized. Please confirm your API Key and Secret Key are correct and try again.' );

			} elseif ($response_code > 201) {

				$response_message = wp_remote_retrieve_response_message( $response ) ;
				$response = new WP_Error( 'error', $response_message );

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

	public function get_params( $mailjet_username = null, $mailjet_password = null ) {

		// Get saved data from DB
		if ( empty( $mailjet_username ) || empty($mailjet_password) ) {
			$mailjet_username = wp_fusion()->settings->get( 'mailjet_username' );
			$mailjet_password = wp_fusion()->settings->get( 'mailjet_password' );
		}

		$auth_key = base64_encode($mailjet_username . ':' . $mailjet_password);

		$this->params = array(
			'timeout'     => 30,
			'httpversion' => '1.1',
			'User-Agent'  => 'WP Fusion; ' . home_url(),
			'headers'     => array(
				'Authorization' => 'Basic ' . $auth_key,
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

	public function connect( $mailjet_username = null, $mailjet_password = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $mailjet_username, $mailjet_password );
		}

		$request  = 'https://api.mailjet.com/v3/REST/contactslist';
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

		$request  = 'https://api.mailjet.com/v3/REST/contactslist';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['Data'] as $row ) {
			$available_tags[ $row['ID'] ] = $row['Name'];
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

		$crm_contact_fields = array( 'Email' => 'Email Address', 'Name' => 'Name' );

		$request    = "https://api.mailjet.com/v3/REST/contactmetadata";
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['Data'] as $field_data ) {
			$crm_meta_fields[$field_data['Name']] =  ucwords( str_replace( '_', ' ', $field_data[ 'Name' ] ) );
		}

		$crm_fields = array( 'Standard Fields' => $crm_contact_fields, 'Custom Fields' => $crm_meta_fields );


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

		$contact_info = array();
		$request      = 'https://api.mailjet.com/v3/REST/contact/' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) && $response->get_error_message() == 'Not Found' ) {

			return false;

		} elseif( is_wp_error( $response ) ) {

			return $response;

		}

		$body_json    = json_decode( $response['body'], true );

		if ( empty( $body_json['Data'][0]['Email'] ) ) {
			return false;
		}

		return $body_json['Data'][0]['ID'];
		
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

		$request    = 'https://api.mailjet.com/v3/REST/contact/' . urlencode( $contact_id ) . '/getcontactslists';
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) || empty( $body_json['Data'][0]['ListID'] ) ) {
			return false;
		}

		$tags = array();

		foreach ($body_json['Data'] as $tag_data) {
			$tags[] = $tag_data['ListID'];
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

		$object_tags = array();

		foreach ($tags as $tag) {
			$object_tags[] = (object) ['ListID' => $tag, 'Action' => 'addnoforce' ];
		}

		$request      		= 'https://api.mailjet.com/v3/REST/contact/' . $contact_id . '/managecontactslists';
		$params           	= $this->params;
		$params['method'] 	= 'POST';
		$params['body']  	= json_encode( array ( 'ContactsLists' =>  $object_tags ) ) ;

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

		$object_tags = array();

		foreach ($tags as $tag) {
			$object_tags[] = (object) ['ListID' => $tag, 'Action' => 'remove' ];
		}

		$request      		= 'https://api.mailjet.com/v3/REST/contact/' . $contact_id . '/managecontactslists';
		$params           	= $this->params;
		$params['method'] 	= 'POST';
		$params['body']  	= json_encode( array ( 'ContactsLists' =>  $object_tags ) ) ; 

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

		if ( empty( $data ) ) {
			return null;
		}

		$post_data = array();

		$post_data['IsExcludedFromCampaigns'] = false;
		$post_data['Name']				  	  = $data['Name'];
		$post_data['Email'] 				  = $data['Email'];

		$url              = 'https://api.mailjet.com/v3/REST/contact';
		$params           = $this->params;
		$params['body']   = json_encode( $post_data );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		unset($data['Name']);
		unset($data['Email']);

		if( ! empty( $data ) ) {

			foreach ($data as $key => $value) {
				$meta[] = array (  'Name' => $key, 'Value' => $value );
			}

			$meta_data['ContactID'] = $body->Data[0]->ID;
			$meta_data['Data'] = $meta; 

			$url               = 'https://api.mailjet.com/v3/REST/contactdata/' . $body->Data[0]->ID;
			$params            = $this->params;
			$params['method']  = 'PUT';
			$params['body']    = json_encode($meta_data);

			$response = wp_remote_post( $url, $params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

		}

		return $body->Data[0]->ID;

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

		$post_data = array();
		$post_data['IsExcludedFromCampaigns'] = false;
		$post_data['Name']				  	  = $data['Name'];
		$post_data['Email'] 				  = $data['Email'];

		$url               = 'https://api.mailjet.com/v3/REST/contact/' . $contact_id;
		$params            = $this->params;
		$params['method']  = 'PUT';
		$params['body']    = json_encode($post_data);

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		// Update metadata below (everything other the Email and Name fields that are dealt with above)

		unset($data['Name']);
		unset($data['Email']);

		if( ! empty( $data ) ) {

			foreach ($data as $key => $value) {
				$meta[] = array (  'Name' => $key, 'Value' => $value );
			}

			$meta_data['ContactID'] = $contact_id;
			$meta_data['Data'] = $meta; 

			$url               = 'https://api.mailjet.com/v3/REST/contactdata/' . $contact_id;
			$params            = $this->params;
			$params['method']  = 'PUT';
			$params['body']    = json_encode($meta_data);

			$response = wp_remote_post( $url, $params );

			if( is_wp_error( $response ) ) {
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

		// Two separate calls needed to get email (body) and all other fields (meta)

		$url     		 = 'https://api.mailjet.com/v3/REST/contactdata/' . urlencode( $contact_id );
		$response_meta   = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response_meta ) ) {
			return $response_meta;
		}

		$user_meta       = array();
		$contact_fields  = wp_fusion()->settings->get( 'contact_fields' );

		$body_json_meta  = json_decode( $response_meta['body'], true );

		if (!empty($body_json_meta['Data'][0]['Data'])) {

			foreach ( $body_json_meta['Data'][0]['Data'] as $field => $value ) {
					
				foreach ( $contact_fields as $field_id => $field_data ) {

					if ( $value['Name'] == $field_data['crm_field'] ) {
						$user_meta[ $field_id ] = $value['Value'];
					}	

				}

			}

		}

		$url      		 = 'https://api.mailjet.com/v3/REST/contact/' . urlencode( $contact_id );
		$response_body   = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response_body ) ) {
			return $response_body;
		}

		$body_json_body  = json_decode( $response_body['body'], true );

		foreach ( $body_json_body['Data'] as $field_body => $value_body ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $value_body == $field_data['crm_field']) {
					$user_meta[ $field_id ] = $value_body[$field_data['crm_field']];
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

		$url     = 'https://api.mailjet.com/v3/REST/listrecipient';
		$results = wp_remote_get( $url, $this->params );

		if( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		foreach ( $body_json['Data'] as $row => $contact ) {
			if ($contact["ListID"] == $tag) {
				$contact_ids[] = $contact['ContactID'];
			}
		}

		return $contact_ids;

	}

}