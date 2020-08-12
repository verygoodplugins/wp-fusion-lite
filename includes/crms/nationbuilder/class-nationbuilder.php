<?php

class WPF_NationBuilder {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * NationBuilder OAuth stuff
	 */

	public $client_id;

	public $client_secret;

	public $token;

	public $url_slug;


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'nationbuilder';
		$this->name     = 'NationBuilder';
		$this->supports = array( 'add_tags' );

		// OAuth
		$this->client_id     = '8c06f23bba8806809b946b0cf07e3bc6788909d806d34fc75d801e32c01f07c0';
		$this->client_secret = '19bb5d590c55e6b1aeabb7f6bd07a14a616e2852b1a55ce6b396aada83c8ea7f';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_NationBuilder_Admin( $this->slug, $this->name, $this );
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
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

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

		if( !is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = $payload->payload->person->id;

		return $post_data;

	}


	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_token = null, $url_slug = null ) {

		// Get saved data from DB
		if ( empty( $access_token ) || empty( $slug ) ) {
			$access_token = wp_fusion()->settings->get( 'nationbuilder_token' );
			$url_slug = wp_fusion()->settings->get( 'nationbuilder_slug' );
		}

		$this->params = array(
			'timeout'     => 30,
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'headers'     => array(
				'Content-Type'	=> 'application/json',
				'Accept'		=> 'application/json'
			)
		);

		$this->token = $access_token;
		$this->url_slug = $url_slug;

		return $this->params;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'nationbuilderapi') !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			if( wp_remote_retrieve_response_code( $response ) > 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				$response = new WP_Error( 'error', $body->message );

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

	public function connect( $access_token = null, $slug = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token, $slug );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people?access_token=' . $this->token;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$continue = true;
		$next_url = false;

		while ( $continue ) {

			$request = 'https://' . $this->url_slug . '.nationbuilder.com';

			if ( false !== $next_url ) {
				$request .= $next_url;
			} else {
				$request .= '/api/v1/tags?limit=100';
			}

			$request .= '&access_token=' . $this->token;

			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if( ! empty( $response->results ) ) {

				foreach( $response->results as $tag ) {

					$available_tags[ $tag->name ] = $tag->name;

				}

			}

			if ( empty( $response->next ) ) {

				$continue = false;

			} else {

				$next_url = $response->next;

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

		// Load built in fields first

		require dirname( __FILE__ ) . '/admin/nationbuilder-fields.php';

		$built_in_fields = array();

		foreach ( $nationbuilder_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Then get custom ones

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/me?access_token=' . $this->token;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		foreach( $response->person as $field => $value ) {

			if( ! isset( $built_in_fields[ $field ] ) && ! in_array( $field, $nationbuilder_ignore_fields ) ) {

				$custom_fields[ $field ] = $field;

			}

		}

		asort( $custom_fields );

		$crm_fields = array( 'Standard Fields' => $built_in_fields, 'Custom Fields' => $custom_fields );

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

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/match?access_token=' . $this->token . '&email=' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->code ) && $response->code == 'no_matches' ) {
			return false;
		}

		return $response->person->id;

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

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if( empty( $response->taggings ) ) {
			return $tags;
		}

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );
		$needs_update = false;

		foreach( $response->taggings as $tag ) {

			$tags[] = $tag->tag;

			if( ! in_array( $tag->tag, $available_tags ) ) {
				$available_tags[] = $tag->tag;
				$needs_update = true;
			}

		}

		if( $needs_update ) {

			asort( $available_tags );
			wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$body = array( 'tagging' => array( 'tag' => array() ) );

		foreach( $tags as $tag ) {
			$body['tagging']['tag'][] = $tag;
		}

		$params 			= $this->params;
		$params['method'] 	= 'PUT';
		$params['body']		= json_encode( $body );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_remote_request( $request, $params );

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

		$body = array( 'tagging' => array( 'tag' => array() ) );

		foreach( $tags as $tag ) {
			$body['tagging']['tag'][] = $tag;
		}

		$params 			= $this->params;
		$params['method'] 	= 'DELETE';
		$params['body'] 	= json_encode( $body );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_remote_request( $request, $params );

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

		// Handle address fields

		foreach( $data as $key => $value ) {

			if( strpos($key, '+') !== false ) {

				$exploded_address = explode('+', $key);

				if( ! isset( $data[ $exploded_address[0] ] ) ) {
					$data[ $exploded_address[0] ] = array();
				}

				if ( ! empty( $value ) ) {
					$data[ $exploded_address[0] ][ $exploded_address[1] ] = $value;
				}

				unset( $data[ $key ] );

			}

		}

		$params = $this->params;
		$params['body'] = json_encode( array( 'person' => $data ) );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->person->id;

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

		// Handle address fields

		foreach( $data as $key => $value ) {

			if( strpos($key, '+') !== false ) {

				$exploded_address = explode('+', $key);

				if( ! isset( $data[ $exploded_address[0] ] ) ) {
					$data[ $exploded_address[0] ] = array();
				}

				if ( ! empty( $value ) ) {
					$data[ $exploded_address[0] ][ $exploded_address[1] ] = $value;
				}

				unset( $data[ $key ] );

			}

		}

		$params 			= $this->params;
		$params['method']	= 'PUT';
		$params['body'] 	= json_encode( array( 'person' => $data ) );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_remote_request( $request, $params );

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

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '?access_token=' . $this->token;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );

		$loaded_data = array();

		foreach( $response->person as $field => $value ) {

			if( ! empty( $value ) && ! is_object( $value ) ) {

				$loaded_data[ $field ] = $value;

			} elseif( ! empty( $value ) && is_object( $value ) ) {

				// Address fields

				foreach( $value as $address_key => $address_value ) {

					if( ! empty( $address_value ) ) {

						$loaded_data[ $field . '+' . $address_key ] = $address_value;

					}

				}

			}

		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $loaded_data[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_data[ $field_data['crm_field'] ];
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
		$next = false;
		$proceed = true;

		while( $proceed == true ) {

			if( $next == false ) {
				$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/tags/' . rawurlencode( $tag ) . '/people?limit=100&access_token=' . $this->token;
			} else {
				$request  = 'https://' . $this->url_slug . '.nationbuilder.com' . $next . '&access_token=' . $this->token;
			}

			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach( $response->results as $result ) {
				$contact_ids[] = $result->id;
			}
			
			if( empty( $response->next ) ) {
				$proceed = false;
			} else {
				$next = $response->next;
			}

		}

		return $contact_ids;

	}


}