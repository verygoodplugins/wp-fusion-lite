<?php

class WPF_MailChimp {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'mailchimp';


	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Mailchimp';

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'events', 'web_id', 'events_multi_key' );


	/**
	 * Data server for this account
	 */

	public $dc;


	/**
	 * List to use for operations
	 */

	public $list;


	/**
	 * Lets us link directly to editing a contact record.
	 *
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

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_MailChimp_Admin( $this->slug, $this->name, $this );
		}

		// See if we're using add_tags (added in v3.38.35).
		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( empty( $available_tags ) || ! is_numeric( key( $available_tags ) ) ) {
			$this->supports[] = 'add_tags';
		}

		$this->get_params(); // Set up the datacenter, etc.

		$this->edit_url = 'https://' . $this->dc . '.admin.mailchimp.com/lists/members/view?id=%d';

		// This has to run before init to be ready for WPF_Auto_Login::start_auto_login().
		add_filter( 'wpf_auto_login_contact_id', array( $this, 'auto_login_contact_id' ) );
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

		if ( isset( $post_data['data'] ) && isset( $post_data['data']['email'] ) ) {
			$post_data['contact_id'] = md5( sanitize_email( $post_data['data']['email'] ) );
			$post_data['email']      = sanitize_email( $post_data['data']['email'] );
		}

		// Journey builder.
		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( $payload && isset( $payload->contact_id ) ) {
			$post_data['contact_id'] = md5( sanitize_email( $payload->contact_id ) );
			$post_data['email']      = sanitize_email( $post_data['data']['email'] );
		}

		return $post_data;
	}

	/**
	 * Allows using an email address in the ?cid parameter
	 *
	 * @access public
	 * @return string Contact ID
	 */

	public function auto_login_contact_id( $contact_id ) {

		if ( is_email( $contact_id ) ) {
			$contact_id = $this->get_contact_id( urldecode( $contact_id ) );
		}

		return $contact_id;
	}

	/**
	 * Formats user entered data to match Userengage field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		// Fix for country.
		if ( 'United States' === $value ) {
			$value = 'United States of America';
		}

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.
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

		if ( strpos( $url, 'mailchimp' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code != 200 && $code != 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				$message = '<strong>' . $body->title . ':</strong> ' . $body->detail;

				if ( 'Resource Not Found' == $body->title ) {
					$message .= ' This usually indicates either the contact record was deleted, or the selected list or tag ID is no longer valid.';
				}

				if ( isset( $body->errors ) ) {

					$message .= '<ul>';

					foreach ( $body->errors as $error ) {
						$message .= '<li><strong>' . $error->field . ':</strong> ' . $error->message;

						// More helpful logging
						if ( 'Please enter a value' == $error->message ) {
							$message .= ' <br /><br />This error message means that ' . $error->field . ' is a required field in Mailchimp, but your API call did not contain this field, so it was rejected. To fix this, make sure that ' . $error->field . ' is not a required field.';
						}

						$message .= '</li>';
					}

					$message .= '</ul>';
				}

				$response = new WP_Error( 'error', $message );

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

	public function get_params( $dc = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $dc ) || empty( $api_key ) ) {
			$dc      = wpf_get_option( 'mailchimp_dc' );
			$api_key = wpf_get_option( 'mailchimp_key' );
		}

		// Get data server from key
		if ( empty( $dc ) && ! empty( $api_key ) ) {
			$key_exploded = explode( '-', $api_key );
			$dc           = ( isset( $key_exploded[1] ) ? $key_exploded[1] : '' );
		}

		$this->params = array(
			'timeout'    => 30,
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
				'Content-Type'  => 'application/json',
			),
			'user-agent' => 'WP Fusion; ' . home_url(),
		);

		$this->dc   = $dc;
		$this->list = wpf_get_option( 'mc_default_list', false );

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $dc = null, $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		$this->get_params( $dc, $api_key );

		$response = $this->sync_lists(); // make sure the API credentials are valid and there's a list.

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response ) ) {
			return new WP_Error( 'error', 'You must create at least one audience in Mailchimp to use with WP Fusion. Please create an audience and then try connecting again.' );
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
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_lists = array();

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/?count=1000';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->lists as $list ) {
			$available_lists[ $list->id ] = $list->name;
		}

		wp_fusion()->settings->set( 'mc_lists', $available_lists );

		// Set default.
		$default_list = wpf_get_option( 'mc_default_list', false );

		if ( empty( $default_list ) ) {

			reset( $available_lists );
			$default_list = key( $available_lists );
			wp_fusion()->settings->set( 'mc_default_list', $default_list );

			$this->list = $default_list;

		}

		return $available_lists;
	}

	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		$available_tags = array();

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/segments/?count=1000&type=static';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->segments ) ) {

			foreach ( $body->segments as $segment ) {

				if ( $segment->type == 'static' ) {

					if ( in_array( 'add_tags', $this->supports, true ) ) {
						$available_tags[ $segment->name ] = $segment->name;
					} else {
						$available_tags[ $segment->id ] = $segment->name;
					}
				}
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

		// Load built in fields to get field types and subtypes
		require __DIR__ . '/admin/mailchimp-fields.php';

		$crm_fields = array();

		foreach ( $mailchimp_fields as $index => $data ) {
			$crm_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/merge-fields/?count=100';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->merge_fields ) ) {

			foreach ( $body->merge_fields as $field ) {

				if ( 'address' !== $field->type ) {

					// Regular fields

					$crm_fields[ $field->tag ] = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $field->name );

				} else {

					// Multipart address fields

					if ( 'Address' !== $field->name ) {

						// If it's a custom address field, add the name as a prefix
						$prefix = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $field->name ) . ' - ';

					} else {
						$prefix = '';
					}

					$crm_fields[ $field->tag . '+addr1' ]   = $prefix . 'Address 1';
					$crm_fields[ $field->tag . '+addr2' ]   = $prefix . 'Address 2';
					$crm_fields[ $field->tag . '+city' ]    = $prefix . 'City';
					$crm_fields[ $field->tag . '+state' ]   = $prefix . 'State';
					$crm_fields[ $field->tag . '+zip' ]     = $prefix . 'Zip';
					$crm_fields[ $field->tag . '+country' ] = $prefix . 'Country';

				}
			}
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

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . md5( strtolower( $email_address ) );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {

			if ( false !== strpos( $response->get_error_message(), 'Resource Not Found' ) ) {
				return false; // contact not found.
			}

			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$user = get_user_by( 'email', $email_address );

		if ( $user ) {
			// Save the web ID so we can link to it later.
			update_user_meta( $user->ID, 'mailchimp_web_id', $body->web_id );
		}

		return $body->id;
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$tags     = array();
		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/tags/?count=1000';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body->tags ) ) {
			return $tags;
		}

		foreach ( $body->tags as $tag ) {

			if ( in_array( 'add_tags', $this->supports, true ) ) {
				$tags[] = $tag->name;
			} else {
				$tags[] = $tag->id;
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

		if ( ! in_array( 'add_tags', $this->supports, true ) ) {
			$tags = array_map( 'wpf_get_tag_label', $tags );
		}

		$data = array( 'tags' => array() );

		foreach ( $tags as $tag ) {

			$data['tags'][] = array(
				'name'   => $tag,
				'status' => 'active',
			);
		}

		$request        = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/tags/';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

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

		if ( ! in_array( 'add_tags', $this->supports, true ) ) {
			$tags = array_map( 'wpf_get_tag_label', $tags );
		}

		$data = array( 'tags' => array() );

		foreach ( $tags as $tag ) {
			$data['tags'][] = array(
				'name'   => $tag,
				'status' => 'inactive',
			);
		}

		$request        = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/tags/';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

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

		// Put address fields in their places.
		foreach ( $data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				if ( ! isset( $data[ $keyparts[0] ] ) ) {

					// Address can't be sent unless it's complete so we'll set "unknown" for now.

					$data[ $keyparts[0] ] = array(
						'addr1' => 'unknown',
						'city'  => 'unknown',
						'zip'   => 'unknown',
						'state' => 'unknown',
					);
				}

				$data[ $keyparts[0] ][ $keyparts[1] ] = $value;

				unset( $data[ $key ] );

			}
		}

		$payload = array(
			'status'                => 'subscribed',
			'email_address'         => $data['email_address'],
			'skip_merge_validation' => true,
			'merge_fields'          => $data,
		);

		if ( wpf_get_option( 'mc_optin' ) ) {
			$payload['status'] = 'pending';
		}

		unset( $payload['merge_fields']['email_address'] );

		if ( empty( $payload['merge_fields'] ) ) {
			unset( $payload['merge_fields'] );
		}

		$url              = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . md5( strtolower( $data['email_address'] ) );
		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $payload );

		$response = wp_safe_remote_request( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		$user = get_user_by( 'email', $data['email_address'] );

		if ( $user ) {
			// Save the web ID so we can link to it later.
			update_user_meta( $user->ID, 'mailchimp_web_id', $body->web_id );
		}

		return $body->id;
	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		// Put address fields in their places
		foreach ( $data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				if ( ! isset( $data[ $keyparts[0] ] ) ) {

					// Address can't be sent unless it's complete so we'll set "unknown" for now

					$data[ $keyparts[0] ] = array(
						'addr1' => 'unknown',
						'city'  => 'unknown',
						'zip'   => 'unknown',
						'state' => 'unknown',
					);
				}

				$data[ $keyparts[0] ][ $keyparts[1] ] = $value;

				unset( $data[ $key ] );

			}
		}

		if ( empty( $data['email_address'] ) ) {
			$email_address = wp_fusion()->crm->get_email_from_cid( $contact_id );
		} else {
			$email_address = $data['email_address'];
			unset( $data['email_address'] );
		}

		// Yes, email address changes do work.

		$url              = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/';
		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = wp_json_encode(
			array(
				'email_address' => $email_address,
				'merge_fields'  => $data,
			)
		);

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		if ( false !== $user_id ) {

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			update_user_meta( $user_id, 'mailchimp_contact_id', $body->id );

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

		$url      = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/';
		$response = wp_safe_remote_get( $url, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body           = json_decode( wp_remote_retrieve_body( $response ) );

		$loaded_meta                  = array();
		$loaded_meta['email_address'] = $body->email_address;

		foreach ( $body->merge_fields as $key => $value ) {

			if ( ! is_object( $value ) ) {

				$loaded_meta[ $key ] = $value;

			} else {

				// Address fields
				foreach ( $value as $subkey => $subvalue ) {
					$loaded_meta[ $key . '+' . $subkey ] = $subvalue;
				}
			}
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @param string $tag Tag name or ID.
	 * @return array|WP_Error Contact IDs returned
	 */
	public function load_contacts( $tag = false ) {

		// We need the tag ID for this.

		if ( false !== $tag && ! is_numeric( $tag ) ) {

			$url      = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/tag-search/?name=' . rawurlencode( $tag );
			$response = wp_safe_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body->tags ) ) {
				return new WP_Error( 'error', 'No tag found with name ' . $tag );
			}

			$tag = $body->tags[0]->id;

		}

		$url = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list;

		if ( $tag ) {
			$url .= '/segments/' . $tag . '/members?count=1000';
		} else {
			$url .= '/members?count=1000';
		}

		$contact_ids = array();
		$offset      = 0;

		do {
			$url = add_query_arg( 'offset', $offset, $url );

			$response = wp_safe_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $body->members as $contact ) {
				$contact_ids[] = $contact->id;
			}

			// Increase the offset by 1000 for the next batch.
			$offset += 1000;

			// See if we need to loop.
			$count = count( $body->members );

		} while ( 1000 === $count ); // Continue if we got a full batch, indicating more records may be available.

		return $contact_ids;
	}

	/**
	 * Track event.
	 *
	 * Track an event with the Mailchimp site tracking API.
	 *
	 * @since  3.38.16
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return; // can't track without an email.
		}

		$contact_id = md5( strtolower( $email_address ) ); // Mailchimp contact IDs are just a hash of the email address.

		// Mailchimp event name must not have spaces.
		$event = str_replace( ' ', '_', $event );

		$body = array(
			'name' => $event,
		);

		if ( is_array( $event_data ) ) {
			$body['properties'] = $event_data;
		} else {
			$body['properties'] = array( 'data' => $event_data );
		}

		$request            = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/events';
		$params             = $this->get_params();
		$params['body']     = wp_json_encode( $body );
		$params['blocking'] = false;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
