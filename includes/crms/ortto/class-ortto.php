<?php

class WPF_Ortto {

	/**
	 * CRM name.
	 *
	 * @var string
	 * @since 3.41.2
	 */
	public $name = 'Ortto';

	/**
	 * CRM slug.
	 *
	 * @var string
	 * @since 3.41.2
	 */
	public $slug = 'ortto';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.41.2
	 */

	public $url;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * @var array
	 * @since 3.41.2
	 */

	public $supports = array( 'add_tags' );

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * Not supported by Ortto, we can't get the account slug over the API.
	 *
	 * @var string
	 * @since 3.41.2
	 */
	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @since 3.41.2
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-ortto-admin.php';
			new WPF_Ortto_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.41.2
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		$region    = wpf_get_option( 'ortto_region' );
		$region    = 'rest' === $region ? '' : $region . '.';
		$this->url = 'https://api.' . $region . 'ap3api.com';

	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.41.2
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			return array(
				'day'      => intval( gmdate( 'd', $value ) ),
				'month'    => intval( gmdate( 'm', $value ) ),
				'year'     => intval( gmdate( 'Y', $value ) ),
				'timezone' => gmdate( 'e', $value ),
			);

		} elseif ( is_array( $value ) ) {

			return $value;

		} elseif ( 'phn::phone' === $field ) {

			$phone_codes = include dirname( __FILE__ ) . '/phone_codes.php';
			$value       = strpos( $value, '+' ) === false ? '+' . $value : $value;
			foreach ( $phone_codes as $code ) {
				if ( ! is_array( $value ) && ( substr( $value, 0, strlen( $code['dial_code'] ) ) == $code['dial_code'] ) ) {
					$value = array(
						'c' => $code['dial_code'],
						'n' => str_replace( $code['dial_code'], '', $value ),
					);
				}
			}

			return $value;
		} elseif ( 'geo::city' === $field || 'geo::country' === $field ) {

			return array( 'name' => $value );

		} else {

			return $value;

		}

	}

	/**
	 * Formats POST data received from webhooks into standard format.
	 *
	 * @since  3.41.2
	 *
	 * @param array $post_data The data read out of the webhook URL.
	 * @return array $post_data The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( $payload ) {
			$post_data['contact_id'] = $payload->id;
		}

		return $post_data;

	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.41.2
	 *
	 * @param  string $api_key The api key.
	 * @return array  $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'ortto_api_key' );
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'X-Api-Key'    => $api_key,
				'Content-Type' => 'application/json',
			),
		);

		return $params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.41.2
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

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
	 * @since  3.41.2
	 *
	 * @param  string $region The account region.
	 * @param  string $api_key The API Key.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $region = null, $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$region  = 'rest' === $region ? '' : $region . '.';
		$request = 'https://api.' . $region . 'ap3api.com/v1/person/get';

		$params         = $this->get_params( $api_key );
		$params['body'] = '{}';
		$response       = wp_safe_remote_post( $request, $params );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.41.2
	 */
	public function sync() {

		$this->sync_crm_fields();

		$this->sync_tags();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.41.2
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request        = $this->url . '/v1/tags/get';
		$params         = $this->get_params();
		$params['body'] = '{}';
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) ) {
			return false;
		}

		foreach ( $body_json as $tag ) {
			$available_tags[ $tag->name ] = $tag->name;
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.41.2
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first.
		require dirname( __FILE__ ) . '/ortto-fields.php';

		foreach ( $ortto_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$request  = $this->url . '/v1/person/custom-field/get';
		$response = wp_safe_remote_post( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		$custom_fields = array();

		if ( ! empty( $response ) && ! empty( $response->fields ) ) {
			foreach ( $response->fields as $field ) {
				$field = $field->field;
				// Skip built in fields.
				if ( in_array( $field->id, array_keys( $built_in_fields ) ) ) {
					continue;
				}
				$custom_fields[ $field->id ] = $field->name;
			}
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
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.41.2
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$params  = $this->get_params();
		$request = $this->url . '/v1/person/get';

		$data = array(
			'filter' => array(
				'$str::is' => array(
					'field_id' => 'str::email',
					'value'    => $email_address,
				),
			),
		);

		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$items = $response->contacts;
		if ( empty( $items ) ) {
			return false;
		}

		return $items[0]->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.41.2
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {
		$email_address = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( $email_address == '' ) {
			return array();
		}

		$params  = $this->get_params();
		$request = $this->url . '/v1/person/get';

		$data = array(
			'fields' => array( 'tags' ),
			'filter' => array(
				'$str::is' => array(
					'field_id' => 'str::email',
					'value'    => $email_address,
				),
			),
		);

		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$user_tags = array();

		if ( isset( $response->contacts[0]->fields ) && ! empty( $response->contacts[0]->fields ) ) {
			$user_tags = $response->contacts[0]->fields->tags;
		}

		return $user_tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.41.2
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$email_address = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( ! $email_address ) {
			return false;
		}

		$params         = $this->get_params();
		$request        = $this->url . '/v1/person/merge';
		$data           = array(
			'people' => array(
				array(
					'fields' => array(
						'str::email' => $email_address,
					),
					'tags'   => $tags,
				),
			),
		);
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.41.2
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$email_address = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( ! $email_address ) {
			return false;
		}

		$params  = $this->get_params();
		$request = $this->url . '/v1/person/merge';
		$data    = array(
			'people' => array(
				array(
					'fields'     => array(
						'str::email' => $email_address,
					),
					'unset_tags' => $tags,
				),
			),
		);

		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.41.2
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$params         = $this->get_params();
		$request        = $this->url . '/v1/person/merge';
		$data           = array(
			'people' => array(
				array(
					'fields' => $contact_data,
				),
			),
		);
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->people[0]->person_id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.41.2
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$params         = $this->get_params();
		$request        = $this->url . '/v1/person/merge';
		$data           = array(
			'people' => array(
				array(
					'fields' => $contact_data,
				),
			),
		);
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );
		update_option( 'test', array( $params, $response ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.41.2
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$email_address = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( $email_address == '' ) {
			return array();
		}

		$params  = $this->get_params();
		$request = $this->url . '/v1/person/get';

		$crm_fields     = wp_fusion()->settings->get( 'crm_fields' );
		$default_fields = array_keys( $crm_fields['Standard Fields'] );

		$data           = array(
			'fields' => $default_fields,
			'filter' => array(
				'$str::is' => array(
					'field_id' => 'str::email',
					'value'    => $email_address,
				),
			),
		);
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		$response = $response['contacts'][0];

		foreach ( $response['fields'] as $field => $value ) {

			if ( is_array( $value ) ) {
				// Geo fields.
				if ( isset( $value['name'] ) ) {
					$response['fields'][ $field ] = $value['name'];
				}

				// Phone
				if ( isset( $value['c'] ) && isset( $value['n'] ) ) {
					$response['fields'][ $field ] = $value['c'] . $value['n'];
				}
			}
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response['fields'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response['fields'][ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.41.2
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		return array();
	}

}
