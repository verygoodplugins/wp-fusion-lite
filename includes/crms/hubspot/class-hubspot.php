<?php
/**
 * WP Fusion - HubSpot CRM integration.
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since unknown
 */

/**
 * HubSpot CRM integration.
 *
 * @package WP Fusion
 * @since   unknown
 */
class WPF_HubSpot {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'hubspot';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'HubSpot';

	/**
	 * Lets pluggable functions know which features are supported by the CRM.
	 *
	 * @var array
	 */
	public $supports = array( 'events', 'add_tags_api', 'auto_oauth' );

	/**
	 * Contains API params.
	 *
	 * @var array
	 */
	public $params;

	/**
	 * HubSpot OAuth client ID.
	 *
	 * @var string
	 */
	public $client_id = '959bd865-5a24-4a43-a8bf-05a69c537938';

	/**
	 * HubSpot OAuth client secret.
	 *
	 * @var string
	 */
	public $client_secret = '56cc5735-c274-4e43-99d4-3660d816a624';

	/**
	 * HubSpot app ID.
	 *
	 * @var int
	 */
	public $app_id = 180159;

	/**
	 * Allows text override for CRMs using alternate segmentation labels.
	 *
	 * @var string
	 */
	public $tag_type = 'List';


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */
	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @since   2.0
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_HubSpot_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// This has to run before init to be ready for WPF_Auto_Login::start_auto_login().
		add_filter( 'wpf_auto_login_contact_id', array( $this, 'auto_login_contact_id' ) );

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			$this->tag_type = 'Tag';
		}
	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @since  unknown
	 *
	 * @return void
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 4 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Add tracking code to header.
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

		// Slow down the batch processses to get around the 100 requests per 10s limit.
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		add_action( 'wpf_guest_contact_updated', array( $this, 'guest_checkout_complete' ), 10, 2 );
		add_action( 'wpf_guest_contact_created', array( $this, 'guest_checkout_complete' ), 10, 2 );

		$trackid = wpf_get_option( 'site_tracking_id' );

		if ( ! empty( $trackid ) && ! is_wp_error( $trackid ) ) {
			$this->edit_url = 'https://app.hubspot.com/contacts/' . $trackid . '/contact/%d/';
		}

		// Resolve list API mode and enforce cutoff for list-based segmentation.
		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			$this->cleanup_lists_api_state_for_multiselect();
		} else {
			$this->get_lists_api_mode();
		}
	}

	/**
	 * Slow down the batch processses to get around the 100 requests per 10s limit.
	 *
	 * @since  unknown
	 *
	 * @param int $seconds Sleep time.
	 * @return int Sleep time
	 */
	public function set_sleep_time( $seconds ) {
		$seconds = absint( $seconds );

		return 0 === $seconds ? 1 : $seconds;
	}


	/**
	 * Formats user entered data to match HubSpot field formats.
	 *
	 * @since 2.0
	 * @since 3.47.4 Added phone number formatting to E.164 format.
	 *
	 * @param mixed  $value       The field value.
	 * @param string $field_type  The field type.
	 * @param string $field       The CRM field name.
	 * @param array  $update_data The full array of data being sent to the CRM.
	 * @return mixed The formatted value.
	 */
	public function format_field_value( $value, $field_type, $field, $update_data = array() ) {

		if ( in_array( $field, wpf_get_option( 'read_only_fields', array() ), true ) ) {

			// Don't sync read only fields, they'll just throw an error anyway.

			return '';

		} elseif ( in_array( $field, array( 'phone', 'mobilephone' ), true ) && ! empty( $value ) ) {

			// Format phone numbers to E.164 format for HubSpot.

			// Determine the default country code (WooCommerce store base or 'US').
			$default_country = 'US';
			if ( function_exists( 'wc_get_base_location' ) ) {
				$base_location = wc_get_base_location();
				if ( ! empty( $base_location['country'] ) ) {
					$default_country = strtoupper( sanitize_text_field( $base_location['country'] ) );
				}
			}

			$country = $default_country;

			// Try to get country code from available fields in priority order.
			if ( ! empty( $update_data['country'] ) ) {
				$country = $update_data['country'];
			} elseif ( ! empty( $update_data['billing_country'] ) ) {
				$country = $update_data['billing_country'];
			}

			// Sanitize and validate the country code.
			$country = strtoupper( sanitize_text_field( $country ) );

			// Validate the country code is a 2-letter ISO alpha-2 code.
			if ( 2 !== strlen( $country ) || ! ctype_alpha( $country ) ) {
				$country = $default_country;
			}

			return wpf_phone_number_to_e164( $value, $country );

		} elseif ( 'date' === $field_type ) {
			/*
			 * Dates are in milliseconds since the epoch, so if the timestamp isn't
			 * already in ms we'll multiply by 1000 here. Can't use gmdate() because
			 * we need local time.
			 *
			 * See https://developers.hubspot.com/docs/api/faq.
			 *
			 * Date properties (including date picker properties created in HubSpot)
			 * store the date, not the time. Date properties display the date they're
			 * set to, regardless of the time zone setting of the account or user.
			 * For date property values, it is recommended to use the ISO 8601 complete
			 * date format.
			 *
			 * If you try to sync an ISO formatted date to a date field you get an error:
			 * Property values were not valid 2024-01-30T01:07:09 was not a valid long.
			 *
			 * We have to do the timezone conversion here because most dates coming into
			 * this function will be UTC (except Woo subs is currently local).
			 *
			 * Update 3.43.0. Dates coming into this should now always be in UTC.
			 */

			if ( ! empty( $value ) && is_numeric( $value ) && $value < 1000000000000 ) {

				if ( 0 !== $value % DAY_IN_SECONDS ) {
					// Not at midnight UTC, need to adjust.
					// Extract the date in local timezone (which is what the user intended).
					$date = new DateTime();
					$date->setTimestamp( $value );
					// Get Y-m-d from local timezone.
					$date_string = $date->format( 'Y-m-d' );

					// Create new DateTime at midnight UTC for that date.
					$date = new DateTime( $date_string . ' 00:00:00', new DateTimeZone( 'UTC' ) );

					$value = $date->getTimestamp();
				}

				// Check if it's within the valid range, otherwise we can get an invalid properties error.
				if ( $value > ( time() + 1000 * YEAR_IN_SECONDS ) || $value < ( time() - 1000 * YEAR_IN_SECONDS ) ) {
					return false;
				}

				$value = $value * 1000;
			}

			return $value;

		} elseif ( 'checkbox' === $field_type ) {

			// Handle string '0' and other falsy values that should be false.
			if ( '0' === $value || 0 === $value || false === $value || '' === $value || null === $value || ( is_array( $value ) && empty( $value ) ) ) {
				return 'false';
			}

			if ( ! empty( $value ) ) {
				// If checkbox is selected.
				return 'true';
			}

			// Default to false for empty values.
			return 'false';
		} elseif ( is_array( $value ) ) {

			return implode( ';', array_filter( $value ) );

		} else {

			return $value;

		}
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @since  unknown
	 *
	 * @param array $post_data The POST data.
	 * @return array
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) && isset( $payload->vid ) ) {
			$post_data['contact_id'] = absint( $payload->vid );
		} elseif ( ! empty( $payload ) && is_array( $payload ) ) {

			// Webhooks via private app.

			if ( 1 === count( $payload ) ) {
				$post_data['contact_id'] = absint( $payload[0]->{'objectId'} );
			} else {

				$contact_ids = array_unique( wp_list_pluck( $payload, 'objectId' ) );

				if ( 'add' !== $post_data['wpf_action'] ) {

					// If we're not importing, remove anyone who doesn't have a user record.

					foreach ( $contact_ids as $i => $contact_id ) {

						if ( ! wpf_get_user_id( $contact_id ) ) {
							unset( $contact_ids[ $i ] );
						}
					}
				}

				wpf_log( 'info', 0, 'Webhook received with <code>' . $post_data['wpf_action'] . '</code>. ' . count( $contact_ids ) . ' contact records detected in payload, creating background process.', array( 'source' => 'api' ) );

				wp_fusion()->batch->includes();
				wp_fusion()->batch->init();

				// Not needed for batch ops. This will preserve "role" and "send_notification".
				unset( $post_data['wpf_action'] );
				unset( $post_data['access_key'] );

				foreach ( $contact_ids as $contact_id ) {
					wp_fusion()->batch->process->push_to_queue( array( 'wpf_batch_import_users', array( $contact_id, $post_data ) ) );
				}

				wp_fusion()->batch->process->save()->dispatch();

				wp_die( '<h3>Success</h3> Started background operation for ' . count( $contact_ids ) . ' records.', 'Success', 200 );

			}
		}

		return $post_data;
	}

	/**
	 * Allows using an email address in the ?cid parameter
	 *
	 * @since  unknown
	 *
	 * @param string|int $contact_id Contact ID or email address.
	 * @return string Contact ID
	 */
	public function auto_login_contact_id( $contact_id ) {

		if ( is_email( $contact_id ) ) {
			$contact_id = $this->get_contact_id( urldecode( $contact_id ) );
		}

		return $contact_id;
	}

	/**
	 * Gets params for API calls
	 *
	 * @since  unknown
	 *
	 * @param string|null $access_token Access token override.
	 * @return  array Params
	 */
	public function get_params( $access_token = null ) {

		// Get saved data from DB.
		if ( empty( $access_token ) ) {
			$access_token = wpf_get_option( 'hubspot_token' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 15,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  string|WP_Error The token on success, error on failure.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( 'hubspot_refresh_token' );

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'       => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uri'  => apply_filters( 'wpf_hubspot_redirect_uri', 'https://wpfusion.com/oauth/?action=wpf_get_hubspot_token' ),
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $body_json ) ) {
			wpf_log( 'error', wpf_get_current_user_id(), 'HubSpot refresh token failed. Response was not valid JSON.' );
			return new WP_Error(
				'error',
				__( 'HubSpot token refresh failed. The response was not valid JSON.', 'wp-fusion-lite' )
			);
		}

		if ( ! isset( $body_json->access_token ) ) {
			$redacted = json_decode( wp_json_encode( $body_json ), true );

			if ( is_array( $redacted ) ) {
				foreach ( array( 'access_token', 'refresh_token', 'client_secret' ) as $key ) {
					if ( isset( $redacted[ $key ] ) ) {
						$redacted[ $key ] = '[redacted]';
					}
				}
			}

			wpf_log(
				'error',
				wpf_get_current_user_id(),
				'HubSpot refresh token failed. Access token missing. Response: ' .
					wpf_print_r( $redacted, true )
			);
			return new WP_Error(
				'error',
				__( 'HubSpot token refresh failed. Access token missing from response.', 'wp-fusion-lite' )
			);
		}

		$this->get_params( $body_json->access_token );

		wp_fusion()->settings->set( 'hubspot_token', $body_json->access_token );

		// Check if it saved.
		$options = get_option( 'wpf_options', array() );

		if ( $options['hubspot_token'] !== $body_json->access_token ) {
			wpf_log( 'error', wpf_get_current_user_id(), 'HubSpot refresh token failed. The token was not saved.' );
		}

		return $body_json->access_token;
	}

	/**
	 * Uninstalls the WP Fusion app from the customer's HubSpot account.
	 *
	 * @since x.x.x
	 *
	 * @see https://developers.hubspot.com/docs/api-reference/crm-app-uninstalls-v3/uninstall/delete-appinstalls-v3-external-install
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function uninstall_app() {

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		$response = wp_remote_request( 'https://api.hubapi.com/appinstalls/v3/external-install', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 204 !== $response_code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ) );

			$message = ( is_object( $body ) && ! empty( $body->message ) )
				? $body->message
				: __( 'Unknown error uninstalling HubSpot app.', 'wp-fusion-lite' );

			return new WP_Error(
				'error',
				$message
			);
		}

		return true;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @since  unknown
	 *
	 * @param array|WP_Error $response HTTP response or WP_Error.
	 * @param array          $args     HTTP request arguments.
	 * @param string         $url      Request URL.
	 * @return array|WP_Error HTTP response or WP_Error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'api.hubapi' ) !== false ) {

			$code      = wp_remote_retrieve_response_code( $response );
			$body_json = json_decode( wp_remote_retrieve_body( $response ) );
			$body_json = is_object( $body_json ) ? $body_json : (object) array();

			if ( 401 === $code ) {

				$message = isset( $body_json->message ) ? $body_json->message : '';

				if ( false !== strpos( $message, 'expired' ) ) {

					$access_token = $this->refresh_token();

					if ( is_wp_error( $access_token ) ) {
						return new WP_Error( 'error', 'Error refreshing access token: ' . $access_token->get_error_message() );
					}

					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_remote_request( $url, $args );

				} else {

					$response = new WP_Error(
						'error',
						'Invalid API credentials. <pre>' . wpf_print_r( $body_json, true ) . '</pre>'
					);

				}
			} elseif ( ( isset( $body_json->status ) && 'error' === $body_json->status ) || isset( $body_json->errorType ) || isset( $body_json->category ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				// Sometimes adding a contact throws an Already Exists error, not sure why. We'll just re-do it with an update and return the ID.

				if ( ( isset( $body_json->error ) && 'CONTACT_EXISTS' === $body_json->error ) || ( isset( $body_json->category ) && 'CONFLICT' === $body_json->category ) ) {

					$contact_id = false;

					if ( isset( $body_json->{'identityProfile'} ) && isset( $body_json->{'identityProfile'}->vid ) ) {
						$contact_id = $body_json->{'identityProfile'}->vid;
					} elseif ( isset( $body_json->context ) && isset( $body_json->context->id ) ) {
						$contact_id = $body_json->context->id;
					}

					if ( empty( $contact_id ) ) {
						return $response;
					}

					$args['method'] = 'PATCH';

					$response = wp_remote_request( 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id, $args );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					// Fake the response to make it look like we just added a contact.

					$response['body'] = wp_json_encode( array( 'id' => $contact_id ) );

					return $response;

				}

				$message = isset( $body_json->message ) ? $body_json->message : 'An unknown error occurred.';
				$code    = 'error';

				// Contextual help.

				if ( 'resource not found' === $message || 'contact does not exist' === $message ) {
					$code     = 'not_found';
					$message .= '.<br /><br />This error can mean that you\'ve deleted or merged a record in HubSpot, and then tried to update an ID that no longer exists. Clicking Resync Lists on the user\'s admin profile will clear out the cached invalid contact ID.';
				} elseif ( 'Can not operate manually on a dynamic list' === $message ) {
					$message .= '.<br /><br />' . __( 'This error means you tried to apply an Active list over the API. Only Static lists can be assigned over the API. For an overview of HubSpot lists, see <a href="https://knowledge.hubspot.com/lists/create-active-or-static-lists#types-of-lists" target="_blank">this documentation page</a>.', 'wp-fusion-lite' );
				} elseif ( isset( $body_json->category ) && 'MISSING_SCOPES' === $body_json->category ) {
					$message .= '<br /><br />' . __( 'This error means you\'re trying to access a feature that requires additional permissions. You can grant these permissions by clicking Reauthorize with HubSpot on the Setup tab in the WP Fusion settings.', 'wp-fusion-lite' );
				}

				if ( isset( $body_json->{'validationResults'} ) ) {

					$message .= '<ul>';
					foreach ( $body_json->{'validationResults'} as $result ) {
						$message .= '<li>' . $result->message . '</li>';
					}
					$message .= '</ul>';

				}

				if ( isset( $body_json->errors ) ) {

					$message .= '<pre>' . wpf_print_r( $body_json->errors, true ) . '</pre>';

				}

				$response = new WP_Error( $code, $message );

			}
		}

		return $response;
	}



	/**
	 * Initialize connection.
	 *
	 * @since  unknown
	 *
	 * @param string|null $access_token  Access token to test.
	 * @param string|null $refresh_token Refresh token to save.
	 * @param bool        $test          Whether to test the connection.
	 * @return  bool
	 */
	public function connect( $access_token = null, $refresh_token = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$request  = 'https://api.hubapi.com/integrations/v1/me';
		$response = wp_remote_get( $request, $this->get_params( $access_token ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Save tracking ID for later.

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'site_tracking_id', $body_json->{'portalId'} ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return true;
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */
	public function sync() {

		$this->connect();

		$this->sync_tags();
		$this->sync_crm_fields();
		$this->sync_owners();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array|WP_Error Lists or WP_Error.
	 */
	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Capture old tags to detect v1→v3 ID changes after sync.
		$old_available_tags = wpf_get_option( 'available_tags', array() );

		$tag_type       = wpf_get_option( 'hubspot_tag_type' );
		$lists_api_mode = 'v3';
		$available_tags = array();

		if ( 'multiselect' === $tag_type ) {
			$available_tags = $this->sync_tags_multiselect();
		} else {
			$lists_api_mode = $this->get_lists_api_mode();
			$available_tags = ( 'v1' === $lists_api_mode )
				? $this->sync_tags_v1()
				: $this->sync_tags_v3();
		}

		if ( is_wp_error( $available_tags ) ) {
			return $available_tags;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		// If IDs disappeared during sync, a v1→v3 migration may be needed.
		if ( ! wpf_get_option( 'wpf_hubspot_v3_migrated' )
			&& ! empty( $old_available_tags )
			&& 'multiselect' !== $tag_type
			&& 'v3' === $lists_api_mode
			&& array_diff( array_keys( $old_available_tags ), array_keys( $available_tags ) )
		) {
			update_option( 'wpf_hubspot_v3_migration_needed', true, false );
		}

		return $available_tags;
	}


	/**
	 * Gets the active HubSpot list API mode.
	 *
	 * @since 3.47.7
	 *
	 * @return string v1 or v3.
	 */
	public function get_lists_api_mode() {

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			return 'v3';
		}

		$stored_mode = wpf_get_option( 'wpf_hubspot_lists_api_mode', '' );

		if ( ! in_array( $stored_mode, array( 'v1', 'v3' ), true ) ) {
			$stored_mode = '';
		}

		$mode = $stored_mode;

		if ( empty( $mode ) ) {
			if ( wpf_get_option( 'wpf_hubspot_v3_migrated' ) ) {
				$mode = 'v3';
			} elseif ( get_option( 'wpf_hubspot_v3_migration_needed' ) ) {
				$mode = 'v1';
			} else {
				$version = wpf_get_option( 'wp_fusion_version' );
				$mode    = ( ! empty( $version ) && version_compare( $version, '3.47.7', '<' ) )
					? 'v1'
					: 'v3';
			}
		}

		$mode = apply_filters( 'wpf_hubspot_lists_api_mode', $mode );

		if ( ! in_array( $mode, array( 'v1', 'v3' ), true ) ) {
			$mode = 'v3';
		}

		$cutoff    = apply_filters(
			'wpf_hubspot_v1_lists_cutoff',
			'2026-04-30 23:59:59 UTC'
		);
		$cutoff_ts = strtotime( $cutoff );

		if ( 'v1' === $mode && false !== $cutoff_ts && time() >= $cutoff_ts ) {
			$mode = 'v3';

			if ( 'v3' !== $stored_mode ) {
				wpf_update_option( 'wpf_hubspot_lists_api_mode', 'v3' );
				wpf_log(
					'notice',
					0,
					'HubSpot lists API automatically switched to v3 after the v1 cutoff date.'
				);
			}

			update_option( 'wpf_hubspot_v3_migration_needed', true, false );
		}

		if ( 'v1' === $mode
			&& ! wpf_get_option( 'wpf_hubspot_v3_migrated' )
			&& ! get_option( 'wpf_hubspot_v3_migration_needed' ) ) {
			update_option( 'wpf_hubspot_v3_migration_needed', true, false );
		}

		if ( 'v3' === $mode && wpf_get_option( 'wpf_hubspot_v3_migrated' ) ) {
			delete_option( 'wpf_hubspot_v3_migration_needed' );
			delete_transient( 'wpf_hubspot_orphaned_ids' );
			delete_transient( 'wpf_hubspot_id_map' );
		}

		if ( $mode !== $stored_mode ) {
			wpf_update_option( 'wpf_hubspot_lists_api_mode', $mode );
		}

		return $mode;
	}


	/**
	 * Cleans list migration state when HubSpot uses multiselect segmentation.
	 *
	 * @since 3.47.7
	 *
	 * @return void
	 */
	private function cleanup_lists_api_state_for_multiselect() {

		wpf_update_option( 'wpf_hubspot_lists_api_mode', false );
		delete_option( 'wpf_hubspot_v3_migration_needed' );
		delete_transient( 'wpf_hubspot_orphaned_ids' );
		delete_transient( 'wpf_hubspot_id_map' );
	}


	/**
	 * Gets available tags from a HubSpot multiselect field.
	 *
	 * @since 3.47.7
	 *
	 * @return array|WP_Error
	 */
	private function sync_tags_multiselect() {

		$available_tags = array();

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.hubapi.com/properties/v1/contacts/properties';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) || ! is_array( $body_json ) ) {
			return $available_tags;
		}

		foreach ( $body_json as $field ) {
			if ( wpf_get_option( 'hubspot_multiselect_field' ) === $field->name
				&& ! empty( $field->options ) ) {
				foreach ( $field->options as $option ) {
					$available_tags[ $option->value ] = $option->label;
				}
			}
		}

		return $available_tags;
	}


	/**
	 * Gets available HubSpot lists via the legacy v1 lists API.
	 *
	 * @since 3.47.7
	 *
	 * @return array|WP_Error
	 */
	private function sync_tags_v1() {

		$available_tags = array();
		$continue       = true;
		$offset         = 0;

		if ( ! $this->params ) {
			$this->get_params();
		}

		while ( $continue ) {

			$request  = 'https://api.hubapi.com/contacts/v1/lists/?count=250&offset=' . $offset;
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->lists ) ) {

				foreach ( $response->lists as $list ) {

					if ( 'STATIC' === $list->listType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$category = 'Static Lists';
					} else {
						$category = 'Active Lists (Read Only)';
					}

					$available_tags[ $list->listId ] = array( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'label'    => $list->name,
						'category' => $category,
					);
				}
			}

			if ( ! empty( $response->{'has-more'} ) ) {
				$offset += 250;
			} else {
				$continue = false;
			}
		}

		return $available_tags;
	}


	/**
	 * Gets available HubSpot lists via the v3 lists API.
	 *
	 * @since 3.47.7
	 *
	 * @return array|WP_Error
	 */
	public function sync_tags_v3() {

		$available_tags = array();
		$continue       = true;
		$offset         = 0;

		if ( ! $this->params ) {
			$this->get_params();
		}

		while ( $continue ) {
			$params           = $this->params;
			$params['body']   = wp_json_encode(
				array(
					'limit'  => 100,
					'offset' => $offset,
				)
			);
			$params['method'] = 'POST';

			$request  = 'https://api.hubapi.com/crm/v3/lists/search';
			$response = wp_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->lists ) ) {
				foreach ( $response->lists as $list ) {
					if ( isset( $list->objectTypeId ) && '0-1' !== $list->objectTypeId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						continue;
					}

					$processing_type = isset( $list->processingType ) ? $list->processingType : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

					if ( in_array( $processing_type, array( 'MANUAL', 'SNAPSHOT' ), true ) ) {
						$category = 'Static Lists';
					} elseif ( 'DYNAMIC' === $processing_type ) {
						$category = 'Active Lists (Read Only)';
					} else {
						$category = 'Static Lists';
					}

					$list_id = isset( $list->listId ) ? $list->listId : ( isset( $list->id ) ? $list->id : false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

					if ( empty( $list_id ) || empty( $list->name ) ) {
						continue;
					}

					$available_tags[ $list_id ] = array(
						'label'    => $list->name,
						'category' => $category,
					);
				}
			}

			if ( isset( $response->hasMore ) && true === $response->hasMore && isset( $response->offset ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$offset = absint( $response->offset );
			} else {
				$continue = false;
			}
		}

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

		$request  = 'https://api.hubapi.com/properties/v1/contacts/properties';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$built_in_fields    = array();
		$custom_fields      = array();
		$multiselect_fields = array();
		$read_only_fields   = array();

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body_json as $field ) {

			if ( $field->{'readOnlyValue'} ) {
				$field->label      .= ' (' . __( 'read only', 'wp-fusion-lite' ) . ')';
				$read_only_fields[] = $field->name;
			}

			if ( empty( $field->{'createdUserId'} ) ) {
				$built_in_fields[ $field->name ] = $field->label;
			} else {
				$custom_fields[ $field->name ] = $field->label;
			}

			// Store the multiselect for the tag type dropdown.
			if ( 'checkbox' === $field->{'fieldType'} ) {
				$multiselect_fields[ $field->name ] = $field->label;
			}
		}

		asort( $built_in_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		// Store the multiselect for the tag type dropdown.
		wp_fusion()->settings->set( 'hubspot_multiselect_fields', $multiselect_fields );

		// Store the read only fields.
		wp_fusion()->settings->set( 'read_only_fields', $read_only_fields );

		return $crm_fields;
	}

	/**
	 * Loads all owners from CRM and saves them to options
	 *
	 * @since 3.47.2
	 * @return array|WP_Error Either the available owners in the CRM, or a WP_Error.
	 */
	public function sync_owners() {

		$available_owners = array();

		$request  = 'https://api.hubapi.com/crm/v3/owners/';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body_json->results ) ) {
			foreach ( $body_json->results as $owner ) {
				$name_parts                     = array_filter( array( $owner->{'firstName'}, $owner->{'lastName'} ) );
				$name                           = ! empty( $name_parts ) ? ' (' . implode( ' ', $name_parts ) . ')' : '';
				$label                          = ! empty( $owner->email ) ? $owner->email . $name : $owner->id;
				$available_owners[ $owner->id ] = $label;
			}
		}

		wp_fusion()->settings->set( 'available_owners', $available_owners );

		return $available_owners;
	}

	/**
	 * Creates a new tag(list) in HubSpot and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int|WP_Error $tag_id the tag id returned from API or WP_Error if there was an error.
	 */
	public function add_tag( $tag_name ) {
		$params = $this->get_params();

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			// Get the current property to update its options.
			$field    = wpf_get_option( 'hubspot_multiselect_field' );
			$request  = 'https://api.hubapi.com/properties/v1/contacts/properties/named/' . $field;
			$response = wp_remote_get( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$property = json_decode( wp_remote_retrieve_body( $response ), true );

			$options = isset( $property['options'] ) ? $property['options'] : array();

			// Check if the tag already exists in options.
			foreach ( $options as $option ) {
				if ( $option['value'] === $tag_name ) {
					return $tag_name;
				}
			}

			$options[] = array(
				'label'        => $tag_name,
				'value'        => $tag_name,
				'hidden'       => false,
				'displayOrder' => count( $options ) + 1,
			);

			// We set type to 'enumeration' and fieldType to 'checkbox' which are required for multiselect fields in HubSpot.
			$data = array(
				'name'        => $field,
				'label'       => $property['label'],
				'description' => $property['description'],
				'groupName'   => $property['groupName'],
				'type'        => 'enumeration',
				'fieldType'   => 'checkbox',
				'formField'   => true,
				'options'     => $options,
			);

			$params['body']   = wp_json_encode( $data );
			$params['method'] = 'PUT';
			$request          = 'https://api.hubapi.com/properties/v1/contacts/properties/named/' . $field;
			$response         = wp_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return $tag_name;

		} else {

			if ( 'v1' === $this->get_lists_api_mode() ) {
				$data           = array(
					'name' => $tag_name,
				);
				$params['body'] = wp_json_encode( $data );
				$request        = 'https://api.hubapi.com/contacts/v1/lists';
				$response       = wp_remote_post( $request, $params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$response = json_decode( wp_remote_retrieve_body( $response ) );

				return $response->{'listId'};
			}

			$data           = array(
				'name'           => $tag_name,
				'objectTypeId'   => '0-1',
				'processingType' => 'MANUAL',
			);
			$params['body'] = wp_json_encode( $data );
			$request        = 'https://api.hubapi.com/crm/v3/lists';
			$response       = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				if ( false !== stripos( $message, 'list' ) && false !== stripos( $message, 'exist' ) ) {
					$available_tags = $this->sync_tags();

					if ( ! is_wp_error( $available_tags ) ) {
						foreach ( $available_tags as $list_id => $list ) {
							$list_label = is_array( $list ) && isset( $list['label'] ) ? $list['label'] : $list;

							if ( 0 === strcasecmp( $list_label, $tag_name ) ) {
								return $list_id;
							}
						}
					}
				}

				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			$tag_id = isset( $response->{'listId'} ) ? $response->{'listId'} : $response->{'id'};
			return $tag_id;
		}
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @since  unknown
	 *
	 * @param string $email_address Email address.
	 * @return int Contact ID
	 */
	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// One contact can have multiple emails in HubSpot, so in theory one user can be linked to multiple contacts.

		$body           = array(
			'filterGroups' => array(
				array(
					'filters' => array(
						array(
							'propertyName' => 'email',
							'operator'     => 'EQ',
							'value'        => $email_address,
						),
					),
				),
			),
			'properties'   => array( 'email' ),
			'limit'        => 1,
		);
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $body );
		$request        = 'https://api.hubapi.com/crm/v3/objects/contacts/search';
		$response       = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json->results ) ) {
			return false;
		}

		return $body_json->results[0]->id;
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @since  unknown
	 *
	 * @param int $contact_id Contact ID.
	 * @return array|WP_Error
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$tags = array();

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			$field    = wpf_get_option( 'hubspot_multiselect_field' );
			$request  = 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id . '?properties=' . rawurlencode( $field );
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body_json ) ) {
				return $tags;
			}

			if ( isset( $body_json->properties->{ $field } ) && ! empty( $body_json->properties->{ $field } ) ) {
				$tags = explode( ';', $body_json->properties->{ $field } );
			}
		} else {
			// This can return IDs for deleted lists, so we only keep known IDs.
			$available_tags = wpf_get_option( 'available_tags', array() );

			if ( 'v1' === $this->get_lists_api_mode() ) {
				$request  = 'https://api.hubapi.com/contacts/v1/contact/vid/' . absint( $contact_id ) . '/profile';
				$response = wp_remote_get( $request, $this->params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( empty( $body_json ) ) {
					return $tags;
				}

				if ( ! empty( $body_json->{'list-memberships'} ) ) {
					foreach ( $body_json->{'list-memberships'} as $list ) {
						$list_id = isset( $list->{'static-list-id'} ) ? $list->{'static-list-id'} : false;

						if ( ! empty( $list_id ) && isset( $available_tags[ $list_id ] ) ) {
							$tags[] = $list_id;
						}
					}
				}
			} else {
				$continue = true;
				$after    = '';

				while ( $continue ) {
					$request = 'https://api.hubapi.com/crm/v3/lists/records/0-1/' . $contact_id . '/memberships?limit=500';

					if ( ! empty( $after ) ) {
						$request .= '&after=' . $after;
					}

					$response = wp_remote_get( $request, $this->params );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					$body_json = json_decode( wp_remote_retrieve_body( $response ) );

					if ( empty( $body_json ) ) {
						return $tags;
					}

					if ( ! empty( $body_json->results ) ) {
						foreach ( $body_json->results as $list ) {
							$list_id = isset( $list->listId ) ? $list->listId : ( isset( $list->id ) ? $list->id : false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

							if ( isset( $available_tags[ $list_id ] ) ) {
								$tags[] = $list_id;
							}
						}
					}

					if ( isset( $body_json->paging ) && isset( $body_json->paging->next ) && isset( $body_json->paging->next->after ) ) {
						$after = $body_json->paging->next->after;
					} else {
						$continue = false;
					}
				}
			}
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @since  unknown
	 *
	 * @param array $tags       Tags to apply.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error
	 */
	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			$properties = array();
			$field      = wpf_get_option( 'hubspot_multiselect_field' );

			// No way to append through the API so we get the current tags.
			$current_tags = $this->get_tags( $contact_id );

			if ( is_wp_error( $current_tags ) ) {
				return $current_tags;
			}

			$properties[ $field ] = implode( ';', array_merge( $tags, $current_tags ) );

			$params           = $this->get_params();
			$params['body']   = wp_json_encode( array( 'properties' => $properties ) );
			$params['method'] = 'PATCH';

			$request  = 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id;
			$response = wp_remote_request( $request, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		} else {
			foreach ( $tags as $tag ) {

				if ( 'v1' === $this->get_lists_api_mode() ) {
					$params         = $this->params;
					$params['body'] = wp_json_encode( array( 'vids' => array( absint( $contact_id ) ) ) );

					$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/add';
					$response = wp_remote_post( $request, $params );
				} else {
					$params           = $this->params;
					$params['method'] = 'PUT';
					$params['body']   = wp_json_encode( array( absint( $contact_id ) ) );

					$request  = 'https://api.hubapi.com/crm/v3/lists/' . $tag . '/memberships/add';
					$response = wp_remote_request( $request, $params );
				}

				if ( is_wp_error( $response ) ) {
					return $response;
				}
			}
		}

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @since  unknown
	 *
	 * @param array $tags       Tags to remove.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error
	 */
	public function remove_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {
			$properties   = array();
			$field        = wpf_get_option( 'hubspot_multiselect_field' );
			$current_tags = $this->get_tags( $contact_id );

			if ( is_wp_error( $current_tags ) ) {
				return $current_tags;
			}

			foreach ( $tags as $tag ) {
				$key = array_search( $tag, $current_tags, true );
				if ( false !== $key ) {
					unset( $current_tags[ $key ] );
				}
			}

			$properties[ $field ] = implode( ';', $current_tags );

			$params           = $this->get_params();
			$params['body']   = wp_json_encode( array( 'properties' => $properties ) );
			$params['method'] = 'PATCH';

			$request  = 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id;
			$response = wp_remote_request( $request, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		} else {
			foreach ( $tags as $tag ) {

				if ( 'v1' === $this->get_lists_api_mode() ) {
					$params         = $this->params;
					$params['body'] = wp_json_encode( array( 'vids' => array( absint( $contact_id ) ) ) );

					$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/remove';
					$response = wp_remote_post( $request, $params );
				} else {
					$params           = $this->params;
					$params['method'] = 'PUT';
					$params['body']   = wp_json_encode(
						array(
							'recordIdsToRemove' => array( absint( $contact_id ) ),
						)
					);

					$request  = 'https://api.hubapi.com/crm/v3/lists/' . $tag . '/memberships/add-and-remove';
					$response = wp_remote_request( $request, $params );
				}

				if ( is_wp_error( $response ) ) {
					return $response;
				}
			}
		}

		return true;
	}


	/**
	 * Adds a new contact
	 *
	 * @since  unknown
	 *
	 * @param array $data Contact data.
	 * @return int Contact ID
	 */
	public function add_contact( $data ) {

		$properties = array();

		foreach ( $data as $property => $value ) {
			$properties[ $property ] = $value;
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'properties' => $properties ) );

		$request  = 'https://api.hubapi.com/crm/v3/objects/contacts';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		return $body_json->id;
	}

	/**
	 * Update contact
	 *
	 * @since  unknown
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data       Contact data.
	 * @return bool
	 */
	public function update_contact( $contact_id, $data ) {

		$properties = array();

		foreach ( $data as $property => $value ) {
			$properties[ $property ] = $value;
		}

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( array( 'properties' => $properties ) );
		$params['method'] = 'PATCH';

		$request  = 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id;
		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @since  unknown
	 *
	 * @param int $contact_id Contact ID.
	 * @return array User meta data that was returned
	 */
	public function load_contact( $contact_id ) {

		$contact_fields = wpf_get_option( 'contact_fields' );
		$properties     = array();

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( ! empty( $field_data['active'] ) && ! empty( $field_data['crm_field'] ) ) {
				$properties[] = $field_data['crm_field'];
			}
		}

		$request = 'https://api.hubapi.com/crm/v3/objects/contacts/' . $contact_id;

		if ( ! empty( $properties ) ) {
			$request .= '?properties=' . implode( '&properties=', array_map( 'rawurlencode', $properties ) );
		}

		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta = array();
		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( ! empty( $field_data['active'] ) && isset( $body_json->properties->{$field_data['crm_field']} ) ) {

				$value = $body_json->properties->{$field_data['crm_field']};

				if ( 'multiselect' === $field_data['type'] && ! empty( $value ) ) {

					$value = explode( ';', $value );

				} elseif ( 'checkbox' === $field_data['type'] ) {

					if ( 'false' === $value ) {
						$value = null;
					} else {
						$value = true;
					}
				} elseif ( ( 'datepicker' === $field_data['type'] || 'date' === $field_data['type'] ) && is_numeric( $value ) ) {

					$value /= 1000; // Convert milliseconds back to seconds.

				}

				$user_meta[ $field_id ] = $value;
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @param string $tag Tag ID or name.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	public function load_contacts( $tag = '' ) {

		$contact_ids    = array();
		$lists_api_mode = 'v3';

		if ( 'multiselect' !== wpf_get_option( 'hubspot_tag_type' ) ) {
			$lists_api_mode = $this->get_lists_api_mode();
		}

		if ( empty( $tag ) ) {

			// Import all contacts.

			if ( 'v1' === $lists_api_mode ) {
				$offset  = 0;
				$proceed = true;
				while ( $proceed ) {

					$request  = 'https://api.hubapi.com/contacts/v1/lists/all/contacts/all/?count=100&&vidOffset=' . $offset;
					$response = wp_remote_get( $request, $this->get_params() );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					$body_json = json_decode( wp_remote_retrieve_body( $response ) );

					if ( empty( $body_json->contacts ) ) {
						return $contact_ids;
					}

					foreach ( $body_json->contacts as $contact ) {
						$contact_ids[] = $contact->vid;
					}

					if ( empty( $body_json->{'has-more'} ) ) {
						$proceed = false;
					} else {
						$offset = $body_json->{'vid-offset'};
					}
				}
			} else {
				$offset  = '';
				$proceed = true;
				while ( $proceed ) {

					$request = 'https://api.hubapi.com/crm/v3/objects/contacts?limit=100';

					if ( ! empty( $offset ) ) {
						$request .= '&after=' . $offset;
					}

					$response = wp_remote_get( $request, $this->get_params() );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					$body_json = json_decode( wp_remote_retrieve_body( $response ) );

					if ( empty( $body_json->results ) ) {
						return $contact_ids;
					}

					foreach ( $body_json->results as $contact ) {
						$contact_ids[] = $contact->id;
					}

					if ( empty( $body_json->paging ) || empty( $body_json->paging->next ) || empty( $body_json->paging->next->after ) ) {
						$proceed = false;
					} else {
						$offset = $body_json->paging->next->after;
					}
				}
			}
		} elseif ( 'multiselect' === wpf_get_option( 'hubspot_tag_type' ) ) {

			// Import based on picklist value.

			$field = wpf_get_option( 'hubspot_multiselect_field' );

			$body           = array(
				'filterGroups' => array(
					array(
						'filters' => array(
							array(
								'propertyName' => $field,
								'operator'     => 'EQ',
								'value'        => $tag,
							),
						),
					),
				),
			);
			$params         = $this->get_params();
			$params['body'] = wp_json_encode( $body );

			$request  = 'https://api.hubapi.com/crm/v3/objects/contacts/search';
			$response = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body_json->results ) ) {
				return $contact_ids;
			}

			foreach ( $body_json->results as $contact ) {
				$contact_ids[] = $contact->id;
			}
		} elseif ( 'v1' === $lists_api_mode ) {

			// Import based on list.

			$offset  = 0;
			$proceed = true;
			while ( $proceed ) {

				$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/contacts/all?count=100&vidOffset=' . $offset;
				$response = wp_remote_get( $request, $this->get_params() );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( empty( $body_json->contacts ) ) {
					return $contact_ids;
				}

				foreach ( $body_json->contacts as $contact ) {
					$contact_ids[] = $contact->vid;
				}

				if ( ! empty( $body_json->{'has-more'} ) ) {
					$offset = $body_json->{'vid-offset'};
				} else {
					$proceed = false;
				}
			}
		} else {

			// Import based on list.

			$offset  = '';
			$proceed = true;
			while ( $proceed ) {

				$request = 'https://api.hubapi.com/crm/v3/lists/' . $tag . '/memberships?limit=500';

				if ( ! empty( $offset ) ) {
					$request .= '&after=' . $offset;
				}

				$response = wp_remote_get( $request, $this->get_params() );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( empty( $body_json->results ) ) {
					return $contact_ids;
				}

				foreach ( $body_json->results as $membership ) {
					$record_id = isset( $membership->recordId ) ? $membership->recordId : ( isset( $membership->id ) ? $membership->id : false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

					if ( ! empty( $record_id ) ) {
						$contact_ids[] = $record_id;
					}
				}

				if ( empty( $body_json->paging ) || empty( $body_json->paging->next ) || empty( $body_json->paging->next->after ) ) {
					$proceed = false;
				} else {
					$offset = $body_json->paging->next->after;
				}
			}
		}

		return $contact_ids;
	}

	/**
	 * Set a cookie to fix tracking for guest checkouts
	 *
	 * @since  unknown
	 *
	 * @param int    $contact_id     Contact ID.
	 * @param string $customer_email Customer email.
	 * @return void
	 */
	public function guest_checkout_complete( $contact_id, $customer_email ) {

		if ( false === wpf_get_option( 'site_tracking' ) || defined( 'DOING_WPF_BATCH_TASK' ) || wpf_is_user_logged_in() ) {
			return;
		}

		if ( headers_sent() ) {
			wpf_log( 'notice', 0, 'Tried and failed to set site tracking cookie for ' . $customer_email . ', because headers have already been sent.' );
			return;
		}

		wpf_log( 'info', 0, 'Starting site tracking session for contact #' . $contact_id . ' with email ' . $customer_email . '.' );

		setcookie( 'wpf_guest', $customer_email, time() + DAY_IN_SECONDS * 30, COOKIEPATH, COOKIE_DOMAIN );
	}


	/**
	 * Output tracking code
	 *
	 * @since  unknown
	 *
	 * @access public
	 * @return mixed
	 */
	public function tracking_code_output() {

		if ( false === wpf_get_option( 'site_tracking' ) || true === wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		// Stop HubSpot messing with WooCommerce account page (sending email changes automatically).
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}

		$trackid = wpf_get_option( 'site_tracking_id' );

		if ( empty( $trackid ) ) {
			$trackid = $this->get_tracking_id();
		}

		echo '<!-- Start of HubSpot Embed Code via WP Fusion -->';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		echo '<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/' . esc_attr( $trackid ) . '.js"></script>';

		if ( wpf_is_user_logged_in() || isset( $_COOKIE['wpf_guest'] ) ) {

			// This will also merge historical tracking data that was accumulated before a visitor registered.

			$email = wpf_get_current_user_email();

			echo '<script>';
			echo 'var _hsq = window._hsq = window._hsq || [];';
			echo '_hsq.push(["identify",{ email: "' . esc_js( $email ) . '" }]);';
			echo '</script>';

		}

		echo '<!-- End of HubSpot Embed Code via WP Fusion -->';
	}

	/**
	 * Gets tracking ID for site tracking script
	 *
	 * @since  unknown
	 *
	 * @access public
	 * @return int tracking ID
	 */
	public function get_tracking_id() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.hubapi.com/integrations/v1/me';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'site_tracking_id', $body_json->portalId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return $body_json->portalId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Track event.
	 *
	 * Track an event with the HubSpot engagements API.
	 *
	 * @since  3.38.16
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return new WP_Error( 'notice', 'Can\'t track events without a user or guest email address.' );
		}

		// Get the contact ID to track.
		$contact_id = $this->get_contact_id( $email_address );

		if ( ! $contact_id ) {
			return new WP_Error( 'notice', 'Contact not found with email ' . $email_address . ' in HubSpot.' );
		}

		$body = array(
			'properties'   => array(
				'hs_timestamp' => wpf_get_iso8601_date(),
				'hs_note_body' => '<b>' . $event . '</b><br>' . nl2br( $event_data ),
			),
			'associations' => array(
				array(
					'to'    => array(
						'id' => $contact_id,
					),
					'types' => array(
						array(
							'associationCategory' => 'HUBSPOT_DEFINED',
							'associationTypeId'   => 202,
						),
					),
				),
			),
		);

		$body = apply_filters( 'wpf_hubspot_add_engagement', $body, $contact_id );

		$params             = $this->params;
		$params['body']     = wp_json_encode( $body );
		$params['blocking'] = false;

		$request  = 'https://api.hubapi.com/crm/v3/objects/notes/';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Creates a new custom object.
	 *
	 * @since 3.38.30
	 *
	 * @param array  $properties     The properties.
	 * @param string $object_type_id The object type ID.
	 * @return int|WP_Error Object ID if success, WP_Error if failed.
	 */
	public function add_object( $properties, $object_type_id ) {

		$properties = array(
			'properties' => $properties,
		);

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $properties );

		$response = wp_remote_post( 'https://api.hubapi.com/crm/v3/objects/' . $object_type_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->id;
	}

	/**
	 * Updates a new custom object.
	 *
	 * @since  3.38.30
	 *
	 * @param  int    $object_id      The object ID to update.
	 * @param  array  $properties     The properties.
	 * @param  string $object_type_id The object type ID.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function update_object( $object_id, $properties, $object_type_id ) {

		$properties = array(
			'properties' => $properties,
		);

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $properties );
		$params['method'] = 'PATCH';

		$response = wp_remote_request( 'https://api.hubapi.com/crm/v3/objects/' . $object_type_id . '/' . $object_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a custom object.
	 *
	 * @since  3.38.30
	 *
	 * @param  int    $object_id      The object ID to update.
	 * @param  string $object_type_id The object type ID.
	 * @param  array  $properties     The properties to load.
	 * @return array|WP_Error Object array if success, WP_Error if failed.
	 */
	public function load_object( $object_id, $object_type_id, $properties ) {

		$params = $this->get_params();

		$request = 'https://api.hubapi.com/crm/v3/objects/' . $object_type_id . '/' . $object_id;

		foreach ( $properties as $property ) {
			$request .= '&properties=' . $property;
		}

		$response = wp_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		return $response;
	}
}
