<?php

class WPF_Constant_Contact {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'constant-contact';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Constant Contact';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.40.0
	 */

	public $url = 'https://api.cc.email/v3';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag IDs).
	 * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
	 *
	 * "add_fields" means that constant-contact field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var array
	 * @since 3.40.0
	 */

	public $supports = array( 'add_tags_api', 'lists' );

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.40.0
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM. CC doesn't
	 * have permalinks to single contact records.
	 *
	 * @var  string
	 * @since 3.40.0
	 */
	public $edit_url = false;


	/**
	 * Client ID for OAuth.
	 *
	 * @var string
	 * @since  3.40.0
	 */
	public $client_id = '03c99fe6-9bc6-493e-90bb-1594b79d14b6';

	/**
	 * Client secret for OAuth.
	 *
	 * @var string
	 * @since  3.40.0
	 */
	public $client_secret = 'HVMvgNKQB-ejPV6uzqVblQ';

	/**
	 * Authorization URL for OAuth.
	 *
	 * @var string
	 * @since  3.40.0
	 */
	public $auth_url = 'https://authz.constantcontact.com/oauth2/default/v1/authorize';

	/**
	 * Get things started
	 *
	 * @since 3.40.0
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Constant_Contact_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.40.0
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		// Slow down the batch processses to get around the 4 requests per second limit
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.40.0
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} elseif ( 'multiselect' === $field_type && empty( $value ) ) {

			$value = null;

		} else {

			return $value;

		}

	}

	/**
	 * Slow down batch processses to get around the 4 requests per second limit.
	 *
	 * @return int Sleep time.
	 */
	public function set_sleep_time() {

		return 1;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since 3.40.0
	 *
	 * @return array $params The API parameters.
	 */
	public function get_params( $access_token = null ) {

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB.
		if ( empty( $access_token ) ) {
			$access_token = wpf_get_option( "{$this->slug}_token" );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-type'  => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token. Remove if not using OAuth.
	 *
	 * @since  3.40.0
	 *
	 * @return string An access token.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( "{$this->slug}_refresh_token" );

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
			),
			'body'       => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_safe_remote_post( 'https://authz.constantcontact.com/oauth2/default/v1/token', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) || ! isset( $body_json->access_token ) ) {
			return new WP_Error( 'error', 'Unknown error refreshing access token: ' . wp_remote_retrieve_body( $response ) );
		}

		$this->get_params( $body_json->access_token );

		wp_fusion()->settings->set( "{$this->slug}_refresh_token", $body_json->refresh_token );
		wp_fusion()->settings->set( "{$this->slug}_token", $body_json->access_token );

		return $body_json->access_token;

	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.40.0
	 *
	 * @return array The default fields in the CRM.
	 */
	public static function get_default_fields() {

		$fields = array();

		$fields['first_name'] = array(
			'crm_label' => 'First Name',
			'crm_field' => 'first_name',
		);

		$fields['last_name'] = array(
			'crm_label' => 'Last Name',
			'crm_field' => 'last_name',
		);

		$fields['user_email'] = array(
			'crm_label' => 'Email',
			'crm_field' => 'email_address',
		);

		$fields['phone_number'] = array(
			'crm_label' => 'Phone Number',
			'crm_field' => 'phone_numbers+phone_number',
		);

		$fields['job_title'] = array(
			'crm_label' => 'Job Title',
			'crm_field' => 'job_title',
		);

		$fields['company_name'] = array(
			'crm_label' => 'Company Name',
			'crm_field' => 'company_name',
		);

		$fields['kind'] = array(
			'crm_label' => 'Address Kind',
			'crm_field' => 'street_addresses+kind',
		);

		$fields['billing_address_1'] = array(
			'crm_label' => 'Street',
			'crm_field' => 'street_addresses+street',
		);

		$fields['billing_city'] = array(
			'crm_label' => 'City',
			'crm_field' => 'street_addresses+city',
		);

		$fields['billing_state'] = array(
			'crm_label' => 'State',
			'crm_field' => 'street_addresses+state',
		);

		$fields['billing_postcode'] = array(
			'crm_label' => 'Postal code',
			'crm_field' => 'street_addresses+postal_code',
		);

		$fields['billing_country'] = array(
			'crm_label' => 'Country',
			'crm_field' => 'street_addresses+country',
		);

		$fields['birthday_month'] = array(
			'crm_label' => 'Birthday Month',
			'crm_field' => 'birthday_month',
		);

		$fields['birthday_day'] = array(
			'crm_label' => 'Birthday Day',
			'crm_field' => 'birthday_day',
		);

		$fields['anniversary'] = array(
			'crm_label' => 'Anniversary',
			'crm_field' => 'anniversary',
		);

		return $fields;

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.40.0
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

			if ( 401 === $response_code ) {

				if ( 'unauthorized' === $body_json->error_key ) {

					$access_token = $this->refresh_token();

					if ( is_wp_error( $access_token ) ) {
						return $access_token;
					}

					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} else {

					$response = new WP_Error( 'error', 'Invalid API credentials.' );

				}
			} elseif ( 400 === $response_code ) {

				$errors = implode( '. ', wp_list_pluck( $body_json, 'error_message' ) );

				return new WP_Error( 'error', $errors );

			} elseif ( 409 === $response_code ) {

				// Conflict.
				$response = new WP_Error( 'error', implode( '. ', wp_list_pluck( $body_json, 'error_message' ) ) );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server [error 500]:', 'wp-fusion-lite' ), implode( '. ', wp_list_pluck( $body_json, 'error_message' ) ) );

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
	 * @since  3.40.0
	 *
	 * @param  string $access_token The access token.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $access_token = null, $test = false ) {
		if ( false === $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		$request  = $this->url . '/account/user/privileges/';
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
	 * @since 3.40.0
	 *
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
	 * Gets all available lists and saves them to options
	 *
	 * @since  3.40.0
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		$available_lists = array();

		$request = $this->url . '/contact_lists/';

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->lists as $list ) {
			$available_lists[ $list->list_id ] = $list->name;
		}

		asort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;

	}



	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.40.0
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request = $this->url . '/contact_tags/?limit=500';

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'.
		if ( ! empty( $response->tags ) ) {

			foreach ( $response->tags as $tag ) {
				$available_tags[ $tag->tag_id ] = sanitize_text_field( $tag->name );
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all constant-contact fields from CRM and merges with local list.
	 *
	 * @since  3.40.0
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Standard fields

		$standard_fields = array();

		foreach ( self::get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		$request  = $this->url . '/contact_custom_fields/';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$custom_fields = array();

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		foreach ( $response->custom_fields as $custom_field ) {
			$custom_fields[ $custom_field->custom_field_id ] = $custom_field->label;
		}

		// Load available fields into $crm_fields like 'field_key' => 'Field Label'.
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $standard_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.40.0
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		// status=all returns deleted contacts.
		$request  = $this->url . '/contacts/?status=all&email=' . rawurlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return $response->contacts[0]->contact_id;
	}

	/**
	 * Creates a new tag in CC and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$params  = $this->get_params();
		$request = $this->url . '/contact_tags/';

		$body           = array(
			'name'       => $tag_name,
			'tag_source' => 'Contact',
		);
		$params['body'] = wp_json_encode( $body );
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->tag_id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.40.0
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->url . '/contacts/' . $contact_id . '/?include=taggings';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// Parse response to create an array of tag ids. $tags = array(123, 678, 543); (should not be an associative array).
		return $response->taggings;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.40.0
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$request = $this->url . '/activities/contacts_taggings_add/';
		$params  = $this->get_params();

		$body = array(
			'source'  => array(
				'contact_ids' => array( $contact_id ),
			),
			'tag_ids' => array_values( $tags ),
		);

		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.40.0
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$request        = $this->url . '/activities/contacts_taggings_remove/';
		$params         = $this->get_params();
		$body           = array(
			'source'  => array(
				'contact_ids' => array( $contact_id ),
			),
			'tag_ids' => array_values( $tags ),
		);
		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.40.0
	 * @since 3.43.14 Added support for lists.
	 *
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		// Address kind is required.
		if ( ! isset( $contact_data['kind'] ) || ! in_array( $contact_data['kind'], array( 'home', 'work', 'other' ) ) ) {
			$contact_data['street_addresses+kind'] = 'other';
		}

		// Phone kind is required.
		if ( ! empty( $contact_data['phone_numbers+phone_number'] ) ) {
			if ( ! isset( $contact_data['kind'] ) || ! in_array( $contact_data['kind'], array( 'home', 'work', 'other', 'mobile' ) ) ) {
				$contact_data['phone_numbers+kind'] = 'other';
			}
		}

		// Put addresses/phones fields in their places.
		foreach ( $contact_data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				$contact_data[ $keyparts[0] ][0][ $keyparts[1] ] = $value;

				unset( $contact_data[ $key ] );

			}
		}

		$contact_data['create_source'] = 'Contact';

		// Email address format.
		$contact_data['email_address'] = array(
			'address'            => $contact_data['email_address'],
			'permission_to_send' => 'implicit',
		);

		// Custom fields.
		$crm_fields = wpf_get_option( 'crm_fields' );
		if ( ! empty( $crm_fields['Custom Fields'] ) ) {
			foreach ( $contact_data as $crm_field => $value ) {
				foreach ( $crm_fields['Custom Fields'] as $custom_field => $custom_field_value ) {
					if ( $crm_field === $custom_field ) {
						$contact_data['custom_fields'][] = array(
							'custom_field_id' => $crm_field,
							'value'           => $value,
						);
						unset( $contact_data[ $custom_field ] );
					}
				}
			}
		}

		// Lists.

		if ( ! empty( $contact_data['lists'] ) ) {
			$contact_data['list_memberships'] = $contact_data['lists'];
			unset( $contact_data['lists'] );
		} elseif ( wpf_get_option( 'assign_lists' ) ) {
			// Set a default list.
			$contact_data['list_memberships'] = wpf_get_option( 'assign_lists', array() );
		}

		$request = $this->url . '/contacts/';
		$params  = $this->get_params();

		$params['body'] = wp_json_encode( $contact_data );
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.
		return $body->contact_id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.40.0
	 * @since 3.43.14 Added support for lists.
	 *
	 * @param int   $contact_id      The ID of the contact to update.
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		// Address kind is required.
		if ( ! isset( $contact_data['kind'] ) || ! in_array( $contact_data['kind'], array( 'home', 'work', 'other' ) ) ) {
			$contact_data['street_addresses+kind'] = 'other';
		}

		// Phone kind is required.
		if ( ! empty( $contact_data['phone_numbers+phone_number'] ) ) {
			if ( ! isset( $contact_data['kind'] ) || ! in_array( $contact_data['kind'], array( 'home', 'work', 'other', 'mobile' ) ) ) {
				$contact_data['phone_numbers+kind'] = 'other';
			}
		}

		// Put addresses/phones fields in their places.
		foreach ( $contact_data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				$contact_data[ $keyparts[0] ][0][ $keyparts[1] ] = $value;

				unset( $contact_data[ $key ] );

			}
		}

		$contact_data['update_source'] = 'Contact';

		// Lists.

		if ( ! empty( $contact_data['lists'] ) ) {
			$contact_data['list_memberships'] = $contact_data['lists'];
			unset( $contact_data['lists'] );
		}

		if ( ! isset( $contact_data['email_address'] ) ) {
			$contact_data['email_address'] = wp_fusion()->crm->get_email_from_cid( $contact_id ); // email address is required.
		}

		$contact_data['email_address'] = array(
			'address' => $contact_data['email_address'],
		);

		// Custom fields.
		$crm_fields = wpf_get_option( 'crm_fields' );

		if ( ! empty( $crm_fields['Custom Fields'] ) ) {

			foreach ( $contact_data as $crm_field => $value ) {
				foreach ( $crm_fields['Custom Fields'] as $custom_field => $custom_field_value ) {
					if ( $crm_field === $custom_field ) {
						$contact_data['custom_fields'][] = array(
							'custom_field_id' => $crm_field,
							'value'           => $value,
						);
						unset( $contact_data[ $custom_field ] );
					}
				}
			}
		}

		$request          = $this->url . '/contacts/' . $contact_id;
		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PUT';

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.40.0
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/contacts/' . $contact_id . '?include=custom_fields,phone_numbers,street_addresses';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = ( $field_data['crm_field'] === 'email_address' ? $response[ $field_data['crm_field'] ]['address'] : $response[ $field_data['crm_field'] ] );
			}

			// Custom fields.
			if ( $field_data['active'] && ! empty( $response['custom_fields'] ) ) {
				foreach ( $response['custom_fields'] as $custom_field ) {
					if ( $field_data['crm_field'] == $custom_field['custom_field_id'] ) {
						$user_meta[ $field_id ] = $custom_field['value'];
					}
				}
			}

			// Addresses.
			if ( $field_data['active'] && ! empty( $response['street_addresses'] ) ) {
				foreach ( $response['street_addresses'][0] as $address_key => $address_value ) {
					if ( $field_data['crm_field'] == 'street_addresses+' . $address_key ) {
						$user_meta[ $field_id ] = $address_value;
					}
				}
			}

			// Phones.
			if ( $field_data['active'] && ! empty( $response['phone_numbers'] ) ) {
				foreach ( $response['phone_numbers'][0] as $phone_key => $phone_value ) {
					if ( $field_data['crm_field'] == 'phone_numbers+' . $phone_key ) {
						$user_meta[ $field_id ] = $phone_value;
					}
				}
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.40.0
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		$request  = $this->url . '/contacts/?tags=' . $tag;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contact_ids = array();
		$response    = json_decode( wp_remote_retrieve_body( $response ) );

		// Iterate over the contacts returned in the response and build an array such that $contact_ids = array(1,3,5,67,890);.
		foreach ( $response->contacts as $contact ) {
			$contact_ids[] = $contact->contact_id;
		}

		return $contact_ids;

	}

}
