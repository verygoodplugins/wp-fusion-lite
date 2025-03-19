<?php

class WPF_Omnisend {

	/**
	 * CRM name.
	 *
	 * @var string
	 * @since 3.42.8
	 */
	public $name = 'Omnisend';

	/**
	 * CRM slug.
	 *
	 * @var string
	 * @since 3.42.8
	 */
	public $slug = 'omnisend';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.42.8
	 */

	public $url = 'https://api.omnisend.com/v3/';

	/**
	 * API Key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * @var array
	 * @since 3.42.8
	 */

	public $supports = array( 'add_fields', 'events', 'events_multi_key', 'add_tags' );

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * Not supported by Omnisend, we can't get the account slug over the API.
	 *
	 * @var string
	 * @since 3.42.8
	 */
	public $edit_url = 'https://app.omnisend.com/audience/contact/%s/';

	/**
	 * Get things started
	 *
	 * @since 3.42.8
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/class-omnisend-admin.php';
			new WPF_Omnisend_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$this->api_key = wpf_get_option( 'omnisend_api_key' );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.42.8
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Site tracking.
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ), 100 );

		// Guest site tracking.
		add_action( 'wpf_guest_contact_created', array( $this, 'set_tracking_cookie_guest' ), 10, 2 );
		add_action( 'wpf_guest_contact_updated', array( $this, 'set_tracking_cookie_guest' ), 10, 2 );
	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * Omnisend doesn't use custom formats for properties.
	 *
	 * @since  3.42.8
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', $value );
		} elseif ( 'checkbox' === $field_type ) {
			return (bool) $value;
		} else {
			return $value;
		}
	}

	/**
	 * Omnisend doesn't support removing tags over the API so we'll use a custom property to
	 * track them.
	 *
	 * @since  3.42.8
	 *
	 * @param  string $tag_name The tag name.
	 * @return string The formatted tag.
	 */
	private function format_tag_for_property( $tag_name ) {

		return 'tag_' . strtolower( str_replace( '-', '_', sanitize_title( $tag_name ) ) );
	}

	/**
	 * Formats POST data received from webhooks into standard format.
	 *
	 * @TODO Omnisend batches webhooks and they can contain multiple subsceribers,
	 * we'll need to update this to create a background process in case there are
	 * multiple subscribers in the payload.
	 *
	 * @since  3.42.8
	 *
	 * @param array $post_data The data read out of the webhook URL.
	 * @return array $post_data The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( $payload ) {

			$post_data['contact_id'] = $payload[0]->id;
			$post_data['tags']       = $payload[0]->contact_tags;

		}

		return $post_data;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.42.8
	 *
	 * @return array  $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'omnisend_api_key' );
		}

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-Type' => 'application/json',
				'accept'       => 'application/json',
				'X-API-KEY'    => $api_key,
			),
		);

		return $params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.42.8
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'https://api.omnisend.com/v3/' ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 404 === $response_code ) {

				$response = new WP_Error( 'not_found', $body_json->error );

			} elseif ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', '<pre>' . print_r( $body_json, true ) . '</pre>' );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

			}
		}

		return $response;
	}

	/**
	 * Returns the standard fields.
	 *
	 * @since 3.42.8
	 *
	 * @return array The standard fields.
	 */
	public static function get_standard_fields() {

		$standard_fields = array(
			'first_name'  => array(
				'crm_label' => 'First Name',
				'crm_field' => 'firstName',
			),
			'last_name'   => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'lastName',
			),
			'user_email'  => array(
				'crm_label' => 'Email Address',
				'crm_field' => 'email',
			),
			'country'     => array(
				'crm_label' => 'Country',
				'crm_field' => 'country',
			),
			'countryCode' => array(
				'crm_label' => 'Country Code',
				'crm_field' => 'countryCode',
			),
			'state'       => array(
				'crm_label' => 'State',
				'crm_field' => 'state',
			),
			'city'        => array(
				'crm_label' => 'City',
				'crm_field' => 'city',
			),
			'address'     => array(
				'crm_label' => 'Address',
				'crm_field' => 'address',
			),
			'postalCode'  => array(
				'crm_label' => 'Postal Code',
				'crm_field' => 'postalCode',
			),
			'gender'      => array(
				'crm_label' => 'Gender',
				'crm_field' => 'gender',
			),
			'birthday'    => array(
				'crm_label' => 'Birthdate',
				'crm_field' => 'birthdate',
			),
		);

		return $standard_fields;
	}

	/**
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @since  3.42.8
	 *
	 * @param  string $api_key The API Key.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$params   = $this->get_params( $api_key );
		$request  = 'https://api.omnisend.com/v3/contacts';
		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.42.8
	 */
	public function sync() {

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}

	/**
	 * Output tracking code.
	 *
	 * @return mixed JavaScript tracking code.
	 *
	 * @since 3.42.8
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) || ! wpf_get_option( 'omnisend_brand_id' ) ) {
			return;
		}

		$email = wpf_get_current_user_email();
		echo '<!-- Omnisend (via WP Fusion) -->';

		echo '
		<script type="text/javascript">
			window.omnisend = window.omnisend || [];
			omnisend.push(["accountID", "' . esc_js( wpf_get_option( 'omnisend_brand_id' ) ) . '"]);
			omnisend.push(["track", "$pageViewed"]);
			!function(){var e=document.createElement("script");e.type="text/javascript",e.async=!0,e.src="https://omnisnippet1.com/inshop/launcher-v2.js";var t=document.getElementsByTagName("script")[0];t.parentNode.insertBefore(e,t)}();
	
		</script>';

		if ( $email ) {
			echo '
			<script type="text/javascript">
			window.onload = function() { 
				window.omnisend.identifyContact({
					"email": "' . esc_js( $email ) . '",
				});
			};
			</script>
			';
		}

		echo '<!-- end Omnisend -->';
	}

	/**
	 * Set a cookie to fix tracking for guest checkouts / form submissions.
	 *
	 * @since 3.40.55
	 *
	 * @param string $contact_id The subscriber ID.
	 * @param string $email      The email address.
	 */
	public function set_tracking_cookie_guest( $contact_id, $email ) {

		if ( wpf_is_user_logged_in() || headers_sent() ) {
			return;
		}

		setcookie( 'wpf_guest', $email, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.42.8
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$available_tags = array();

		// Load 100 contacts and see what tags they have.
		$response = wp_remote_get( 'https://api.omnisend.com/v3/contacts', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->contacts ) ) {

			foreach ( $body->contacts as $contact ) {
				foreach ( $contact->tags as $tag ) {
					$available_tags[ $tag ] = $tag;
				}
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.42.8
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$crm_fields = array();

		foreach ( $this::get_standard_fields() as $field ) {
			$crm_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		// Now load 100 contacts and see what properties they have.
		$response = wp_remote_get( 'https://api.omnisend.com/v3/contacts', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->contacts ) ) {

			foreach ( $body->contacts as $contact ) {
				if ( ! empty( $contact->{'customProperties'} ) ) {
					foreach ( $contact->{'customProperties'} as $key => $value ) {
						$crm_fields[ $key ] = $key;
					}
				}
			}
		}

		natcasesort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}



	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.42.8
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|bool|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$params  = $this->get_params();
		$request = 'https://api.omnisend.com/v3/contacts?email=' . rawurlencode( $email_address );

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return $response->contacts[0]->{'contactID'};
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.42.8
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$params  = $this->get_params();
		$request = 'https://api.omnisend.com/v3/contacts/' . $contact_id;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$user_tags = array();

		if ( ! empty( $response->tags ) ) {
			$user_tags = $response->tags;
		}

		// See if any tags were removed earlier and saved to properties.

		foreach ( $user_tags as $i => $tag ) {

			$tag = $this->format_tag_for_property( $tag );

			if ( ! empty( $response->{'customProperties'} ) && isset( $response->{'customProperties'}->{ $tag } ) && 'false' === $response->{'customProperties'}->{ $tag } ) {
				unset( $user_tags[ $i ] );
			}
		}

		return $user_tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.42.8
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params  = $this->get_params();
		$request = 'https://api.omnisend.com/v3/contacts/' . $contact_id;

		$data = array(
			'tags' => $tags,
		);

		foreach ( $tags as $tag ) {

			$tag = $this->format_tag_for_property( $tag );

			$data['customProperties'][ $tag ] = null;
		}

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PATCH';
		$response         = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * Omnisend doesn't currently have an API method for removing tags, so we will store
	 * the removed tags as custom properties, and compare them when loading the tags.
	 *
	 * @since  3.42.8
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$update_data = array();

		foreach ( $tags as $tag ) {
			$tag                 = $this->format_tag_for_property( $tag );
			$update_data[ $tag ] = 'false';
		}

		return $this->update_contact( $contact_id, $update_data );
	}

	/**
	 * Moves custom properties into their own array.
	 *
	 * @since 3.42.8
	 *
	 * @param array $update_data An associative array of contact fields and field values.
	 * @return array $contact_data The formatted contact data.
	 **/
	private function format_contact_payload( $update_data ) {

		$standard_fields = wp_list_pluck( $this::get_standard_fields(), 'crm_field' );

		foreach ( $update_data as $key => $value ) {

			if ( ! in_array( $key, $standard_fields ) ) {
				$update_data['customProperties'][ $key ] = $value;
				unset( $update_data[ $key ] );
			}
		}

		return $update_data;
	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.42.8
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$contact_data = $this->format_contact_payload( $contact_data );

		$contact_data['identifiers'] = array(
			array(
				'id'       => $contact_data['email'],
				'type'     => 'email',
				'channels' => array(
					'email' => array(
						'status' => 'subscribed',
					),
				),
			),
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $contact_data );
		$request        = 'https://api.omnisend.com/v3/contacts';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->{'contactID'};
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.42.8
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$contact_data = $this->format_contact_payload( $contact_data );

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PATCH';
		$request          = 'https://api.omnisend.com/v3/contacts/' . $contact_id;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.42.8
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$params  = $this->get_params();
		$request = 'https://api.omnisend.com/v3/contacts/' . $contact_id;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $response['customProperties'] as $key => $value ) {
			$response[ $key ] = $value;
			unset( $response['customProperties'] );
		}

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
	 * @since 3.42.8
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		$contact_ids = array();

		$request  = 'https://api.omnisend.com/v3/contacts?limit=250&tag=' . rawurlencode( strtolower( $tag ) );
		$continue = true;

		while ( $continue ) {

			$response = wp_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			$contact_ids = array_merge( $contact_ids, wp_list_pluck( $body->contacts, 'contactID' ) );

			if ( ! empty( $body->links->next ) ) {
				$request = $body->links->next;
			} else {
				$continue = false;
			}
		}

		return $contact_ids;
	}


	/**
	 * Track event.
	 *
	 * Track an event with the site tracking API.
	 *
	 * @since  3.42.8
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = array(), $email_address = false ) {

		if ( empty( $email_address ) && ! wpf_is_user_logged_in() ) {
			// Tracking only works if WP Fusion knows who the contact is.
			return false;
		}

		// Get the email address to track.
		if ( empty( $email_address ) ) {
			$user          = wpf_get_current_user();
			$email_address = $user->user_email;
		}

		// remove all $ and % signs from event values, and convert to float if numeric.
		foreach ( $event_data as $key => $value ) {
			$event_data[ $key ] = str_replace( array( '$', '%' ), '', $value );

			if ( is_numeric( $event_data[ $key ] ) ) {
				$event_data[ $key ] = floatval( $event_data[ $key ] );
			}
		}

		$data = array(
			'contact'    => array(
				'email' => $email_address,
			),
			'eventName'  => $event,
			'origin'     => 'api',
			'properties' => $event_data,
		);

		$params             = $this->get_params();
		$params['body']     = wp_json_encode( $data );
		$params['blocking'] = false;
		$request            = 'https://api.omnisend.com/v3/customer-events';
		$response           = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
