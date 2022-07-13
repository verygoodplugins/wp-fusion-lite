<?php

class WPF_Intercom {

	/**
	 * (deprecated)
	 */

	public $app;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = '';


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'intercom';
		$this->name     = 'Intercom';
		$this->supports = array( 'add_fields', 'add_tags', 'events', 'leads' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Intercom_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_woocommerce_customer_data', array( $this, 'set_country_names' ), 10, 2 );

		$app_id_code = wpf_get_option( 'app_id_code' );

		if ( ! empty( $app_id_code ) ) {
			$this->edit_url = 'https://app.intercom.com/a/apps/' . $app_id_code . '/users/%s/all-conversations';
		}

	}


	/**
	 * Formats user entered data to match CRM field formats.
	 *
	 * @since  3.37.29
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The field in the CRM.
	 * @return mixed
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} else {

			return $value;

		}

	}

	/**
	 * Formats POST data received from webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = sanitize_key( $payload->data->item->user->id );

		return $post_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'intercom' ) !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors['0']->code );

			}
		}

		return $response;

	}


	/**
	 * Use full country names instead of abbreviations with WooCommerce
	 *
	 * @access public
	 * @return array Customer data
	 */

	public function set_country_names( $customer_data, $order ) {

		if ( isset( $customer_data['billing_country'] ) ) {
			$customer_data['billing_country'] = WC()->countries->countries[ $customer_data['billing_country'] ];
		}

		if ( isset( $customer_data['shipping_country'] ) ) {
			$customer_data['shipping_country'] = WC()->countries->countries[ $customer_data['shipping_country'] ];
		}

		return $customer_data;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) ) {
			$access_key = wpf_get_option( 'intercom_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'Accept'           => 'application/json',
				'Authorization'    => 'Bearer ' . $access_key,
				'Content-Type'     => 'application/json',
				'Intercom-Version' => '1.4',
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

	public function connect( $access_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		$request  = 'https://api.intercom.io/me';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->errors ) ) {
			return new WP_Error( $response->errors[0]->code, $response->errors[0]->message );
		}

		// Save this for later

		wp_fusion()->settings->set( 'app_id_code', $response->app->id_code );

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

		$available_tags = array();

		$request  = 'https://api.intercom.io/tags';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->tags as $tag ) {
			$available_tags[ $tag->name ] = $tag->name;
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

		$crm_fields = array(
			'email'     => 'Email',
			'phone'     => 'Phone',
			'firstname' => 'First Name',
			'lastname'  => 'Last Name',
			'name'      => 'Name',
		);

		$request  = 'https://api.intercom.io/data_attributes';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->data_attributes as $field ) {

			if ( $field->api_writable == true && 'customer' == $field->model ) {
				$crm_fields[ $field->name ] = $field->label;
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

		$request  = 'https://api.intercom.io/users?email=' . rawurlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->id ) ) {

			return $response->id;

		} elseif ( isset( $response->users ) && ! empty( $response->users ) ) {

			return $response->users[0]->id;

		} else {

			return false;

		}

	}


	/**
	 * Gets lead ID for a user based on email address.
	 *
	 * @since  3.38.36
	 *
	 * @param  string $email_address The email address.
	 * @return int    Contact ID
	 */
	public function get_lead_id( $email_address ) {

		$request  = 'https://api.intercom.io/contacts?email=' . rawurlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->id ) ) {

			return $response->id;

		} elseif ( isset( $response->contacts ) && ! empty( $response->contacts ) ) {

			return $response->contacts[0]->id;

		} else {

			return false;

		}

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

		$user_tags = array();

		$request  = 'https://api.intercom.io/users/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $response['tags']['tags'] ) ) {
			return array();
		}

		foreach ( $response['tags']['tags'] as $tag ) {
			$user_tags[] = $tag['name'];
		}

		return $user_tags;
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

		$url    = 'https://api.intercom.io/tags';
		$params = $this->params;

		foreach ( $tags as $tag ) {

			$params['body'] = wp_json_encode(
				array(
					'name'  => $tag,
					'users' => array( array( 'id' => $contact_id ) ),
				)
			);

			$response = wp_safe_remote_post( $url, $params );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url    = 'https://api.intercom.io/tags';
		$params = $this->params;

		foreach ( $tags as $tag ) {

			$params['body'] = wp_json_encode(
				array(
					'name'  => $tag,
					'users' => array(
						array(
							'id'    => $contact_id,
							'untag' => true,
						),
					),
				)
			);

			$response = wp_safe_remote_post( $url, $params );

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

	public function add_contact( $data, $lead = false ) {

		// General cleanup and restructuring.

		$body = array( 'email' => $data['email'] );

		// Intercom requires a name.

		if ( ! isset( $data['firstname'] ) ) {
			$data['firstname'] = '';
		}

		if ( ! isset( $data['lastname'] ) ) {
			$data['lastname'] = '';
		}

		$body['name'] = trim( $data['firstname'] . ' ' . $data['lastname'] );

		unset( $data['firstname'] );
		unset( $data['lastname'] );

		if ( empty( $body['name'] ) ) {
			$body['name'] = 'unknown';
		}

		require_once dirname( __FILE__ ) . '/admin/intercom-fields.php';

		// Move core fields up into the body and out of attributes.

		foreach ( $intercom_fields as $field ) {
			if ( isset( $data[ $field['crm_field'] ] ) ) {
				$body[ $field['crm_field'] ] = $data[ $field['crm_field'] ];
				unset( $data[ $field['crm_field'] ] );
			}
		}

		if ( ! empty( $data ) ) {

			// All other custom fields.
			$body['custom_attributes'] = $data;

		}

		if ( false === $lead ) {
			$url = 'https://api.intercom.io/users';
		} else {
			$url = 'https://api.intercom.io/contacts';
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;

	}

	/**
	 * Adds a new lead.
	 *
	 * @since  3.38.36
	 *
	 * @param  array $data   The lead data.
	 * @return string The lead ID.
	 */
	public function add_lead( $data ) {

		return $this->add_contact( $data, $lead = true );

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $lead = false ) {

		// General cleanup and restructuring.

		$body = array( 'id' => $contact_id );

		// Maybe use the combined name

		if ( isset( $data['firstname'] ) && isset( $data['lastname'] ) ) {

			$body['name'] = $data['firstname'] . ' ' . $data['lastname'];

			unset( $data['firstname'] );
			unset( $data['lastname'] );

		}

		require dirname( __FILE__ ) . '/admin/intercom-fields.php';

		// Move core fields up into the body and out of attributes.

		foreach ( $intercom_fields as $field ) {
			if ( isset( $data[ $field['crm_field'] ] ) ) {
				$body[ $field['crm_field'] ] = $data[ $field['crm_field'] ];
				unset( $data[ $field['crm_field'] ] );
			}
		}

		if ( ! empty( $data ) ) {

			// All other custom fields
			$body['custom_attributes'] = $data;

		}

		if ( false === $lead ) {
			$url = 'https://api.intercom.io/users';
		} else {
			$url = 'https://api.intercom.io/contacts';
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Upadates a lead.
	 *
	 * @since  3.38.36
	 *
	 * @param  string $contact_id The contact ID.
	 * @param  array  $data       The lead data.
	 * @return string The lead ID.
	 */
	public function update_lead( $contact_id, $data ) {

		return $this->update_contact( $contact_id, $data, $lead = true );

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$url      = 'https://api.intercom.io/users/' . $contact_id;
		$response = wp_safe_remote_get( $url, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		// Break the "name" field into firstname / lastname

		$names                  = explode( ' ', $body_json['name'] );
		$body_json['firstname'] = $names[0];

		unset( $names[0] );

		if ( ! empty( $names ) ) {
			$body_json['lastname'] = implode( ' ', $names );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			// Core fields
			if ( $field_data['active'] == true && isset( $body_json[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json[ $field_data['crm_field'] ];
			}

			// Custom attributes
			if ( $field_data['active'] == true && isset( $body_json['custom_attributes'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json['custom_attributes'][ $field_data['crm_field'] ];
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

	public function load_contacts( $tag_query ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();
		$param       = false;
		$proceed     = true;

		while ( $proceed == true ) {

			$url = 'https://api.intercom.io/users/scroll/';

			if ( false !== $param ) {
				$url .= '?scroll_param=' . $param;
			}

			$response = wp_safe_remote_get( $url, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->users ) ) {

				$param = $response->scroll_param;

				foreach ( $response->users as $user ) {

					foreach ( $user->tags->tags as $tag ) {

						if ( $tag->name == $tag_query ) {
							$contact_ids[] = $user->id;
							break;
						}
					}
				}
			} else {

				$proceed = false;

			}
		}

		return $contact_ids;

	}

	/**
	 * Track event.
	 *
	 * Track an event with the Intercom site tracking API.
	 *
	 * @since  3.38.28
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		if ( empty( $email_address ) && ! wpf_is_user_logged_in() ) {
			// Tracking only works if WP Fusion knows who the contact is.
			return;
		}

		// Get the email address to track.
		if ( empty( $email_address ) ) {
			$user          = wpf_get_current_user();
			$email_address = $user->user_email;
		}

		$data = array(
			'event_name' => sanitize_title( $event ),
			'created_at' => time(),
			'email'      => $email_address,
			'metadata'   => array(
				'data' => $event_data,
			),
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( 'https://api.intercom.io/events', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


}
