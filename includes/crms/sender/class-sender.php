<?php

/**
 * WP Fusion Sender CRM class.
 *
 * @since 3.45.9
 */
class WPF_Sender {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 * @since 3.45.9
	 */

	public $slug = 'sender';

	/**
	 * The CRM name.
	 *
	 * @var string
	 * @since 3.45.9
	 */

	public $name = 'Sender';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.45.9
	 */

	public $url = 'https://api.sender.net/v2';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag IDs).
	 * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
	 *
	 * "add_tags_api" means that tags can be created via an API call. Uses the add_tag() method.
	 *
	 * "lists" means contacts can be added to lists in addition to tags. Requires the sync_lists() method.
	 *
	 * "add_fields" means that sender field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * "events" enables the integration for Event Tracking: https://wpfusion.com/documentation/event-tracking/event-tracking-overview/.
	 *
	 * "events_multi_key" enables the integration for Event Tracking with multiple keys: https://wpfusion.com/documentation/event-tracking/event-tracking-overview/#multi-key-events.
	 *
	 * @var array<string>
	 * @since 3.45.9
	 */

	public $supports = array(
		'add_tags_api',
	);

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc).
	 *
	 * @var string
	 * @since 3.45.9
	 */
	public $tag_type = 'Group';

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var string
	 * @since 3.45.9
	 */
	public $edit_url = 'https://app.sender.net/subscribers/%s/view/';


	/**
	 * Get things started.
	 *
	 * @since 3.45.9
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/class-wpf-sender-admin.php';
			new WPF_Sender_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.45.9
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.45.9
	 *
	 * @link https://wpfusion.com/documentation/getting-started/syncing-contact-fields/#field-types
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type ('text', 'date', 'multiselect', 'checkbox').
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Dates come in as a timestamp.

			$date = gmdate( 'm/d/Y H:i:s', $value );

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
	 * Formats post data.
	 *
	 * This runs when a webhook is received and extracts the contact ID (and optionally
	 * tags) from the webhook payload.
	 *
	 * @since  3.45.9
	 *
	 * @link https://wpfusion.com/documentation/webhooks/about-webhooks/
	 *
	 * @param  array $post_data The post data.
	 * @return array $post_data The formatted post data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) ) {
			$post_data['contact_id'] = $payload->email;
		}

		return $post_data;
	}


	/**
	 * Sends a success message after a webhook is received.
	 *
	 * @since 3.45.9
	 *
	 * @param int    $user_id The user ID.
	 * @param string $method The method that was called.
	 * @return mixed JSON success message.
	 */
	public function api_success( $user_id, $method ) {

		wp_send_json_success(
			array(
				'user_id' => $user_id,
				'method'  => $method,
			)
		);
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since 3.45.9
	 *
	 * @param string $access_token The access token.
	 * @return array<string|mixed> $params The API parameters.
	 */
	public function get_params( $access_token = null ) {

		// Get saved data from DB.
		if ( ! $access_token ) {
			$access_token = wpf_get_option( 'sender_access_token' );
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-type'  => 'application/json',
			),
		);

		return $params;
	}


	/**
	 * Gets the default fields.
	 *
	 * @since 3.45.9
	 *
	 * @return array<string, array> The default fields in the CRM.
	 */
	public static function get_default_fields() {

		return array(
			'first_name' => array(
				'crm_label' => 'First Name',
				'crm_field' => 'firstname',
			),
			'last_name'  => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'lastname',
			),
			'user_email' => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
				'crm_type'  => 'email',
			),
			'phone'      => array(
				'crm_label' => 'Phone',
				'crm_field' => 'phone',
			),
		);
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since 3.45.9
	 *
	 * @param  array  $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return array|WP_Error The response or WP_Error on error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 401 === $response_code ) {

				$response = new WP_Error( 'error', 'Invalid API credentials.' );

			} elseif ( isset( $body_json->success ) && false === (bool) $body_json->success && isset( $body_json->message ) ) {

				$response = new WP_Error( 'error', $body_json->message );

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
	 * @since  3.45.9
	 *
	 * @param  string $access_token The second API credential.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $access_token = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$request  = $this->url . '/subscribers';
		$response = wp_remote_get( $request, $this->get_params( $access_token ) );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.45.9
	 *
	 * @return bool
	 */
	public function sync() {

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.45.9
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request  = $this->url . '/groups';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response       = json_decode( wp_remote_retrieve_body( $response ) );
		$available_tags = array();

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $group ) {
				$available_tags[ $group->id ] = $group->title;
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all sender fields from CRM and merges with local list.
	 *
	 * @since  3.45.9
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$standard_fields = array();

		foreach ( $this->get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		$request  = $this->url . '/fields';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$custom_fields = array();
		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		foreach ( $response->data as $field ) {
			$field_name = str_replace( array( '{$', '}' ), '', $field->name );
			if ( false !== strpos( implode( ',', array_keys( $standard_fields ) ), $field_name ) ) {
				continue;
			}

			// We did like that because load_contact requires the field name to be used and the update contact requires the field id
			$custom_fields[ $field->name . '__' . $field->id ] = $field->title;
		}

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
	 * @since  3.45.9
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/subscribers/' . rawurlencode( $email_address );
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			if ( strtolower( $response->get_error_message() ) === 'not found' ) {
				return false;
			}
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->data ) ) {
			return false;
		} else {
			return $response->data->email;
		}
	}


	/**
	 * Creates a new tag in Sender and returns the ID.
	 *
	 * @since  3.45.9
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'title' => $tag_name ) );

		$request  = $this->url . '/groups';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		$tag_id   = $response->data->id;
		return $tag_id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.45.9
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$tags = array();

		$request  = $this->url . '/subscribers/' . $contact_id;
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->data ) || empty( $response->data->subscriber_tags ) ) {
			return $tags;
		}

		foreach ( $response->data->subscriber_tags as $tag ) {
			$tags[] = $tag->id;
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.45.9
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		// We can do update contact endpoint to apply tags but it fails most of the times and it does not trigger automations
		$params = $this->get_params();
		foreach ( $tags as $tag ) {
			$request        = $this->url . '/subscribers/groups/' . $tag;
			$params['body'] = wp_json_encode(
				array(
					'subscribers' => array( $contact_id ),
				)
			);
			$response       = wp_remote_post( $request, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.45.9
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		foreach ( $tags as $tag_id ) {
			$request          = $this->url . '/subscribers/groups/' . $tag_id;
			$params           = $this->get_params();
			$params['body']   = array(
				'subscribers' => array( $contact_id ),
			);
			$params['method'] = 'DELETE';
			$response         = wp_remote_request( $request, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.45.9
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$request = $this->url . '/subscribers';
		$params  = $this->get_params();

		$standard_fields = array();
		foreach ( $this->get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		foreach ( $contact_data as $key => $value ) {
			if ( false === strpos( implode( ',', array_keys( $standard_fields ) ), $key ) ) {
				// Extract the field id from the key.
				$field_id                            = explode( '__', $key )[0];
				$contact_data['fields'][ $field_id ] = $value;
				unset( $contact_data[ $key ] );
			}
		}

		$params['body'] = wp_json_encode( $contact_data );
		$response       = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.
		return $body->data->email;
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.45.9
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$request = $this->url . '/subscribers/' . $contact_id;
		$params  = $this->get_params();

		$standard_fields = array();
		foreach ( $this->get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		foreach ( $contact_data as $key => $value ) {
			if ( false === strpos( implode( ',', array_keys( $standard_fields ) ), $key ) ) {
				// Extract the field id from the key.
				$field_id                            = explode( '__', $key )[0];
				$contact_data['fields'][ $field_id ] = $value;
				unset( $contact_data[ $key ] );
			}
		}

		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PATCH';

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.45.9
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/subscribers/' . $contact_id;
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		$response = $response['data'];

		// Add columns to response
		foreach ( $response['columns'] as $column ) {
			$response[ $column['id'] ] = $column['value'];
		}
		foreach ( $contact_fields as $field_id => $field_data ) {
			$crm_field = strpos( $field_data['crm_field'], '__' ) !== false ? explode( '__', $field_data['crm_field'] )[1] : $field_data['crm_field'];

			if ( $field_data['active'] && isset( $response[ $crm_field ] ) ) {
				$user_meta[ $field_id ] = $response[ $crm_field ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.45.9
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	public function load_contacts( $tag ) {
		$contact_ids = array();
		$proceed     = true;
		$page        = 1;

		while ( $proceed ) {
			$request = $this->url . '/groups/' . $tag . '/subscribers?page=' . $page;

			$response = wp_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->data as $result ) {
				$contact_ids[] = $result->email;
			}

			if ( $response->meta->current_page === $response->meta->last_page ) {
				$proceed = false;
			} else {
				++$page;
			}
		}

		return $contact_ids;
	}
}
