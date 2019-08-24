<?php

class WPF_Flexie {

	/**
	 * URL to Flexie application
	 */

	public $url;

	/**
	 * Key to Flexie application
	 */

	public $api_key;


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

		$this->slug     = 'flexie';
		$this->name     = 'Flexie';
		$this->supports = array('add_tags');

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Flexie_Admin( $this->slug, $this->name, $this );
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

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

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

		if( isset( $payload->{'flexie.lead_post_save_update'} ) ) {
			$post_data['contact_id'] = $payload->{'flexie.lead_post_save_update'}[0]->lead->id;
		}

		return $post_data; 

	}

	/**
	 * Formats user entered data to match Flexie field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( "Y-m-d", $value );

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

		if( strpos($url, 'flexie.io') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error );

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

	public function connect( $flexie_url = null, $api_key = null, $test = false ) {

		// Get saved data from DB
		if ( empty( $flexie_url ) || empty($api_key) ) {
			$flexie_url = wp_fusion()->settings->get( 'flexie_url' );
			$api_key = wp_fusion()->settings->get( 'flexie_key' );
		}

		$this->url = trailingslashit( $flexie_url );
		$this->api_key = $api_key;

		if ( $test == false ) {
			return true;
		}

		$request  = $this->url . 'api/contacts?apikey=' . $this->api_key;
		$response = wp_remote_get( $request );
		
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
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		$this->connect();

		$available_tags = array();

		$request  = $this->url . 'api/contact/lists?apikey=' . $this->api_key;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ($body_json['lists'] as $list) {
			$avaliable_lists[$list['id']] = $list['name'];
		}

		wp_fusion()->settings->set( 'available_tags', $avaliable_lists );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		$this->connect();

		$crm_fields = array();
		$request  = $this->url . 'api/contacts/list/fields?apikey=' . $this->api_key;
		$response = wp_remote_get( $request );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ($body_json as $field) {
			$crm_fields[$field['alias']] = $field['label'];

		}

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

		$this->connect();

		$contact_info = array();
		$request      = $this->url . 'api/contacts?apikey=' . $this->api_key;
		$filters 	  = array('filters' => array(
				array(
					'type'	=> 'boolean',
					'alias'	=> 'email',
					'value'	=> array(
						'input'		=> $email_address,
						'operator'	=> 'eq',
						'label'		=> false
					),
					'strict'	=> true
				),
			)
		);

		$params = array(
			'body' => 	json_encode( $filters ),
			'headers' => array( 'Content-Type' => 'application/json' )
		);


		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

		if ( empty( $body_json['contacts'] ) ) {
			return false;
		}

		$contact = array_shift( $body_json['contacts'] );


		return $contact['id'];
	}


	/**
	 * Gets all lists currently applied to the user, also update the list of available lists
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {


		$this->connect();

		$contact_info = array();
		$request      = $this->url . 'api/contacts/' . $contact_id . '/lists?apikey=' . $this->api_key;
		$response     = wp_remote_get( $request );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		$contact_tags = array();

		if ( empty( $body_json['lists'] ) ) {
			return false;
		}


		foreach ($body_json['lists'] as $tag) {
			$contact_tags[$tag['id']] = $tag['name'];
		}



		return $contact_tags;
	}

	/**
	 * Applies lists to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$this->connect();

		$lists = wp_fusion()->settings->get( 'avaliable_lists' );

		foreach ($tags as $tag_key => $tag) {

			foreach ($lists as $list_key => $list) {

				if( $tag == $list ){

					$tags = $list_key;

					$request      		= $this->url . 'api/contact/lists/' . $tags . '/add/' . $contact_id . '?apikey=' . $this->api_key;
					$response = wp_remote_post( $request );
					$body_json = json_decode( $response['body'] );

					if( is_wp_error( $response ) ) {
						return $response;
					}
				}

			}

		}

		return true;

	}

	/**
	 * Removes lists from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$this->connect();

			$lists = wp_fusion()->settings->get( 'avaliable_lists' );

			foreach ($tags as $tag_key => $tag) {

				foreach ($lists as $list_key => $list) {

					if( $tag == $list ){
						$tags = $list_key;

						$request      		= $this->url . 'api/contact/lists/' . $tags . '/remove/' . $contact_id . '?apikey=' . $this->api_key;
						
						$response = wp_remote_post( $request );
						$body_json = json_decode( $response['body'] );

						if( is_wp_error( $response ) ) {
							return $response;
						}
					}

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

		$this->connect();


		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$request 		= $this->url . 'api/contacts/new?apikey=' . $this->api_key;
		$params = array(
			'body' => 	json_encode( $data ),
			'headers' => array( 'Content-Type' => 'application/json' )
		);

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		


		if( isset( $body->errors ) ) {
			return new WP_Error( 'error', $body->errors[0]->message );
		}

		return $body->contact->id;

	}



	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}
	
		if( empty( $data ) ) {
			return false;
		}

		$request      		= $this->url . 'api/contacts/' . $contact_id . '?apikey=' . $this->api_key;
		$params = array(
			'body' => 	json_encode( $data ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'method' => 'PUT'
		);
		

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body->errors ) ) {
			return new WP_Error( 'error', $body->errors[0]->message );
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

		$this->connect();

		$url      = $this->url . 'api/contacts/' . $contact_id . '?apikey=' . $this->api_key;
		$response = wp_remote_get( $url );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( $field_data['active'] == true && isset( $body_json['contact'][ $field_data['crm_field'] ] )) {
				$user_meta[ $field_id ] = $body_json['contact'][ $field_data['crm_field'] ];
			}

		}


		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on list
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		$this->connect();

		$contact_ids = array();
		$proceed = true;

		$lists = wp_fusion()->settings->get( 'avaliable_lists' );

		foreach($lists as $list => $value){
			$tag = $list;
		}

		if( $proceed == true ) {

			$url     = $this->url . "/api/contacts?apikey=" . $this->api_key . "&entityList=" . $tag;
			$results = wp_remote_get( $url );

			if( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['contacts'] as $contact ) {
				$contact_ids[] = $contact['id'];
			}
		}


		if(count($body_json['contacts']) < 50) {
				$proceed = false;
		}

		return $contact_ids;

	}

}