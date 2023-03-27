<?php

class WPF_Groundhogg_REST {

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * @var array
	 * @since 3.38.10
	 */

	public $supports;

	/**
	 * API authentication parameters and headers.
	 *
	 * @var array
	 * @since 3.38.10
	 */

	public $params;

	/**
	 * API URL.
	 *
	 * @var array
	 * @since 3.38.10
	 */

	public $url;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.38.14
	 * @var  string
	 */

	public $edit_url = '';

	/**
	 * Track which fields are core vs custom meta.
	 *
	 * @since 3.40.29
	 * @var  array
	 */

	private $data_fields = array(
		'email',
		'first_name',
		'last_name',
		'user_id',
		'owner_id',
		'optin_status',
		'date_created',
		'date_optin_status_changed',
		'ID',
		'gravatar',
		'full_name',
		'age',
	);


	/**
	 * Get things started
	 *
	 * @since 3.38.10
	 */

	public function __construct() {

		$this->slug      = 'groundhogg-rest';
		$this->name      = 'Groundhogg';
		$this->menu_name = 'Groundhogg (REST API)';
		$this->supports  = array( 'events', 'add_tags_api', 'events_multi_key' );

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-groundhogg-rest-admin.php';
			new WPF_Groundhogg_REST_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.38.10
	 */
	public function init() {

		// Webhooks.
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Add tracking code to footer.
		// add_action( 'wp_enqueue_scripts', array( $this, 'tracking_code' ) );

		$url = wpf_get_option( 'groundhogg_rest_url' );

		if ( ! empty( $url ) ) {
			$this->url      = trailingslashit( $url ) . 'wp-json/gh/v4';
			$this->edit_url = trailingslashit( $url ) . 'wp-admin/admin.php?page=gh_contacts&action=edit&contact=%d';
		}

	}



	/**
	 * Registers tracking script. Not currently in use.
	 *
	 * @since 3.38.37
	 */
	public function tracking_code() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		$payload = json_decode( stripslashes( file_get_contents( 'php://input' ) ) );

		if ( ! is_object( $payload ) ) {
			return $post_data;
		}

		$post_data['contact_id'] = absint( $payload->id );
		$post_data['tags']       = wp_list_pluck( (array) $payload->tags, 'slug' );

		return $post_data;

	}


	/**
	 * Gets params for API calls.
	 *
	 * @since  3.38.10
	 *
	 * @param  string $url      The api url.
	 * @param  string $username The application username.
	 * @param  string $password The application password.
	 * @return array  $params The API parameters.
	 */

	public function get_params( $url = null, $username = null, $password = null ) {

		if ( $this->params ) {
			return $this->params; // already set up
		}

		// Get saved data from DB
		if ( ! $url || ! $username || ! $password ) {
			$url      = wpf_get_option( 'groundhogg_rest_url' );
			$username = wpf_get_option( 'groundhogg_rest_username' );
			$password = wpf_get_option( 'groundhogg_rest_password' );
		}

		$this->url = trailingslashit( $url ) . 'wp-json/gh/v4';

		$this->params = array(
			'timeout'    => 15,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'sslverify'  => false, // fixes issues with localhost testing.
			'headers'    => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
			),
		);

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.38.10
	 *
	 * @param  WP_HTTP_Response $response The HTTP response.
	 * @param  array            $args     The HTTP request arguments.
	 * @param  string           $url      The HTTP request URL.
	 * @return WP_HTTP_Response $response The response.
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			if ( 404 == wp_remote_retrieve_response_code( $response ) || empty( wp_remote_retrieve_body( $response ) ) ) {

				$response = new WP_Error( 'error', 'No response was returned. You may need to <a href="https://wordpress.org/support/article/using-permalinks/#mod_rewrite-pretty-permalinks" target="_blank">enable pretty permalinks</a>.' );

			} elseif ( wp_remote_retrieve_response_code( $response ) > 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				if ( ! empty( $body->code ) ) {

					if ( 'rest_no_route' == $body->code ) {

						$body->message .= ' <strong>' . __( 'This usually means the Groundhogg plugin isn\'t active.', 'wp-fusion-lite' ) . '</strong> (URL: ' . $url . ')';

					}

					$response = new WP_Error( 'error', $body->message );

				} else {

					$response = new WP_Error( 'error', wp_remote_retrieve_response_message( $response ) );

				}
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
	 * @since  3.38.10
	 *
	 * @param  string $url      The api url.
	 * @param  string $username The application username.
	 * @param  string $password The application password.
	 * @param  bool   $test     Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */

	public function connect( $url = null, $username = null, $password = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $url, $username, $password );
		}

		if ( false === $test ) {
			return true;
		}

		$request  = $this->url . '/contacts';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since  3.38.10
	 *
	 * @return bool
	 */

	public function sync() {

		$this->connect();

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.38.10
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$available_tags = array();
		$continue       = true;
		$offset         = 0;

		while ( $continue ) {

			$request  = $this->url . '/tags?limit=1000&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->items ) ) {

				foreach ( $response->items as $tag ) {

					$available_tags[ $tag->ID ] = $tag->data->tag_name;

				}
			}

			if ( empty( $response->items ) || count( $response->items ) < 1000 ) {
				$continue = false;
			} else {
				$offset = $offset + 1000;
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}


	/**
	 * Gets all available fields from the CRM and saves them to options.
	 *
	 * @since  3.38.10
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first.

		$fields = WPF_Groundhogg_REST_Admin::get_default_fields();

		$built_in_fields = array();

		foreach ( $fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Then get custom ones

		$request  = $this->url . '/fields?limit=500';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		if ( ! empty( $response->items ) ) {
			foreach ( $response->items as $field ) {
				$custom_fields[ $field->id ] = $field->label;
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
	 * @since  3.38.10
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/contacts?limit=1&search=' . rawurlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->items ) ) {
			return false;
		}

		return $response->items[0]->ID;

	}



	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since  3.38.10
	 *
	 * @param  int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . '/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		foreach ( $response->item->tags as $tag ) {
			$tags[] = $tag->ID;
		}

		return $tags;

	}


	/**
	 * Applies tags to a contact.
	 *
	 * @since  3.38.10
	 *
	 * @param  array $tags       A numeric array of tags to apply to the
	 *                           contact.
	 * @param  int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $tags );

		$request  = $this->url . '/contacts/' . $contact_id . '/tags';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.38.10
	 *
	 * @param  array $tags       A numeric array of tags to remove from
	 *                           the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $tags );
		$params['method'] = 'DELETE';

		$request  = $this->url . '/contacts/' . $contact_id . '/tags';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}



	/**
	 * Adds a new contact.
	 *
	 * @since  3.38.10
	 *
	 * @param  array $data An associative array of contact fields and
	 *                     field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $data ) {

		// Custom fields go in their own key.

		$meta = array();

		foreach ( $data as $key => $value ) {

			if ( ! in_array( $key, $this->data_fields ) ) {
				$meta[ $key ] = $value;
				unset( $data[ $key ] );
			}
		}

		$update_data = array(
			'data' => $data,
			'meta' => $meta,
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $update_data );

		$request  = $this->url . '/contacts';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->item->ID;

	}


	/**
	 * Creates a new tag in Groundhogg and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$params         = $this->get_params();
		$data           = array(
			'tags' => array(
				$tag_name,
			),
		);
		$params['body'] = wp_json_encode( $data );
		// Add tags only works on v3 of the API.
		$request  = str_replace( 'v4', 'v3', $this->url ) . '/tags';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		$tag_id   = key( $response->tags );
		return $tag_id;
	}


	/**
	 * Updates an existing contact record.
	 *
	 * @since  3.38.10
	 *
	 * @param  int   $contact_id The ID of the contact to update.
	 * @param  array $data       An associative array of contact
	 *                           fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $data ) {

		$fields = wpf_get_option( 'crm_fields' );

		// Custom fields go in their own key.

		$meta = array();

		foreach ( $data as $key => $value ) {

			if ( ! in_array( $key, $this->data_fields ) ) {
				$meta[ $key ] = $value;
				unset( $data[ $key ] );
			}
		}

		$update_data = array(
			'data' => $data,
			'meta' => $meta,
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $update_data );

		$request  = $this->url . '/contacts/' . $contact_id;
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress
	 * fields.
	 *
	 * @since  3.38.10
	 *
	 * @param  int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		$data = $response['item']['data'];

		if ( ! empty( $response['item']['meta'] ) ) {
			$data = array_merge( $data, $response['item']['meta'] ); // merge custom fields for quicker mapping.
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $data[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $data[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;

	}


	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @since  3.38.10
	 *
	 * @param  string $tag    The tag ID or name to search for.
	 * @return array  Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		$request  = $this->url . '/contacts/?tags_include=' . $tag;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->items ) ) {
			return array();
		}

		$contact_ids = array();

		foreach ( $response->items as $contact ) {
			$contact_ids[] = $contact->ID;
		}

		return $contact_ids;

	}


	/**
	 * Track event.
	 *
	 * Track an event with the Groundhogg activity API.
	 *
	 * @since  3.38.16
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		$contact_id = false;

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
			$contact_id    = wpf_get_contact_id();
		}

		if ( false === $email_address ) {
			return; // can't track without an email.
		}

		if ( false === $contact_id ) {
			$contact_id = $this->get_contact_id( $email_address );

			if ( ! $contact_id ) {
				return;
			}
		}

		$body = array(
			'data' => array(
				'activity_type' => 'wp_fusion',
				'contact_id'    => $contact_id,
			),
			'meta' => array(
				'event_name'  => $event,
				'event_value' => $event_data,
			),
		);

		if ( is_array( $event_data ) ) {
			$body['meta']['event_value'] = reset( $event_data );
			$body['meta']                = array_merge( $body['meta'], $event_data );
		}

		$request            = $this->url . '/activity/?force=1'; // force to always create a new entry, not update an existing one.
		$params             = $this->get_params();
		$params['body']     = wp_json_encode( $body );
		$params['blocking'] = false;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			wpf_log( 'error', 0, 'Error tracking event: ' . $response->get_error_message() );
			return $response;
		}

		return true;
	}



}
