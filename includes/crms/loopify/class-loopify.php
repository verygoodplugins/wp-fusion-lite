<?php

class WPF_Loopify {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Loopify OAuth stuff
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

		$this->slug     = 'loopify';
		$this->name     = 'Loopify';
		$this->supports = array( 'add_tags' );

		// OAuth
		$this->client_id     = 'wpfusion-client';
		$this->client_secret = 'bfMmSXbE1XtoVYszUlx5';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Loopify_Admin( $this->slug, $this->name, $this );
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
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  bool
	 */

	public function refresh_token() {

		$refresh_token = wp_fusion()->settings->get( 'loopify_refresh_token' );

		$params = array(
			'headers' => array(
				'Content-type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_remote_post( 'https://auth.loopify.com/token', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body_json->error ) ) {
			return new WP_Error( 'error', $body_json->error_description );
		}

		wp_fusion()->settings->set( 'loopify_token', $body_json->access_token );
		wp_fusion()->settings->set( 'loopify_refresh_token', $body_json->refresh_token );

		return $body_json->access_token;

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
			$access_token = wp_fusion()->settings->get( 'loopify_token' );
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

		if ( strpos( $url, 'loopify' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			if ( wp_remote_retrieve_response_code( $response ) == 401 ) {

				// Don't filter the responses to refresh the token
				remove_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

				// Refresh token
				$access_token = $this->refresh_token();

				if ( is_wp_error( $access_token ) ) {
					return $access_token;
				}

				add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

				$args['headers']['Authorization'] = 'Bearer ' . $access_token;

				sleep( 1 ); // It seems to take a second for the new token to stick?

				$response = wp_remote_request( $url, $args );

			} elseif ( wp_remote_retrieve_response_code( $response ) > 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body->error ) ) {

					$response = new WP_Error( 'error', $body->error . ' ' . $body->error_description );

				} elseif ( isset( $body->code ) && 0 == $body->code ) {

					$response = new WP_Error( 'error', $body->message );

				}
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

	public function connect( $access_token = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( false === $test ) {
			return true;
		}

		$request  = 'https://api.loopify.com/me';
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

		$request  = 'https://api.loopify.com/contacts/tag-groups';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response as $tag_group ) {

			if ( 'tags' == $tag_group->name ) {

				wp_fusion()->settings->set( 'loopify_tag_group', $tag_group->_id );

				foreach ( $tag_group->tags as $tag ) {

					$available_tags[ $tag ] = $tag;

				}

				break;

			}
		}

		asort( $available_tags );

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

		$built_in_fields = array();

		// Load built in fields
		require_once dirname( __FILE__ ) . '/admin/loopify-fields.php';

		foreach ( $loopify_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request  = 'https://api.loopify.com/contacts/custom-fields';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response as $field ) {
			$custom_fields[ $field->name ] = $field->name;
		}

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

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

		$request  = 'https://api.loopify.com/contacts?search=' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return $response->contacts[0]->_id;

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

		$request  = 'https://api.loopify.com/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		foreach ( $response->tagGroups as $tag_group ) {

			if ( 'tags' == $tag_group->name ) {
				$tags = $tag_group->tags;
				break;
			}
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

		$tag_group = wp_fusion()->settings->get( 'loopify_tag_group' );

		$body = array(
			'contactIds' => array( $contact_id ),
			'tagGroups'  => array(
				(object) array(
					'_id'  => $tag_group,
					'tags' => $tags,
				),
			),
		);

		$params         = $this->params;
		$params['body'] = json_encode( $body );

		$request  = 'https://api.loopify.com/contacts/tag-groups/bulk-insert';
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

		$tag_group = wp_fusion()->settings->get( 'loopify_tag_group' );

		$body = array(
			'contactIds' => array( $contact_id ),
			'tagGroups'  => array(
				(object) array(
					'_id'  => $tag_group,
					'tags' => $tags,
				),
			),
		);

		$params         = $this->params;
		$params['body'] = json_encode( $body );

		$request  = 'https://api.loopify.com/contacts/tag-groups/bulk-delete';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
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

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$custom_fields = array();

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		// Move custom fields into customFields
		foreach ( $data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {
				$custom_fields[ $key ] = $value;
				unset( $data[ $key ] );
			}
		}

		if ( ! empty( $custom_fields ) ) {

			$data['customFields'] = array();

			foreach ( $custom_fields as $key => $value ) {

				$custom_field = array(
					'name'  => $key,
					'value' => $value,
				);

				$data['customFields'][] = (object) $custom_field;

			}
		}

		$params         = $this->params;
		$params['body'] = json_encode( $data );

		$request  = 'https://api.loopify.com/contacts';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->_id;

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

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$custom_fields = array();

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		// Move custom fields into customFields
		foreach ( $data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {
				$custom_fields[ $key ] = $value;
				unset( $data[ $key ] );
			}
		}

		if ( ! empty( $custom_fields ) ) {

			$data['customFields'] = array();

			foreach ( $custom_fields as $key => $value ) {

				$custom_field = array(
					'name'  => $key,
					'value' => $value,
				);

				$data['customFields'][] = (object) $custom_field;

			}
		}

		$params           = $this->params;
		$params['body']   = json_encode( $data );
		$params['method'] = 'PUT';

		$request  = 'https://api.loopify.com/contacts/' . $contact_id;
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

		$request  = 'https://api.loopify.com/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true == $field_data['active'] ) {

				if ( isset( $response->{ $field_data['crm_field'] } ) ) {

					$user_meta[ $field_id ] = $response->{ $field_data['crm_field'] };
					continue;

				}

				// Check custom fields
				foreach ( $response->customFields as $custom_field ) {

					if ( $custom_field->name == $field_data['crm_field'] ) {
						$user_meta[ $field_id ] = $custom_field->value;
					}
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
		$proceed     = true;
		$page        = 1;

		$query = array(
			'name' => 'tags',
			'tags' => array( $tag ),
		);

		while ( $proceed ) {

			$request  = 'https://api.loopify.com/contacts/?selectedTags=' . urlencode( json_encode( array( $query ) ) ) . '&pageSize=100';
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->contacts as $contact ) {
				$contact_ids[] = $contact->_id;
			}

			if ( count( $response->contacts ) < 100 ) {
				$proceed = false;
			} else {
				$page++;
			}
		}

		return $contact_ids;

	}


}
