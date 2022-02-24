<?php

class WPF_MooSend {

	/**
	 * Contains API url
	 *
	 * @var  string
	 *
	 * @since 3.38.42
	 */

	public $url = 'https://api.moosend.com/v3';

	/**
	 * Altenrative API URL.
	 *
	 * @var string
	 */
	public $alt_url = 'https://gateway.services.moosend.com';


	/**
	 * List to use for operations,
	 *
	 * @var string
	 *
	 * @since 3.38.42
	 */

	public $list;

	/**
	 * Contains API key.
	 *
	 * @var string
	 *
	 * @since 3.38.42
	 */
	public $api_key;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * "add_fields" means that custom field / attrubute keys don't need to exist
	 * first in the CRM to be used. With add_fields enabled, WP Fusion will
	 * allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var  array
	 *
	 * @since 3.38.42
	 */

	public $supports = array( 'add_tags' );


	/**
	 * API parameters
	 *
	 * @var  array
	 *
	 * @since 3.38.42
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 * It needs the Organization URL and we can't get that through the API.
	 *
	 * @var  string
	 *
	 * @since 3.38.42
	 */
	public $edit_url = false;

	/**
	 * Get things started
	 *
	 * @since 3.38.42
	 */
	public function __construct() {

		$this->slug = 'moosend';
		$this->name = 'MooSend';

		$this->api_key = wpf_get_option( 'moosend_api_key' );

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-moosend-admin.php';
			new WPF_MooSend_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}



	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.38.42
	 */
	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format.
	 *
	 * @since 3.38.42
	 *
	 * @param  array $post_data The post data.
	 * @return array The post data.
	 */
	public function format_post_data( $post_data ) {

		$body = json_decode( file_get_contents( 'php://input' ), true );

		if ( ! empty( $body ) && isset( $body['Event']['ContactContext']['Id'] ) ) {
			$post_data['contact_id'] = $body['Event']['ContactContext']['Id'];
			$post_data['tags']       = $body['Event']['ContactContext']['Tags'];
		}

		return $post_data;

	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since 3.38.42
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {
		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = gmdate( 'm/d/Y H:i:s', $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} elseif ( 'checkbox' === $field_type && ! empty( $value ) ) {

			return ( boolval( $value ) === true ? 'true' : 'false' );

		} else {

			return $value;

		}

	}

	/**
	 * Gets params for API calls.
	 *
	 * @since 3.38.42
	 * @param $api_key Api Key.
	 *
	 * @return array $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		if ( ! empty( $api_key ) ) {
			$this->api_key = $api_key;
		}

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'moosend_api_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'X-ApiKey'     => $this->api_key,
				'Accept'       => 'application/json',
			),
		);

		$this->list = wpf_get_option( 'moosend_default_list', false );

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since 3.38.42
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( isset( $body_json->Error ) && false != $body_json->Error ) {

				$response = new WP_Error( 'error', $body_json->Error );

			}
		}

		return $response;

	}


	/**
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @since 3.38.42
	 *
	 * @param  string $api_key   The API key.
	 * @param  bool   $test      Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = $this->url . '/lists.json?apikey=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $this->params );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.38.42
	 *
	 * @return bool
	 */
	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_lists();
		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}



	/**
	 * Gets all available lists and saves them to options
	 *
	 * @since  3.38.42
	 *
	 * @return array Lists.
	 */
	public function sync_lists() {

		$available_lists = array();

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = $this->url . '/lists.json?apikey=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $this->params );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		$lists = $response['Context']['MailingLists'];

		foreach ( $lists as $list ) {
			$available_lists[ $list['ID'] ] = $list['Name'];
		}

		wp_fusion()->settings->set( 'moosend_list', $available_lists );

		// Set default.
		$default_list = wpf_get_option( 'moosend_default_list', false );

		if ( empty( $default_list ) ) {

			reset( $available_lists );
			$default_list = key( $available_lists );

			wp_fusion()->settings->set( 'moosend_default_list', $default_list );

			$this->list = $default_list;
		}

		return $available_lists;

	}

	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since 3.38.42
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$params = $this->get_params();

		$available_tags = array();
		$page           = 1;
		$proceed        = true;
		$limit          = 0;

		while ( $proceed ) {
			$request  = $this->url . '/lists/' . $this->list . '/subscribers.json?apikey=' . $this->api_key . '&Page=' . $page . '&PageSize=100';
			$response = wp_safe_remote_get( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response    = json_decode( wp_remote_retrieve_body( $response ) );
			$paging      = $response->Context->Paging;
			$subscribers = $response->Context->Subscribers;

			foreach ( $subscribers as $subscriber ) {
				if ( empty( $subscriber->Tags ) ) {
					continue;
				}
				$available_tags = array_merge( $available_tags, $subscriber->Tags );
			}

			if ( $paging->TotalResults < 100 || $limit === 5 ) {
				$proceed = false;
			} else {
				$limit++;
				$page++;
			}
		}
		$available_tags = asort( array_unique( $available_tags ) );
		wp_fusion()->settings->set( 'available_tags', $available_tags );
		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since 3.38.42
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {
		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields first.
		require dirname( __FILE__ ) . '/moosend-fields.php';

		$built_in_fields = array();

		foreach ( $moosend_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$request  = $this->url . '/lists/' . $this->list . '/details.json/?apikey=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		$custom_fields = array();
		$fields_def    = $response->Context->CustomFieldsDefinition;

		if ( ! empty( $fields_def ) ) {
			foreach ( $fields_def as $field ) {
				$name                   = $field->Name;
				$custom_fields[ $name ] = $name;
			}
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
	 * @since 3.38.42
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$params = $this->get_params();

		$request  = $this->url . '/subscribers/' . $this->list . '/view.json?apikey=' . $this->api_key . '&Email=' . $email_address;
		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) && 'MEMBER_NOT_FOUND' === $response->get_error_message() ) {
			return false;
		} elseif ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) || empty( $response->Context ) ) {
			return false;
		}

		return $response->Context->ID;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.38.42
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$params = $this->get_params();

		$request  = $this->url . '/subscribers/' . $this->list . '/find/' . $contact_id . '.json?apikey=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = $response->Context->Tags;

		// Add new tags to available tags if they don't already exist.
		$needs_update = false;

		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $tags as $tag ) {

			if ( ! in_array( $tag, $available_tags, true ) ) {
				$needs_update           = true;
				$available_tags[ $tag ] = $tag;
			}
		}

		if ( $needs_update ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.38.42
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {
		$params         = $this->get_params();
		$data           = array(
			'MailingListId' => $this->list,
			'UserId'        => $contact_id,
			'MembersTags'   => array(
				'MemberId' => $contact_id,
				'Tags'     => $tags,
			),
		);
		$request        = $this->alt_url . '/members/add-tags';
		$params['body'] = wp_json_encode( $data );
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}


	/**
	 * Removes tags from a contact.
	 *
	 * @since 3.38.42
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {
		$params         = $this->get_params();
		$data           = array(
			'MailingListId' => $this->list,
			'UserId'        => $contact_id,
			'MembersTags'   => array(
				'MemberId' => $contact_id,
				'Tags'     => $tags,
			),
		);
		$request        = $this->alt_url . '/members/delete-tags';
		$params['body'] = wp_json_encode( $data );
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;

	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.38.42
	 *
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @param bool  $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$update_data = array(
			'Email' => $data['email'],
		);

		// Names.

		if ( isset( $data['firstname'] ) && isset( $data['lastname'] ) ) {

			$update_data['Name'] = $data['firstname'] . ' ' . $data['lastname'];

			unset( $data['firstname'] );
			unset( $data['lastname'] );

		}

		// Extract and organize custom fields.

		foreach ( $data as $key => $value ) {

			if ( ! in_array( strtolower( $key ), array( 'name', 'email' ) ) ) {

				if ( ! isset( $update_data['CustomFields'] ) ) {
					$update_data['CustomFields'] = array();
				}

				$update_data['CustomFields'][] = $key . '=' . $value;
			}
		}

		$params = $this->get_params();

		$request        = $this->url . '/subscribers/' . $this->list . '/subscribe.json?apikey=' . $this->api_key;
		$params['body'] = wp_json_encode( $update_data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.
		$contact_id = $body->Context->ID;

		// If there are custom fields in addition to email, send those in a separate request.
		if ( count( $data ) > 1 ) {
			$this->update_contact( $contact_id, $data, false );
		}

		return $contact_id;

	}



	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.38.42
	 *
	 * @param int   $contact_id      The ID of the contact to update.
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @param bool  $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$update_data = array(
			'Email' => isset( $data['email'] ) ? $data['email'] : wp_fusion()->crm_base->get_email_from_cid( $contact_id ),
		);

		// Names.

		if ( isset( $data['firstname'] ) && isset( $data['lastname'] ) ) {

			$update_data['Name'] = $data['firstname'] . ' ' . $data['lastname'];

			unset( $data['firstname'] );
			unset( $data['lastname'] );

		}

		// Extract and organize custom fields.

		foreach ( $data as $key => $value ) {

			if ( ! in_array( strtolower( $key ), array( 'name', 'email' ) ) ) {

				if ( ! isset( $update_data['CustomFields'] ) ) {
					$update_data['CustomFields'] = array();
				}

				$update_data['CustomFields'][] = $key . '=' . $value;
			}
		}

		$params = $this->get_params();

		$request = $this->url . '/subscribers/' . $this->list . '/update/' . $contact_id . '.json?apikey=' . $this->api_key;

		$params['body'] = wp_json_encode( $update_data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.38.42
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {
		$params = $this->get_params();

		$request  = $this->url . '/subscribers/' . $this->list . '/find/' . $contact_id . '.json?apikey=' . $this->api_key;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );

		$loaded_meta = array(
			'email' => $response->Context->Email,
			'name'  => $response->Context->Name,
		);

		if ( ! empty( $response->Context->CustomFields ) ) {

			foreach ( $response->Context->CustomFields as $field ) {
				$loaded_meta[ $field->Name ] = $field->Value;
			}
		}

		// Break the "name" field into firstname / lastname.

		if ( ! empty( $loaded_meta['name'] ) ) {

			$names                  = explode( ' ', $loaded_meta['name'] );
			$loaded_meta['firstname'] = $names[0];

			unset( $names[0] );

			if ( ! empty( $names ) ) {
				$loaded_meta['lastname'] = implode( ' ', $names );
			}
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.38.42
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		return array();
	}


}
