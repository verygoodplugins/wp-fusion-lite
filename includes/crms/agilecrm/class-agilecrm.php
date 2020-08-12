<?php

class WPF_AgileCRM {

	/**
	 * (deprecated)
	 */

	public $app;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Contains AgileCRM domain
	 */

	public $domain;

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

		$this->slug     = 'agilecrm';
		$this->name     = 'AgileCRM';
		$this->supports = array( 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_AgileCRM_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_pre_send_contact_data', array( $this, 'format_contact_api_payload' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_action( 'wpf_api_success', array( $this, 'api_success' ), 10, 2 );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

	}


	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( wp_fusion()->settings->get( 'site_tracking' ) == false ) {
			return;
		}

		$tracking_id = wp_fusion()->settings->get( 'site_tracking_acct' );

		if ( empty( $tracking_id ) ) {
			return;
		}

		$domain = wp_fusion()->settings->get( 'agile_domain' );

		if ( wpf_is_user_logged_in() ) {
			$user  = get_userdata( wpf_get_current_user_id() );
			$email = $user->user_email;
		}

		echo '<script id="_agile_min_js" async type="text/javascript" src="https://' . $domain . '.agilecrm.com/stats/min/agile-min.js"> </script>';
		echo '<script type="text/javascript" >';
		echo 'var Agile_API = Agile_API || {}; Agile_API.on_after_load = function(){';
		echo '_agile.set_account("' . $tracking_id . '", "' . $domain . '", false);';
		echo '_agile.track_page_view();';
		echo '_agile_execute_web_rules();';

		if ( isset( $email ) ) {

			echo '_agile.set_email("' . $email . '");';

		}

		echo '};';

		echo '</script>';

	}


	/**
	 * Formats POST data received from Webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		if ( isset( $post_data['email'] ) ) {

			$user = get_user_by( 'email', $post_data['email'] );

			if ( $user != false ) {

				$post_data['contact_id'] = get_user_meta( $user->ID, 'agilecrm_contact_id', true );

			} else {

				$contact_id              = $this->get_contact_id( $post_data['email'] );
				$post_data['contact_id'] = $contact_id;

			}
		} else {

			$payload = json_decode( file_get_contents( 'php://input' ) );

			if ( is_object( $payload ) ) {
				$post_data['contact_id'] = $payload->eventData->id;
			}
		}

		return $post_data;

	}

	/**
	 * Sends a JSON success after Agile API actions so they show as success in the app
	 *
	 * @access public
	 * @return array
	 */

	public function api_success( $user_id, $method ) {

		wp_send_json_success();

	}


	/**
	 * Formats user entered data to match AgileCRM field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Agile has a weird thing with timezones that we don't understand...

			// Since only the date is displayed in Agile, lets incremement the timestamp by 12h to make sure the date doesn't show as the previous day

			if ( 00 == date( 'H', $value ) ) {
				$value += 12 * HOUR_IN_SECONDS;
			}

			$date = date( 'm/d/Y H:i:s', $value );

			return $date;

		} elseif ( $field_type == 'checkbox' || $field_type == 'checkbox-full' ) {

			if ( empty( $value ) ) {
				// If checkbox is unselected
				return 'off';
			} else {
				// If checkbox is selected
				return 'on';
			}
		} else {

			return $value;

		}

	}

	/**
	 * Formats contact data for AgileCRM preferred update / create structure
	 *
	 * @access public
	 * @return array
	 */

	public function format_contact_api_payload( $data ) {

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/agilecrm-fields.php';

		$contact_data = array( 'properties' => array() );
		$address_data = array();

		foreach ( $data as $crm_key => $value ) {

			// SYSTEM FIELDS
			foreach ( $agilecrm_fields as $system_field ) {

				if ( $system_field['crm_field'] == $crm_key ) {

					if ( strpos( $crm_key, '+' ) !== false ) {

						// For system fields with subtypes
						$field_components = explode( '+', $crm_key );

						if ( $field_components[0] == 'address' ) {

							$address_data[ $field_components[1] ] = $value;
							continue 2;

						} else {

							$contact_data['properties'][] = array(
								'type'    => 'SYSTEM',
								'name'    => $field_components[0],
								'subtype' => $field_components[1],
								'value'   => $value,
							);

							continue 2;

						}
					} else {

						// For standard system fields
						$contact_data['properties'][] = array(
							'type'  => 'SYSTEM',
							'name'  => $crm_key,
							'value' => $value,
						);

						continue 2;

					}
				}
			}

			// CUSTOM FIELDS
			// If field didn't match a system field
			$contact_data['properties'][] = array(
				'type'  => 'CUSTOM',
				'name'  => $crm_key,
				'value' => $value,
			);

		}

		// If we're updating address data
		if ( ! empty( $address_data ) ) {

			$contact_data['properties'][] = array(
				'type'  => 'SYSTEM',
				'name'  => 'address',
				'value' => json_encode( $address_data ),
			);

		}

		return $contact_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'agilecrm' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$response_code    = wp_remote_retrieve_response_code( $response );
			$response_message = wp_remote_retrieve_response_message( $response );

			if ( $response_code > 204 && ! empty( $response_message ) ) {

				$response = new WP_Error( 'error', $response_message . '. ' . wp_remote_retrieve_body( $response ) );

			}
		}

		return $response;

	}


	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  bool
	 */

	public function get_params( $agile_domain = null, $user_email = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $agile_domain ) || empty( $user_email ) || empty( $api_key ) ) {

			$this->domain = wp_fusion()->settings->get( 'agile_domain' );
			$user_email   = wp_fusion()->settings->get( 'agile_user_email' );
			$api_key      = wp_fusion()->settings->get( 'agile_key' );

		} else {
			$this->domain = $agile_domain;
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $user_email . ':' . $api_key ),
				'Content-type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		return $this->params;
	}


	/**
	 * AgileCRM sometimes requires an email to be submitted when contacts are modified
	 *
	 * @access public
	 * @return string Email
	 */

	public function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'agilecrm_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			$contact = $this->load_contact( $contact_id );

			if ( ! is_wp_error( $contact ) ) {

				return $contact['user_email'];

			} else {

				return false;

			}
		}

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $agile_domain = null, $user_email = null, $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $agile_domain, $user_email, $api_key );
		}

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/users/current-user';
		$response = wp_remote_get( $request, $this->params );

		if ( wp_remote_retrieve_response_code( $response ) != 200 ) {

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
			} else {
				$message = wp_remote_retrieve_body( $response );
			}

			return new WP_Error( 'error', $message );
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

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/tags';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		foreach ( $body_json as $tag ) {
			$available_tags[ $tag->tag ] = $tag->tag;
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
		require dirname( __FILE__ ) . '/admin/agilecrm-fields.php';

		$built_in_fields = array();

		foreach ( $agilecrm_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		// Agile can't list custom fields so we'll query contacts instead. Not sure about the order of results. Might be oldest first.

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/search/?q=%&page_size=100&type=PERSON';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body_json as $contact_object ) {

			foreach ( $contact_object->properties as $field_object ) {

				if ( $field_object->type == 'CUSTOM' ) {
					$custom_fields[ $field_object->name ] = $field_object->name;
				}
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
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/search/email/' . $email_address;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) == 204 ) {

			// No contact found
			return false;

		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) ) {

			// Try cleaning up some stuff? Don't remember why this is here

			$body_json = preg_replace( '/("\w+"):(\d+)/', '\\1:"\\2"', wp_remote_retrieve_body( $response ) );
			$body_json = json_decode( $body_json );

		}

		if ( empty( $body_json ) ) {
			return false;
		}

		return $body_json->id;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $body_json->tags ) || empty( $body_json->tags ) ) {
			return array();
		}

		// Add new tags to available tags if they don't already exist
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		if ( empty( $available_tags ) ) {
			$available_tags = array();
		}

		foreach ( $body_json->tags as $tag ) {

			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $body_json->tags;

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

		// "Tag name should start with an alphabet and cannot contain special characters other than underscore and space."

		foreach ( $tags as $tag ) {
			if ( preg_match( '/[\'\.^£$%&*()}{@#~?><>,|=+¬-]/', $tag ) ) {
				return new WP_Error( 'error', 'Tag name cannot contain special characters other than underscore and space.' );
			}
		}

		$contact_json = array(
			'id'   => $contact_id,
			'tags' => $tags,
		);

		$nparams           = $this->params;
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $contact_json );

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/edit/tags';
		$response = wp_remote_request( $request, $nparams );

		// Error handling
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Possibly update available tags if it's a newly created one
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		foreach ( $tags as $tag ) {
			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
				$needs_update           = true;
			}
		}

		if ( isset( $needs_update ) ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
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

		$contact_json = array(
			'id'   => $contact_id,
			'tags' => $tags,
		);

		$nparams           = $this->params;
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $contact_json );

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/delete/tags';
		$response = wp_remote_request( $request, $nparams );

		// Error handling
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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$data = apply_filters( 'wpf_pre_send_contact_data', $data );

		$nparams         = $this->params;
		$nparams['body'] = json_encode( $data );

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts';
		$response = wp_remote_post( $request, $nparams );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = preg_replace( '/("\w+"):(\d+)/', '\\1:"\\2"', wp_remote_retrieve_body( $response ) );
		$body_json = json_decode( $body_json );

		return $body_json->id;

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

		$data       = apply_filters( 'wpf_pre_send_contact_data', $data );
		$data['id'] = $contact_id;

		$nparams           = $this->params;
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $data );

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/edit-properties';
		$response = wp_remote_request( $request, $nparams );

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

		$request  = 'https://' . $this->domain . '.agilecrm.com/dev/api/contacts/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$loaded_meta = array();

		foreach ( $body_json->properties as $field_object ) {

			if ( ! empty( $field_object->subtype ) ) {

				$loaded_meta[ $field_object->name . '+' . $field_object->subtype ] = $field_object->value;

			} else {

				$maybe_json = json_decode( $field_object->value );

				if ( json_last_error() === JSON_ERROR_NONE && is_object( $maybe_json ) ) {

					foreach ( (array) $maybe_json as $key => $value ) {
						$loaded_meta[ $field_object->name . '+' . $key ] = $value;
					}
				} else {

					$loaded_meta[ $field_object->name ] = $field_object->value;

				}
			}
		}

		// Fix email fields if no main email is set
		if ( empty( $loaded_meta['email'] ) ) {
			if ( ! empty( $loaded_meta['email+work'] ) ) {
				$loaded_meta['email'] = $loaded_meta['email+work'];
			} elseif ( ! empty( $loaded_meta['email+home'] ) ) {
				$loaded_meta['email'] = $loaded_meta['email+home'];
			}
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		// Set missing fields
		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		foreach ( $loaded_meta as $name => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $name ] ) && ! isset( $crm_fields['Custom Fields'][ $name ] ) ) {
				$crm_fields['Custom Fields'][ $name ] = $name;
				wp_fusion()->settings->set( 'crm_fields', $crm_fields );
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

		$request = 'https://' . $this->domain . '.agilecrm.com/dev/api/filters/filter/dynamic-filter';

		$params                            = $this->params;
		$params['headers']['Content-type'] = 'application/x-www-form-urlencoded';

		$filter = array(
			'rules'        => array(
				array(
					'LHS'       => 'tags',
					'CONDITION' => 'EQUALS',
					'RHS'       => $tag,
				),
			),
			'contact_type' => 'PERSON',
		);

		$contact_ids = array();
		$cursor      = false;
		$proceed     = true;

		while ( true == $proceed ) {

			$params['body'] = array(
				'page_size'  => 5000,
				'filterJson' => json_encode( $filter ),
			);

			if ( $cursor ) {
				$params['body']['cursor'] = $cursor;
			}

			$response = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body_json ) ) {
				$proceed = false;
				continue;
			}

			foreach ( $body_json as $i => $contact_object ) {

				$contact_ids[] = $contact_object->id;

			}

			// Check for cursor on last contact
			if ( isset( $contact_object->cursor ) ) {
				$cursor = $contact_object->cursor;
			} else {
				$proceed = false;
			}
		}

		return $contact_ids;

	}

}
