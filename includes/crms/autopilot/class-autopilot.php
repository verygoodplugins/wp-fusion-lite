<?php

class WPF_Autopilot {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'autopilot';
		$this->name     = 'Autopilot';
		$this->supports = array();


		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Autopilot_Admin( $this->slug, $this->name, $this );
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

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ), 10, 1 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Tracking stuff
		add_action( 'init', array( $this, 'maybe_clear_tracking_cookie' ) );
		add_action( 'wp_footer', array( $this, 'identify' ), 100 );
		add_action( 'wpf_guest_contact_created', array( $this, 'set_tracking_cookie_forms' ), 10, 2 );
		add_action( 'wpf_guest_contact_updated', array( $this, 'set_tracking_cookie_forms' ), 10, 2 );

	}

	/**
	 * Clear tracking cookie if someone logs in
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_clear_tracking_cookie() {

		if ( wpf_is_user_logged_in() && ! empty( $_COOKIE['autopilot_id'] ) ) {
			setcookie( 'autopilot_id', false, time() - ( 15 * 60 ), COOKIEPATH, COOKIE_DOMAIN );
		}

	}

	/**
	 * Identify logged in user to tracking script
	 *
	 * @access public
	 * @return void
	 */

	public function identify() {

		if ( is_admin() ) {
			return;
		}

		$email = false;

		if ( wpf_is_user_logged_in() ) {

			$contact_id = wp_fusion()->user->get_contact_id();

			if ( ! empty( $contact_id ) ) {

				$user = wp_get_current_user();
				$email = $user->user_email;

			}

		} elseif ( ! empty( $_COOKIE['autopilot_id'] ) ) {

			$email = $_COOKIE['autopilot_id'];

		}

		if ( false !== $email ) {

			echo '<script type="text/javascript">
			  if (typeof Autopilot !== "undefined") {

			  	console.log("doing it");

				Autopilot.run("associate", {
					_simpleAssociate: true,  
					Email: "' . $email . '"
				});

			}
			</script>';

		}

	}

	/**
	 * Set tracking cookie after form submission
	 *
	 * @access public
	 * @return void
	 */

	public function set_tracking_cookie_forms( $contact_id, $email_address ) {

		setcookie( 'autopilot_id', $email_address, time() + DAY_IN_SECONDS * 180, COOKIEPATH, COOKIE_DOMAIN );

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id']))
			return $post_data;

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if( !is_object( $payload ) ) {
			return false;
		}

		if( $post_data['wpf_action'] == 'update' ) {

			$post_data['contact_id'] = $payload->contact_id;
			return $post_data;

		} elseif( $post_data['wpf_action'] == 'add' ) {

			$tag = wp_fusion()->settings->get('autopilot_add_tag');

			if( $payload->list_id == $tag[0] ) {
				$post_data['contact_id'] = $payload->contact_id;
				return $post_data;
			} else {
				return false;
			}

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'autopilothq') !== false && $args['user-agent'] == 'WP Fusion; ' . home_url()  ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) && $body_json->message !== 'Contact could not be found.' ) {

				$response = new WP_Error( 'error', $body_json->message );

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
			$access_key = wp_fusion()->settings->get( 'autopilot_key' );
		}

		$this->params = array(
			'timeout'     => 60,
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'headers'     => array(
				'autopilotapikey' => $access_key,
				'Content-type'	=> 'application/json'
			)
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_key = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api2.autopilothq.com/v1/contacts';
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

		$request    = 'https://api2.autopilothq.com/v1/lists';
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) ) {
			return false;
		}

		foreach ( $body_json->lists as $list ) {
			$available_tags[ $list->list_id ] = $list->title;
		}

		asort( $available_tags );

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

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/autopilot-fields.php';

		$built_in_fields = array();

		foreach ( $autopilot_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$request  = 'https://api2.autopilothq.com/v1/contacts/custom_fields';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		foreach( $response as $field ) {

			$custom_fields[ $field->name ] = ucwords( str_replace( '_', ' ', $field->name ) );

		}

		asort( $custom_fields );

		$crm_fields = array( 'Standard Fields' => $built_in_fields, 'Custom Fields' => $custom_fields );

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

		$request  = 'https://api2.autopilothq.com/v1/contact/' . urlencode( $email_address );
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $response ) || empty( $response->contact_id ) ) {
			return false;
		}

		return $response->contact_id;

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

		$request  = 'https://api2.autopilothq.com/v1/contact/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if( empty( $response->lists ) ) {
			return false;
		}

		foreach( $response->lists as $tag_id ) {
			$tags[] = $tag_id;
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

		foreach( $tags as $tag ) {

			$tag = $tag;

			$params = $this->params;;

			$request  = 'https://api2.autopilothq.com/v1/list/' . $tag . '/contact/' . $contact_id;
			$response = wp_remote_post( $request, $params );

			if( is_wp_error( $response ) ) {
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

		foreach( $tags as $tag ) {
			$tag = $tag;

			$params = $this->params;
			$params['method'] = 'DELETE';


			$request  = 'https://api2.autopilothq.com/v1/list/' . $tag . '/contact/' . $contact_id;
			$response = wp_remote_request( $request, $params );

			if( is_wp_error( $response ) ) {
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

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		require dirname( __FILE__ ) . '/admin/autopilot-fields.php';

		$update_data = array('contact' => array('custom' => array()));

		foreach( $data as $crm_field => $value ) {

			$date = date_parse($value);

			foreach( $autopilot_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data['contact'][$crm_field] = $value;

					continue 2;

				}

			}

			// Custom fields (for different field types as)

			$update_data['contact']['custom'][ 'string--' . str_replace(' ','--', $crm_field)] = $value;

			// if (is_string($value)) {
			// 	$update_data['contact']['custom'][ 'string--' . str_replace(' ','--', $crm_field)] = $value;
			// }

			// elseif (is_float($value)) {
			// 	$update_data['contact']['custom'][ 'float--' . str_replace(' ','--', $crm_field)] = $value;
			// }
			
			// elseif (is_bool($value)) {
			// 	$update_data['contact']['custom'][ 'boolean--' . str_replace(' ','--', $crm_field)] = $value;
			// }

			// //janky solution to find make sure value is a date
			// elseif ( $date !== false && checkdate($date["month"], $date["day"], $date["year"])) {
			// 	$update_data['contact']['custom'][ 'date--' . str_replace(' ','--', $crm_field)] = $value;
			// }

		}

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api2.autopilothq.com/v1/contact';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->contact_id;

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

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/autopilot-fields.php';

		$update_data = array('contact' => array('custom' => array()));

		foreach( $data as $crm_field => $value ) {

			$date = date_parse($value);

			foreach( $autopilot_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// If it's a built in field
					$update_data['contact'][$crm_field] = $value;

					continue 2;

				}

			}

			// Custom fields (for different field types aswell)

			$update_data['contact']['custom'][ 'string--' . str_replace(' ','--', $crm_field)] = $value;

			// if (is_string($value)) {
			// 	$update_data['contact']['custom'][ 'string--' . str_replace(' ','--', $crm_field)] = $value;
			// }

			// elseif (is_float($value)) {
			// 	$update_data['contact']['custom'][ 'float--' . str_replace(' ','--', $crm_field)] = $value;
			// }
			
			// elseif (is_bool($value)) {
			// 	$update_data['contact']['custom'][ 'boolean--' . str_replace(' ','--', $crm_field)] = $value;
			// }

			// //janky solution to find make sure value is a date
			// elseif ( $date !== false && checkdate($date["month"], $date["day"], $date["year"])) {
			// 	$update_data['contact']['custom'][ 'date--' . str_replace(' ','--', $crm_field)] = $value;
			// }

		}

		$params = $this->params;
		$params['body'] = json_encode( $update_data );

		$request  = 'https://api2.autopilothq.com/v1/contact';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
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

		$request  = 'https://api2.autopilothq.com/v1/contact/' . $contact_id;
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response      	= json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $response ) ) {
			return new WP_Error( 'error', 'Unable to find contact ID ' . $contact_id . ' in Autopilot.' );
		}

		if ( ! empty( $response->custom_fields ) ) {

			foreach ($response->custom_fields as $key => $custom_field) {

				foreach ( $contact_fields as $field_id => $field_data ) {

					if ( $field_data['active'] == true && ! empty( $response->{ $field_data['crm_field'] } ) ) {
						$user_meta[ $field_id ] = $response->{ $field_data['crm_field'] };
					}

				}

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

		$url     = 'https://api2.autopilothq.com/v1/list/'. $tag . '/contacts';
		$results = wp_remote_get( $url, $this->params );

		if( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		if ($body_json['total_contacts'] == 100) {
			$url     = 'https://api2.autopilothq.com/v1/list/' . $tag . '/contacts/'. $body_json['contacts'][100]['contact_id'];
			$results = wp_remote_get( $url, $this->params );
		}

		foreach ( $body_json['contacts'] as $row => $contact ) {
			$contact_ids[] = $contact['contact_id'];
		}

		return $contact_ids;


	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return int Rule ID
	 */

	public function register_webhook( $type ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if( $type == 'add' ) {
			$event_type = 'added_to_list';
		}	elseif( $type == 'update' ) {
			$event_type = 'updated';
		}

		$access_key = wp_fusion()->settings->get('access_key');

		$data = array(
			'target_url'    => get_home_url( null, '/?wpf_action=' . $type . '&access_key=' . $access_key ),
			'event' 		=> 'contact_'. $event_type
		);

		$request      		= 'https://api2.autopilothq.com/v1/hook';
		$params           	= $this->params;
		$params['method'] 	= 'POST';
		$params['body']  	= json_encode($data,JSON_UNESCAPED_SLASHES);

		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if(is_object($result)) {
			return $result->hook_id;
		} else {
			return false;
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function destroy_webhook( $rule_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request                = 'https://api2.autopilothq.com/v1/hook/' . $rule_id;
		$params           		= $this->params;
		$params['method'] 		= 'DELETE';

		$response     		    = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


}