<?php

class WPF_Klaviyo {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'klaviyo';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Klaviyo';

	/**
	 * Contains API key (needed for some requests)
	 */

	public $key;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'events', 'add_fields', 'events_multi_key' );

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var string
	 */
	public $tag_type = 'List';


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @var string
	 */

	public $edit_url = 'https://www.klaviyo.com/profile/%s';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Klaviyo_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_woocommerce_customer_data', array( $this, 'format_phone_numbers' ) );
		add_filter( 'wpf_user_update', array( $this, 'format_phone_numbers' ), 5 ); // 5 so it's before WPF_WooCommerce::user_update().

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return array|WP_Error
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( false !== strpos( $url, 'klaviyo' ) && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->message ) ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( isset( $body_json->detail ) ) {

				$response = new WP_Error( 'error', $body_json->detail );

			} elseif ( isset( $body_json->errors ) ) {

				foreach ( $body_json->errors as $error ) {

					if ( 'duplicate_profile' === $error->code && ! empty( $error->meta ) ) {

						// Duplicate handling. Take the duplicate ID out of the
						// response and update them instead.

						$body           = json_decode( $args['body'] );
						$body->data->id = $error->meta->duplicate_profile_id;
						$args['body']   = wp_json_encode( $body );
						$args['method'] = 'PATCH';

						$response = wp_safe_remote_post( $url . '/' . $body->data->id, $args );

						if ( is_wp_error( $response ) ) {
							return $response;
						} else {
							return $args; // return the body so add_contact() can get the ID.
						}

					}

				}

				$response = new WP_Error( 'error', implode( ' ', wp_list_pluck( $body_json->errors, 'detail' ) ) );
			}
		}

		return $response;

	}


	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) ) {
			$access_key = wpf_get_option( 'klaviyo_key' );
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Revision'      => '2023-12-15',
				'Authorization' => 'Klaviyo-API-Key ' . $access_key,
			),
		);

		$this->key = $access_key;

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		$request  = 'https://a.klaviyo.com/api/lists';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
	 * Gets the date in the correct format for the Klaviyo API.
	 *
	 * @since 3.42.12
	 * 
	 * @return string The formatted date.
	 */
	public function get_current_date_with_timezone_offset() {

		$timestamp       = current_time('timestamp'); // Get current time according to WordPress timezone settings.
		$timezone_string = get_option( 'timezone_string' );

		if ( ! empty( $timezone_string ) ) {
			$timezone = new DateTimeZone( $timezone_string );
		} else {
			// If no timezone string exists, fall back to an offset
			$current_offset = get_option( 'gmt_offset' );
			// Prepend a plus if offset is positive or zero, minus is already included for negative offsets.
			$formatted_offset = ( $current_offset >= 0 ? '+' : '' ) . str_pad( $current_offset, 2, '0', STR_PAD_LEFT ) . ':00';
			$timezone         = new DateTimeZone( $formatted_offset );
		}

		$date_time = new DateTime();
		$date_time->setTimestamp( $timestamp );
		$date_time->setTimezone( $timezone );

		// Format the date. Note: 'P' gives the offset as +HH:MM, need to remove ':'
		$formatted_date = $date_time->format( 'Y-m-d\TH:i:s' ) . substr( $date_time->format( 'P' ), 0, 3 ) . substr( $date_time->format( 'P' ), 4, 2 );

		return $formatted_date;
	}

	/**
	 * Formats phone numbers from WooCommerce for the Klaviyo API.
	 *
	 * @since 3.42.12
	 *
	 * @param array $customer_data The customer data.
	 * @return array The customer data.
	 */
	public function format_phone_numbers( $customer_data ) {

		if ( ! empty( $customer_data['billing_phone'] ) ) {
			$customer_data['billing_phone'] = wpf_phone_number_to_e164( $customer_data['billing_phone'], $customer_data['billing_country'] );
		}

		if ( ! empty( $customer_data['shipping_phone'] ) ) {
			$customer_data['shipping_phone'] = wpf_phone_number_to_e164( $customer_data['shipping_phone'], $customer_data['shipping_country'] );
		}

		return $customer_data;
	}


	/**
	 * Sync Tags.
	 *
	 * Gets all available tags and saves them to options.
	 *
	 * @since 3.41.21 Added support for pagination.
	 *
	 * @return array Lists.
	 */
	public function sync_tags() {

		$available_tags = array();
		$continue       = true;

		$request = 'https://a.klaviyo.com/api/lists';

		while ( $continue ) {

			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->data as $list ) {
				$available_tags[ $list->id ]            = $list->attributes->name;
				$available_tags[ $list->id . '_optin' ] = $list->attributes->name . ' ' . __( '(opt-in to marketing)', 'wp-fusion-lite' );
			}

			$response->links->next ? $request = $response->links->next : $continue = false;

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

		$default = array(
			'Standard Fields' => array(),
			'Custom Fields'   => array(),
		);

		$crm_fields = wp_parse_args( wpf_get_option( 'crm_fields' ), $default );

		// Load built in fields first.
		require dirname( __FILE__ ) . '/admin/klaviyo-fields.php';

		foreach ( $klaviyo_fields as $field ) {
			$crm_fields['Standard Fields'][ $field['crm_field'] ] = $field['crm_label'];
		}

		// Custom fields.

		$request  = 'https://a.klaviyo.com/api/profiles/';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $person ) {

				if ( ! empty( $person->attributes->properties ) ) {
					foreach ( $person->attributes->properties as $key => $value ) {
						if ( ! isset( $custom_fields[ $key ] ) ) {
							$crm_fields['Custom Fields'][ $key ] = $key;
						}
					}
				}

			}

		}

		asort( $crm_fields['Custom Fields'] );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://a.klaviyo.com/api/profiles/?filter=equals(email,"' . $email_address . '")';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->data[0] ) ) {

			return $response->data[0]->id;

		} else {

			return false;

		}

	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}
		$user_tags = array();
		$request   = 'https://a.klaviyo.com/api/profiles/' . $contact_id . '/lists';
		$response  = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// Klaviyo sometimes returns unknown lists (maybe deleted ones?). We'll exclude them.
		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $list ) {

				if ( ! isset( $available_tags[ $list->id ] ) ) {
					continue;
				}

				$user_tags[] = $list->id;
			}
		}



		return $user_tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @return bool|WP_Error True on success, error on failure.
	 */

	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_params();

		$data = array(
			'data' => array(
				'type'          => 'profile-subscription-bulk-create-job',
				'attributes'    => array(
					'custom_source' => __( 'WP Fusion', 'wp-fusion-lite' ),
					'profiles'      => array(
						'data' => array(
							array(
								'type'       => 'profile',
								'id'         => $contact_id,
								'attributes' => array(
									'email'         => wp_fusion()->crm->get_email_from_cid( $contact_id ),
									'subscriptions' => array(
										'email' => array(
											'marketing' => array(
												'consent'      => 'SUBSCRIBED',
												'consented_at' => $this->get_current_date_with_timezone_offset(),
											),
										),
									),
								),
							),
						),
					),
				),
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
						),
					),
				),
			),
		);

		foreach ( $tags as $tag_id ) {

			if ( false === strpos( $tag_id, '_optin' ) ) {

				// Non explicit consent, adds them to the list NEVER SUBSCRIBED.

				$data = array(
					'data' => array(
						array(
							'type' => 'profile',
							'id'   => $contact_id,
						),
					),
				);

				$request = 'https://a.klaviyo.com/api/lists/' . $tag_id . '/relationships/profiles/';

			} else {

				// Explicit consent. Adds them to the list SUBSCRIBED.

				$data['data']['relationships']['list']['data']['id'] = $tag_id;

				$request = 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/';
			}

			$params['body'] = wp_json_encode( $data );
			$response       = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params = $this->params;

		$data = array(
			'data' => array(
				array(
					'type' => 'profile',
					'id'   => $contact_id,
				),
			),
		);

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag_id ) {

			$request  = 'https://a.klaviyo.com/api/lists/' . $tag_id . '/relationships/profiles/';
			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;

	}

	/**
	 * Formats the contact data for the Klaviyo API.
	 *
	 * @since 3.40.41
	 *
	 * @param array $data The contact data.
	 */
	public function format_contact_payload( $data ) {

		// Move custom fields to their own place.
		$crm_fields = wpf_get_option( 'crm_fields' );

		foreach ( $data as $key => $value ) {

			if ( ! isset( $crm_fields['Standard Fields'][ $key ] ) ) {

				if ( ! isset( $data['properties'] ) ) {
					$data['properties'] = array();
				}

				$data['properties'][ $key ] = $value;
				unset( $data[ $key ] );

			}

			// Klavio doesn't allow the $ sign when updating a contact.

			if ( false !== strpos( $key, '$' ) ) {
				$newkey = str_replace( '$', '', $key );
				unset( $data[ $key ] );
				$data[ $newkey ] = $value;
			}

			// Format address & compound fields.
			if ( false !== strpos( $key, '+' ) ) {

				$parts = explode( '+', $key );

				if ( ! isset( $data[ $parts[0] ] ) ) {
					$data[ $parts[0] ] = array();
				}

				$data[ $parts[0] ][ $parts[1] ] = $value;

				unset( $data[ $key ] );

			}
		}

		return $data;

	}

	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		$data = $this->format_contact_payload( $data );

		$body = array(
			'type'       => 'profile',
			'attributes' => $data,
		);

		$request        = 'https://a.klaviyo.com/api/profiles';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'data' => $body ) );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->data->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		$data = $this->format_contact_payload( $data );

		$body = array(
			'type'       => 'profile',
			'id'         => $contact_id,
			'attributes' => $data,
		);

		$request          = 'https://a.klaviyo.com/api/profiles/' . $contact_id;
		$params           = $this->get_params();
		$params['body']   = wp_json_encode( array( 'data' => $body ) );
		$params['method'] = 'PATCH';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params = $this->params;

		$request  = 'https://a.klaviyo.com/api/profiles/' . $contact_id;
		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {
			// Core fields.
			$crm_field = str_replace( '$', '', $field_data['crm_field'] );

			if ( $field_data['active'] && isset( $body_json['data']['attributes'][ $crm_field ] ) ) {
				$user_meta[ $field_id ] = $body_json['data']['attributes'][ $crm_field ];
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

	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();

		$request  = 'https://a.klaviyo.com/api/lists/' . $tag . '/profiles';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $response->data ) ) {

			foreach ( $response->data as $record ) {
				$contact_ids[] = $record->id;
			}
		}

		return $contact_ids;

	}



	/**
	 * Track event.
	 *
	 * Track an event with the Bento site tracking API.
	 *
	 * @since  3.40.40
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = array(), $email_address = false ) {

		if ( empty( $email_address ) && ! wpf_is_user_logged_in() ) {
			// Tracking only works if WP Fusion knows who the contact is.
			return;
		}

		// Get the email address to track.
		if ( empty( $email_address ) ) {
			$user          = wpf_get_current_user();
			$email_address = $user->user_email;
		}

		$body = array(
			'type'       => 'event',
			'attributes' => array(
				'profile'    => array(
					'email' => $email_address,
				),
				'metric'     => array(
					'name'    => $event,
					'service' => 'event',
				),
				'properties' => array(
					'event_title' => $event,
					'event_desc'  => $event_data,
				),
			),
		);

		if ( is_array( $event_data ) ) {
			$body['attributes']['properties'] = $event_data; // multi-key support.
		}

		$request        = 'https://a.klaviyo.com/api/events';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'data' => $body ) );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

}
