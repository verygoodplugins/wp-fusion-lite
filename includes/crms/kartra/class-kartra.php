<?php

class WPF_Kartra {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Kartra app ID
	 */

	public $app_id;

	/**
	 * Kartra API url
	 */

	public $api_url;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'kartra';
		$this->name     = 'Kartra';
		$this->supports = array( 'add_fields' );

		// WP Fusion app ID
		$this->app_id 	= 'EoPIrcjdRhQl';
		$this->api_url 	= 'https://app.kartra.com/api';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Kartra_Admin( $this->slug, $this->name, $this );
		}

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
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

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

		$post_data['contact_id'] = $payload->lead->id;

		// Set the post data to contain the email for imports
		$_POST['kartra_email'] = $payload->lead->email;

		return $post_data;

	}


	/**
	 * Formats user entered data to match Kartra field formats
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
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( $url == $this->api_url ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( $body_json->status != 'Success' && isset( $body_json->message ) && $body_json->message != 'No lead found' ) {

				$response = new WP_Error( 'error', $body_json->message );

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

	public function get_params( $api_key = null, $api_password = null ) {

		// Get saved data from DB
		if ( empty( $api_key ) || empty( $api_password ) ) {
			$api_key = wp_fusion()->settings->get( 'kartra_api_key' );
			$api_password = wp_fusion()->settings->get( 'kartra_api_password' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'headers'     => array(
				'Content-Type'	=> 'application/x-www-form-urlencoded'
			),
			'body' => array(
				'app_id'		=> $this->app_id,
				'api_key'		=> $api_key,
				'api_password'	=> $api_password
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

	public function connect( $api_key = null, $api_password = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key, $api_password );
		}

		$params = $this->params;

		$params['body']['actions'] = array(
			array( 'cmd' => 'retrieve_account_tags' )
		);

		$response = wp_remote_post( $this->api_url, $params );

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

		$params = $this->params;

		$params['body']['actions'] = array(
			array( 'cmd' => 'retrieve_account_tags' )
		);
		
		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if( ! empty( $response->account_tags ) ) {

			foreach( $response->account_tags as $tag ) {

				$available_tags[ $tag ] = $tag;

			}

		}

		asort( $available_tags );

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

		$params = $this->params;

		$params['body']['actions'] = array(
			array( 'cmd' => 'retrieve_account_lists' )
		);
		
		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_lists = array();

		if( ! empty( $response->account_lists ) ) {

			foreach( $response->account_lists as $list ) {

				$available_lists[ $list ] = $list;

			}

		}

		asort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/kartra-fields.php';

		$crm_fields = array();

		foreach ( $kartra_fields as $index => $data ) {
			$crm_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$params = $this->params;

		// Try and get any custom fields by querying the first lead

		$params['body']['get_lead']['id'] = '1';
		
		$response = wp_remote_post( $this->api_url, $params );

		if( ! is_wp_error( $response ) ) {

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if( ! empty( $response ) ) {

				foreach( $response->lead_details as $field => $value ) {

					if( strpos($field, ' ') !== false || strtolower( $field ) != $field ) {
						$crm_fields[ $field ] = $field;
					}

				}

			}

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

		$params = $this->params;

		$params['body']['get_lead']['email'] = $email_address;

		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->message ) && $response->message == 'No lead found' ) {
			return false;
		}

		// Save to local buffer
		$kartra_email_buffer = get_option( 'wpf_kartra_email_buffer', array() );
		$kartra_email_buffer[ $response->lead_details->id ] = $email_address;
		update_option( 'wpf_kartra_email_buffer', $kartra_email_buffer, false );

		return $response->lead_details->id;

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

		$params = $this->params;
		$params['body']['get_lead']['id'] = $contact_id;

		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if( empty( $response->lead_details->tags ) ) {
			return $tags;
		}

		foreach( $response->lead_details->tags as $tag ) {
			$tags[] = $tag->tag_name;
		}

		// Add new tags to available tags if they don't already exist

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach ( $tags as $tag ) {

			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
			}

		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$params = $this->params;

		$params['body']['lead'] = array( 'id' => $contact_id );
		$params['body']['actions'] = array();

		foreach( $tags as $tag ) {
			$params['body']['actions'][] = array( 'cmd' => 'assign_tag', 'tag_name' => $tag );
		}

		$response = wp_remote_post( $this->api_url, $params );

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

		$params['body']['lead'] = array( 'id' => $contact_id );
		$params['body']['actions'] = array();

		foreach( $tags as $tag ) {
			$params['body']['actions'][] = array( 'cmd' => 'unassign_tag', 'tag_name' => $tag );
		}

		$response     = wp_remote_post( $this->api_url, $params );

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
		$params['body']['lead'] = $data;
		$params['body']['actions'] = array(
			array( 'cmd' => 'create_lead' )
		);

		$lists = wp_fusion()->settings->get( 'kartra_lists' );

		if( ! empty( $lists ) && ! empty( $lists[0] ) ) {

			// Try and assign to configured lists

			foreach( $lists as $list ) {
				$params['body']['actions'][] = array( 'cmd' => 'subscribe_lead_to_list', 'list_name' => $list );
			}

		}

		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Save to local buffer
		$kartra_email_buffer = get_option( 'wpf_kartra_email_buffer', array() );
		$kartra_email_buffer[ $body->actions[0]->create_lead->lead_details->id ] = $data['email'];
		update_option( 'wpf_kartra_email_buffer', $kartra_email_buffer, false );

		return $body->actions[0]->create_lead->lead_details->id;

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

		$data['cmd'] = 'edit_lead';
		$data['id'] = $contact_id;

		if( isset( $data['email'] ) ) {
			$data['new_email'] = $data['email'];
			unset( $data['email'] );
		}

		$params = $this->params;
		$params['body']['lead'] = $data;
		$params['body']['actions'] = array(
			array( 'cmd' => 'edit_lead' )
		);

		$response = wp_remote_post( $this->api_url, $params );

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

		$params = $this->params;
		$params['body']['get_lead']['id'] = $contact_id;
		
		$response = wp_remote_post( $this->api_url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json->lead_details->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $body_json->lead_details->{ $field_data['crm_field'] };
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

		// Won't work yet with Kartra

		return $contact_ids;

	}


}