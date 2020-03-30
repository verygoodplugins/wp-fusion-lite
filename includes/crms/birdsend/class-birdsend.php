<?php

class WPF_BirdSend {

	//
	// Unsubscribes: BirdSend can return a contact ID and tags from an unsubscribed subscriber, as well as update tags
	//

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * BirdSend OAuth stuff
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

		$this->slug     = 'birdsend';
		$this->name     = 'BirdSend';
		$this->supports = array();

		// OAuth
		$this->client_id     = '132';
		$this->client_secret = '7UYfusxoBqEvI3OxKgigSpQwBRDeH12VmOkLH5if';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_BirdSend_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * BirdSend requires an email to be submitted when contacts are modified
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users( array( 'meta_key'   => 'birdsend_contact_id',
		                           'meta_value' => $contact_id,
		                           'fields'     => array( 'user_email' )
		) );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;
			
		} else {
			
			// Try an API lookup

			$data = $this->load_contact( $contact_id );

			if( ! is_wp_error( $data ) && ! empty( $data['user_email'] ) ) {

				return $data['user_email'];

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

	public function get_params( $access_token = null ) {

		// Get saved data from DB
		if ( empty( $access_token ) ) {
			$access_token = wp_fusion()->settings->get( 'birdsend_token' );
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
		);

		return $this->params;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'birdsend' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			if ( wp_remote_retrieve_response_code( $response ) > 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				$message = $body->message;

				if ( ! empty( $body->errors ) ) {

					foreach ( $body->errors as $error ) {

						$message .= ' ' . implode( '. ', $error );

					}

				}

				$response = new WP_Error( 'error', $message );

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

	public function connect( $access_token = null, $refresh_token = false, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.birdsend.co/v1/account';
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

		$available_tags = array();

		$proceed = true;
		$page    = 1;

		while ( $proceed ) {

			$request  = 'https://api.birdsend.co/v1/tags?per_page=100&page=' . $page;
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->data ) ) {

				foreach ( $response->data as $tag ) {

					$available_tags[ $tag->tag_id ] = $tag->name;

				}
			}

			if ( count( $response->data ) < 100 ) {
				$proceed = false;
			}

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

		$request  = 'https://api.birdsend.co/v1/fields?per_page=100';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$crm_fields = array( 'email' => 'Email' );

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $field ) {

				$crm_fields[ $field->key ] = $field->label;

			}
		}

		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.birdsend.co/v1/contacts?search_by=email&keyword=' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->data ) ) {
			return false;
		}

		return $response->data[0]->contact_id;

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

		$request  = 'https://api.birdsend.co/v1/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if ( empty( $response->tags ) ) {
			return $tags;
		}

		foreach ( $response->tags as $tag ) {
			$tags[] = $tag->tag_id;
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

		// BirdSend applies tags by name, not ID
		foreach ( $tags as $i => $tag_id ) {
			$tags[ $i ] = wp_fusion()->user->get_tag_label( $tag_id );
		}

		$body = array( 'tags' => $tags );

		$params           = $this->params;
		$params['body']   = json_encode( $body );

		$request  = 'https://api.birdsend.co/v1/contacts/' . $contact_id . '/tags';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
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

		$params           = $this->params;
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag_id ) {

			$request  = 'https://api.birdsend.co/v1/contacts/' . $contact_id . '/tags/' . $tag_id;
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

		$update_data = array(
			'email'  => $data['email'],
			'fields' => $data,
		);

		unset( $update_data['fields']['email'] );

		$params         = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api.birdsend.co/v1/contacts/';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->contact_id;

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

		$update_data = array(
			'fields' => $data,
		);

		if ( isset( $update_data['fields']['email'] ) ) {
			$update_data['email'] = $update_data['fields']['email'];
			unset( $update_data['fields']['email'] );
		} else {

			// Email is required for updates
			$update_data['email'] = $this->get_email_from_cid( $contact_id );

		}

		$params           = $this->params;
		$params['method'] = 'PATCH';
		$params['body']   = json_encode( $update_data );

		$request  = 'https://api.birdsend.co/v1/contacts/' . $contact_id;
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

		$request  = 'https://api.birdsend.co/v1/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );

		// Move email into fields so it's loaded properly
		$response->fields->email = $response->email;

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $response->fields->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $response->fields->{ $field_data['crm_field'] };
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

		$tag = wp_fusion()->user->get_tag_label( $tag );

		$contact_ids = array();
		$proceed     = true;
		$page        = 1;

		while ( $proceed ) {

			$request  = 'https://api.birdsend.co/v1/contacts?search_by=tag&keyword=' . urlencode( $tag ) . '&per_page=100&page=' . $page;
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->data as $result ) {
				$contact_ids[] = $result->contact_id;
			}

			if ( count( $response->data ) < 100 ) {
				$proceed = false;
			} else {
				$page++;
			}

		}

		return $contact_ids;

	}


}
