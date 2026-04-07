<?php
/**
 * WP Fusion - Kit CRM Integration
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.47.2
 */

/**
 * The Kit CRM integration class.
 *
 * @since 3.47.2
 */
class WPF_Kit {

	/**
	 * The CRM slug.
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	public $slug = 'kit';

	/**
	 * The CRM name.
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	public $name = 'Kit';

	/**
	 * API access token (Bearer).
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	public $token;

	/**
	 * Kit OAuth client ID.
	 *
	 * @since 3.47.2
	 * @var string
	 */
	public $client_id = 'RCxc5ahWzfaLyPEUSgsU8f2Bvjf0WL-EV4nABFV6nYI';

	/**
	 * Kit OAuth client secret.
	 *
	 * @since 3.47.2
	 * @var string
	 */
	public $client_secret = 'IKzU8RHgGmhOidmMIinJguJGNHgOiy1vWsaSOh6ma5A';

	/**
	 * Supported capabilities.
	 *
	 * @since 3.47.2
	 * @var   array
	 */
	public $supports = array( 'add_tags_api', 'auto_oauth' );

	/**
	 * HTTP params for requests.
	 *
	 * @since 3.47.2
	 * @var   array
	 */
	public $params;

	/**
	 * Edit URL for a contact in the app.
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	public $edit_url = 'https://app.convertkit.com/subscribers/%d';

	/**
	 * Initialize the integration.
	 *
	 * @since 3.47.2
	 */
	public function __construct() {

		$this->token = wpf_get_option( 'kit_token' );

		if ( is_admin() ) {
			require_once __DIR__ . '/class-kit-admin.php';
			new WPF_Kit_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * @since 3.47.2
	 */
	public function init() {
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );
	}

	/**
	 * Slow down batch processes to avoid rate limits.
	 *
	 * @since  3.47.2
	 *
	 * @param  int $seconds Current sleep time.
	 * @return int Modified sleep time.
	 */
	public function set_sleep_time( $seconds ) {
		return 1;
	}

	/**
	 * Format field values for the Kit API.
	 *
	 * @since  3.47.2
	 *
	 * @param  mixed  $value      The field value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The field identifier.
	 * @return mixed The formatted value.
	 */
	public function format_field_value( $value, $field_type, $field ) {
		if ( 'date' === $field_type && ! empty( $value ) ) {
			if ( gmdate( 'H:i:s', $value ) !== '00:00:00' ) {
				return gmdate( wpf_get_datetime_format(), $value );
			} else {
				return gmdate( get_option( 'date_format' ), $value );
			}
		}

		return $value;
	}

	/**
	 * Error handling for Kit v4 API responses.
	 *
	 * @since  3.47.2
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object The response or WP_Error.
	 */
	public function handle_http_response( $response, $args, $url ) {
		if ( false === strpos( $url, 'api.kit.com' ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body_json     = json_decode( wp_remote_retrieve_body( $response ) );

		// Handle expired/invalid tokens before parsing errors to allow refresh.
		if ( 401 === $response_code ) {
			if ( ! empty( $args['wpf_kit_refresh_attempted'] ) ) {
				return new WP_Error( 'error', 'Invalid or expired API token. Please re-authorize with Kit.' );
			}

			$access_token = $this->refresh_token();

			if ( is_wp_error( $access_token ) ) {
				return new WP_Error( 'error', 'Invalid or expired API token. Please re-authorize with Kit: ' . $access_token->get_error_message() );
			}

			$args['headers']['Authorization']   = 'Bearer ' . $access_token;
			$args['wpf_kit_refresh_attempted'] = true;

			// Retry the request with the new token.
			return wp_remote_request( $url, $args );
		}

		// Handle v4 error format: { "errors": ["msg1", "msg2"] }.
		if ( isset( $body_json->errors ) && is_array( $body_json->errors ) ) {
			$message = implode( ', ', $body_json->errors );
			return new WP_Error( 'error', $message );
		}

		// Handle rate limiting.
		if ( 429 === $response_code ) {
			return new WP_Error( 'error', 'API limits exceeded. Try again in one minute.' );
		}

		return $response;
	}

	/**
	 * Get API parameters with Bearer authentication.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $token Optional. The access token.
	 * @return array The API parameters.
	 */
	public function get_params( $token = null ) {
		if ( ! empty( $token ) ) {
			$this->token = $token;
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'WP Fusion; ' . home_url(),
		);

		if ( ! empty( $this->token ) ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$this->params = array(
			'timeout' => 15,
			'headers' => $headers,
		);

		return $this->params;
	}

	/**
	 * Test the API connection.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $token Optional. The access token to test.
	 * @param  bool   $test  Whether to run a test.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function connect( $token = null, $test = false ) {
		if ( false === $test ) {
			return true;
		}

		$params = $this->get_params( $token );

		$response = wp_remote_get( 'https://api.kit.com/v4/tags', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'error', 'Invalid API token. Please verify your Kit access token.' );
		}

		return true;
	}

	/**
	 * Authorize the application and generate access token.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $code The authorization code.
	 * @return string|bool The access token on success, false on error.
	 */
	public function authorize( $code ) {

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri'  => 'https://wpfusion.com/oauth/?action=wpf_get_kit_token',
		);

		$params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'       => $body,
		);

		// Prevent the error handling from looping on itself.
		remove_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$response = wp_remote_post( 'https://api.kit.com/oauth/token', $params );

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		if ( is_wp_error( $response ) ) {
			wp_fusion()->admin_notices->add_notice( 'Error requesting authorization code: ' . $response->get_error_message() );
			wpf_log( 'error', 0, 'Error requesting authorization code: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_fusion()->admin_notices->add_notice( $code . ' error requesting authorization: ' . wp_remote_retrieve_body( $response ) );
			wpf_log( 'error', 0, $code . ' error requesting authorization: ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $response->access_token ) ) {
			wp_fusion()->admin_notices->add_notice( 'Error: No access token in response.' );
			wpf_log( 'error', 0, 'Error: No access token in response: ' . wp_json_encode( $response ) );
			return false;
		}

		wp_fusion()->settings->set( 'kit_token', $response->access_token );
		wp_fusion()->settings->set( 'crm', $this->slug );
		wp_fusion()->settings->set( 'connection_configured', true );

		// Save refresh token if provided.
		if ( isset( $response->refresh_token ) ) {
			wp_fusion()->settings->set( 'kit_refresh_token', $response->refresh_token );
		}

		wpf_log( 'notice', 0, 'Successfully authorized with Kit. Refresh token saved.' );

		return $response->access_token;
	}

	/**
	 * Refresh an access token from a refresh token.
	 *
	 * @since  3.47.2
	 *
	 * @return string|WP_Error The access token on success, error on failure.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( 'kit_refresh_token' );

		if ( empty( $refresh_token ) ) {
			wpf_log( 'error', 0, 'Kit token refresh failed: No refresh token found. Please re-authorize with Kit.' );
			return new WP_Error( 'error', 'No refresh token found. Please re-authorize with Kit.' );
		}

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
		);

		$params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'       => $body,
		);

		// Prevent the error handling from looping on itself.
		remove_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$response = wp_remote_post( 'https://api.kit.com/oauth/token', $params );

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		if ( is_wp_error( $response ) ) {
			wpf_log( 'error', 0, 'Kit token refresh failed: ' . $response->get_error_message() );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			wpf_log( 'error', 0, 'Kit token refresh failed with code ' . $response_code . ': ' . $response_body );
			return new WP_Error( 'error', 'Error refreshing access token: ' . $response_body );
		}

		$response = json_decode( $response_body );

		if ( ! isset( $response->access_token ) ) {
			wpf_log( 'error', 0, 'Kit token refresh failed: No access token in response: ' . wp_json_encode( $response ) );
			return new WP_Error( 'error', 'No access token in refresh response.' );
		}

		wp_fusion()->settings->set( 'kit_token', $response->access_token );

		// Update the instance token so subsequent API calls use the new token.
		$this->token = $response->access_token;

		// Update refresh token if a new one is provided.
		if ( isset( $response->refresh_token ) ) {
			wp_fusion()->settings->set( 'kit_refresh_token', $response->refresh_token );
		}

		wpf_log( 'notice', 0, 'Kit access token successfully refreshed.' );

		return $response->access_token;
	}

	/**
	 * Perform initial sync once connection is configured.
	 *
	 * @since  3.47.2
	 *
	 * @return bool True on success.
	 */
	public function sync() {
		$this->sync_tags();
		$this->sync_crm_fields();
		do_action( 'wpf_sync' );
		return true;
	}

	/**
	 * Creates a new tag in Kit and returns the ID.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $tag_name The tag name.
	 * @return int|WP_Error The tag ID or WP_Error.
	 */
	public function add_tag( $tag_name ) {
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'name' => $tag_name ) );

		$response = wp_remote_post( 'https://api.kit.com/v4/tags', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// V4 nests tag under 'tag' attribute per upgrade guide.
		if ( isset( $body->tag->id ) ) {
			return $body->tag->id;
		}

		return new WP_Error( 'error', 'Failed to create tag.' );
	}

	/**
	 * Sync tags and forms from Kit.
	 *
	 * Forms can be used to re-subscribe unsubscribed users.
	 *
	 * @since  3.47.2
	 *
	 * @return array|WP_Error Array of available tags and forms or WP_Error.
	 */
	public function sync_tags() {
		$available = array();

		// Tags.
		$resp = wp_remote_get( 'https://api.kit.com/v4/tags', $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$result = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( isset( $result->tags ) && is_array( $result->tags ) ) {
			foreach ( $result->tags as $tag ) {
				if ( isset( $tag->id ) && isset( $tag->name ) ) {
					$available[ $tag->id ] = array(
						'label'    => $tag->name,
						'category' => 'Tags',
					);
				}
			}
		}

		// Forms - used for re-subscribing unsubscribed users.
		$resp = wp_remote_get( 'https://api.kit.com/v4/forms', $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$result = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( isset( $result->forms ) && is_array( $result->forms ) ) {
			foreach ( $result->forms as $form ) {
				if ( isset( $form->id ) && isset( $form->name ) ) {
					$available[ 'form_' . $form->id ] = array(
						'label'    => $form->name,
						'category' => 'Forms',
					);
				}
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available );
		return $available;
	}

	/**
	 * Sync custom fields from Kit.
	 *
	 * Loads fields from first subscriber as v4 API doesn't expose a dedicated fields endpoint.
	 *
	 * @since  3.47.2
	 *
	 * @return array|WP_Error Array of available fields or WP_Error.
	 */
	public function sync_crm_fields() {
		$crm_fields = array(
			'first_name'    => 'First Name',
			'email_address' => 'Email',
		);

		$resp = wp_remote_get( 'https://api.kit.com/v4/subscribers', $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$res = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( isset( $res->subscribers ) && is_array( $res->subscribers ) && ! empty( $res->subscribers ) ) {
			$sample = $res->subscribers[0];
			if ( isset( $sample->fields ) && is_object( $sample->fields ) ) {
				foreach ( $sample->fields as $key => $val ) {
					$crm_fields[ $key ] = ucwords( str_replace( '_', ' ', $key ) );
				}
			}
		}

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );
		return $crm_fields;
	}

	/**
	 * Get contact ID by email address.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|bool The contact ID or false if not found.
	 */
	public function get_contact_id( $email_address ) {
		$url  = 'https://api.kit.com/v4/subscribers?email_address=' . rawurlencode( $email_address ) . '&status=all';
		$resp = wp_remote_get( $url, $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$res = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( empty( $res ) || empty( $res->subscribers ) || ! is_array( $res->subscribers ) ) {
			return false;
		}
		return $res->subscribers[0]->id;
	}

	/**
	 * Get all tags currently applied to a contact.
	 *
	 * @since  3.47.2
	 *
	 * @param  int $contact_id The contact ID.
	 * @return array|WP_Error Array of tag IDs or WP_Error.
	 */
	public function get_tags( $contact_id ) {
		$contact_tags = array();
		$resp         = wp_remote_get( 'https://api.kit.com/v4/subscribers/' . $contact_id . '/tags', $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( isset( $body->tags ) && is_array( $body->tags ) ) {
			foreach ( $body->tags as $t ) {
				if ( isset( $t->id ) ) {
					$contact_tags[] = $t->id;
				}
			}
		}
		return $contact_tags;
	}

	/**
	 * Apply tags to a contact using bulk endpoint.
	 *
	 * @since  3.47.2
	 *
	 * @param  array $tags       Array of tag IDs to apply.
	 * @param  int   $contact_id The contact ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function apply_tags( $tags, $contact_id ) {
		// Separate form_ tags from regular tags.
		// Forms can re-subscribe unsubscribed users in Kit.
		$form_ids = array();
		$tag_ids  = array();

		foreach ( $tags as $tag ) {
			if ( 0 === strpos( (string) $tag, 'form_' ) ) {
				$form_ids[] = str_replace( 'form_', '', $tag );
			} else {
				$tag_ids[] = $tag;
			}
		}

		// Apply tags using bulk endpoint.
		if ( ! empty( $tag_ids ) ) {
			$taggings = array();
			foreach ( $tag_ids as $tag_id ) {
				$taggings[] = array(
					'tag_id'        => intval( $tag_id ),
					'subscriber_id' => intval( $contact_id ),
				);
			}

			$params         = $this->get_params();
			$params['body'] = wp_json_encode( array( 'taggings' => $taggings ) );

			$response = wp_remote_post( 'https://api.kit.com/v4/bulk/tags/subscribers', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Handle partial failures.
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			if ( isset( $body->failures ) && ! empty( $body->failures ) ) {
				foreach ( $body->failures as $failure ) {
					$errors = isset( $failure->errors ) ? implode( ', ', $failure->errors ) : 'Unknown error';
					wpf_log( 'notice', wpf_get_user_id( $contact_id ), 'Failed to apply tag: ' . $errors );
				}
			}
		}

		// Add subscriber to forms using bulk endpoint.
		// This can re-subscribe unsubscribed users.
		if ( ! empty( $form_ids ) ) {
			$additions = array();
			foreach ( $form_ids as $form_id ) {
				$additions[] = array(
					'form_id'       => intval( $form_id ),
					'subscriber_id' => intval( $contact_id ),
				);
			}

			$params         = $this->get_params();
			$params['body'] = wp_json_encode( array( 'additions' => $additions ) );

			$response = wp_remote_post( 'https://api.kit.com/v4/bulk/forms/subscribers', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Handle partial failures.
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			if ( isset( $body->failures ) && ! empty( $body->failures ) ) {
				foreach ( $body->failures as $failure ) {
					$errors = isset( $failure->errors ) ? implode( ', ', $failure->errors ) : 'Unknown error';
					wpf_log( 'notice', wpf_get_user_id( $contact_id ), 'Failed to add to form: ' . $errors );
				}
			}
		}

		return true;
	}

	/**
	 * Remove tags from a contact using bulk endpoint.
	 *
	 * @since  3.47.2
	 *
	 * @param  array $tags       Array of tag IDs to remove.
	 * @param  int   $contact_id The contact ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function remove_tags( $tags, $contact_id ) {
		// Build taggings array for bulk operation.
		$taggings = array();
		foreach ( $tags as $tag_id ) {
			$taggings[] = array(
				'tag_id'        => intval( $tag_id ),
				'subscriber_id' => intval( $contact_id ),
			);
		}

		$params           = $this->get_params();
		$params['method'] = 'DELETE';
		$params['body']   = wp_json_encode( array( 'taggings' => $taggings ) );

		$response = wp_remote_request( 'https://api.kit.com/v4/bulk/tags/subscribers', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Add a new contact to Kit.
	 *
	 * @since  3.47.2
	 *
	 * @param  array $data The contact data.
	 * @return int|WP_Error The contact ID on success, WP_Error on failure.
	 */
	public function add_contact( $data ) {
		$post = array();
		if ( isset( $data['first_name'] ) ) {
			$post['first_name'] = $data['first_name'];
			unset( $data['first_name'] );
		}
		if ( isset( $data['email_address'] ) ) {
			$post['email_address'] = $data['email_address'];
			unset( $data['email_address'] );
		}
		$post['fields'] = $data;

		$params           = $this->get_params();
		$params['method'] = 'POST';
		$params['body']   = wp_json_encode( $post );
		$response         = wp_remote_request( 'https://api.kit.com/v4/subscribers', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $body->subscriber ) && isset( $body->subscriber->id ) ) {
			return $body->subscriber->id;
		}
		return new WP_Error( 'error', 'Unexpected response adding subscriber.' );
	}

	/**
	 * Update a contact.
	 *
	 * @since  3.47.2
	 *
	 * @param  int   $contact_id The contact ID.
	 * @param  array $data       The contact data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_contact( $contact_id, $data ) {
		$post = array();
		if ( isset( $data['first_name'] ) ) {
			$post['first_name'] = $data['first_name'];
			unset( $data['first_name'] );
		}
		if ( isset( $data['email_address'] ) ) {
			$post['email_address'] = $data['email_address'];
			unset( $data['email_address'] );
		}
		$post['fields'] = $data;

		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = wp_json_encode( $post );
		$resp             = wp_remote_request( 'https://api.kit.com/v4/subscribers/' . $contact_id, $params );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		return true;
	}

	/**
	 * Load a contact from Kit and return mapped user meta.
	 *
	 * @since  3.47.2
	 *
	 * @param  int $contact_id The contact ID to load.
	 * @return array|WP_Error User meta data or WP_Error.
	 */
	public function load_contact( $contact_id ) {
		$resp = wp_remote_get( 'https://api.kit.com/v4/subscribers/' . $contact_id . '?status=all', $this->get_params() );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$res = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( ! isset( $res->subscriber ) ) {
			return new WP_Error( 'notice', 'No contact #' . $contact_id . ' found in Kit.' );
		}
		$returned = array(
			'first_name'    => isset( $res->subscriber->first_name ) ? $res->subscriber->first_name : '',
			'email_address' => isset( $res->subscriber->email_address ) ? $res->subscriber->email_address : '',
		);
		if ( isset( $res->subscriber->fields ) && is_object( $res->subscriber->fields ) ) {
			foreach ( $res->subscriber->fields as $k => $v ) {
				$returned[ $k ] = $v;
			}
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( ! empty( $field_data['active'] ) && isset( $field_data['crm_field'] ) && isset( $returned[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $returned[ $field_data['crm_field'] ];
			}
		}
		return $user_meta;
	}

	/**
	 * Load contacts by tag using cursor-based pagination.
	 *
	 * @since  3.47.2
	 *
	 * @param  string $tag The tag ID to load contacts for.
	 * @return array|WP_Error Array of contact IDs or WP_Error.
	 */
	public function load_contacts( $tag ) {
		$ids     = array();
		$cursor  = null;
		$proceed = true;

		while ( $proceed ) {
			$url = 'https://api.kit.com/v4/tags/' . $tag . '/subscribers';

			if ( ! empty( $cursor ) ) {
				$url .= '?after=' . rawurlencode( $cursor );
			}

			$resp = wp_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $resp ) ) {
				return $resp;
			}

			$res = json_decode( wp_remote_retrieve_body( $resp ) );

			if ( isset( $res->subscribers ) && is_array( $res->subscribers ) && ! empty( $res->subscribers ) ) {
				foreach ( $res->subscribers as $sub ) {
					if ( isset( $sub->id ) ) {
						$ids[] = $sub->id;
					} elseif ( isset( $sub->subscriber->id ) ) {
						$ids[] = $sub->subscriber->id;
					}
				}
			}

			// Check for next cursor to continue pagination.
			if ( isset( $res->pagination->next_cursor ) && ! empty( $res->pagination->next_cursor ) ) {
				$cursor = $res->pagination->next_cursor;
			} else {
				$proceed = false;
			}
		}

		return $ids;
	}

	/**
	 * Register a webhook with Kit.
	 *
	 * @since  3.47.2
	 *
	 * @param  string   $type The webhook type (add, update, unsubscribe).
	 * @param  int|bool $tag  The tag ID for tag-based webhooks.
	 * @return int|WP_Error Webhook ID on success, WP_Error on failure.
	 */
	public function register_webhook( $type, $tag ) {
		$access_key = wpf_get_option( 'access_key' );

		if ( empty( $access_key ) ) {
			return false;
		}

		// Determine event name based on type.
		$event_name = 'subscriber.tag_add';
		if ( 'update' === $type ) {
			$event_name = 'subscriber.tag_add';
		} elseif ( 'unsubscribe' === $type ) {
			$event_name = 'subscriber.subscriber_unsubscribe';
		}

		$data = array(
			'target_url' => get_home_url() . '/?wpf_action=' . $type . '&access_key=' . $access_key,
			'event'      => array(
				'name' => $event_name,
			),
		);

		// Add tag_id for tag-based events.
		if ( ! empty( $tag ) && 'unsubscribe' !== $type ) {
			$data['event']['tag_id'] = intval( $tag );
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_remote_post( 'https://api.kit.com/v4/webhooks', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		// V4 returns webhook object instead of rule.
		if ( isset( $body->webhook->id ) ) {
			return $body->webhook->id;
		}

		return false;
	}

	/**
	 * Destroy a webhook in Kit.
	 *
	 * @since  3.47.2
	 *
	 * @param  int $webhook_id The webhook ID to destroy.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function destroy_webhook( $webhook_id ) {
		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		$response = wp_remote_request( 'https://api.kit.com/v4/webhooks/' . $webhook_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// V4 returns 204 empty response on success.
		return true;
	}
}
