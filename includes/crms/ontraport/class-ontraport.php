<?php

class WPF_Ontraport {

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
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'ontraport';
		$this->name     = 'Ontraport';
		$this->supports = array();

		$this->object_type = 0;

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Ontraport_Admin( $this->slug, $this->name, $this );
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
		// add_filter( 'wpf_apply_tags', array( $this, 'create_new_tags' ) );

		// Add tracking code to footer
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

	}

	/**
	 * Formats user entered data to match Ontraport field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		// Ontraport is pretty easy with this stuff

		return $value;

	}

	/**
	 * Creates new tags in Ontraport if needed
	 *
	 * @access public
	 * @return array Tags
	 */

	public function create_new_tags( $tags ) {

		foreach( $tags as $i => $tag_id ) {

			if( is_numeric( $tag_id ) || empty( $tag_id ) ) {
				continue;
			}

			// Remove the tag with a label from the list of IDs
			unset( $tags[ $i ] );

			$available_tags = wp_fusion()->settings->get( 'available_tags' );

			if( isset( $available_tags[ $tag_id ] ) ) {
				unset( $available_tags[ $tag_id ] );
			}

			$params = $this->get_params();
			$params['body'] = json_encode( array( 'tag_name' => $tag_id ) );
			$response = wp_remote_post( 'https://api.ontraport.com/1/Tags', $params );
			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if( is_wp_error( $response ) ) {
				return $tags;
			}

			$available_tags[ $response->data->tag_id ] = $tag_id;
			wp_fusion()->settings->set( 'available_tags', $available_tags );

			$tags[] = $response->data->tag_id;

		}

		return $tags;

	}


	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if( wp_fusion()->settings->get( 'site_tracking' ) == false )
			return;

		$account_id = wp_fusion()->settings->get('account_id');

		echo "<!-- Ontraport -->";
		echo "<script src='https://optassets.ontraport.com/tracking.js' type='text/javascript' async='true' onload='_mri=\"" . wp_fusion()->settings->get('account_id') . "\",_mr_domain=\"tracking.ontraport.com\",mrtracking();'></script>";
		echo "<!-- end Ontraport -->";

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		// Ignore on find by email requests since they'll return a 400 error if no matching email is found
		if( strpos($url, 'ontraport') !== false && strpos($url, 'getByEmail') === false ) {

			$body = wp_remote_retrieve_body( $response );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 != $response_code ) {

				$response = new WP_Error( 'error', $body );

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

	public function get_params( $api_url = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_url ) || empty( $api_key ) ) {
			$api_url = wp_fusion()->settings->get( 'op_url' );
			$api_key = wp_fusion()->settings->get( 'op_key' );
		}

		$this->params = array(
			'timeout'     => 30,
			'httpversion' => '1.1',
			'headers'     => array(
				"Api-Appid" => $api_url,
				"Api-Key"   => $api_key
			)
		);

		$this->object_type = apply_filters( 'wpf_crm_object_type', $this->object_type );

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_url = null, $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_url, $api_key );
		}

		$request  = "https://api.ontraport.com/1/objects/meta?format=byId&objectID=" . $this->object_type;
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
		$offset         = 0;
		$continue       = true;

		while( $continue == true ) {

			$request  = "https://api.ontraport.com/1/objects?objectID=14&start=" . $offset;
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['data'] as $row ) {
				$available_tags[ $row['tag_id'] ] = $row['tag_name'];
			}

			if ( count( $body_json['data'] ) < 50 ) {
				$continue = false;
			}

			$offset = $offset + 50;
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

		$crm_fields = array();
		$request    = "https://api.ontraport.com/1/objects/meta?format=byId&objectID=" . $this->object_type;
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['data'][0]['fields'] as $key => $field_data ) {
			$crm_fields[ $key ] = $field_data['alias'];
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

		$contact_info = array();
		$request      = "https://api.ontraport.com/1/object/getByEmail?objectID=" . $this->object_type . "&email=" . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

		if ( empty( $body_json['data'] ) ) {
			return false;
		}

		return $body_json['data']['id'];
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

		$contact_info = array();
		$request      = "https://api.ontraport.com/1/object?objectID=" . $this->object_type . "&id=" . $contact_id;
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['data']['contact_cat'] ) ) {
			return false;
		}

		$cat = array_filter( explode( '*/*', $body_json['data']['contact_cat'] ) );

		return $cat;
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

		$url               = "https://api.ontraport.com/1/objects/tag";
		$nparams           = $this->params;
		$alist             = implode( ",", $tags );
		$post_data         = array(
			'objectID' => $this->object_type,
			'add_list' => $alist,
			'ids'      => $contact_id
		);
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $post_data );

		$response = wp_remote_post( $url, $nparams );

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

		$url               = "https://api.ontraport.com/1/objects/tag";
		$nparams           = $this->params;
		$alist             = implode( ",", $tags );
		$post_data         = array(
			'objectID' 	  => $this->object_type,
			'remove_list' => $alist,
			'ids'         => $contact_id
		);
		$nparams['method'] = 'DELETE';
		$nparams['body']   = json_encode( $post_data );
		$response          = wp_remote_post( $url, $nparams );

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

		// Referral data
		if( isset( $_COOKIE['aff_'] ) ) {
			$data['freferrer'] = $_COOKIE['aff_'];
		}

		$urlp              = "https://api.ontraport.com/1/objects";
		$data['objectID']  = $this->object_type;
		$nparams           = $this->params;
		$nparams['method'] = 'POST';
		$nparams['body']   = json_encode( $data );

		$response = wp_remote_post( $urlp, $nparams );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->data->id;

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

		// Referral data
		if( isset( $_COOKIE['aff_'] ) ) {
			$data['lreferrer'] = $_COOKIE['aff_'];
		}

		$urlp              = "https://api.ontraport.com/1/objects";
		$data['objectID']  = $this->object_type;
		$data['id']        = $contact_id;
		$nparams           = $this->params;
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $data );

		$response = wp_remote_post( $urlp, $nparams );

		if( is_wp_error( $response ) && $response->get_error_message() == 'Object not found' ) {

			// If contact ID changed, try again
			$user_id = wp_fusion()->user->get_user_id( $contact_id );
			$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

			if( ! empty( $contact_id ) ) {

				$this->update_contact( $contact_id, $data, false );

			}

		} elseif( is_wp_error( $response ) ) {
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

		$url      = "https://api.ontraport.com/1/object?objectID=" . $this->object_type . "&id=" . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json['data'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json['data'][ $field_data['crm_field'] ];
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

		while($proceed == true) {

			$url     = "https://api.ontraport.com/1/objects/tag?objectID=" . $this->object_type . "&tag_id=" . $tag . "&range=50&start=" . $offset . "&listFields=object_id";
			$results = wp_remote_get( $url, $this->params );

			if( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['data'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			$offset = $offset + 50;

			if(count($body_json['data']) < 50) {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

}