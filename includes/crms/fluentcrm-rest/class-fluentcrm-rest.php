<?php

class WPF_FluentCRM_REST {

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $supports;

	/**
	 * API authentication parameters and headers.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $params;

	/**
	 * API URL.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $url;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.14
	 * @var  string
	 */

	public $edit_url = '';


	/**
	 * Get things started
	 *
	 * @since 3.37.14
	 */

	public function __construct() {

		$this->slug      = 'fluentcrm-rest';
		$this->name      = 'FluentCRM';
		$this->menu_name = 'FluentCRM (REST API)';
		$this->supports  = array('add_tags_api');

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-fluentcrm-rest-admin.php';
			new WPF_FluentCRM_REST_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.37.14
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		$url = wpf_get_option( 'fluentcrm_rest_url' );

		if ( ! empty( $url ) ) {
			$this->url      = trailingslashit( $url ) . 'wp-json/fluent-crm/v2';
			$this->edit_url = trailingslashit( $url ) . 'wp-admin/admin.php?page=fluentcrm-admin#/subscribers/%d/';
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
			return false;
		}

		$post_data['contact_id'] = absint( $payload->id );
		$post_data['tags']       = wp_list_pluck( (array) $payload->tags, 'slug' );

		return $post_data;

	}

	/**
	 * Formats field values for API calls.
	 *
	 * @since  3.40.49
	 *
	 * @param  mixed  $value      The field value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The field in the cRM.
	 * @return mixed  $value     The formatted field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.
			$date = gmdate( 'Y-m-d', $value );

			return $date;

		} else {

			return $value;

		}

	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.37.14
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
			$url      = wpf_get_option( 'fluentcrm_rest_url' );
			$username = wpf_get_option( 'fluentcrm_rest_username' );
			$password = wpf_get_option( 'fluentcrm_rest_password' );
		}

		$this->url = trailingslashit( $url ) . 'wp-json/fluent-crm/v2';

		$this->params = array(
			'timeout'    => 15,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'sslverify'  => false,
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
	 * @since  3.37.14
	 *
	 * @param  WP_HTTP_Response $response The HTTP response.
	 * @param  array            $args     The HTTP request arguments.
	 * @param  string           $url      The HTTP request URL.
	 * @return WP_HTTP_Response $response The response.
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			if ( 404 === wp_remote_retrieve_response_code( $response ) || empty( wp_remote_retrieve_body( $response ) ) ) {

				$response = new WP_Error( 'error', 'No response was returned. You may need to <a href="https://wordpress.org/support/article/using-permalinks/#mod_rewrite-pretty-permalinks" target="_blank">enable pretty permalinks</a>.' );

			} elseif ( wp_remote_retrieve_response_code( $response ) > 204 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				if ( ! empty( $body->code ) ) {

					if ( 'rest_no_route' == $body->code ) {

						$body->message .= ' <strong>' . __( 'This usually means the FluentCRM plugin isn\'t active.', 'wp-fusion-lite' ) . '</strong> (URL: ' . $url . ')';

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
	 * @since  3.37.14
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

		$request  = $this->url . '/subscribers';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since  3.37.14
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
	 * @since  3.37.14
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$available_tags = array();
		$continue       = true;
		$page           = 1;

		while ( $continue ) {

			$request  = $this->url . '/tags?sort_by=id&sort_order=DESC&per_page=100&page=' . $page;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->tags ) ) {

				foreach ( $response->tags->data as $tag ) {

					$available_tags[ $tag->slug ] = $tag->title;

				}
			}

			if ( empty( $response->tags ) || count( $response->tags->data ) < 100 ) {
				$continue = false;
			} else {
				$page++;
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}


	/**
	 * Gets all available fields from the CRM and saves them to options.
	 *
	 * @since  3.37.14
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first

		$fields = WPF_FluentCRM_REST_Admin::get_default_fields();

		$built_in_fields = array();

		foreach ( $fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Then get custom ones

		$request  = $this->url . '/custom-fields/contacts?per_page=500';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		if ( ! empty( $response->fields ) ) {
			foreach ( $response->fields as $field ) {
				$custom_fields[ $field->slug ] = $field->label;
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
	 * @since  3.37.14
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/subscribers?per_page=1&search=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->subscribers->data ) ) {
			return false;
		}

		return $response->subscribers->data[0]->id;

	}


	/**
	 * Creates a new tag in FluentCRM and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$body = array(
			'title' => $tag_name,
			'slug'  => $tag_name,
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$request  = $this->url . '/tags';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tag_id = $response->lists->id;

		return $tag_id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since  3.37.14
	 *
	 * @param  int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . '/subscribers/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		foreach ( $response->subscriber->tags as $tag ) {
			$tags[] = $tag->slug;
		}

		return $tags;

	}


	/**
	 * Applies tags to a contact.
	 *
	 * @since  3.37.14
	 *
	 * @param  array $tags       A numeric array of tags to apply to the
	 *                           contact.
	 * @param  int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$body = array(
			'type'        => 'tags',
			'attach'      => $tags,
			'subscribers' => array( $contact_id ),
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$request  = $this->url . '/subscribers/sync-segments';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.37.14
	 *
	 * @param  array $tags       A numeric array of tags to remove from
	 *                           the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$body = array(
			'type'        => 'tags',
			'detach'      => $tags,
			'subscribers' => array( $contact_id ),
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$request  = $this->url . '/subscribers/sync-segments';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}



	/**
	 * Adds a new contact.
	 *
	 * @since  3.37.14
	 *
	 * @param  array $data   An associative array of contact fields and
	 *                       field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $data ) {

		$fields = wpf_get_option( 'crm_fields' );

		// Custom fields go in their own key.

		foreach ( $data as $key => $value ) {

			if ( ! isset( $fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $data['custom_values'] ) ) {
					$data['custom_values'] = array();
				}

				$data['custom_values'][ $key ] = $value;

				unset( $data[ $key ] );

			}
		}

		if ( empty( $data['status'] ) ) {
			$data['status'] = wpf_get_option( 'default_status', 'subscribed' );
		}

		if ( 'susbcribed' === $data['status'] ) {
			$data['status'] = 'subscribed'; // fixes typo between v3.40.40 and 3.41.5.
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$request  = $this->url . '/subscribers';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$contact_id = $response->contact->id;

		if ( 'pending' === $data['status'] ) {

			// Send double opt-in request.
			wp_remote_post( $this->url . '/subscribers/' . $contact_id . '/send-double-optin', $this->get_params() );

		}

		return $contact_id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since  3.37.14
	 *
	 * @param  int   $contact_id The ID of the contact to update.
	 * @param  array $data       An associative array of contact fields
	 *                           and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $data ) {

		$fields = wpf_get_option( 'crm_fields' );

		// Custom fields go in their own key.

		foreach ( $data as $key => $value ) {

			if ( ! isset( $fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $data['custom_values'] ) ) {
					$data['custom_values'] = array();
				}

				$data['custom_values'][ $key ] = $value;

				unset( $data[ $key ] );

			}
		}

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( array( 'subscriber' => $data ) );
		$params['method'] = 'PUT';

		$request  = $this->url . '/subscribers/' . $contact_id;
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress
	 * fields.
	 *
	 * @since  3.37.14
	 *
	 * @param  int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/subscribers/' . $contact_id . '?with%5B%5D=subscriber.custom_values';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $response['subscriber']['custom_values'] ) ) {
			$response['subscriber'] = array_merge( $response['subscriber'], $response['subscriber']['custom_values'] ); // merge custom fields for quicker mapping
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true == $field_data['active'] && isset( $response['subscriber'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response['subscriber'][ $field_data['crm_field'] ];
			}
		}

		return $user_meta;

	}


	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @since  3.37.14
	 *
	 * @param  string $tag    The tag ID or name to search for.
	 * @return array  Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		// At the moment WP Fusion is storing the tag slug, but FCRM uses the ID for searches, so we need to look it up

		$request  = $this->url . '/tags?sort_by=id&per_page=1&search=' . $tag;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->tags->data ) ) {
			return new WP_Error( 'error', 'Unable to determine tag ID from tag ' . $tag );
		}

		$tag_id = $response->tags->data[0]->id;

		$contact_ids = array();
		$proceed     = true;
		$page        = 1;

		while ( $proceed ) {

			$request  = $this->url . '/subscribers?per_page=100&tags%5B%5D=' . $tag_id . '&page=' . $page;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->subscribers->data as $subscriber ) {
				$contact_ids[] = $subscriber->id;
			}

			if ( count( $response->subscribers->data ) < 100 ) {
				$proceed = false;
			} else {
				$page++;
			}
		}

		return $contact_ids;

	}


}
