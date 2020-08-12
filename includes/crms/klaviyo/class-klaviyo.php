<?php

class WPF_Klaviyo {

	/**
	 * Contains API key (needed for some requests)
	 */

	public $key;

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

		$this->slug     = 'klaviyo';
		$this->name     = 'Klaviyo';
		$this->supports = array( 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Klaviyo_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'klaviyo' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			$code = wp_remote_retrieve_response_code( $response );

			if ( 404 == $code && strpos( $url, 'https://a.klaviyo.com/api/v2/people/search' ) !== false ) {

				// A 404 when searching for a contact is normal, we don't need to treat it as a serious error

				return $response;

			} elseif ( isset( $body_json->message ) ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( isset( $body_json->detail ) ) {

				$response = new WP_Error( 'error', $body_json->detail );

			}
		}

		return $response;

	}


	/**
	 * Klaviyo requires an email for list membership lookups
	 *
	 * @access public
	 * @return string Email
	 */

	public function get_email_from_cid( $contact_id ) {

		$users = get_users( array(
			'meta_key'   => 'klaviyo_contact_id',
			'meta_value' => $contact_id,
			'fields'     => array( 'user_email' )
		) );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			$contact_data = $this->load_contact( $contact_id );

			if ( ! empty( $contact_data ) ) {
				return $contact_data['user_email'];
			} else {
				return false;
			}

		}

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
			$access_key = wp_fusion()->settings->get( 'klaviyo_key' );
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'api-key'      => $access_key,
			),
		);

		$this->key = $access_key;

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		$request  = 'https://a.klaviyo.com/api/v2/lists';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
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

		$request  = 'https://a.klaviyo.com/api/v2/lists';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response as $list ) {
			$available_tags[ $list->list_id ] = $list->list_name;
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

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/klaviyo-fields.php';

		foreach ( $klaviyo_fields as $field ) {
			$crm_fields[ $field['crm_field'] ] = $field['crm_label'];
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

		$request  = 'https://a.klaviyo.com/api/v2/people/search?email=' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->id ) ) {

			return $response->id;

		} else {

			return false;

		}

	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$user_tags = array();

		$available_lists = wp_fusion()->settings->get( 'available_tags' );

		$email = $this->get_email_from_cid( $contact_id );

		if ( empty( $email ) ) {
			return new WP_Error( 'error', 'Unable to get email address from contact ID ' . $contact_id . '. Lists lookup failed.' );
		}

		// This is really bad for performance :(

		foreach ( $available_lists as $list_id => $list_name ) {

			$request  = 'https://a.klaviyo.com/api/v2/list/' . $list_id . '/members?emails=' . $email;
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response ) ) {
				$user_tags[] = $list_id;
			}

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

		$params = $this->params;
		$email  = $this->get_email_from_cid( $contact_id );

		$data = array(
			'api_key'  => $this->key,
			'profiles' => array( (object) array( 'email' => $email ) ),
		);

		$params['body'] = json_encode( $data );

		foreach ( $tags as $tag_id ) {

			$request  = 'https://a.klaviyo.com/api/v2/list/' . $tag_id . '/members';
			$response = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
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

		$params = $this->params;
		$email  = $this->get_email_from_cid( $contact_id );

		$data = array(
			'api_key' => $this->key,
			'emails'  => array( $email ),
		);

		$params['method'] = 'DELETE';
		$params['body']   = json_encode( $data );

		foreach ( $tags as $tag_id ) {

			$request  = 'https://a.klaviyo.com/api/v2/list/' . $tag_id . '/members';
			$response = wp_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
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

		// Klavio doesn't allow the $ sign when adding a contact
		foreach( $data as $key => $value ) {

			$newkey = str_replace( '$', '', $key );
			unset( $data[ $key ] );
			$data[ $newkey ] = $value;

		}

		// Users can't be added without a list
		$assign_lists = wp_fusion()->settings->get( 'assign_tags' );

		// If no tags configured, pick the first one in the account so the request doesn't fail
		if ( ! empty( $assign_lists ) ) {

			$assign_list = $assign_lists[0];

		} else {

			$available_lists = wp_fusion()->settings->get( 'available_tags' );
			reset( $available_lists );
			$assign_list = key( $available_lists );

		}

		$request        = 'https://a.klaviyo.com/api/v2/list/' . $assign_list . '/members';
		$params         = $this->params;
		$params['body'] = json_encode( array( 'profiles' => array( $data ) ) );

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body[0]->id;

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
			return false;
		}

		return new WP_Error( 'error', 'Klaviyo does not currently support updating Person records over the API.' );

		$data['api_key'] = $this->key;

		// This doesn't currently work

		$params           = $this->params;
		$params['body']   = $data;
		$params['method'] = 'PUT';
		$request          = 'https://a.klaviyo.com/api/v1/person/' . $contact_id . '?api-key=' . $this->key;

		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
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

		$params         = $this->params;
		$params['body'] = array( 'api_key' => $this->key );

		$request  = 'https://a.klaviyo.com/api/v1/person/' . $contact_id;
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			// Core fields
			if ( $field_data['active'] == true && isset( $body_json[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json[ $field_data['crm_field'] ];
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

	public function load_contacts( $tag_query ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();

		$request  = 'https://a.klaviyo.com/api/v2/group/' . $tag_query . '/members/all';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $response->records ) ) {

			foreach ( $response->records as $record ) {
				$contact_ids[] = $record->id;
			}

		}

		return $contact_ids;

	}

}
