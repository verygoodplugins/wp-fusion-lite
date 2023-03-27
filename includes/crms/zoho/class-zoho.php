<?php

class WPF_Zoho {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Zoho OAuth stuff
	 */

	public $client_id;

	public $client_secret_us;

	public $client_secret_eu;

	public $client_secret_in;

	public $client_secret_au;

	public $api_domain;

	/**
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type;

	/**
	 * Lets us link directly to editing a contact record.
	 * @var string
	 */

	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'zoho';
		$this->name     = 'Zoho';
		$this->supports = array();

		// OAuth
		$this->client_id = '1000.BC6W0X67OT9F47300RAHN6TOPDG0E3';

		$this->client_secret_us = '3618d9156bc0e54d177585fcc0d6443c6791460c2a';
		$this->client_secret_eu = 'cddd03e43d2864dcfbee5b3178668cfc7b8f3457b5';
		$this->client_secret_in = 'bd920ac806f5fe45c63e52fa6ab9416c14d479d20e';
		$this->client_secret_au = '08dcc7d1734284158f1819af1e06490777a4682323';

		$this->object_type = 'Contacts';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Zoho_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		remove_all_filters( 'wpf_format_field_value' ); // removes the base filtering in WPF_CRM_Base.

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		$org_id = wpf_get_option( 'zoho_org_id' );

		if ( ! empty( $org_id ) ) {

			$location = wpf_get_option( 'zoho_location' );

			if ( 'us' == $location ) {
				$domain = 'com';
			} elseif ( 'au' == $location ) {
				$domain = 'com.au';
			} else {
				$domain = $location;
			}

			$this->edit_url = 'https://crm.zoho.' . $domain . '/crm/' . $org_id . '/tab/Contacts/%d';
		}

		// Set up params.

		$this->get_params();
	}


	/**
	 * Formats user entered data to match Zoho field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'datepicker' == $field_type || 'date' == $field_type ) {

			if ( ! is_numeric( $value ) ) {
				$value = strtotime( $value );
			}

			// Adjust formatting for date fields (doesn't work with Date/time fields)
			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( 'datetime' == $field_type ) {

			if ( ! is_numeric( $value ) ) {
				$value = strtotime( $value );
			}

			// Works for Date/Time field
			$date = date( 'c', $value );

			return $date;

		} elseif ( 'checkbox' == $field_type || 'checkbox-full' == $field_type ) {

			if ( ! empty( $value ) ) {

				// If checkbox is selected
				return true;

			}
		} elseif ( 'text' == $field_type || 'textarea' == $field_type ) {

			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			return strval( $value );

		} elseif ( ( 'multiselect' == $field_type || 'checkboxes' == $field_type ) && ! is_array( $value ) ) {

			return array_map( 'trim', explode( ',', $value ) );

		} else {

			return $value;

		}

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_token = null ) {

		// Get saved data from DB.
		if ( empty( $access_token ) ) {
			$access_token = wpf_get_option( 'zoho_token' );
		}

		$this->api_domain = wpf_get_option( 'zoho_api_domain' );

		$this->params = array(
			'timeout'     => 20,
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Zoho-oauthtoken ' . $access_token,
			),
			'sslverify'   => false,
		);

		$this->object_type = apply_filters( 'wpf_crm_object_type', $this->object_type );

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  bool
	 */

	public function refresh_token() {

		$refresh_token = wpf_get_option( 'zoho_refresh_token' );
		$location      = wpf_get_option( 'zoho_location' );

		if ( $location == 'eu' ) {
			$client_secret   = $this->client_secret_eu;
			$accounts_server = 'https://accounts.zoho.eu';
		} elseif ( $location == 'in' ) {
			$client_secret   = $this->client_secret_in;
			$accounts_server = 'https://accounts.zoho.in';
		} elseif ( $location == 'au' ) {
			$client_secret   = $this->client_secret_au;
			$accounts_server = 'https://accounts.zoho.com.au';
		} else {
			$client_secret   = $this->client_secret_us;
			$accounts_server = 'https://accounts.zoho.com';
		}

		$request  = $accounts_server . '/oauth/v2/token?client_id=' . $this->client_id . '&grant_type=refresh_token&client_secret=' . $client_secret . '&refresh_token=' . $refresh_token;
		$response = wp_safe_remote_post( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $body_json->access_token ) ) {
			return new WP_Error( 'error', 'Unable to refresh access token.' );
		}

		$this->get_params( $body_json->access_token );

		wp_fusion()->settings->set( 'zoho_token', $body_json->access_token );

		return $body_json->access_token;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'zoho' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->code ) ) {

				// Codes.

				if ( 'INVALID_TOKEN' === $body_json->code ) {

					$access_token = $this->refresh_token();

					if ( is_wp_error( $access_token ) ) {
						return $access_token;
					}

					$args['headers']['Authorization'] = 'Zoho-oauthtoken ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} elseif ( 'INVALID_DATA' === $body_json->code || 'MANDATORY_NOT_FOUND' === $body_json->code ) {

					$response = new WP_Error( 'error', '<strong>Invalid Data</strong> error: <strong>' . $body_json->message . '</strong>.' );

				} else {

					$response = new WP_Error( 'error', '<strong>' . $body_json->code . '</strong>: ' . $body_json->message . '.' );

				}
			} elseif ( ! empty( $body_json->data ) && isset( $body_json->data[0]->code ) && ( $body_json->data[0]->code == 'INVALID_DATA' || $body_json->data[0]->code == 'MANDATORY_NOT_FOUND' ) ) {

				$code = 'error';

				if ( 'MANDATORY_NOT_FOUND' === $body_json->data[0]->code ) {

					$message = 'Mandatory field not found: <pre>' . wpf_print_r( $body_json, true ) . '</pre>';

				} elseif ( 'INVALID_DATA' === $body_json->data[0]->code ) {

					if ( ( isset( $body_json->data[0]->details->api_name ) && 'Contact_Name' === $body_json->data[0]->details->api_name ) || 'the id given seems to be invalid' === $body_json->data[0]->message ) {

						$code    = 'not_found';
						$message = 'Invalid contact ID. It looks like this contact record has been deleted or merged. Please resync the user\'s contact ID from their admin user profile and try again.';

					} else {
						$message  = 'Invalid data passed for field.';
						$message .= '<br /><br />';
						$message .= 'This error normally means that you tried to update a Zoho field with invalid data. For example syncing multi-select data to a text field. ';
						$message .= 'It can also mean you synced multi-select or picklist data, but one or more of the options sent over the API didn\'t match the allowed options inside Zoho.';
					}

					$message .= '<br /><br /><strong>API response from Zoho:</strong><pre>' . wpf_print_r( $body_json, true ) . '</pre>';

				}

				$response = new WP_Error( $code, $message );

			} elseif ( wp_remote_retrieve_response_code( $response ) == 429 ) {

				$response = new WP_Error( 'error', 'Number of API requests per minute/day has exceeded the limit.' );

			} elseif ( wp_remote_retrieve_response_code( $response ) == 401 ) {

				$response = new WP_Error( 'error', 'Unauthorized. Check your access token on the Setup tab in the WP Fusion settings.' );

			} elseif ( wp_remote_retrieve_response_code( $response ) == 500 ) {

				$response = new WP_Error( 'error', 'Unexpected Zoho server error.' );

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

	public function connect( $access_token = null, $refresh_token = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		$this->get_params( $access_token );

		$request  = $this->api_domain . '/crm/v2/contacts';
		$response = wp_safe_remote_get( $request, $this->params );

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

		$this->sync_org();
		$this->sync_tags();
		$this->sync_crm_fields();
		$this->sync_layouts();
		$this->sync_users();

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

		$request  = $this->api_domain . '/crm/v2/settings/tags?module=' . $this->object_type . '&scope=ZohoCRM.settings.ALL';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if ( ! empty( $response->tags ) ) {

			foreach ( $response->tags as $tag ) {

				$available_tags[ $tag->name ] = $tag->name;

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

		$request  = $this->api_domain . '/crm/v2/settings/fields?module=' . $this->object_type;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$built_in_fields = array();
		$custom_fields   = array();

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body_json->fields ) ) {

			foreach ( $body_json->fields as $field ) {

				if ( false == $field->custom_field ) {
					$built_in_fields[ $field->api_name ] = $field->field_label;
				} else {
					$custom_fields[ $field->api_name ] = $field->field_label;
				}
			}
		}

		asort( $built_in_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}


	/**
	 * Syncs available contact layouts
	 *
	 * @access public
	 * @return array Layouts
	 */

	public function sync_layouts() {

		$request  = $this->api_domain . '/crm/v2/settings/layouts?module=' . $this->object_type;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$available_layouts = array();

		if ( ! empty( $body_json->layouts ) ) {

			foreach ( $body_json->layouts as $layout ) {

				$available_layouts[ $layout->id ] = $layout->name;

			}
		}

		wp_fusion()->settings->set( 'zoho_layouts', $available_layouts );

		return $available_layouts;

	}

	/**
	 * Syncs available contact owners
	 *
	 * @access public
	 * @return array Owners
	 */

	public function sync_users() {

		$request  = $this->api_domain . '/crm/v2/users?type=AllUsers';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$available_users = array();

		if ( ! empty( $body_json->users ) ) {
			foreach ( $body_json->users as $user ) {
				$available_users[ $user->id ] = $user->first_name . ' ' . $user->last_name;
			}
		}

		wp_fusion()->settings->set( 'zoho_users', $available_users );

		return $available_users;

	}

	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type . '/search?email=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) || empty( $body_json->data ) ) {
			return false;
		}

		return $body_json->data[0]->id;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type . '/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if ( empty( $body_json ) || empty( $body_json->data[0]->Tag ) ) {
			return $tags;
		}

		foreach ( $body_json->data[0]->Tag as $tag ) {
			$tags[] = $tag->name;
		}

		// Maybe update available tags list

		$available_tags = wpf_get_option( 'available_tags' );

		foreach ( $body_json->data[0]->Tag as $tag ) {
			$available_tags[ $tag->name ] = $tag->name;
		}

		asort( $available_tags );

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

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type . '/' . $contact_id . '/actions/add_tags?tag_names=' . implode( ',', $tags ) . '&over_write=false';
		$response = wp_safe_remote_post( $request, $this->params );

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

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type . '/' . $contact_id . '/actions/remove_tags?tag_names=' . implode( ',', $tags ) . '&over_write=false';
		$response = wp_safe_remote_post( $request, $this->params );

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

	public function add_contact( $data ) {

		// Set layout.

		$layout = wpf_get_option( 'zoho_layout' );

		if ( ! empty( $layout ) && empty( $data['Layout'] ) ) {
			$data['Layout'] = $layout;
		}

		// Set owner

		$owner = wpf_get_option( 'zoho_owner' );

		if ( ! empty( $owner ) && empty( $data['Owner'] ) ) {
			$data['Owner'] = $owner;
		}

		// Contact creation will fail if there isn't a last name
		if ( empty( $data['Last_Name'] ) && ( 'Contacts' == $this->object_type || 'Leads' == $this->object_type ) ) {
			$data['Last_Name'] = 'unknown';
		}

		$params         = $this->params;
		$params['body'] = wp_json_encode( array( 'data' => array( $data ) ) );

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type;
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		return $body_json->data[0]->details->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( array( 'data' => array( $data ) ) );
		$params['method'] = 'PUT';

		$request  = $this->api_domain . '/crm/v2/' . $this->object_type . '/' . $contact_id;
		$response = wp_safe_remote_request( $request, $params );

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

		$url      = $this->api_domain . '/crm/v2/' . $this->object_type . '/' . $contact_id;
		$response = wp_safe_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json->data ) ) {
			return new WP_Error( 'error', 'Unable to find contact ID ' . $contact_id . ' in Zoho.' );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json->data[0]->{$field_data['crm_field']} ) ) {
				$user_meta[ $field_id ] = $body_json->data[0]->{$field_data['crm_field']};

				// Fix objects / lookups
				if ( is_object( $user_meta[ $field_id ] ) && isset( $user_meta[ $field_id ]->name ) ) {
					$user_meta[ $field_id ] = $user_meta[ $field_id ]->name;
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

		$contact_ids = array();
		$page        = 1;
		$proceed     = true;

		while ( $proceed == true ) {

			$url      = $this->api_domain . '/crm/v2/' . $this->object_type . '/search?word=' . urlencode( $tag ) . '&page=' . $page;
			$response = wp_safe_remote_get( $url, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body_json->data ) ) {
				return $contact_ids;
			}

			foreach ( $body_json->data as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if ( $body_json->info->more_records == false ) {
				$proceed = false;
			} else {
				$page++;
			}
		}

		return $contact_ids;

	}

	/**
	 * Get organization ID to use for edit URL.
	 *
	 * @since  3.37.30
	 *
	 * @return int Organization ID.
	 */
	public function sync_org() {

		$request  = $this->api_domain . '/crm/v2/org';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json   = json_decode( wp_remote_retrieve_body( $response ) );
		$zoho_org_id = $body_json->org[0]->domain_name;

		wp_fusion()->settings->set( 'zoho_org_id', $zoho_org_id );

		return $zoho_org_id;

	}

}
