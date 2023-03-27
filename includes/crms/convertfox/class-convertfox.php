<?php

class WPF_ConvertFox {

	//
	// Unsubscribes: Gist can return a contact ID and tags from an unsubscribed subscriber, as well as update tags
	//

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

		$this->slug     = 'convertfox';
		$this->name     = 'Gist';
		$this->supports = array( 'add_fields', 'add_tags', 'events', 'leads', 'events_multi_key' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_ConvertFox_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ), 10, 1 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 2 );

		// Add tracking code to footer
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ), 100 );

		add_action( 'wpf_forms_post_submission', array( $this, 'set_tracking_cookie_forms' ), 10, 4 );

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) ) {

			if ( isset( $payload->message ) && is_object( $payload->message ) ) { // automation webhooks.
				$payload->contact = $payload->message;
			}

			$post_data['contact_id'] = $payload->contact->id;
			$post_data['tags']       = wp_list_pluck( $payload->contact->tags, 'name' );
		}

		return $post_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'getgist' ) !== false && strpos( $url, '?email=' ) === false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0]->message );

			}
		}

		return $response;

	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.40.42
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type ) {

		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		return $value;

	}

	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		$email = wpf_get_current_user_email();

		if ( false !== $email ) {

			echo '<!-- WP Fusion / Gist identify -->';
			echo '<script type="text/javascript">';
			echo 'if ( typeof gist !== "undefined" ) {';
			echo 'gist.identify("' . esc_js( strtolower( $email ) ) . '");';
			echo '}';
			echo '</script>';

		}

	}

	/**
	 * Identify the user to the tracking script after a form submission
	 *
	 * @access public
	 * @return void
	 */

	public function set_tracking_cookie_forms( $update_data, $user_id, $contact_id, $form_id ) {

		if ( wpf_is_user_logged_in() || headers_sent() ) {
			return;
		}

		setcookie( 'wpf_guest', $update_data['email'], time() + DAY_IN_SECONDS * 365, COOKIEPATH, COOKIE_DOMAIN );

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
			$api_key = wpf_get_option( 'convertfox_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 20,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
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

		$request  = 'https://api.getgist.com/tags';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body_json->errors ) ) {
			return new WP_Error( 'error', 'Unauthorized. Make sure you\'re using an <strong>Access Token</strong> which can be found in Settings >> API and Integrations >> Access Token in your Convertfox account.' );
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

		$page           = 1;
		$proceed        = true;
		$available_tags = array();

		while ( $proceed ) {

			$request  = 'https://api.getgist.com/tags?per_page=50&page=' . $page;
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( $response['tags'] as $tag ) {
				$available_tags[ $tag['name'] ] = $tag['name'];
			}

			if ( empty( $response['tags'] ) || count( $response['tags'] ) < 50 ) {
				$proceed = false;
			}

			$page++;

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

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';

		$built_in_fields = array();

		foreach ( $convertfox_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request  = 'https://api.getgist.com/contacts?page=1&per_page=1';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( isset( $body_json['contacts'] ) && is_array( $body_json['contacts'] ) ) {

			foreach ( $body_json['contacts'] as $field_data ) {

				foreach ( $field_data['custom_properties'] as $key => $value ) {

					$custom_fields[ $key ] = $key;

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

		$contact_info = array();
		$request      = 'https://api.getgist.com/contacts?email=' . urlencode( $email_address );
		$response     = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['contact'] ) ) {
			return false;
		}

		return $body_json['contact']['id'];
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
		$request  = 'https://api.getgist.com/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json->contact->tags ) ) {
			return $tags;
		}

		foreach ( $body_json->contact->tags as $tag ) {
			$tags[] = $tag->name;
		}

		// Check if we need to update the available tags list
		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $tags as $tag_name ) {
			if ( ! isset( $available_tags[ $tag_name ] ) ) {
				$available_tags[ $tag_name ] = $tag_name;
			}
		}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url    = 'https://api.getgist.com/tags';
		$params = $this->params;

		foreach ( $tags as $tag ) {

			$update_data = (object) array(
				'contacts' => array( (object) array( 'id' => $contact_id ) ),
				'name'     => $tag,
			);

			$params['body'] = wp_json_encode( $update_data );

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

		$url    = 'https://api.getgist.com/tags';
		$params = $this->params;

		foreach ( $tags as $tag ) {

			$update_data = (object) array(
				'contacts' => array(
					(object) array(
						'id'    => $contact_id,
						'untag' => true,
					),
				),
				'name'     => $tag,
			);

			$params['body'] = wp_json_encode( $update_data );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Fix names.

		if ( isset( $data['first_name'] ) && isset( $data['last_name'] ) ) {

			$data['name'] = $data['first_name'] . ' ' . $data['last_name'];

			unset( $data['first_name'] );
			unset( $data['last_name'] );

		}

		$update_data = (object) array(
			'custom_properties' => array(),
		);

		// Gist needs a user ID to create a User, otherwise they'll be created as a Lead.

		if ( isset( $data['user_id'] ) ) {

			$update_data->user_id = $data['user_id'];

		} elseif ( get_user_by( 'email', $data['email'] ) ) {

			$user = get_user_by( 'email', $data['email'] );

			$update_data->user_id = $user->ID;
		}

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';

		foreach ( $data as $crm_field => $value ) {

			foreach ( $convertfox_fields as $meta_key => $field_data ) {

				if ( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data->{$crm_field} = $value;

					continue 2;

				}
			}

			// Custom fields
			$update_data->custom_properties[ $crm_field ] = $value;

		}

		if ( true === $lead  ) {
			$update_data->type = 'lead';
		}

		$params         = $this->params;
		$params['body'] = wp_json_encode( $update_data );

		$response = wp_safe_remote_post( 'https://api.getgist.com/contacts', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->contact->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $lead = false ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Need email address for updates

		if ( ! isset( $data['email'] ) ) {

			$data['email'] = wp_fusion()->crm->get_email_from_cid( $contact_id );

			if ( is_wp_error( $data['email'] ) ) {
				return $data['email'];
			}
		}

		// Fix names

		if ( isset( $data['first_name'] ) && isset( $data['last_name'] ) ) {
			$data['name'] = $data['first_name'] . ' ' . $data['last_name'];
		}

		$update_data = (object) array(
			'id'                => $contact_id,
			'custom_properties' => array(),
		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/convertfox-fields.php';

		foreach ( $data as $crm_field => $value ) {

			foreach ( $convertfox_fields as $meta_key => $field_data ) {

				if ( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data->{$crm_field} = $value;

					continue 2;

				}
			}

			// Custom fields
			$update_data->custom_properties[ $crm_field ] = $value;

		}

		if ( true === $lead ) {
			$update_data->type = 'lead';
		} else {

			$user = get_user_by( 'email', $data['email'] );

			if ( $user ) {
				$update_data->user_id = $user->ID; // if it's a user, send the ID.
			}
		}

		$params         = $this->params;
		$params['body'] = wp_json_encode( $update_data );

		$response = wp_safe_remote_post( 'https://api.getgist.com/contacts', $params );

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

		$url      = 'https://api.getgist.com/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		// Explode name into first name and last name

		$exploded_name                      = explode( ' ', $body_json['contact']['name'] );
		$body_json['contact']['first_name'] = $exploded_name[0];
		unset( $exploded_name[0] );
		$body_json['contact']['last_name'] = implode( ' ', $exploded_name );

		$user_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( ! isset( $field_data['crm_field'] ) ) {
				continue;
			}

			if ( isset( $body_json['contact'][ $field_data['crm_field'] ] ) && $field_data['active'] == true ) {

				// First level fields
				$user_meta[ $field_id ] = $body_json['contact'][ $field_data['crm_field'] ];

			} else {

				// Custom fields
				foreach ( $body_json['contact']['custom_properties'] as $custom_key => $custom_value ) {

					if ( $custom_key == $field_data['crm_field'] && $field_data['active'] == true ) {

						$user_meta[ $field_id ] = $custom_value;

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
		$page        = 1;
		$proceed     = true;

		while ( $proceed == true ) {

			$url      = 'https://api.getgist.com/contacts?page=' . $page . '&tags=' . $tag;
			$response = wp_safe_remote_get( $url, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $body_json->contacts as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if ( count( $body_json->contacts ) < 50 ) {
				$proceed = false;
			} else {
				$page++;
			}
		}

		return $contact_ids;

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_info = array();
		$request      = 'https://api.getgist.com/contacts?email=' . urlencode( $email_address );
		$response     = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['contact'] ) ) {
			return false;
		}

		return $body_json['contact']['id'];

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
	 * Upadates a lead.
	 *
	 * @since  3.38.36
	 *
	 * @param  int   $contact_id The contact ID.
	 * @param  array $data       The lead data.
	 * @return string The lead ID.
	 */
	public function update_lead( $contact_id, $data ) {

		return $this->update_contact( $contact_id, $data, $lead = true );

	}


	/**
	 * Track event.
	 *
	 * Track an event with the Gist site tracking API.
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
		if ( ! $this->params ) {
			$this->get_params();
		}

		$data = array(
			'email'      => $email_address,
			'event_name' => $event,
			'properties' => array(
				'manual_record' => false,
				'recorded_from' => 'wp-fusion-lite',
			),
		);

		if ( is_array( $event_data ) ) {
			$data['properties'] = array_merge( $data['properties'], $event_data );
		} else {
			$data['properties']['data'] = $event_data;
		}

		$params         = $this->params;
		$params['body'] = wp_json_encode( $data );
		$response       = wp_safe_remote_post( 'https://api.getgist.com/events', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

}
