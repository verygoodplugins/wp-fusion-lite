<?php
/**
 * WP Fusion - Omnisend CRM Integration
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.42.8
 */

/**
 * Omnisend CRM integration class.
 *
 * @since 3.42.8
 */
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
	 * Omnisend API version sent in the Omnisend-Version header.
	 *
	 * @since 3.48.2
	 * @var string
	 */
	const API_VERSION = '2026-03-15';

	/**
	 * Base URL for the current Omnisend API.
	 *
	 * @since 3.48.2
	 * @var string
	 */
	const API_URL = 'https://api.omnisend.com/api/';

	/**
	 * Base URL for the deprecated Omnisend v3 API.
	 *
	 * @since 3.48.2
	 * @var string
	 */
	const LEGACY_API_URL = 'https://api.omnisend.com/v3/';

	/**
	 * Maximum number of tags per request to the contact tags endpoints.
	 *
	 * @since 3.48.2
	 * @var int
	 */
	const TAGS_PER_REQUEST = 100;

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.42.8
	 */
	public $url = self::API_URL;

	/**
	 * API Key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Contains API params
	 *
	 * @var array
	 * @since 3.42.8
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

		if ( $this->is_legacy_api() ) {
			$this->url    = self::LEGACY_API_URL;
			$this->params = array();
		}

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Site tracking.
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ), 100 );

		// Guest site tracking.
		add_action( 'wpf_guest_contact_created', array( $this, 'set_tracking_cookie_guest' ), 10, 2 );
		add_action( 'wpf_guest_contact_updated', array( $this, 'set_tracking_cookie_guest' ), 10, 2 );
	}

	/**
	 * Whether to use the deprecated Omnisend v3 API.
	 *
	 * Temporary fallback for sites that run into problems with the
	 * v2026-03-15 API. Omnisend shuts off v3 on December 31, 2027.
	 *
	 * @since 3.48.2
	 *
	 * @return bool Whether the legacy v3 API is in use.
	 */
	public function is_legacy_api() {

		/**
		 * Filters whether WP Fusion uses the legacy Omnisend v3 API.
		 *
		 * @since 3.48.2
		 *
		 * @param bool $use_legacy_api Whether to use the v3 API.
		 */
		return (bool) apply_filters( 'wpf_omnisend_use_legacy_api', (bool) wpf_get_option( 'omnisend_legacy_api' ) );
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

			// Handle string '0' and other falsy values that should be false.
			if ( '0' === $value || 0 === $value || false === $value || '' === $value || null === $value || ( is_array( $value ) && empty( $value ) ) ) {
				return false;
			}

			if ( ! empty( $value ) ) {
				// If checkbox is selected.
				return true;
			}

			// Default to false for empty values.
			return false;
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
	 * Handles webhooks with or without contact_id in URL. If contact_id is not
	 * provided, extracts email from request body and looks up contact_id.
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

			// Handle batched webhooks (array of contacts).
			if ( is_array( $payload ) ) {
				$contact = $payload[0];
			} else {
				$contact = $payload;
			}

			// If contact_id not provided in URL, look it up by email.
			if ( empty( $post_data['contact_id'] ) && ! empty( $contact->email ) ) {
				$post_data['contact_id'] = $this->get_contact_id( $contact->email );
			} elseif ( ! empty( $contact->id ) ) {
				$post_data['contact_id'] = $contact->id;
			}

			// Extract tags if available.
			if ( ! empty( $contact->contact_tags ) ) {
				$post_data['tags'] = $contact->contact_tags;
			} elseif ( ! empty( $contact->tags ) ) {
				$post_data['tags'] = $contact->tags;
			}
		}

		return $post_data;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.42.8
	 * @since  3.48.2 Sends the v2026-03-15 auth and version headers unless the legacy API is in use.
	 *
	 * @param  string $api_key The API key. Defaults to the saved key.
	 * @return array  $params The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// A key passed in (connection test) always rebuilds the params.
		if ( $this->params && empty( $api_key ) ) {
			return $this->params;
		}

		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'omnisend_api_key' );
		}

		if ( $this->is_legacy_api() ) {
			$auth_headers = array(
				'X-API-KEY' => $api_key,
			);
		} else {
			$auth_headers = array(
				'Authorization'    => 'Omnisend-API-Key ' . $api_key,
				'Omnisend-Version' => self::API_VERSION,
			);
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array_merge(
				array(
					'Content-Type' => 'application/json',
					'accept'       => 'application/json',
				),
				$auth_headers
			),
		);

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * Handles both the v2026-03-15 (RFC 9457) and the legacy v3 error formats.
	 *
	 * @since  3.42.8
	 * @since  3.48.2 Added RFC 9457 errors, 401/403/410 handling and a single 429 retry.
	 *
	 * @param  array|WP_Error $response The HTTP response.
	 * @param  array          $args     The HTTP request arguments.
	 * @param  string         $url      The HTTP request URL.
	 * @return array|WP_Error The response, or a WP_Error on failure.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( 0 !== strpos( $url, 'https://api.omnisend.com/' ) || 'WP Fusion; ' . home_url() !== $args['user-agent'] ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body          = json_decode( wp_remote_retrieve_body( $response ) );
		$response_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $response_code ) {
			return $this->retry_rate_limited_request( $response, $args, $url, $body );
		}

		if ( 400 > $response_code && ! isset( $body->error ) ) {
			return $response;
		}

		$message = $this->get_error_message( $body, $response_code );

		if ( 404 === $response_code ) {
			return new WP_Error( 'not_found', $message );
		}

		if ( 401 === $response_code || 403 === $response_code ) {
			return new WP_Error( 'unauthorized', $message );
		}

		if ( 410 === $response_code ) {
			return new WP_Error(
				'error',
				__( 'This version of the Omnisend API has been retired. Please update WP Fusion, and turn off the Legacy API setting if it is enabled.', 'wp-fusion-lite' )
			);
		}

		return new WP_Error( 'error', $message );
	}

	/**
	 * Builds a readable error message from an Omnisend error response.
	 *
	 * @since 3.48.2
	 *
	 * @param object|null $body          The decoded response body.
	 * @param int         $response_code The HTTP response code.
	 * @return string The error message.
	 */
	private function get_error_message( $body, $response_code ) {

		if ( ! empty( $body->detail ) ) {
			$message = $body->detail;
		} elseif ( ! empty( $body->title ) ) {
			$message = $body->title;
		} elseif ( ! empty( $body->error ) && is_string( $body->error ) ) {
			$message = $body->error;
		} else {
			// translators: %d is the HTTP response code.
			$message = sprintf( __( 'Unknown Omnisend API error. [error %d]', 'wp-fusion-lite' ), $response_code );
		}

		if ( ! empty( $body->errors ) && is_array( $body->errors ) ) {

			$field_errors = array();

			foreach ( $body->errors as $error ) {
				if ( ! empty( $error->field ) && ! empty( $error->message ) ) {
					$field_errors[] = $error->field . ': ' . $error->message;
				}
			}

			if ( ! empty( $field_errors ) ) {
				$message .= ' (' . implode( '; ', $field_errors ) . ')';
			}
		} elseif ( ! empty( $body->fields ) ) {
			$message .= ' ' . wp_json_encode( $body->fields );
		}

		if ( ! empty( $body->instance ) ) {
			$message .= ' [' . $body->instance . ']';
		}

		return $message;
	}

	/**
	 * Waits and retries a rate limited request once.
	 *
	 * @since 3.48.2
	 *
	 * @param array       $response The 429 HTTP response.
	 * @param array       $args     The HTTP request arguments.
	 * @param string      $url      The HTTP request URL.
	 * @param object|null $body     The decoded response body.
	 * @return array|WP_Error The retried response, or a WP_Error if still rate limited.
	 */
	private function retry_rate_limited_request( $response, $args, $url, $body ) {

		if ( ! empty( $args['wpf_omnisend_429_retry'] ) ) {
			return new WP_Error( 'rate_limited', __( 'Omnisend is rate limiting requests (HTTP 429). Please try again later.', 'wp-fusion-lite' ) );
		}

		if ( ! empty( $body->{'retryAfter'} ) ) {
			$retry_after = (int) $body->{'retryAfter'};
		} else {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		}

		if ( 0 >= $retry_after ) {
			$retry_after = 2;
		}

		/**
		 * Filters the longest wait, in seconds, before retrying a rate limited Omnisend request.
		 *
		 * @since 3.48.2
		 *
		 * @param int $max_retry_after The maximum wait in seconds.
		 */
		$max_retry_after = (int) apply_filters( 'wpf_omnisend_max_retry_after', 10 );
		$retry_after     = min( $retry_after, max( 1, $max_retry_after ) );

		wpf_log(
			'notice',
			0,
			sprintf( 'Omnisend returned 429 (rate limited). Waiting %ds and retrying.', $retry_after ),
			array( 'source' => 'omnisend' )
		);

		sleep( $retry_after );

		$args['wpf_omnisend_429_retry'] = true;

		return wp_remote_request( $url, $args );
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
			'first_name'      => array(
				'crm_label' => 'First Name',
				'crm_field' => 'firstName',
			),
			'last_name'       => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'lastName',
			),
			'user_email'      => array(
				'crm_label' => 'Email Address',
				'crm_field' => 'email',
			),
			'country'         => array(
				'crm_label' => 'Country',
				'crm_field' => 'country',
			),
			'countryCode'     => array(
				'crm_label' => 'Country Code',
				'crm_field' => 'countryCode',
			),
			'state'           => array(
				'crm_label' => 'State',
				'crm_field' => 'state',
			),
			'city'            => array(
				'crm_label' => 'City',
				'crm_field' => 'city',
			),
			'address'         => array(
				'crm_label' => 'Address',
				'crm_field' => 'address',
			),
			'postalCode'      => array(
				'crm_label' => 'Postal Code',
				'crm_field' => 'postalCode',
			),
			'gender'          => array(
				'crm_label' => 'Gender',
				'crm_field' => 'gender',
			),
			'birthday'        => array(
				'crm_label' => 'Birthdate',
				'crm_field' => 'birthdate',
			),
			'emailStatus'     => array(
				'crm_label' => 'Email Subscription Status',
				'crm_field' => 'emailStatus',
			),
			'emailStatusDate' => array(
				'crm_label' => 'Email Status Date',
				'crm_field' => 'emailStatusDate',
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
		$request  = $this->url . 'contacts?limit=1';
		$response = wp_remote_get( $request, $params );

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

		// Load 250 contacts and see what tags they have.
		$response = wp_remote_get( $this->url . 'contacts?limit=250', $this->get_params() );

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

		// Now load 250 contacts and see what properties they have.
		$response = wp_remote_get( $this->url . 'contacts?limit=250', $this->get_params() );

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
		$request = $this->url . 'contacts?email=' . rawurlencode( $email_address );

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return $this->get_record_id( $response->contacts[0] );
	}

	/**
	 * Gets the contact ID from a contact record.
	 *
	 * The field is "id" since v2026-03-15 and "contactID" in v3.
	 *
	 * @since 3.48.2
	 *
	 * @param object $contact The contact record from the API.
	 * @return string|false The contact ID, or false if missing.
	 */
	private function get_record_id( $contact ) {

		if ( ! empty( $contact->id ) ) {
			return $contact->id;
		}

		return $contact->{'contactID'} ?? false;
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
		$request = $this->url . 'contacts/' . $contact_id;

		$response = wp_remote_get( $request, $params );

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
	 * Also clears any removal markers left on the contact by the v3
	 * integration, so re-applied tags aren't hidden by get_tags().
	 *
	 * @since 3.42.8
	 * @since 3.48.2 Uses the contact tags endpoint unless the legacy API is in use.
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		if ( empty( $tags ) ) {
			return true;
		}

		$data = array(
			'customProperties' => array(),
		);

		foreach ( $tags as $tag ) {
			$data['customProperties'][ $this->format_tag_for_property( $tag ) ] = null;
		}

		// v3 merges contact tags. Since v2026-03-15 they replace the full tag set.
		if ( $this->is_legacy_api() ) {
			$data['tags'] = $tags;
		}

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PATCH';
		$response         = wp_remote_post( $this->url . 'contacts/' . $contact_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $this->is_legacy_api() ) {
			return true;
		}

		return $this->send_contact_tags( 'POST', $tags, $contact_id );
	}

	/**
	 * Removes tags from a contact.
	 *
	 * The v3 API can't remove tags, so in legacy mode the removed tags are
	 * stored as custom properties and filtered out when loading the tags.
	 *
	 * @since  3.42.8
	 * @since  3.48.2 Uses the contact tags endpoint unless the legacy API is in use.
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		if ( empty( $tags ) ) {
			return true;
		}

		if ( ! $this->is_legacy_api() ) {
			return $this->send_contact_tags( 'DELETE', $tags, $contact_id );
		}

		$update_data = array();

		foreach ( $tags as $tag ) {
			$tag                 = $this->format_tag_for_property( $tag );
			$update_data[ $tag ] = 'false';
		}

		return $this->update_contact( $contact_id, $update_data );
	}

	/**
	 * Adds or removes tags with the contact tags endpoint.
	 *
	 * The endpoint processes requests asynchronously and returns 202.
	 *
	 * @since 3.48.2
	 *
	 * @param string     $method     POST to add the tags, DELETE to remove them.
	 * @param array      $tags       The tags.
	 * @param int|string $contact_id The contact ID.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	private function send_contact_tags( $method, $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['method'] = $method;

		foreach ( array_chunk( array_map( 'strval', array_values( $tags ) ), self::TAGS_PER_REQUEST ) as $tags_chunk ) {

			$params['body'] = wp_json_encode(
				array(
					'contactIDs' => array( (string) $contact_id ),
					'tags'       => $tags_chunk,
				)
			);

			$response = wp_remote_request( $this->url . 'contacts/tags', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Moves custom properties into their own array and handles email subscription status.
	 *
	 * @since 3.42.8
	 *
	 * @param array $update_data An associative array of contact fields and field values.
	 * @return array $contact_data The formatted contact data.
	 **/
	private function format_contact_payload( $update_data ) {

		$standard_fields = wp_list_pluck( $this::get_standard_fields(), 'crm_field' );

		// Handle email subscription status if present.
		if ( ! empty( $update_data['emailStatus'] ) && ! empty( $update_data['email'] ) ) {
			$update_data['identifiers'] = array(
				array(
					'type'     => 'email',
					'id'       => $update_data['email'],
					'channels' => array(
						'email' => array(
							'status' => $update_data['emailStatus'],
						),
					),
				),
			);

			if ( ! empty( $update_data['emailStatusDate'] ) ) {
				// v2026-03-15 renamed the channel field from statusDate to statusChangedAt.
				$date_field = $this->is_legacy_api() ? 'statusDate' : 'statusChangedAt';

				$update_data['identifiers'][0]['channels']['email'][ $date_field ] = $update_data['emailStatusDate'];
			}
		}

		// Remove emailStatus fields as they're now in identifiers.
		unset( $update_data['emailStatus'], $update_data['emailStatusDate'] );

		// Move custom properties into their own array.
		foreach ( $update_data as $key => $value ) {

			// Skip identifiers array - it needs to stay at root level.
			if ( 'identifiers' === $key ) {
				continue;
			}

			if ( ! in_array( $key, $standard_fields, true ) ) {
				$update_data['customProperties'][ $key ] = $value;
				unset( $update_data[ $key ] );
			}
		}

		return $update_data;
	}

	/**
	 * Drops contact fields the v2026-03-15 API no longer accepts.
	 *
	 * Email stays on the email identifier. The legacy v3 API still accepts the top-level field.
	 *
	 * @since 3.48.2
	 *
	 * @param array $contact_data The contact payload.
	 * @return array The payload safe to send.
	 */
	private function normalize_contact_request( $contact_data ) {

		if ( $this->is_legacy_api() || ! isset( $contact_data['email'] ) ) {
			return $contact_data;
		}

		$email = $contact_data['email'];
		unset( $contact_data['email'] );

		if ( ! is_string( $email ) || '' === $email ) {
			return $contact_data;
		}

		if ( empty( $contact_data['identifiers'] ) || ! is_array( $contact_data['identifiers'] ) ) {
			$contact_data['identifiers'] = array();
		}

		foreach ( $contact_data['identifiers'] as $identifier ) {
			if ( is_array( $identifier ) && isset( $identifier['type'] ) && 'email' === $identifier['type'] ) {
				return $contact_data;
			}
		}

		$contact_data['identifiers'][] = array(
			'type' => 'email',
			'id'   => $email,
		);

		return $contact_data;
	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.42.8
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return string|false|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		// Format contact data (handles identifiers if emailStatus is set).
		$contact_data = $this->format_contact_payload( $contact_data );

		// If identifiers weren't set by format_contact_payload(), default to subscribed.
		if ( empty( $contact_data['identifiers'] ) && ! empty( $contact_data['email'] ) ) {
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
		}

		$contact_data = $this->normalize_contact_request( $contact_data );

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $contact_data );
		$request        = $this->url . 'contacts';

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $this->get_record_id( $body );
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

		// Format contact data (handles identifiers if emailStatus is set).
		$contact_data = $this->format_contact_payload( $contact_data );
		$contact_data = $this->normalize_contact_request( $contact_data );

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PATCH';
		$request          = $this->url . 'contacts/' . $contact_id;

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
		$request = $this->url . 'contacts/' . $contact_id;

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract custom properties into main array.
		if ( ! empty( $response['customProperties'] ) ) {
			foreach ( $response['customProperties'] as $key => $value ) {
				$response[ $key ] = $value;
			}
			unset( $response['customProperties'] );
		}

		// Extract email subscription status from identifiers array.
		if ( ! empty( $response['identifiers'] ) && is_array( $response['identifiers'] ) ) {
			foreach ( $response['identifiers'] as $identifier ) {
				if ( 'email' === $identifier['type'] && ! empty( $identifier['channels']['email'] ) ) {
					$email_channel = $identifier['channels']['email'];

					if ( ! empty( $email_channel['status'] ) ) {
						$response['emailStatus'] = $email_channel['status'];
					}

					// The response field is "statusChangedAt" since v2026-03-15.
					if ( ! empty( $email_channel['statusChangedAt'] ) ) {
						$response['emailStatusDate'] = $email_channel['statusChangedAt'];
					} elseif ( ! empty( $email_channel['statusDate'] ) ) {
						$response['emailStatusDate'] = $email_channel['statusDate'];
					}

					break;
				}
			}
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
	 * @since 3.48.2 Follows v2026-03-15 cursor pagination.
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array|WP_Error Contact IDs returned, or a WP_Error if the API call failed.
	 */
	public function load_contacts( $tag ) {

		$contact_ids = array();

		$first_page = $this->url . 'contacts?limit=250&tag=' . rawurlencode( strtolower( $tag ) );
		$request    = $first_page;

		while ( $request ) {

			$response = wp_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body->contacts ) ) {
				break;
			}

			foreach ( $body->contacts as $contact ) {
				$contact_ids[] = $this->get_record_id( $contact );
			}

			$request = $this->get_next_page_url( $body, $first_page );
		}

		return array_filter( $contact_ids );
	}

	/**
	 * Gets the URL of the next page of a contacts list response.
	 *
	 * @since 3.48.2
	 *
	 * @param object $body       The decoded list response.
	 * @param string $first_page The URL of the first page, including filters.
	 * @return string|false The next page URL, or false on the last page.
	 */
	private function get_next_page_url( $body, $first_page ) {

		// v2026-03-15 uses an opaque cursor. The filters are encoded in it, so they must not change.
		if ( ! empty( $body->paging->{'hasMore'} ) && ! empty( $body->paging->cursors->after ) ) {
			return $first_page . '&after=' . rawurlencode( $body->paging->cursors->after );
		}

		// v3 returns the full URL of the next page.
		if ( ! empty( $body->paging->next ) && is_string( $body->paging->next ) ) {
			return $body->paging->next;
		}

		if ( ! empty( $body->links->next ) ) {
			return $body->links->next;
		}

		return false;
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
		$request            = $this->url . ( $this->is_legacy_api() ? 'customer-events' : 'events' );
		$response           = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
