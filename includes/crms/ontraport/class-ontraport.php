<?php

class WPF_Ontraport {

	// 
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

	public $supports;

	/**
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'ontraport';
		$this->name     = 'Ontraport';
		$this->supports = array();

		$this->object_type = 0;

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
		// add_filter( 'wpf_apply_tags', array( $this, 'create_new_tags' ) );

		// Add tracking code to footer
		add_action( 'init', array( $this, 'set_tracking_cookie' ) );
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

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

		$options = wp_fusion()->settings->get( 'ontraport_dropdown_options', array() );

		if ( 'datepicker' == $field_type || 'date' == $field_type && is_numeric( $value ) ) {

			// Dates are a unix timestamp and have to match the timezone set in the Ontraport account. For now we'll assume that is the same as the WP timezone

			// strtotime() in CRM_Base seems to give us UTC, so this will switch it back to local? I really have no idea....

			$offset = get_option( 'gmt_offset' );
			$value -= ( $offset * 60 * 60 );

			return $value;

		} elseif ( isset( $options[ $field ] ) ) {

			if ( 'multiselect' == $field_type || 'checkboxes' == $field_type ) {

				// Maybe convert multiselect options to picklist IDs if matches are found

				$values = explode( ',', $value );

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
	 * Creates new tags in Ontraport if needed
	 *
	 * @access public
	 * @return array Tags
	 */

	public function create_new_tags( $tags ) {

		foreach( $tags as $i => $tag_id ) {

			if( is_numeric( $tag_id ) || empty( $tag_id ) ) {
				continue;
			}

			// Remove the tag with a label from the list of IDs
			unset( $tags[ $i ] );

			$available_tags = wp_fusion()->settings->get( 'available_tags' );

			if( isset( $available_tags[ $tag_id ] ) ) {
				unset( $available_tags[ $tag_id ] );
			}

			$params = $this->get_params();
			$params['body'] = json_encode( array( 'tag_name' => $tag_id ) );
			$response = wp_remote_post( 'https://api.ontraport.com/1/Tags', $params );
			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if( is_wp_error( $response ) ) {
				return $tags;
			}

			$available_tags[ $response->data->tag_id ] = $tag_id;
			wp_fusion()->settings->set( 'available_tags', $available_tags );

			$tags[] = $response->data->tag_id;

		}

		return $tags;

	}


	/**
	 * Set tracking cookie if enabled
	 *
	 * @access public
	 * @return mixed
	 */

	public function set_tracking_cookie() {

		if( wp_fusion()->settings->get( 'site_tracking' ) == false ) {
			return;
		}

		if( wpf_is_user_logged_in() && ! isset( $_COOKIE['contact_id'] ) ) {

			$contact_id = wp_fusion()->user->get_contact_id();

			if( ! empty( $contact_id ) ) {

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

		if( wp_fusion()->settings->get( 'site_tracking' ) == false ) {
			return;
		}

		$account_id = wp_fusion()->settings->get('account_id');

		echo "<!-- Ontraport -->";
		echo "<script src='https://optassets.ontraport.com/tracking.js' type='text/javascript' async='true' onload='_mri=\"" . wp_fusion()->settings->get('account_id') . "\",_mr_domain=\"tracking.ontraport.com\",mrtracking();'></script>";
		echo "<!-- end Ontraport -->";

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		// Ignore on find by email requests since they'll return a 400 error if no matching email is found
		if( strpos( $url, 'ontraport' ) !== false && strpos( $url, 'getByEmail' ) === false ) {

			$body = wp_remote_retrieve_body( $response );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 != $response_code ) {

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
			$api_url = wp_fusion()->settings->get( 'op_url' );
			$api_key = wp_fusion()->settings->get( 'op_key' );
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

		$this->object_type = apply_filters( 'wpf_crm_object_type', $this->object_type );

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

		$request  = "https://api.ontraport.com/1/objects/meta?format=byId&objectID=" . $this->object_type;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
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

		while( $continue == true ) {

			$request  = 'https://api.ontraport.com/1/objects?objectID=14&start=' . $offset;
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['data'] as $row ) {

				if( $row['object_type_id'] == $this->object_type ) {
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

		$crm_fields = array();
		$request    = "https://api.ontraport.com/1/objects/meta?format=byId&objectID=" . $this->object_type;
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
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

		$contact_info = array();
		$request      = "https://api.ontraport.com/1/object/getByEmail?objectID=" . $this->object_type . "&email=" . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

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
		$request      = "https://api.ontraport.com/1/object?objectID=" . $this->object_type . "&id=" . $contact_id;
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
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
			'objectID' => $this->object_type,
			'add_list' => implode( ',', $tags ),
			'ids'      => $contact_id,
		);

		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = json_encode( $post_data );

		$response = wp_remote_post( 'https://api.ontraport.com/1/objects/tag', $params );

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
			'objectID'    => $this->object_type,
			'remove_list' => implode( ',', $tags ),
			'ids'         => $contact_id,
		);

		$params           = $this->params;
		$params['method'] = 'DELETE';
		$params['body']   = json_encode( $post_data );

		$response = wp_remote_post( 'https://api.ontraport.com/1/objects/tag', $params );

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

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data['email'] ) ) {
			return false;
		}

		// Referral data
		if ( isset( $_COOKIE['aff_'] ) ) {
			$data['freferrer'] = $_COOKIE['aff_'];
			$data['lreferrer'] = $_COOKIE['aff_'];
		}

		// To automatically update Campaign / Lead Source / Medium relational fields
		$data['use_utm_names'] = true;

		if ( $this->object_type == 0 ) {
			$url = 'https://api.ontraport.com/1/Contacts/saveorupdate';
		} else {
			$url = 'https://api.ontraport.com/1/objects';
			$data['objectID'] = $this->object_type;
		}

		$params           = $this->params;
		$params['body']   = json_encode( $data );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $body->data->id ) && isset( $body->data->attrs->id ) ) {

			return new WP_Error( 'error', 'Failed to add contact with email ' . $data['email'] . ', contact already exists with ID ' . $body->data->attrs->id );

		}

		return $body->data->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		// Referral data
		if ( isset( $_COOKIE['aff_'] ) ) {
			$data['lreferrer'] = $_COOKIE['aff_'];
		}

		$data['objectID'] = $this->object_type;
		$data['id']       = $contact_id;

		// $data['bulk_mail'] = 1; // Contacts can only be set to 0 (transactional) over the API

		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = json_encode( $data );

		$request = 'https://api.ontraport.com/1/objects';

		$response = wp_remote_post( $request, $params );

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

		$url      = "https://api.ontraport.com/1/object?objectID=" . $this->object_type . "&id=" . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$options        = wp_fusion()->settings->get( 'ontraport_dropdown_options', array() );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json['data'][ $field_data['crm_field'] ] ) ) {

				$value = $body_json['data'][ $field_data['crm_field'] ];

				// Handle dropdowns and picklists

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
		$offset = 0;
		$proceed = true;

		while($proceed == true) {

			$url     = "https://api.ontraport.com/1/objects/tag?objectID=" . $this->object_type . "&tag_id=" . $tag . "&range=50&start=" . $offset . "&listFields=object_id";
			$results = wp_remote_get( $url, $this->params );

			if( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['data'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			$offset = $offset + 50;

			if(count($body_json['data']) < 50) {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

}