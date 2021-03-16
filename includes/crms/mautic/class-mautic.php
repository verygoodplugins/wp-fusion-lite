<?php

class WPF_Mautic {

	/**
	 * URL to Mautic application
	 */

	public $url;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'mautic';
		$this->name     = 'Mautic';
		$this->supports = array( 'add_tags', 'combined_updates' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Mautic_Admin( $this->slug, $this->name, $this );
		}

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
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Add tracking code to footer
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

		// Set tracking cookie
		add_action( 'init', array( $this, 'set_tracking_cookie' ) );
		add_action( 'wpf_forms_post_submission', array( $this, 'set_tracking_cookie_forms' ), 10, 4 );

	}


	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( false == wp_fusion()->settings->get( 'site_tracking' ) || true == wp_fusion()->settings->get( 'staging_mode' ) ) {
			return;
		}

		$url = wp_fusion()->settings->get( 'mautic_url' );

		echo '<!-- WP Fusion Mautic site tracking -->';
		echo '<script>';
		echo '(function(w,d,t,u,n,a,m){w["MauticTrackingObject"]=n;';
		echo 'w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)},a=d.createElement(t),';
		echo 'm=d.getElementsByTagName(t)[0];a.async=1;a.src=u;m.parentNode.insertBefore(a,m)';
		echo '})(window,document,"script","' . trailingslashit( $url ) . 'mtc.js","mt");';

		if ( true == wp_fusion()->settings->get( 'advanced_site_tracking' ) && wpf_is_user_logged_in() ) {

			$user = get_userdata( wpf_get_current_user_id() );

			// This is required to track against the correct contact record for the current user

			// NB: Does not work if you're currently logged into Mautic with the same email, test incognito

			echo 'mt("send", "pageview", {"email": "' . $user->user_email . '"});';

		} else {

			echo 'mt("send", "pageview");';

		}

		echo '</script>';
		echo '<!-- End WP Fusion Mautic site tracking -->';

	}

	/**
	 * Set tracking cookie
	 *
	 * @access public
	 * @return void
	 */

	public function set_tracking_cookie() {

		if ( is_admin() || ! wpf_is_user_logged_in() || headers_sent() ) {
			return;
		}

		if ( true != wp_fusion()->settings->get( 'advanced_site_tracking' ) ) {
			return;
		}

		$contact_id = wp_fusion()->user->get_contact_id();

		if ( ! empty( $contact_id ) && ( ! isset( $_COOKIE['mtc_id'] ) || $_COOKIE['mtc_id'] != $contact_id ) ) {

			setcookie( 'mtc_id', $contact_id, time() + DAY_IN_SECONDS * 730, COOKIEPATH, COOKIE_DOMAIN );

		}

	}

	/**
	 * Set tracking cookie after form submission
	 *
	 * @access public
	 * @return void
	 */

	public function set_tracking_cookie_forms( $update_data, $user_id, $contact_id, $form_id ) {

		setcookie( 'mtc_id', $contact_id, time() + DAY_IN_SECONDS * 730, COOKIEPATH, COOKIE_DOMAIN );

	}

	/**
	 * Formats POST data received from webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( isset( $payload->{'mautic.lead_post_save_update'} ) ) {

			if ( isset( $payload->{'mautic.lead_post_save_update'}[0]->lead ) ) {

				$post_data['contact_id'] = $payload->{'mautic.lead_post_save_update'}[0]->lead->id;

				if ( ! empty( $payload->{'mautic.lead_post_save_update'}[0]->lead->tags ) ) {

					$post_data['tags'] = array();

					foreach ( $payload->{'mautic.lead_post_save_update'}[0]->lead->tags as $tag ) {
						$post_data['tags'][] = $tag->tag;
					}
				}
			} elseif ( isset( $payload->{'mautic.lead_post_save_update'}[0]->contact ) ) {

				$post_data['contact_id'] = $payload->{'mautic.lead_post_save_update'}[0]->contact->id;

				if ( ! empty( $payload->{'mautic.lead_post_save_update'}[0]->contact->tags ) ) {

					$post_data['tags'] = array();

					foreach ( $payload->{'mautic.lead_post_save_update'}[0]->contact->tags as $tag ) {
						$post_data['tags'][] = $tag->tag;
					}
				}
			}

			// Deal with changing contact IDs

			$user_id = wp_fusion()->user->get_user_id( $post_data['contact_id'] );

			if ( empty( $user_id ) ) {

				$email = $payload->{'mautic.lead_post_save_update'}[0]->lead->fields->core->email->value;

				$user = get_user_by( 'email', $email );

				if ( ! empty( $user ) ) {

					update_user_meta( $user->ID, 'mautic_contact_id', $contact_id );

				}
			}
		}

		return $post_data;

	}

	/**
	 * Formats user entered data to match Mautic field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( "Y-m-d", $value );

			return $date;

		} elseif ( ( $field_type == 'checkboxes' || $field_type == 'multiselect' ) && ! is_array( $value ) ) {

			return explode( ',', $value );

		} elseif ( $field_type == 'country' ) {

			$countries = include dirname( __FILE__ ) . '/includes/countries.php';

			if( isset( $countries[$value] ) ) {

				return $countries[$value];

			} else {

				return $value;

			}

		} elseif ( $field_type == 'state' ) {

			$states = include dirname( __FILE__ ) . '/includes/states.php';

			if( isset( $states[$value] ) ) {

				return $states[$value];

			} else {

				// Try and fix foreign characters

				// ã
				$value = str_replace('&atilde;', 'a', $value);

				// á
				$value = str_replace('&aacute;', 'a', $value);

				// é
				$value = str_replace('&eacute;', 'e', $value);

				return $value;

			}

		} else {

			return $value;

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( ! empty( $this->url ) && strpos( $url, $this->url ) !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0]->message );

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

	public function get_params( $mautic_url = null, $mautic_username = null, $mautic_password = null ) {

		// Get saved data from DB
		if ( empty( $mautic_url ) || empty( $mautic_username ) || empty($mautic_password) ) {
			$mautic_url = wp_fusion()->settings->get( 'mautic_url' );
			$mautic_username = wp_fusion()->settings->get( 'mautic_username' );
			$mautic_password = wp_fusion()->settings->get( 'mautic_password' );
		}

		$auth_key = base64_encode($mautic_username . ':' . $mautic_password);

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Basic ' . $auth_key
			)
		);

		$this->url = trailingslashit( $mautic_url );

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $mautic_url = null, $mautic_username = null, $mautic_password = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $mautic_url, $mautic_username, $mautic_password );
		}

		if( $test == true ) {

			$request  = $this->url . 'api/contacts';
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body->errors ) ) {

				if( $body->errors[0]->code == 404 ) {

					return new WP_Error( $body->errors[0]->code, '404 error. This sometimes happens when you\'ve just enabled the API, and your cache needs to be rebuilt. See <a href="https://mautic.org/docs/en/tips/troubleshooting.html" target="_blank">here for more info</a>.' );

				} elseif( $body->errors[0]->code == 403 ) {

					return new WP_Error( $body->errors[0]->code, '403 error. You need to enable the API from within Mautic\'s configuration settings for WP Fusion to connect.' );

				} else {

					return new WP_Error( $body->errors[0]->code, $body->errors[0]->message );

				}

			} elseif ( empty( $body ) ) {

				return new WP_Error( 'error', 'This is not a valid URL. Please enter the base URL to your Mautic install.' );

			}

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

		$request  = $this->url . 'api/tags?limit=5000';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if( ! empty( $body_json['tags'] ) ) {

			foreach( $body_json['tags'] as $tag ) {

				$available_tags[ $tag['tag'] ] = $tag['tag'];

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

		$crm_fields = array();
		$request  = $this->url . 'api/fields/contact?limit=1000';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach( $body_json['fields'] as $field ) {

			$crm_fields[ $field['alias'] ] = $field['label'];

		}

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
		$request      = $this->url . 'api/contacts?search=' . urlencode( 'email:"+' . $email_address . '"' ) . '&minimal=true';
		$response     = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['contacts'] ) ) {

			// Use tracking cookie if CORS is configured properly and the tracked contact doesn't have an email address

			if ( true == wp_fusion()->settings->get( 'advanced_site_tracking' ) ) {

				if ( isset( $_COOKIE['mtc_id'] ) && ( ! is_admin() || defined( 'DOING_AJAX' ) ) ) {

					$contact_data = $this->load_contact( $_COOKIE['mtc_id'] );

					if ( ! is_wp_error( $contact_data ) && empty( $contact_data['user_email'] ) ) {

						return $_COOKIE['mtc_id'];

					}
				}
			}

			return false;
		}

		$contact = array_shift( $body_json['contacts'] );

		return $contact['id'];
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
		$request      = $this->url . 'api/contacts/' . $contact_id;
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		$contact_tags = array();

		if ( empty( $body_json['contact']['tags'] ) ) {
			return $contact_tags;
		}


		$found_new = false;
		$available_tags = wp_fusion()->settings->get('available_tags');

		foreach( $body_json['contact']['tags'] as $tag ) {
			
			$contact_tags[] = $tag['tag'];

			// Handle tags that might not have been picked up by sync_tags
			if( !isset( $available_tags[$tag['tag']] ) ) {
				$available_tags[$tag['tag']] = $tag['tag'];
				$found_new = true;
			}

		}

		if( $found_new ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}

		return $contact_tags;
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

		$request      		= $this->url . 'api/contacts/' . $contact_id . '/edit';
		$params           	= $this->params;
		$params['method'] 	= 'PATCH';
		$params['body']  	= array('tags' => $tags);

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
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

		// Prefix with - sign for removal
		foreach( $tags as $i => $tag ) {
			$tags[$i] = '-' . $tag;
		}

		$request      		= $this->url . 'api/contacts/' . $contact_id . '/edit';
		$params           	= $this->params;
		$params['method'] 	= 'PATCH';
		$params['body']  	= array('tags' => $tags);

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
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

		$data['ipAddress'] = $_SERVER['REMOTE_ADDR'];

		$request 		= $this->url . 'api/contacts/new';
		$params 		= $this->params;
		$params['body'] = $data;

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body->errors ) ) {
			return new WP_Error( 'error', $body->errors[0]->message );
		}

		if ( ! is_admin() ) {

			// Set cookie
			setcookie( 'mtc_id', $body->contact->id, time() + DAY_IN_SECONDS * 730, COOKIEPATH, COOKIE_DOMAIN );

		}

		return $body->contact->id;

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

		if( empty( $data ) ) {
			return false;
		}

		$request          = $this->url . 'api/contacts/' . $contact_id . '/edit';
		$params           = $this->params;
		$params['method'] = 'PATCH';
		$params['body']   = $data;

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {

			if ( $response->get_error_message() == 'Item was not found.' ) {

				// Deal with changed CIDs

				$user_id = wp_fusion()->user->get_user_id( $contact_id );

				if ( ! empty( $user_id ) ) {

					$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

					if ( ! empty( $contact_id ) ) {
						return $this->update_contact( $contact_id, $data, false );
					}
				}
			} else {

				return $response;

			}
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body->errors ) ) {
			return new WP_Error( 'error', $body->errors[0]->message );
		}

		return true;
	}

	/**
	 * Updates contact and applies / removes tags in a single API call
	 *
	 * @access public
	 * @return bool
	 */

	public function combined_update( $contact_id, $update_data ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( isset( $update_data['update_contact'] ) ) {
			$data = $update_data['update_contact'];
		} else {
			$data = array();
		}

		if ( isset( $update_data['apply_tags'] ) ) {
			$data['tags'] = $update_data['apply_tags'];
		}

		// Append minus sign for tag removal

		if ( isset( $update_data['remove_tags'] ) ) {

			foreach ( $update_data['remove_tags'] as $tag ) {
				$data['tags'][] = '-' . $tag;
			}

		}

		$result = $this->update_contact( $contact_id, $data, false );

		return $result;

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

		$url      = $this->url . 'api/contacts/' . $contact_id;;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body_json['contact']['fields']['all'][ $field_data['crm_field'] ] )) {
				$user_meta[ $field_id ] = $body_json['contact']['fields']['all'][ $field_data['crm_field'] ];

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

		$start       = 0;
		$proceeed    = true;
		$contact_ids = array();

		while ( $proceeed ) {

			$url      = $this->url . 'api/contacts?limit=1000&minimal=true&search=' . urlencode( 'tag:"+' . $tag . '"' ) . '&start=' . $start;
			$response = wp_remote_get( $url, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->contacts ) ) {

				foreach ( $response->contacts as $contact ) {

					$contact_ids[] = $contact->id;

				}
			}

			$start += 1000;

			if ( $response->total <= $start ) {
				$proceeed = false;
			}
		}

		return $contact_ids;

	}

}