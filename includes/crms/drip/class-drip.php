<?php

/**
 * WP Fusion - Drip CRM Integration
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.46.10
 */

/**
 * Drip CRM integration class.
 *
 * Handles all communication with the Drip API including contact management,
 * tag operations, and event tracking.
 *
 * Note: When someone unsubscribes from Drip:
 * - get_contact_id() will return Not Found unless the person has been reactivated
 * - update_contact() will work but will return the "status" indicating they're unsubscribed
 * - apply_tags() does work
 *
 * @since 3.46.10
 */
class WPF_Drip {

	/**
	 * The CRM slug.
	 *
	 * @since 3.46.10
	 * @var   string
	 */
	public $slug = 'drip';

	/**
	 * The CRM name.
	 *
	 * @since 3.46.10
	 * @var   string
	 */
	public $name = 'Drip';

	/**
	 * Drip API instance for direct access, bypassing WP Fusion.
	 *
	 * @since 3.46.10
	 * @var   Drip_API
	 */
	public $app;

	/**
	 * API parameters for v3 API methods.
	 *
	 * @since 3.46.10
	 * @var   array
	 */
	public $params;

	/**
	 * Drip Account ID used for API queries.
	 *
	 * @since 3.46.10
	 * @var   string
	 */
	public $account_id;

	/**
	 * Features supported by this CRM.
	 *
	 * @since 3.46.10
	 * @var   array
	 */
	public $supports = array( 'add_tags', 'add_fields', 'events', 'events_multi_key' );

	/**
	 * Regex pattern for validating event property keys.
	 *
	 * Drip supports alphanumeric characters, spaces, underscores, and hyphens
	 * in event property keys. Keys are case-sensitive.
	 *
	 * @since 3.46.10
	 * @var   string
	 */
	public $event_key_format = '/^[a-zA-Z0-9 _-]+$/';

	/**
	 * URL template for editing contact records in Drip.
	 *
	 * @since 3.37.30
	 * @var   string
	 */
	public $edit_url = '';

	/**
	 * Constructor - Initialize the Drip CRM integration.
	 *
	 * Sets up admin interface if running in WordPress admin.
	 *
	 * @since 2.0
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_Drip_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * Initializes filters and actions for Drip-specific functionality
	 * including field formatting, HTTP response handling, and tracking.
	 *
	 * @since 3.46.10
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// HTTP response filter for API calls outside the SDK.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Slow down the batch processes to get around the 3600 requests per hour limit.
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		// Add tracking code to footer.
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

		$this->account_id = wpf_get_option( 'drip_account' );

		if ( $this->account_id ) {
			$this->edit_url = 'https://www.getdrip.com/' . $this->account_id . '/subscribers/%s';
		}
	}

	/**
	 * Formats any custom user entered field IDs for the Drip API.
	 *
	 * @since  3.38.41
	 *
	 * @param  string $field  The field name.
	 * @return string The formatted field name.
	 */
	public function format_custom_field_key( $field ) {

		$field = str_replace( ' ', '_', $field );

		return str_replace( '-', '_', $field );
	}

	/**
	 * Slow down batch processes to get around the 3600 requests per hour limit.
	 *
	 * Drip has a rate limit of 3600 requests per hour, so we need to add
	 * a delay between batch operations to prevent hitting the limit.
	 *
	 * @since  3.46.10
	 *
	 * @param  int $seconds The default sleep time.
	 * @return int Sleep time in seconds.
	 */
	public function set_sleep_time( $seconds ) {

		return 2;
	}


	/**
	 * Formats user entered data to match Drip field formats.
	 *
	 * Handles special formatting for date fields and string values to ensure
	 * compatibility with Drip's API requirements.
	 *
	 * @since  3.46.10
	 *
	 * @param  mixed  $value      The field value to format.
	 * @param  string $field_type The type of field being formatted.
	 * @param  string $field      The field name.
	 * @return mixed  The formatted field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( ( 'datepicker' === $field_type || 'date' === $field_type ) && ! empty( $value ) ) {

			// Adjust formatting for date fields.
			$date = gmdate( 'm/d/Y', $value );

			return $date;

		} elseif ( ! empty( $value ) && is_string( $value ) ) {

			return stripslashes( $value );

		} else {
			return $value;
		}
	}

	/**
	 * Get API params for v3 API calls
	 *
	 * @access public
	 * @return array Params
	 */
	public function get_params() {

		$api_token = wpf_get_option( 'drip_token' );

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $api_token ),
				'Content-Type'  => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * Filters HTTP responses from Drip API calls to detect and handle
	 * error conditions, converting them to WP_Error objects.
	 *
	 * @since  3.46.10
	 *
	 * @param  array|WP_Error $response HTTP response or WP_Error object.
	 * @param  array          $args     HTTP request arguments.
	 * @param  string         $url      The request URL.
	 * @return array|WP_Error HTTP response on success, WP_Error on API error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( false !== strpos( $url, 'api.getdrip.com' ) ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0]->message );

			} elseif ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			}
		}

		return $response;
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$drip_payload = json_decode( file_get_contents( 'php://input' ) );

		if ( isset( $drip_payload->event ) && ( 'subscriber.applied_tag' === $drip_payload->event || 'subscriber.removed_tag' === $drip_payload->event || 'subscriber.updated_custom_field' === $drip_payload->event || 'subscriber.updated_email_address' === $drip_payload->event ) ) {

			// Admin settings webhooks.
			$post_data['contact_id'] = sanitize_key( $drip_payload->data->subscriber->id );
			$post_data['tags']       = array_map(
				'sanitize_text_field',
				$drip_payload->data->subscriber->tags ?? array()
			);

			return $post_data;

		} elseif ( isset( $drip_payload->subscriber ) ) {

			// Automations / rules triggers.
			$post_data['contact_id'] = sanitize_key( $drip_payload->subscriber->id );
			$post_data['tags']       = array_map(
				'sanitize_text_field',
				$drip_payload->subscriber->tags ?? array()
			);

			return $post_data;

		} else {
			wp_die( 'Unsupported method', 'Success', 200 );
		}
	}

	/**
	 * Output tracking code.
	 *
	 * @deprecated 3.40.40
	 *
	 * @return mixed HTML and JavaScript output.
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		// Stop Drip messing with WooCommerce account page (sending email changes automatically)
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}

		echo '<!-- Drip (via WP Fusion) -->';
		echo '<script type="text/javascript">';
		echo 'var _dcq = _dcq || [];';
		echo 'var _dcs = _dcs || {};';
		echo "_dcs.account = '" . esc_js( $this->account_id ) . "';";

		echo '(function() {';
		echo "var dc = document.createElement('script');";
		echo "dc.type = 'text/javascript'; dc.async = true;";
		echo "dc.src = '//tag.getdrip.com/" . esc_js( $this->account_id ) . ".js';";
		echo "var s = document.getElementsByTagName('script')[0];";
		echo 's.parentNode.insertBefore(dc, s);';
		echo '})();';

		// Identify visitor

		if ( wpf_is_user_logged_in() && ! empty( wp_fusion()->user->get_contact_id() ) ) {

			$userdata = wp_get_current_user();

			if ( empty( $userdata ) && doing_wpf_auto_login() ) {

				$user_email = get_user_meta( wpf_get_current_user_id(), 'user_email', true );

			} else {

				$user_email = $userdata->user_email;

			}

			// Check to see if we need to set tracking cookies

			$found = false;

			foreach ( $_COOKIE as $key => $value ) {

				if ( strpos( $key, 'drip_client' ) !== false ) {
					$found = true;
					break;
				}
			}

			if ( false === $found ) {

				echo '_dcq.push(["identify", {';
				echo 'email: "' . esc_js( $user_email ) . '",';
				echo 'success: function(response) {}';
				echo '}]);';

			}
		}

		echo '</script>';
		echo '<!-- end Drip -->';
	}


	/**
	 * Initialize connection to Drip API.
	 *
	 * Sets up the Drip API client and validates account access if testing.
	 * If the API client is already initialized, returns true immediately.
	 *
	 * @since  3.46.10
	 *
	 * @param  string $api_token  The Drip API token.
	 * @param  string $account_id The Drip account ID.
	 * @param  bool   $test       Whether to test the connection.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function connect( $api_token = null, $account_id = null, $test = false ) {

		// If app is already running, don't try and restart it.
		if ( is_object( $this->app ) ) {
			return true;
		}

		if ( empty( $api_token ) || empty( $account_id ) ) {
			$api_token  = wpf_get_option( 'drip_token' );
			$account_id = wpf_get_option( 'drip_account' );
		}

		if ( ! class_exists( 'Drip_API' ) ) {
			require_once __DIR__ . '/includes/Drip_API.class.php';
		}

		$app = new Drip_API( $api_token );

		if ( true === $test ) {

			$accounts = $app->get_accounts();

			if ( is_wp_error( $accounts ) ) {
				return $accounts;
			}

			$valid_id = false;

			if ( ! empty( $accounts ) ) {
				foreach ( $accounts as $account ) {
					if ( strval( $account['id'] ) === strval( $account_id ) ) {
						$valid_id = true;
					}
				}
			}

			if ( false === $valid_id ) {
				return new WP_Error( 'error', __( 'Access denied: Your API token doesn\'t have access to this account.', 'wp-fusion-lite' ) );
			}
		}

		$this->account_id = $account_id;
		$this->app        = $app;

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$url      = 'https://api.getdrip.com/v2/' . $this->account_id . '/tags/';
		$response = $this->app->make_request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( $response['buffer'] );

		$available_tags = array();

		if ( ! empty( $response->tags ) ) {
			foreach ( $response->tags as $tag ) {
				$available_tags[ $tag ] = $tag;
			}
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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		// Load built in fields first.
		require __DIR__ . '/admin/drip-fields.php';

		$built_in_fields = array();

		foreach ( $drip_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Custom fields.

		$url      = 'https://api.getdrip.com/v2/' . $this->account_id . '/custom_field_identifiers/';
		$response = $this->app->make_request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( $response['buffer'] );

		$crm_fields = array();

		if ( ! empty( $response->custom_field_identifiers ) ) {
			foreach ( $response->custom_field_identifiers as $field_id ) {

				if ( ! isset( $built_in_fields[ $field_id ] ) ) {
					$crm_fields[ $field_id ] = $field_id;
				}
			}
		}

		asort( $crm_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $crm_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * Searches Drip for a subscriber with the given email address and returns
	 * their contact ID. Also clears any inactive status if the contact is found.
	 *
	 * @since  3.46.10
	 *
	 * @param  string $email_address The email address to search for.
	 * @return string|false|WP_Error Contact ID on success, false if not found, WP_Error on API error.
	 */
	public function get_contact_id( $email_address ) {

		$this->connect();

		$result = $this->app->fetch_subscriber(
			array(
				'account_id' => $this->account_id,
				'email'      => $email_address,
			)
		);

		if ( is_wp_error( $result ) ) {

			if ( 'The resource you requested was not found' === $result->get_error_message() ) {

				// If no contact with that email.
				return false;

			} else {

				return $result;

			}
		}

		if ( empty( $result ) || ! isset( $result['id'] ) ) {
			return false;
		}

		// If the lookup worked then they aren't inactive.
		$user = get_user_by( 'email', $email_address );

		if ( $user ) {
			delete_user_meta( $user->ID, 'drip_inactive' );
		}

		return $result['id'];
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return array Tags
	 */
	public function get_tags( $contact_id ) {

		$this->connect();

		$result = $this->app->fetch_subscriber(
			array(
				'account_id'    => $this->account_id,
				'subscriber_id' => $contact_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) || empty( $result['tags'] ) ) {
			return array();
		}

		// Set available tags
		$available_tags = wpf_get_option( 'available_tags' );

		if ( ! is_array( $available_tags ) ) {
			$available_tags = array();
		}

		foreach ( $result['tags'] as $tag ) {

			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $result['tags'];
	}

	/**
	 * Applies tags to a contact.
	 *
	 * Adds one or more tags to a Drip subscriber. Tags are applied individually
	 * through separate API calls to ensure proper error handling.
	 *
	 * @since  3.46.10
	 *
	 * @param  array  $tags       Array of tag names to apply.
	 * @param  string $contact_id The Drip contact ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$email = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( is_wp_error( $email ) || false === $email ) {
			return $email;
		}

		$params = $this->get_params();

		foreach ( $tags as $tag ) {

			$data = array(
				'tags' => array(
					array(
						'email' => $email,
						'tag'   => $tag,
					),
				),
			);

			$params['body'] = wp_json_encode( $data );

			$result = wp_safe_remote_post( "https://api.getdrip.com/v2/{$this->account_id}/tags", $params );

			if ( is_wp_error( $result ) ) {
				return $result;
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

		$email = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( is_wp_error( $email ) || false === $email ) {
			return $email;
		}

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag ) {

			$data = array(
				'tags' => array(
					array(
						'email' => $email,
						'tag'   => $tag,
					),
				),
			);

			$params['body'] = wp_json_encode( $data );

			$result = wp_safe_remote_request( "https://api.getdrip.com/v2/{$this->account_id}/tags", $params );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}


	/**
	 * Adds a new contact to Drip.
	 *
	 * Creates a new subscriber in Drip with the provided contact data.
	 * Email is required and is handled separately from custom fields.
	 *
	 * @since  3.46.10
	 *
	 * @param  array $data Contact data including email and custom fields.
	 * @return string|WP_Error Contact ID on success, WP_Error on failure.
	 */
	public function add_contact( $data ) {

		$this->connect();

		$email = $data['email'];
		unset( $data['email'] );

		// Fixes user entered field key formats to avoid 422 errors.
		foreach ( $data as $key => $value ) {

			$newkey = $this->format_custom_field_key( $key );

			if ( $key !== $newkey ) {
				unset( $data[ $key ] );
				$data[ $newkey ] = $value;
			}
		}

		$params = array(
			'account_id'    => $this->account_id,
			'email'         => $email,
			'custom_fields' => $data,
		);

		$result = $this->app->create_or_update_subscriber( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['id'];
	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */
	public function update_contact( $contact_id, $data ) {

		$this->connect();

		if ( isset( $data['email'] ) ) {
			$provided_email = $data['email'];
			unset( $data['email'] );
		}

		// Fixes user entered field key formats to avoid 422 errors.

		foreach ( $data as $key => $value ) {

			$newkey = $this->format_custom_field_key( $key );

			if ( $key !== $newkey ) {
				unset( $data[ $key ] );
				$data[ $newkey ] = $value;
			}
		}

		$params = array(
			'account_id'    => $this->account_id,
			'id'            => $contact_id,
			'custom_fields' => $data,
		);

		if ( isset( $data['status'] ) ) {

			if ( true === $data['status'] ) {
				$data['status'] = 'active';
			} elseif ( null === $data['status'] ) {
				$data['status'] = 'unsubscribed';
			}

			if ( 'active' == $data['status'] || 'unsubscribed' == $data['status'] ) {

				$params['status'] = $data['status'];
				unset( $params['custom_fields']['status'] );

			} else {

				wpf_log( 'notice', 0, $data['status'] . ' is not a valid status. Status must be either <strong>active</strong> or <strong>unsubscribed</strong>.', array( 'source' => 'drip' ) );

			}
		}

		// Maybe update optin status

		$result = $this->app->create_or_update_subscriber( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		if ( ! empty( $result['status'] ) && 'active' !== $result['status'] ) {
			// translators: %s is the documentation URL
			wpf_log( 'notice', $user_id, sprintf( __( 'Person has unsubscribed from marketing. Updates may not have been saved. For more information, see <a href="%s" target="_blank">our documentation</a>.', 'wp-fusion-lite' ), 'https://wpfusion.com/documentation/crm-specific-docs/inactive-people-in-drip/' ), array( 'source' => 'drip' ) );

			if ( ! empty( $user_id ) ) {
				update_user_meta( $user_id, 'drip_inactive', true );
			}
		} elseif ( ! empty( $user_id ) ) {

			// If they were inactive.
			delete_user_meta( $user_id, 'drip_inactive' );
		}

		// Check if we need to change the email address
		if ( isset( $provided_email ) && strtolower( $result['email'] ) != strtolower( $provided_email ) ) {

			$old_email           = $result['email'];
			$params['new_email'] = $provided_email;

			$result = $this->app->create_or_update_subscriber( $params );

			if ( is_wp_error( $result ) ) {

				// This isn't a serious error so we'll ignore it.
				if ( strpos( $result->get_error_message(), 'New email is already subscribed' ) !== false || strpos( $result->get_error_message(), 'Unprocessable Entity' ) !== false ) {
					wpf_log( 'notice', wpf_get_current_user_id(), 'Failed to update subscriber email address from ' . $old_email . ' to ' . $params['new_email'] . ', because there is already a subscriber with the new email address. This can usually be ignored, but you may want to consider manually merging the duplicate subscribers in Drip.' );
					return true;
				}

				return new WP_Error( 'error', 'Failed to update subscriber email address from ' . $old_email . ' to ' . $params['new_email'] . ': ' . $result->get_error_message() );
			}

			if ( wpf_get_option( 'email_change_event' ) == true ) {

				$params = array(
					'account_id' => $this->account_id,
					'id'         => $contact_id,
					'action'     => 'Email Changed',
				);

				$this->app->record_event( $params );

			}
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

		$this->connect();

		$result = $this->app->fetch_subscriber(
			array(
				'account_id'    => $this->account_id,
				'subscriber_id' => $contact_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return false;
		}

		$contact_fields = wpf_get_option( 'contact_fields' );
		$user_meta      = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( empty( $field_data['crm_field'] ) ) {
				continue;
			}

			// Fix formatting differences between user entered fields and those stored in Drip.
			$field_data['crm_field'] = $this->format_custom_field_key( $field_data['crm_field'] );

			if ( true === $field_data['active'] && isset( $result[ $field_data['crm_field'] ] ) ) {

				$user_meta[ $field_id ] = $result[ $field_data['crm_field'] ];

			} elseif ( true === $field_data['active'] && isset( $result['custom_fields'][ $field_data['crm_field'] ] ) ) {

				$user_meta[ $field_id ] = $result['custom_fields'][ $field_data['crm_field'] ];

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
	public function load_contacts( $tag = false ) {

		$this->connect();

		$contact_ids = array();
		$page        = 1;
		$continue    = true;

		// Load all subscribers.

		while ( $continue ) {
			$url = 'https://api.getdrip.com/v2/' . $this->account_id . '/subscribers/?status=all&per_page=1000&page=' . $page;

			if ( $tag ) {
				$url = add_query_arg( 'tags', rawurlencode( $tag ), $url );
			}

			$result = $this->app->make_request( $url );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = json_decode( $result['buffer'] );

			if ( ! empty( $result->subscribers ) ) {

				foreach ( $result->subscribers as $subscriber ) {
					$contact_ids[] = $subscriber->id;
				}
			}

			if ( count( $result->subscribers ) < 1000 ) {
				$continue = false;
			} else {
				++$page;
			}
		}

		return $contact_ids;
	}

	/**
	 * Track event.
	 *
	 * Track an event with the Drip site tracking API.
	 *
	 * @since  3.38.16
	 *
	 * @param  string       $event      The event title.
	 * @param  string|array $event_data The event data.
	 * @param  bool|string  $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = '', $email_address = false ) {

		if ( empty( $email_address ) && ! wpf_is_user_logged_in() ) {
			// Tracking only works if WP Fusion knows who the contact is.
			return;
		}

		$this->connect();

		// Get the email address to track.
		if ( empty( $email_address ) ) {
			$user          = wpf_get_current_user();
			$email_address = $user->user_email;
		}

		$data = array(
			'account_id' => $this->account_id,
			'action'     => $event,
			'email'      => $email_address,
			'properties' => array( 'data' => $event_data ),
		);

		if ( is_array( $event_data ) ) {
			$data['properties'] = $event_data; // multi-key data.
		}

		$result = $this->app->record_event( $data );

		if ( is_wp_error( $result ) ) {
			wpf_log( 'error', 0, 'Error tracking event: ' . $result->get_error_message() );
			return $result;
		}

		return true;
	}
}
