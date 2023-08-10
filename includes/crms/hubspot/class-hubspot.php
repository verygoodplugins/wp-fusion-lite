<?php

class WPF_HubSpot {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * HubSpot OAuth stuff
	 */

	public $client_id;

	public $client_secret;

	public $app_id;

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
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
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'hubspot';
		$this->name     = 'HubSpot';
		$this->supports = array( 'events', 'add_tags_api' );

		// OAuth
		$this->client_id     = '959bd865-5a24-4a43-a8bf-05a69c537938';
		$this->client_secret = '56cc5735-c274-4e43-99d4-3660d816a624';
		$this->app_id        = 180159;

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_HubSpot_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_auto_login_contact_id', array( $this, 'auto_login_contact_id' ) );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

		// Slow down the batch processses to get around the 100 requests per 10s limit
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		add_action( 'wpf_guest_contact_updated', array( $this, 'guest_checkout_complete' ), 10, 2 );
		add_action( 'wpf_guest_contact_created', array( $this, 'guest_checkout_complete' ), 10, 2 );

		$trackid = wpf_get_option( 'site_tracking_id' );

		if ( ! empty( $trackid ) && ! is_wp_error( $trackid ) ) {
			$this->edit_url = 'https://app.hubspot.com/contacts/' . $trackid . '/contact/%d/';
		}

	}

	/**
	 * Slow down the batch processses to get around the 100 requests per 10s limit
	 *
	 * @access public
	 * @return int Sleep time
	 */

	public function set_sleep_time( $seconds ) {

		return 1;

	}


	/**
	 * Formats user entered data to match HubSpot field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type ) {

			// Dates are in milliseconds since the epoch so if the timestamp
			// isn't already in ms we'll multiply x 1000 here. Can't use
			// gmdate() because we need local time.

			if ( ! empty( $value ) && is_numeric( $value ) && $value < 1000000000000 ) {

				if ( $value % DAY_IN_SECONDS !== 0 ) {
					// If the date is a timestamp, do the timezone offset and then reset it to midnight.
					$value -= (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
					$value  = strtotime( 'today', $value );
				}

				// Check if it's within the valid range, otherwise we can get an invalid properties error.
				if ( $value > ( time() + 1000 * YEAR_IN_SECONDS ) || $value < ( time() - 1000 * YEAR_IN_SECONDS ) ) {
					return false;
				}

				$value = $value * 1000;
			}

			return $value;

		} elseif ( 'checkbox' === $field_type ) {

			if ( ! empty( $value ) ) {
				return 'true';
			} else {
				return 'false';
			}
		} elseif ( is_array( $value ) ) {

			return implode( ';', array_filter( $value ) );

		} else {

			return $value;

		}

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $payload ) && isset( $payload->vid ) ) {
			$post_data['contact_id'] = absint( $payload->vid );
		}

		return $post_data;

	}

	/**
	 * Allows using an email address in the ?cid parameter
	 *
	 * @access public
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
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_token = null ) {

		// Get saved data from DB
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
	 * @return  bool
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
				'redirect_uri'  => 'https://wpfusion.com/oauth/?action=wpf_get_hubspot_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_safe_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$this->get_params( $body_json->access_token );

		wp_fusion()->settings->set( 'hubspot_token', $body_json->access_token );

		return $body_json->access_token;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'api.hubapi' ) !== false ) {

			$code      = wp_remote_retrieve_response_code( $response );
			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( $code == 401 ) {

				if ( strpos( $body_json->message, 'expired' ) !== false ) {

					$access_token = $this->refresh_token();

					if ( is_wp_error( $access_token ) ) {
						return new WP_Error( 'error', 'Error refreshing access token: ' . $access_token->get_error_message() );
					}

					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} else {

					$response = new WP_Error( 'error', 'Invalid API credentials.' );

				}
			} elseif ( ( isset( $body_json->status ) && $body_json->status == 'error' ) || isset( $body_json->errorType ) ) {

				// Sometimes adding a contact throws an Already Exists error, not sure why. We'll just re-do it with an update and return the ID.

				if ( isset( $body_json->error ) && 'CONTACT_EXISTS' === $body_json->error ) {

					$contact_id = $body_json->{'identityProfile'}->vid;

					$response = wp_safe_remote_post( "https://api.hubapi.com/contacts/v1/contact/vid/{$contact_id}/profile", $args );

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					// Fake the response to make it look like we just added a contact.

					$response['body'] = wp_json_encode( array( 'vid' => $contact_id ) );

					return $response;

				}

				$message = $body_json->message;

				// Contextual help

				if ( 'resource not found' == $message ) {
					$message .= '.<br /><br />This error can mean that you\'ve deleted or merged a record in HubSpot, and then tried to update an ID that no longer exists. Clicking Resync Lists on the user\'s admin profile will clear out the cached invalid contact ID.';
				} elseif ( 'Can not operate manually on a dynamic list' == $message ) {
					$message .= '.<br /><br />' . __( 'This error means you tried to apply an Active list over the API. Only Static lists can be assigned over the API. For an overview of HubSpot lists, see <a href="https://knowledge.hubspot.com/lists/create-active-or-static-lists#types-of-lists" target="_blank">this documentation page</a>.', 'wp-fusion-lite' );
				} elseif ( isset( $body_json->category ) && 'MISSING_SCOPES' === $body_json->category ) {
					$message .= '<br /><br />' . __( 'This error means you\'re trying to access a feature that requires additional permissions. You can grant these permissions by clicking Reauthorize with HubSpot on the Setup tab in the WP Fusion settings.', 'wp-fusion-lite' );
				}

				if ( isset( $body_json->validationResults ) ) {

					$message .= '<ul>';
					foreach ( $body_json->validationResults as $result ) {
						$message .= '<li>' . $result->message . '</li>';
					}
					$message .= '</ul>';

				}

				if ( isset( $body_json->errors ) ) {

					$message .= '<pre>' . print_r( $body_json->errors, true ) . '</pre>';

				}

				$response = new WP_Error( 'error', $message );

			}
		}

		return $response;

	}



	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_token = null, $refresh_token = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.hubapi.com/integrations/v1/me';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Save tracking ID for later

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'site_tracking_id', $body_json->{'portalId'} );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$continue = true;
		$offset   = 0;

		while ( $continue ) {

			$request  = 'https://api.hubapi.com/contacts/v1/lists/?count=250&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->lists ) ) {

				foreach ( $response->lists as $list ) {

					if ( 'STATIC' == $list->listType ) {
						$category = 'Static Lists';
					} else {
						$category = 'Active Lists (Read Only)';
					}

					$available_tags[ $list->listId ] = array(
						'label'    => $list->name,
						'category' => $category,
					);

				}
			}

			if ( $response->{'has-more'} ) {
				$offset += 250;
			} else {
				$continue = false;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.hubapi.com/properties/v1/contacts/properties';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$built_in_fields = array();
		$custom_fields   = array();

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body_json as $field ) {

			if ( $field->readOnlyValue == true ) {
				continue;
			}

			if ( empty( $field->createdUserId ) ) {
				$built_in_fields[ $field->name ] = $field->label;
			} else {
				$custom_fields[ $field->name ] = $field->label;
			}
		}

		asort( $built_in_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}



	/**
	 * Creates a new tag(list) in HubSpot and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$params         = $this->get_params();
		$data           = array(
			'name' => $tag_name,
		);
		$params['body'] = wp_json_encode( $data );

		$request  = 'https://api.hubapi.com/contacts/v1/lists';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tag_id = $response->listId;
		return $tag_id;
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

		// One contact can have multiple emails in HubSpot, so in theory one user can be linked to multiple contacts.

		$request  = 'https://api.hubapi.com/contacts/v1/contact/email/' . rawurlencode( $email_address ) . '/profile';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) && false !== strpos( $response->get_error_message(), 'contact does not exist' ) ) {

			// not found, okay.
			return false;

		} elseif ( is_wp_error( $response ) ) {

			return $response;

		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) ) {
			return false;
		}

		return $body_json->vid;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if ( empty( $body_json ) || empty( $body_json->{'list-memberships'} ) ) {
			return $tags;
		}

		// This can return the IDs of lists that have been deleted, for some reason, so
		// we'll only load the lists that we already know the IDs for.

		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $body_json->{'list-memberships'} as $list ) {

			if ( isset( $available_tags[ $list->{'static-list-id'} ] ) ) {
				$tags[] = $list->{'static-list-id'};
			}
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		foreach ( $tags as $tag ) {

			$params         = $this->params;
			$params['body'] = wp_json_encode( array( 'vids' => array( $contact_id ) ) );

			$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/add';
			$response = wp_safe_remote_post( $request, $params );

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

		foreach ( $tags as $tag ) {

			$params         = $this->params;
			$params['body'] = wp_json_encode( array( 'vids' => array( $contact_id ) ) );

			$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/remove';
			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		$properties = array();

		foreach ( $data as $property => $value ) {
			$properties[] = array(
				'property' => $property,
				'value'    => $value,
			);
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'properties' => $properties ) );

		$request  = 'https://api.hubapi.com/contacts/v1/contact';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		return $body_json->vid;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		$properties = array();

		foreach ( $data as $property => $value ) {
			$properties[] = array(
				'property' => $property,
				'value'    => $value,
			);
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'properties' => $properties ) );

		$request  = 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
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

		$request  = 'https://api.hubapi.com/contacts/v1/contact/vid/' . $contact_id . '/profile';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( ! empty( $field_data['active'] ) && isset( $body_json->properties->{$field_data['crm_field']} ) ) {

				$value = $body_json->properties->{$field_data['crm_field']}->value;

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
		$offset      = 0;
		$proceed     = true;

		while ( $proceed ) {

			$request  = 'https://api.hubapi.com/contacts/v1/lists/' . $tag . '/contacts/all?count=100&vidOffset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->params );

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

			if ( false == $body_json->{'has-more'} ) {
				$proceed = false;
			} else {
				$offset = $body_json->{'vid-offset'};
			}
		}

		return $contact_ids;

	}

	/**
	 * Set a cookie to fix tracking for guest checkouts
	 *
	 * @access public
	 * @return void
	 */

	public function guest_checkout_complete( $contact_id, $customer_email ) {

		if ( wpf_get_option( 'site_tracking' ) == false || defined( 'DOING_WPF_BATCH_TASK' ) || wpf_is_user_logged_in() ) {
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
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( false == wpf_get_option( 'site_tracking' ) || true == wpf_get_option( 'staging_mode' ) ) {
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
	 * @access public
	 * @return int tracking ID
	 */

	public function get_tracking_id() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.hubapi.com/integrations/v1/me';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'site_tracking_id', $body_json->portalId );

		return $body_json->portalId;

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
			return; // can't track without an email.
		}

		// Get the contact ID to track.
		$contact_id = $this->get_contact_id( $email_address );

		if ( ! $contact_id ) {
			return;
		}

		$body = array(
			'engagement'   => array(
				'active'  => true,
				'type'    => 'NOTE',
			),
			'associations' => array(
				'contactIds' => array( $contact_id ),
			),
			'metadata'     => array(
				'title' => $event,
				'body'  => '<b>' . $event . '</b><br>' . nl2br( $event_data ),
			),

		);

		$params             = $this->params;
		$params['body']     = wp_json_encode( $body );
		$params['blocking'] = false;

		$request  = 'https://api.hubapi.com/engagements/v1/engagements';
		$response = wp_safe_remote_post( $request, $params );

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

		$response = wp_safe_remote_post( 'https://api.hubapi.com/crm/v3/objects/' . $object_type_id, $params );

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

		$response = wp_safe_remote_request( 'https://api.hubapi.com/crm/v3/objects/' . $object_type_id . '/' . $object_id, $params );

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

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		return $response;

	}


}
