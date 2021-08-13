<?php

class WPF_MailChimp {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;


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
	 * Not working, get_contact_id() returns an id but the edit URL uses variable web_id.
	 * @var string
	 */

	public $edit_url = false;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'mailchimp';
		$this->name     = 'Mailchimp';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_MailChimp_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		$this->get_params(); // Set up the datacenter, etc

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_auto_login_contact_id', array( $this, 'auto_login_contact_id' ) );

		// $this->edit_url = 'https://'.$this->dc.'.admin.mailchimp.com/lists/members/view?id=%d';

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

		// Fix for country
		if ( $value == 'United States' ) {
			$value == 'United States of America';
		}

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
	 * MailChimp requires an email to be submitted when tags are applied/removed
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'mailchimp_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			$url      = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/';
			$response = wp_safe_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! isset( $result->email_address ) ) {
				return false;
			}

			return $result->email_address;

		}

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
		if ( empty( $dc ) ) {
			$key_exploded = explode( '-', $api_key );
			$dc           = $key_exploded[1];
		}

		$this->params = array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
				'Content-Type'  => 'application/json',
			),
			'user-agent'  => 'WP Fusion; ' . home_url(),
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

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $dc, $api_key );
		}

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/';
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
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		$available_lists = array();

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/?count=1000';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->lists as $list ) {
			$available_lists[ $list->id ] = $list->name;
		}

		wp_fusion()->settings->set( 'mc_lists', $available_lists );

		// Set default
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

		$request  = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/segments/?count=1000';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->segments ) ) {

			foreach ( $body->segments as $segment ) {

				if ( $segment->type == 'static' ) {

					$available_tags[ $segment->id ] = $segment->name;

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
		require dirname( __FILE__ ) . '/admin/mailchimp-fields.php';

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

		$contact_info = array();
		$request      = 'https://' . $this->dc . '.api.mailchimp.com/3.0/search-members/?query=' . $email_address;
		$response     = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$contact_id = false;

		// Exact matches
		if ( isset( $body->exact_matches ) && ! empty( $body->exact_matches->members ) ) {

			foreach ( $body->exact_matches->members as $member ) {

				if ( $member->list_id == $this->list ) {
					$contact_id = $member->id;
					break;
				}
			}
		} elseif ( isset( $body->full_search ) && ! empty( $body->full_search->members ) ) {

			foreach ( $body->full_search->members as $member ) {

				if ( $member->list_id == $this->list ) {
					$contact_id = $member->id;
					break;
				}
			}
		}

		return $contact_id;

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
			$tags[] = $tag->id;
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

		$email_address = $this->get_email_from_cid( $contact_id );

		foreach ( $tags as $tag ) {

			$request        = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/segments/' . $tag . '/members/';
			$params         = $this->get_params();
			$params['body'] = json_encode( array( 'email_address' => $email_address ) );

			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
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

		$email_address = $this->get_email_from_cid( $contact_id );

		foreach ( $tags as $tag ) {

			$request          = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/segments/' . $tag . '/members/' . $contact_id;
			$params           = $this->get_params();
			$params['method'] = 'DELETE';

			$response = wp_safe_remote_request( $request, $params );

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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// Put address fields in their places
		foreach ( $data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				if ( ! isset( $data[ $keyparts[0] ] ) ) {

					// Address can't be sent unless it's complete so we'll set "unknown" for now

					$data[ $keyparts[0] ] = array(
						'addr1'   => 'unknown',
						'addr2'   => 'unknown',
						'city'    => 'unknown',
						'zip'     => 'unknown',
						'country' => 'unknown',
						'state'   => 'unknown',
					);
				}

				$data[ $keyparts[0] ][ $keyparts[1] ] = $value;

				unset( $data[ $key ] );

			}
		}

		$payload = array(
			'status'        => 'subscribed',
			'email_address' => $data['email_address'],
			'merge_fields'  => $data,
		);

		if ( true == wpf_get_option( 'mc_optin' ) ) {
			$payload['status'] = 'pending';
		}

		unset( $payload['merge_fields']['email_address'] );

		if ( empty( $payload['merge_fields'] ) ) {
			unset( $payload['merge_fields'] );
		}

		$url              = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . md5( strtolower( $data['email_address'] ) );
		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = json_encode( $payload );

		$response = wp_safe_remote_request( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;

	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		// Put address fields in their places
		foreach ( $data as $key => $value ) {

			if ( strpos( $key, '+' ) !== false ) {

				$keyparts = explode( '+', $key );

				if ( ! isset( $data[ $keyparts[0] ] ) ) {

					// Address can't be sent unless it's complete so we'll set "unknown" for now

					$data[ $keyparts[0] ] = array(
						'addr1'   => 'unknown',
						'addr2'   => 'unknown',
						'city'    => 'unknown',
						'zip'     => 'unknown',
						'country' => 'unknown',
						'state'   => 'unknown',
					);
				}

				$data[ $keyparts[0] ][ $keyparts[1] ] = $value;

				unset( $data[ $key ] );

			}
		}

		if ( empty( $data['email_address'] ) ) {
			$email_address = $this->get_email_from_cid( $contact_id );
		} else {
			$email_address = $data['email_address'];
			unset( $data['email_address'] );
		}

		// Yes email address changes do work

		$url              = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/members/' . $contact_id . '/';
		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = json_encode(
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
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		$contact_ids = array();

		$url      = 'https://' . $this->dc . '.api.mailchimp.com/3.0/lists/' . $this->list . '/segments/' . $tag . '/members?count=1000';
		$response = wp_safe_remote_get( $url, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->members as $contact ) {
			$contact_ids[] = $contact->id;
		}

		return $contact_ids;

	}


}
