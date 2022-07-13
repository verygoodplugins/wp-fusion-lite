<?php

class WPF_Bento {

	/**
	 * Contains API url
	 *
	 * @var  string
	 *
	 * @since 3.38.4
	 */

	public $url = 'https://app.bentonow.com/api/v1';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * "add_fields" means that custom field / attrubute keys don't need to exist
	 * first in the CRM to be used. With add_fields enabled, WP Fusion will
	 * allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var  array
	 *
	 * @since 3.38.4
	 */

	public $supports = array( 'add_tags', 'add_fields', 'events', 'web_id' );


	/**
	 * API parameters
	 *
	 * @var  array
	 *
	 * @since 3.38.4
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var  string
	 *
	 * @since 3.38.4
	 */
	public $edit_url = 'https://app.bentonow.com%s';

	/**
	 * Get things started
	 *
	 * @since 3.38.4
	 */
	public function __construct() {

		$this->slug = 'bento';
		$this->name = 'Bento';

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-bento-admin.php';
			new WPF_Bento_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.38.4
	 */
	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

		// Slow down the batch processses to get around the 10 requests per 10 second limit.
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format.
	 *
	 * @since  3.38.5
	 *
	 * @param  array $post_data The post data.
	 * @return array The post data.
	 */
	public function format_post_data( $post_data ) {

		$body = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $body ) && isset( $body->id ) ) {
			$post_data['contact_id'] = $body->id;
		}

		return $post_data;

	}

	/**
	 * Slow down batch processses to get around the 10 requests per 10 seconds
	 * limit.
	 *
	 * @since  3.38.16
	 *
	 * @param  int $seconds The seconds to sleep between steps.
	 * @return int   The seconds.
	 */
	public function set_sleep_time( $seconds ) {

		return 4;

	}

	/**
	 * Output tracking code.
	 *
	 * @return mixed JavaScript tracking code.
	 *
	 * @since 3.38.4
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		echo '<!-- Bento (via WP Fusion) -->';
		echo '<script src="' . esc_url( 'https://app.bentonow.com/' . wpf_get_option( 'site_uuid' ) . '.js' ) . '"></script>';
		echo "
		<script>
		if (typeof(bento$) != 'undefined') {
		  bento$(function() {";

		if ( wpf_is_user_logged_in() ) {

			$userdata = wpf_get_current_user();
			echo "bento.identify('" . esc_js( $userdata->user_email ) . "');";
		}

		echo '
			bento.autofill();
			bento.view();
		  });
		}
		</script>';
		echo '<!-- end Bento -->';

	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.38.4
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = gmdate( 'c', $value );

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
	 * Gets params for API calls.
	 *
	 * @since 3.38.4
	 *
	 * @return array $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'bento_api_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Basic ' . base64_encode( $api_key ),
			),
		);

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.38.4
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

			if ( isset( $body_json->success ) && false == $body_json->success ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 401 === $response_code ) {

				return new WP_Error( 'error', 'Invalid API key.' );

			} elseif ( 429 === $response_code ) {

				return new WP_Error( 'error', 'API limits exceeded. Try again later.' );

			} elseif ( 500 === $response_code ) {

				if ( false !== strpos( $url, 'fetch/subscribers/' ) ) {
					return $response; // At the moment Bento throws a 500 error when a contact isn't found.
				}

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
	 * @since  3.38.4
	 *
	 * @param  string $site_uuid The site UUID.
	 * @param  string $api_key   The API key.
	 * @param  bool   $test      Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $site_uuid = null, $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}
		$this->site_uuid = $site_uuid;

		$request  = $this->url . '/fetch/subscribers?site_uuid=' . $this->site_uuid;
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
	 * @since 3.38.4
	 *
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
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.38.4
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$site_uuid = wpf_get_option( 'site_uuid' );

		$request  = $this->url . '/fetch/tags/?site_uuid=' . $site_uuid;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();
		$tag_ids        = array();

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'.
		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $tag ) {

				$name = sanitize_text_field( $tag->attributes->name );
				$id   = absint( $tag->id );

				$available_tags[ $name ] = $name;
				$tag_ids[ $id ]          = $name;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		// Store the ID / name pairings as well for when we load tags for a contact.

		wp_fusion()->settings->set( 'bento_tag_ids', $tag_ids );

		return $tag_ids;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.38.4
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {
		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields first
		require dirname( __FILE__ ) . '/bento-fields.php';

		$built_in_fields = array();

		foreach ( $bento_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$site_uuid = wpf_get_option( 'site_uuid' );

		$request  = $this->url . '/fetch/fields/?site_uuid=' . $site_uuid;
		$response = wp_safe_remote_get( $request, $this->get_params() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		foreach ( $response->data as $field ) {

			if ( ! isset( $built_in_fields[ $field->attributes->key ] ) ) {
				$custom_fields[ $field->attributes->key ] = $field->attributes->name;
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
	 * @since  3.38.4
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$site_uuid = wpf_get_option( 'site_uuid' );
		$request   = $this->url . '/fetch/subscribers/?site_uuid=' . $site_uuid . '&email=' . rawurlencode( $email_address );
		$response  = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// @todo find a way to save "navigation_url"=>"/account/visitors/visitor_XXXX"
		// from the response so we can link to the contact record.

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) || empty( $response->data ) ) {
			return false;
		}

		$user = get_user_by( 'email', $email_address );

		if ( $user ) {
			// Save the web ID so we can link to it later.
			update_user_meta( $user->ID, 'bento_web_id', $response->data->attributes->navigation_url );
		}

		// Parse response for contact ID here.
		return $response->data->attributes->uuid;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.38.4
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$site_uuid = wpf_get_option( 'site_uuid' );
		$request   = $this->url . '/fetch/subscribers/?site_uuid=' . $site_uuid . '&uuid=' . $contact_id;
		$response  = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if ( empty( $response ) ) {
			return false;
		}

		if ( empty( $response->data->attributes->cached_tag_ids ) ) {
			return false;
		}

		$tag_ids = wpf_get_option( 'bento_tag_ids', array() );

		foreach ( $response->data->attributes->cached_tag_ids as $tag_id ) {

			if ( isset( $tag_ids[ $tag_id ] ) ) {
				$tags[] = $tag_ids[ $tag_id ];
			} else {

				// Resync needed.
				$tag_ids = $this->sync_tags();
				$tags[]  = $tag_ids[ $tag_id ];

			}
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.38.4
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$site_uuid = wpf_get_option( 'site_uuid' );

		$body = array(
			'site_uuid' => $site_uuid,
			'command'   => array(),
		);

		foreach ( $tags as $tag ) {

			$body['command'][] = array(
				'command' => 'add_tag_via_event',
				'uuid'    => $contact_id,
				'query'   => $tag,
			);
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$request  = $this->url . '/fetch/commands/';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.38.4
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$site_uuid = wpf_get_option( 'site_uuid' );

		$body = array(
			'site_uuid' => $site_uuid,
			'command'   => array(),
		);

		foreach ( $tags as $tag ) {

			$body['command'][] = array(
				'command' => 'remove_tag',
				'uuid'    => $contact_id,
				'query'   => $tag,
			);
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$request  = $this->url . '/fetch/commands/?site_uuid=' . $site_uuid;
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.38.4
	 *
	 * @param array $data    An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $data ) {

		// Bento can't create a subscriber with custom fields.

		$contact_data = array(
			'site_uuid' => wpf_get_option( 'site_uuid' ),
			'email'     => $data['email'],
		);

		$request        = $this->url . '/fetch/subscribers/';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $contact_data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.
		$contact_id = $body->data->attributes->uuid;

		unset( $data['email'] );

		// If there are custom fields in addition to email, send those in a separate request.
		if ( ! empty( $data ) ) {
			$this->update_contact( $contact_id, $data, false );
		}

		return $contact_id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.38.4
	 *
	 * @param int   $contact_id      The ID of the contact to update.
	 * @param array $data            An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $data ) {

		$body = array(
			'site_uuid' => wpf_get_option( 'site_uuid' ),
			'command'   => array(),
		);

		if ( isset( $data['first_name'] ) && isset( $data['last_name'] ) ) {
			$data['name'] = $data['first_name'] . ' ' . $data['last_name'];
		}

		foreach ( $data as $field => $value ) {

			$body['command'][] = array(
				'command' => 'add_field',
				'uuid'    => $contact_id,
				'query'   => array(
					'key'   => $field,
					'value' => $value,
				),
			);
		}

		$request        = $this->url . '/fetch/commands/';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.38.4
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$site_uuid = wpf_get_option( 'site_uuid' );
		$request   = $this->url . '/fetch/subscribers/?site_uuid=' . $site_uuid . '&uuid=' . $contact_id;
		$response  = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		$loaded_meta = array(
			'email' => $response['data']['attributes']['email'],
		);

		if ( ! empty( $response['data']['attributes']['fields'] ) ) {
			$loaded_meta = array_merge( $loaded_meta, $response['data']['attributes']['fields'] );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.38.4
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		return array();
	}


	/**
	 * Track event.
	 *
	 * Track an event with the Bento site tracking API.
	 *
	 * @since  3.38.16
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

		if ( is_object( json_decode( $event_data ) ) ) {
			$details = json_decode( $event_data );
		} elseif ( is_array( $event_data ) ) {
			$details = (object) $event_data;
		} else {
			$details = (object) array(
				'name' => $event,
				'val'  => $event_data,
			);
		}

		$data['events'][] = array(
			'email'   => $email_address,
			'type'    => '$' . sanitize_title( $event ),
			'details' => $details,
		);

		$request            = $this->url . '/batch/events/?site_uuid=' . wpf_get_option( 'site_uuid' );
		$params             = $this->get_params();
		$params['body']     = wp_json_encode( $data );
		$params['blocking'] = false;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


}
