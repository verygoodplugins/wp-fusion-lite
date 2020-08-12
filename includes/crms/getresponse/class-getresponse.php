<?php

class WPF_GetResponse {

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

		$this->slug     = 'getresponse';
		$this->name     = 'GetResponse';
		$this->supports = array( 'add_lists' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_GetResponse_Admin( $this->slug, $this->name, $this );
		}

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
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}



	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = $payload->user->id;

		return $post_data;

	}

	/**
	 * Formats user entered data to match Getresponse field formats
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

		if( strpos($url, 'getresponse') !== false ) {

			$code = wp_remote_retrieve_response_code( $response );

			if( $code == 401 ) {

				$response = new WP_Error( 'error', 'Invalid API key' );

			} elseif( $code > 200 ) {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->message ) ) {

					$message = $body_json->message;

					if ( ! empty( $body_json->context ) ) {

						$message .= '. <strong>Context</strong>: ';

						foreach ( $body_json->context as $context ) {

							if ( is_object( $context ) ) {

								$message .= $context->fieldName . ': ' . $context->errorDescription . '. ';

							} else {

								$message .= $context;

							}
						}

					}

					$response = new WP_Error( 'error', $message );
				}


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

	public function get_params( $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'getresponse_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'X-Auth-Token' => 'api-key ' . $api_key,
				'Content-Type' => 'application/json',
			),
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.getresponse.com/v3/accounts';
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
		$this->sync_lists();

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

		$request  = 'http://api.getresponse.com/v3/tags';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $tag ) {
			$available_tags[ $tag['tagId'] ] = $tag['name'];
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}

	/**
	 * Loads all Campaigns lists
	 *
	 * @access public
	 * @return array Campaign lists
	 */

	public function sync_lists() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.getresponse.com/v3/campaigns';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$available_lists = array();

		foreach ( $body_json as $list ) {
			if ( is_object( $list ) ) {
				$available_lists[ $list->campaignId ] = $list->name;
			}
		}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/getresponse-fields.php';

		$built_in_fields = array();

		foreach ( $getresponse_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();
		$request       = 'http://api.getresponse.com/v3/custom-fields/';
		$response      = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $field ) {
			$custom_fields[ $field['customFieldId'] ] = $field['name'];
		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

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

		$params   = $this->params;
		$request  = 'https://api.getresponse.com/v3/contacts?query%5Bemail%5D=' . $email_address;
		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return false;
		}

		return $body_json[0]['contactId'];
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

		$tags     = array();
		$request  = 'http://api.getresponse.com/v3/contacts/' . $contact_id . '?fields=tags';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['tags'] ) ) {
			return false;
		}

		foreach ( $body_json['tags'] as $tag ) {
			$tags[] = $tag['tagId'];
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

		$apply_tags = array( 'tags' => array() );

		foreach ( $tags as $tag ) {
			$apply_tags['tags'][] = array( 'tagId' => $tag );
		}

		$request        = 'https://api.getresponse.com/v3/contacts/' . $contact_id . '/tags';
		$params         = $this->params;
		$params['body'] = json_encode( $apply_tags );

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

		// Currently not Possible
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

		$list = wp_fusion()->settings->get( 'getresponse_list' );

		// Allow filtering
		$list = apply_filters( 'wpf_add_contact_lists', $list );

		$contact_data = array();

		if ( ! empty( $list ) ) {

			$contact_data['campaign'] = array(
				'campaignId' => $list,
			);

		} else {

			// Get the first list
			$request   = 'https://api.getresponse.com/v3/campaigns';
			$response  = wp_remote_get( $request, $this->params );
			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $body_json as $list ) {
				if ( is_object( $list ) && $list->isDefault == 'true' ) {
					$first_list = $list->campaignId;
				}
			}

			$contact_data['campaign'] = array(
				'campaignId' => $first_list,
			);

			wp_fusion()->settings->set( 'getresponse_list', $first_list );

		}

		if ( isset( $data['name'] ) ) {
			$contact_data['name'] = $data['name'];
			unset( $data['name'] );
		}

		if ( isset( $data['email'] ) ) {
			$contact_data['email'] = $data['email'];
			unset( $data['email'] );
		}

		if ( ! empty( $data ) ) {

			$contact_data['customFieldValues'] = array();

			foreach ( $data as $key => $value ) {

				$contact_data['customFieldValues'][] = array(
					'customFieldId' => $key,
					'value'         => array( $value ),
				);

			}
		}

		$url            = 'https://api.getresponse.com/v3/contacts';
		$params         = $this->params;
		$params['body'] = json_encode( $contact_data );

		$response = wp_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// GetResponse just gives us a 202 message, no contact ID, so we look it up

		$contact_id = $this->get_contact_id( $contact_data['email'] );

		return $contact_id;

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

		$contact_data = array();

		if ( isset( $data['name'] ) ) {
			$contact_data['name'] = $data['name'];
			unset( $data['name'] );
		}

		if ( isset( $data['email'] ) ) {
			$contact_data['email'] = $data['email'];
			unset( $data['email'] );
		}

		if ( ! empty( $data ) ) {

			$contact_data['customFieldValues'] = array();

			foreach ( $data as $key => $value ) {

				$contact_data['customFieldValues'][] = array(
					'customFieldId' => $key,
					'value'         => array( $value ),
				);

			}
		}

		$url            = 'https://api.getresponse.com/v3/contacts/' . $contact_id;
		$params         = $this->params;
		$params['body'] = json_encode( $contact_data );

		$response = wp_remote_post( $url, $params );

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

		$url      = 'https://app.getresponse.com/api/public/users/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$name                    = $body_json['name'];
		$exploded_name           = explode( ' ', $name );
		$body_json['first_name'] = $exploded_name[0];
		unset( $exploded_name[0] );
		$body_json['last_name'] = implode( ' ', $exploded_name );

		foreach ( $body_json as $key => $field ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && $key == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field;
				}
			}
		}

		foreach ( $body_json['attributes'] as $attribute ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && $attribute['name_std'] == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $attribute['value'];
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

		// not possible
	}


}
