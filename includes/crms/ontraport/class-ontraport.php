<?php

class WPF_Ontraport {

	// Note: OP support says their API can take up to 60s to give a response

	/**
	 * (deprecated)
	 */

	public $app;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'add_tags_api' );

	/**
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type = 0;


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = 'https://app.ontraport.com/#!/contact/edit&id=%d';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug = 'ontraport';
		$this->name = 'Ontraport';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Ontraport_Admin( $this->slug, $this->name, $this );
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

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_woocommerce_customer_data', array( $this, 'format_states' ), 10, 2 );

		// Add tracking code to footer
		add_action( 'init', array( $this, 'set_tracking_cookie' ) );
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

		$this->object_type = apply_filters( 'wpf_crm_object_type', $this->object_type );

	}

	/**
	 * Register cookies allowed in the async process
	 *
	 * @access public
	 * @return array Cookies
	 */

	public function allowed_cookies( $cookies ) {

		$cookies[] = 'oprid';

		return $cookies;

	}

	/**
	 * Formats user entered data to match Ontraport field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		$options = wpf_get_option( 'ontraport_dropdown_options', array() );

		if ( 'date' === $field_type && is_numeric( $value ) ) {

			$offset = get_option( 'gmt_offset' );
			$value -= ( $offset * 60 * 60 );

			// Dates in Ontraport are definitely stored in UTC. And loaded in
			// UTC. This function assumes the date coming in is local time, and
			// converts it.
			//
			// Many dates in WP are already UTC, so this messes those up. But
			// for some reason we decided to add this in 3.33.17 and it's been
			// mostly working since then so we're not going to mess with it.
			//
			// Though considering we don't convert the date loaded *from* OP
			// into local time, this does mean a datetime field will drift by a
			// few hours every time a user is edited and the date is synced back
			// to OP. So basically, we're screwed either way 🤷‍♂️.

			return $value;

		} elseif ( isset( $options[ $field ] ) ) {

			if ( 'multiselect' == $field_type || 'checkboxes' == $field_type ) {

				// Maybe convert multiselect options to picklist IDs if matches are found

				if ( ! is_array( $value ) ) {
					$values = explode( ',', $value );
				} else {
					$values = $value;
				}

				$maybe_new_values = array();

				foreach ( $values as $v ) {

					$option_id = array_search( $v, $options[ $field ] );

					if ( false !== $option_id ) {
						$maybe_new_values[] = $option_id;
					}
				}

				if ( ! empty( $maybe_new_values ) ) {
					$value = '*/*' . implode( '*/*', $maybe_new_values ) . '*/*';
				}
			} else {

				$option_id = array_search( $value, $options[ $field ] );

				if ( $option_id ) {
					$value = $option_id;
				}
			}
		} elseif ( ( 'bulk_mail' === $field || 'bulk_sms' === $field ) && empty( $value ) ) {

			$value = '0'; // allows setting contacts to transactional over the API.

		}

		return $value;

	}

	/**
	 * Formats states back into codes
	 *
	 * @access public
	 * @return array Customer Data
	 */

	public function format_states( $customer_data, $order ) {

		if ( isset( $customer_data['billing_state'] ) ) {
			$customer_data['billing_state'] = $order->get_billing_state();
		}

		if ( isset( $customer_data['shipping_state'] ) ) {
			$customer_data['shipping_state'] = $order->get_shipping_state();
		}

		// Ontraport has non-standard Australian state abbreviations
		if ( $customer_data['billing_country'] == 'AU' ) {

			if ( $customer_data['billing_state'] == 'NT' ) {
				$customer_data['billing_state'] = 'AU_NT';
			}

			if ( $customer_data['shipping_state'] == 'NT' ) {
				$customer_data['shipping_state'] = 'AU_NT';
			}

			if ( $customer_data['billing_state'] == 'WA' ) {
				$customer_data['billing_state'] = 'AU_WA';
			}

			if ( $customer_data['shipping_state'] == 'WA' ) {
				$customer_data['shipping_state'] = 'AU_WA';
			}
		}

		return $customer_data;

	}

	/**
	 * Creates a new tag in Ontraport and returns the ID.
	 *
	 * @since  3.38.40
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( array( 'tag_name' => $tag_name ) );
		$response       = wp_safe_remote_post( 'https://api.ontraport.com/1/Tags', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->data->tag_id;
	}


	/**
	 * Set tracking cookie if enabled
	 *
	 * @access public
	 * @return mixed
	 */

	public function set_tracking_cookie() {

		if ( wpf_get_option( 'site_tracking' ) == false ) {
			return;
		}

		if ( wpf_is_user_logged_in() && ! isset( $_COOKIE['contact_id'] ) ) {

			$contact_id = wp_fusion()->user->get_contact_id();

			if ( ! empty( $contact_id ) ) {

				setcookie( 'contact_id', $contact_id, time() + DAY_IN_SECONDS * 180, COOKIEPATH, COOKIE_DOMAIN );

			}
		}

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

		$account_id = wpf_get_option( 'account_id' );

		echo '<!-- Ontraport -->';
		echo "<script src='https://optassets.ontraport.com/tracking.js' type='text/javascript' async='true' onload='_mri=\"" . esc_js( wpf_get_option( 'account_id' ) ) . "\",_mr_domain=\"tracking.ontraport.com\",mrtracking();'></script>";
		echo '<!-- end Ontraport -->';

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		// Ignore on find by email requests since they'll return a 400 error if no matching email is found
		if ( strpos( $url, 'ontraport' ) !== false && strpos( $url, 'getByEmail' ) === false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$body          = wp_remote_retrieve_body( $response );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 != $response_code ) {

				if ( 'Object not found' == $body && ! empty( $args['body'] ) ) {

					$data = json_decode( $args['body'], true );

					$user_id = wp_fusion()->user->get_user_id( $data['id'] );

					if ( $user_id ) {

						$data['id'] = wp_fusion()->user->get_contact_id( $user_id, true );

						if ( ! empty( $data['id'] ) ) {

							$args['body'] = wp_json_encode( $data );

							return wp_safe_remote_request( $url, $args );
						}
					}
				} elseif ( 'Invalid Contact ID' == $body && ! empty( $args['body'] ) ) {

					// Ecom addon

					$data = json_decode( $args['body'], true );

					$user_id = wp_fusion()->user->get_user_id( $data['contact_id'] );

					if ( $user_id ) {

						$data['contact_id'] = wp_fusion()->user->get_contact_id( $user_id, true );

						if ( ! empty( $data['contact_id'] ) ) {

							$args['body'] = wp_json_encode( $data );

							return wp_safe_remote_request( $url, $args );
						}
					}
				}

				if ( empty( $body ) ) {
					$body = wp_remote_retrieve_response_message( $response );
				}

				$response = new WP_Error( 'error', $body );

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

	public function get_params( $api_url = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_url ) || empty( $api_key ) ) {
			$api_url = wpf_get_option( 'op_url' );
			$api_key = wpf_get_option( 'op_key' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 20,
			'httpversion' => '1.1',
			'headers'     => array(
				'Api-Appid' => $api_url,
				'Api-Key'   => $api_key,
			),
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_url = null, $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_url, $api_key );
		}

		$request  = 'https://api.ontraport.com/1/objects/meta?format=byId&objectID=' . $this->object_type;
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
		$offset         = 0;
		$continue       = true;

		while ( $continue == true ) {

			$request  = 'https://api.ontraport.com/1/objects?objectID=14&start=' . $offset;
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['data'] as $row ) {

				if ( $row['object_type_id'] == $this->object_type ) {
					$available_tags[ $row['tag_id'] ] = $row['tag_name'];
				}
			}

			if ( count( $body_json['data'] ) < 50 ) {
				$continue = false;
			}

			$offset = $offset + 50;
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

		$crm_fields = array(
			'unique_id' => 'Unique ID (Read Only)', // adding this manually
		);

		$request  = 'https://api.ontraport.com/1/objects/meta?format=byId&objectID=' . $this->object_type;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		$dropdown_options = array();

		foreach ( $body_json['data'][ $this->object_type ]['fields'] as $key => $field_data ) {

			if ( false == $field_data['editable'] || 'subscription' == $field_data['type'] ) {
				continue;
			}

			$crm_fields[ $key ] = $field_data['alias'];

			// Let's save a cache of dropdown field values

			if ( isset( $field_data['options'] ) ) {

				$dropdown_options[ $key ] = $field_data['options'];

			}
		}

		wp_fusion()->settings->set( 'ontraport_dropdown_options', $dropdown_options );

		asort( $crm_fields );
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

		$request  = 'https://api.ontraport.com/1/object/getByEmail?objectID=' . $this->object_type . '&email=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['data'] ) ) {
			return false;
		}

		return $body_json['data']['id'];
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

		$contact_info = array();
		$request      = 'https://api.ontraport.com/1/object?objectID=' . $this->object_type . '&id=' . $contact_id;
		$response     = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['data']['contact_cat'] ) ) {
			return array();
		}

		$cat = array_filter( explode( '*/*', $body_json['data']['contact_cat'] ) );

		return $cat;
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

		$post_data = array(
			'objectID'           => $this->object_type,
			'add_list'           => implode( ',', $tags ),
			'ids'                => $contact_id,
			'background_request' => true, // see update_contact()
		);

		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $post_data );

		$response = wp_safe_remote_post( 'https://api.ontraport.com/1/objects/tag', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
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

		$post_data = array(
			'objectID'           => $this->object_type,
			'remove_list'        => implode( ',', $tags ),
			'ids'                => $contact_id,
			'background_request' => true, // see update_contact()
		);

		$params           = $this->params;
		$params['method'] = 'DELETE';
		$params['body']   = wp_json_encode( $post_data );

		$response = wp_safe_remote_post( 'https://api.ontraport.com/1/objects/tag', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
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

		// Referral data.
		if ( isset( $_COOKIE['aff_'] ) ) {
			$data['freferrer'] = sanitize_text_field( wp_unslash( $_COOKIE['aff_'] ) );
			$data['lreferrer'] = sanitize_text_field( wp_unslash( $_COOKIE['aff_'] ) );
		}

		if ( isset( $data['bulk_sms'] ) && '0' === $data['bulk_sms'] ) {
			$data['force_sms_opt_out'] = '1'; // this force opts-out even when "API SMS opt-ins" is enabled in OP.
		}

		// To automatically update Campaign / Lead Source / Medium relational fields. @link https://api.ontraport.com/doc/#add-utm-variables-by-name.
		$data['use_utm_names'] = true;

		if ( $this->object_type == 0 ) {
			$url = 'https://api.ontraport.com/1/Contacts/saveorupdate';
		} else {
			$url              = 'https://api.ontraport.com/1/objects';
			$data['objectID'] = $this->object_type;
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $body->data->id ) && isset( $body->data->attrs->id ) ) {

			return new WP_Error( 'error', 'Failed to add contact with email ' . $data['email'] . ', contact already exists with ID #' . $body->data->attrs->id );

		}

		return $body->data->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		// Referral data
		if ( isset( $_COOKIE['aff_'] ) ) {
			$data['lreferrer'] = sanitize_text_field( wp_unslash( $_COOKIE['aff_'] ) );
		}

		$data['objectID'] = $this->object_type;
		$data['id']       = $contact_id;

		$data['background_request'] = true; // Added by OP support, OP ticket #500416. Incoming data will be validated and we'll get a 200 response. OP will continue to process the API call in a background request.
		$data['use_utm_names']      = true; // @link https://api.ontraport.com/doc/#add-utm-variables-by-name.

		$params           = $this->get_params();;
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $data );

		$request  = 'https://api.ontraport.com/1/objects';
		$response = wp_safe_remote_request( $request, $params );

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

		$url      = 'https://api.ontraport.com/1/object?objectID=' . $this->object_type . '&id=' . $contact_id;
		$response = wp_safe_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$options        = wpf_get_option( 'ontraport_dropdown_options', array() );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json['data'][ $field_data['crm_field'] ] ) ) {

				$value = $body_json['data'][ $field_data['crm_field'] ];

				// Dates: Ontraport returns datetime fields in the time zone set on
				// the field (no conversion). We'll assume the timezone in OP
				// matches the site, and also not convert anything here.

				// Handle dropdowns and picklists.

				if ( isset( $options[ $field_data['crm_field'] ] ) && isset( $options[ $field_data['crm_field'] ][ $value ] ) ) {
					$value = $options[ $field_data['crm_field'] ][ $value ];
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

		while ( $proceed == true ) {

			$url     = 'https://api.ontraport.com/1/objects/tag?objectID=' . $this->object_type . '&tag_id=' . $tag . '&range=50&start=' . $offset . '&listFields=object_id';
			$results = wp_safe_remote_get( $url, $this->params );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['data'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			$offset = $offset + 50;

			if ( count( $body_json['data'] ) < 50 ) {
				$proceed = false;
			}
		}

		return $contact_ids;

	}

}
