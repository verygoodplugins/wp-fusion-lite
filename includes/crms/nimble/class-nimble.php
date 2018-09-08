<?php

class WPF_Nimble {

	/**
	 * URL to Nimble application
	 */

	public $url;

	/**
	 * Contains API params
	 */

	// public $params;


	/**
	 * Contains API key
	 */


	public $api_key;

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

		$this->slug     = 'nimble';
		$this->name     = 'Nimble';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Nimble_Admin( $this->slug, $this->name, $this );
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

	}

	/**
	 * Formats user entered data to match Nimble field formats
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

		if( strpos($url, 'nimble') !== false ) {

			// $body_json = json_decode( wp_remote_retrieve_body( $response ) );

			// error_log(print_r($body_json, true));

			// if( isset( $body_json->errors ) ) {

			// 	$response = new WP_Error( 'error', $body_json->errors['0']->code );

			// 	error_log(print_r($response, true));

			// }

		}

		return $response;

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( ! empty( $this->api_key ) ) {
			return true;
		}

		if( empty( $api_key ) ) {
			$this->api_key = wp_fusion()->settings->get( 'nimble_key' );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = "https://app.nimble.com/api/v1/contacts?access_token=" . $api_key;
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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		// error_log('key ' . $this->api_key);

		$available_tags = array();

		$request  = 'https://app.nimble.com/api/v1/contacts?access_token=' . $this->api_key;
		$response = wp_remote_get( $request );

		// error_log(print_r($response, true));

		if( is_wp_error( $response ) ) {
				return $response;
		}

		

		// error_log(print_r($request, true));

		$response = json_decode(wp_remote_retrieve_body( $response ));

		// error_log(print_r($response, true));

		foreach ( $response->resources as $contact ) {
			
			// error_log('single contact');
			// error_log(print_r($contact, true));

			foreach ($contact->tags as $tags) {

				// error_log('tags');
				// error_log(print_r($tags, true));

				$available_tags[ $tags->id ] = $tags->tag;
				// error_log(print_r($available_tags, true));

			}

		}

		error_log('available tags');
		error_log(print_r($available_tags, true));

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$crm_fields = array();

		$response = wp_remote_get( 'https://app.nimble.com/api/v1/contacts?access_token=' . $this->api_key );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );

		// error_log(print_r($results, true));

			foreach ( $results->resources as $contact ) {

				foreach ($contact->fields as $field_key => $field_value) {
					
					$crm_fields[ $field_key ] = ucwords( str_replace( '_', ' ', $field_key ) );

				}
				
			}

		// error_log(print_r($crm_fields, true));

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_info = array();
		$response      = wp_remote_get( 'https://app.nimble.com/api/v1/contacts?access_token=' . $this->api_key . '&query=%7B%22email%22%3A%20%7B%22is%22%3A%20%22'.$email_address.'%22%7D%7D' );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $results->resources ) ) {
			return false;
		}

		foreach ($results->resources as $contact) {
			$id = $contact->id;
		}

		return $id;
	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_info = array();
		$response     = wp_remote_get( 'https://app.nimble.com/api/v1/contact/'. $contact_id .'?access_token=' . $this->api_key );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$results = json_decode( wp_remote_retrieve_body( $response ) );


		if ( empty( $results->resources ) ) {
			return false;
		}

		$tags = array();


		foreach ($results->resources[0]->tags as $tag) {
			$tags[] = $tag->id;
		}

		// error_log('tags for get_tags');
		// error_log(print_r($tags, true));

		return $tags;

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	// public function apply_tags( $tags, $contact_id ) {

	// 	// error_log('tags & $contact_id');
	// 	error_log(print_r($tags, true));
	// 	// error_log(print_r($contact_id, true));

	// 	if ( is_wp_error( $this->connect() ) ) {
	// 		return false;
	// 	}

	// 	$tags_to_apply = array();

	// 	foreach( $tags as $tag_id ) {
	// 	    $tags_to_apply[] = array(
	// 	        'tag'    => wp_fusion()->user->get_tag_label($tag_id),
	// 	        'id'    => $tag_id
	// 	    );
	// 	}

	// 	// $available_tags    = wp_fusion()->settings->get('available_tags');
	// 	// error_log("tag_name");
	// 	// error_log(print_r($tags_to_apply, true));
	// 	// error_log("tag");
	// 	// error_log(print_r($tag_id, true));
	// 	// error_log('tags');
	// 	// error_log(print_r($tags, true));

	// 	$url               = 'https://app.nimble.com/api/v1/contact/'. $contact_id .'?access_token=' . $this->api_key;
	// 	// $nparams           = $this->params;
	// 	// $alist             = implode( ",", $tags );
	// 	// $post_data         = array(
	// 	// 	'tags' 	   	   => array(
	// 	// 	  'tag' => $tags,
	// 	// 	  'id'  => $tags
	// 	// ));

	// 	// error_log('post_data');
	// 	// error_log(print_r($post_data, true));

	// 	$nparams = array(
	// 		'headers'     => array(
	// 			'Authorization' => 'Bearer ' . $this->api_key,
	// 			'Content-Type' => 'application/json'
	// 		),

	// 	);


	// 	$nparams['method'] = 'PUT';
	// 	$nparams['body']   = json_encode( $tags_to_apply );

	// 	$response = wp_remote_post( $url, $nparams );

	// 	$response = json_decode( wp_remote_retrieve_body( $response ) );

	// 	error_log('$nparams');
	// 	error_log(print_r($nparams, true));

	// 	error_log('response');
	// 	error_log(print_r($response, true));

	// 	if( is_wp_error( $response ) ) {
	// 		return $response;
	// 	}

	// 	return true;

	// }

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		// error_log('data');
		// error_log(print_r($data, true));
		// error_log('map_meta_fields');
		// error_log(print_r($map_meta_fields, true));

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( empty( $data ) ) {
			return false;
		}

		if( isset( $data['email'] ) ) {
			$provided_email = $data['email'];
			unset($data['email']);
		}

		// $tags = wp_fusion()->user->get_tag_label($tag_id);
		// error_log('tags');
		// error_log(print_r($tags, true));

		$alt_params = array();
		$alt_params['fields'] = array();
		$alt_params['record_type'] = 'person';
		$alt_params['tags'] = 'rare';

		foreach ($data as $key => $value) {
			
			$alt_params['fields'][$key] = array();
			$alt_params['fields'][$key][0] = array(
				'value' => $value,
				'modifier' => ''

			);

		}

		// error_log(print_r($alt_params, true));

		$url     = 'https://app.nimble.com/api/v1/contact/'. $contact_id .'?access_token=' . $this->api_key;

		$nparams = array(
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type' => 'application/json'
			),

		);

		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $alt_params );

		// error_log(print_r($nparams, true));

		$response = wp_remote_post( $url, $nparams );

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// error_log('response');
		// error_log(print_r($response, true));

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

		// error_log('data');
		// error_log(print_r($data, true));
		// error_log('map_meta_fields');
		// error_log(print_r($map_meta_fields, true));

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( isset( $data['email'] ) ) {
			$provided_email = $data['email'];
			unset($data['email']);
		}

		$alt_params = array();
		$alt_params['fields'] = array();
		$alt_params['record_type'] = 'person';
		$alt_params['tags'] = '';

		foreach ($data as $key => $value) {
			
			$alt_params['fields'][$key] = array();
			$alt_params['fields'][$key][0] = array(
				'value' => $value,
				'modifier' => ''

			);

		}

		// error_log(print_r($alt_params, true));

		$url               = 'https://app.nimble.com/api/v1/contact/?access_token=' . $this->api_key;

		$nparams = array(
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type' => 'application/json'
			),

		);

		$nparams['method'] = 'POST';
		$nparams['body']   = json_encode( $alt_params );

		// error_log(print_r($nparams, true));

		$response = wp_remote_post( $url, $nparams );

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// error_log('response');
		// error_log(print_r($response, true));

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

		error_log('works');

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_info = array();
		$response     = wp_remote_get( 'https://app.nimble.com/api/v1/contact/'. $contact_id .'?access_token=' . $this->api_key );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		// error_log(print_r($body_json, true));
		// error_log(print_r($contact_fields, true));

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json['resources'][0]['fields'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json['resources'][0]['fields'][ $field_data['crm_field'] ];
			}

		}

		// error_log(print_r($user_meta, true));
		error_log(print_r($field_data, true));

		return $user_meta;

	}

}