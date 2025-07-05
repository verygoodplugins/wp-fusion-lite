<?php

/**
 * WP Fusion Encharge CRM class.
 *
 * @since 3.43.10
 */
class WPF_Encharge {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 * @since 3.43.10
	 */

	public $slug = 'encharge';

	/**
	 * The CRM name.
	 *
	 * @var string
	 * @since 3.43.10
	 */

	public $name = 'Encharge';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.43.10
	 */

	public $url = 'https://api.encharge.io/v1';

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
	 * "add_fields" means that encharge field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * "events" enables the integration for Event Tracking: https://wpfusion.com/documentation/event-tracking/event-tracking-overview/.
	 *
	 * "events_multi_key" enables the integration for Event Tracking with multiple keys: https://wpfusion.com/documentation/event-tracking/event-tracking-overview/#multi-key-events.
	 *
	 * @var array<string>
	 * @since 3.43.10
	 */

	public $supports = array(
		'add_tags',
		'events',
		'events_multi_key',
	);

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var string
	 * @since 3.43.10
	 */
	public $edit_url = 'https://app.encharge.io/people/%s';


	/**
	 * Get things started.
	 *
	 * @since 3.43.10
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/class-wpf-encharge-admin.php';
			new WPF_Encharge_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.43.10
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Add tracking code to footer
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );
	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.43.10
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
	 * @since  3.43.10
	 *
	 * @link https://wpfusion.com/documentation/webhooks/about-webhooks/
	 *
	 * @param  array $post_data The post data.
	 * @return array $post_data The formatted post data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) ) {
			$post_data['contact_id'] = $payload->id;
		}

		return $post_data;
	}


	/**
	 * Sends a success message after a webhook is received.
	 *
	 * @since 3.43.11
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
	 * @since 3.43.10
	 *
	 * @param string $api_key The API key.
	 * @return array<string|mixed> $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// Get saved data from DB.
		if ( ! $api_key ) {
			$api_key = wpf_get_option( 'encharge_key' );
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'X-Encharge-Token' => $api_key,
			),
		);

		return $params;
	}


	/**
	 * Gets tracking params for API calls.
	 *
	 * @since 3.43.10
	 *
	 * @return array<string|mixed> $params The API parameters.
	 */
	public function get_tracking_params() {
		$api_key = wpf_get_option( 'site_tracking_write_key' );

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-type'     => 'application/json',
				'Accept'           => 'application/json',
				'X-Encharge-Token' => $api_key,
			),
		);

		return $params;
	}


	/**
	 * Gets the default fields.
	 *
	 * @since 3.43.10
	 *
	 * @return array<string, array> The default fields in the CRM.
	 */
	public static function get_default_fields() {

		return array(
			'first_name' => array(
				'crm_label' => 'First Name',
				'crm_field' => 'firstName',
			),
			'last_name'  => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'lastName',
			),
			'user_email' => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
				'crm_type'  => 'email',
			),

		);
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since 3.43.10
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
	 * @since  3.43.10
	 *
	 * @param  string $api_key The second API credential.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$request  = $this->url . '/accounts/info';
		$response = wp_safe_remote_get( $request, $this->get_params( $api_key ) );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Output tracking code.
	 *
	 * @since 3.43.10
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) || ! wpf_get_option( 'site_tracking_write_key' ) ) {
			return;
		}

		echo '<!-- Encharge (via WP Fusion) -->';
		echo '
		<script type="text/javascript">!function(){if(!window.EncTracking||!window.EncTracking.started){window.EncTracking=Object.assign({}, window.EncTracking, {queue:window.EncTracking&&window.EncTracking.queue?window.EncTracking.queue:[],track:function(t){this.queue.push({type:"track",props:t})},identify:function(t){this.queue.push({type:"identify",props:t})},started:!0});var t=window.EncTracking;t.writeKey="' . wpf_get_option( 'site_tracking_write_key' ) . '",t.hasOptedIn=true,t.shouldGetConsent=true,t.hasOptedIn&&(t.shouldGetConsent=!1),t.optIn=function(){t.hasOptedIn=!0,t&&t.init&&t.init()},t.optOut=function(){t.hasOptedIn=!1,t&&t.setOptOut&&t.setOptOut(!0)};var n=function(t){var n=document.createElement("script");n.type="text/javascript",n.async=void 0===t||t,n.src="https://resources-app.encharge.io/encharge-tracking.min.js";var e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};"complete"===document.readyState?n():window.attachEvent?window.attachEvent("onload",n):window.addEventListener("load",n,!1)}}();
		</script>';

		$email = wpf_get_current_user_email();

		if ( $email ) {
			echo '
			<script>
				EncTracking.identify({ 
				email: "' . esc_js( $email ) . '"
			  });
			</script>
			';
		}

		echo '<!-- end Encharge -->';
	}




	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.43.10
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
	 * @since  3.43.10
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request  = $this->url . '/people/all';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'.
		if ( ! empty( $response->people ) ) {

			foreach ( $response->people as $person ) {
				if ( $person->tags && ! empty( $person->tags ) ) {
					$tags = explode( ',', $person->tags );

					foreach ( $tags as $tag ) {
						if ( ! array_key_exists( $tag, $available_tags ) ) {
							$available_tags[ $tag ] = $tag;
						}
					}
				}
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all encharge fields from CRM and merges with local list.
	 *
	 * @since  3.43.10
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$standard_fields = array();

		foreach ( $this->get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		$request  = $this->url . '/fields';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$custom_fields = array();
		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		foreach ( $response->items as $field ) {
			if ( $field->readOnly === true || in_array( $field->name, array_keys( $standard_fields ) ) ) {
				continue;
			}

			$custom_fields[ $field->name ] = $field->name;
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
	 * @since  3.43.10
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/people?people[0][email]=' . rawurlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->users ) ) {
			return 0;
		}

		return $response->users[0]->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.43.10
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . '/people?people[0][id]=' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $response->users ) ) {
			return array();
		}

		$tags = $response->users[0]->tags;
		if ( empty( $tags ) ) {
			return array();
		}

		return explode( ',', $tags );
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.43.10
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$request        = $this->url . '/tags';
		$params         = $this->get_params();
		$params['body'] = array(
			'tag' => implode( ',', $tags ),
			'id'  => $contact_id,
		);

		$response = wp_safe_remote_post( $request, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.43.10
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$request          = $this->url . '/tags';
		$params           = $this->get_params();
		$params['body']   = array(
			'tag' => implode( ',', $tags ),
			'id'  => $contact_id,
		);
		$params['method'] = 'DELETE';
		$response         = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.43.10
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$request        = $this->url . '/people';
		$params         = $this->get_params();
		$params['body'] = $contact_data;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// Get new contact ID out of response.

		return $body->user->id;
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.43.10
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$request            = $this->url . '/people';
		$params             = $this->get_params();
		$contact_data['id'] = $contact_id;
		$params['body']     = $contact_data;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.43.10
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/people?people[0][id]=' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );
		$response       = $response['users'][0];
		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.43.10
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	public function load_contacts( $tag ) {
		return array();
	}

	/**
	 * Track event.
	 *
	 * Track an event with the site tracking API.
	 *
	 * @since  3.43.10
	 *
	 * @link   https://wpfusion.com/documentation/event-tracking/event-tracking-overview/
	 *
	 * @param  string      $event         The event title.
	 * @param  array       $event_data    The event data (associative array).
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = array(), $email_address = false ) {

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return false; // can't track without an email.
		}

		$data = array(
			'user'       => array(
				'email' => $email_address,
			),
			'name'       => $event,
			'properties' => $event_data,
		);

		$params             = $this->get_tracking_params();
		$params['body']     = wp_json_encode( $data );
		$params['blocking'] = false; // we don't need to wait for a response.

		$response = wp_safe_remote_post( 'https://ingest.encharge.io/v1', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
