<?php

class WPF_MailerLite {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */

	public $tag_type = 'Group';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'mailerlite';
		$this->name     = 'MailerLite';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_MailerLite_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Slow down the batch processses to get around API limits
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

	}


	/**
	 * Slow down batch processses to get around the 3600 requests per hour limit
	 *
	 * @access public
	 * @return int Sleep time
	 */

	public function set_sleep_time( $seconds ) {

		return 2;
		
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

			$post_data['contact_id'] = $payload->events[0]->data->subscriber->id;
			return $post_data;

		} elseif( $post_data['wpf_action'] == 'add' ) {

			$tag = wp_fusion()->settings->get('mailerlite_add_tag');

			if( $payload->events[0]->data->group->id == $tag[0] ) {
				$post_data['contact_id'] = $payload->events[0]->data->subscriber->id;
				return $post_data;
			} else {
				return false;
			}

		}

	}

	/**
	 * Formats user entered data to match Mailerlite field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

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

		if( strpos($url, 'mailerlite') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif( wp_remote_retrieve_response_code( $response ) == 429 ) {

				$response = new WP_Error( 'error', 'API limits exceeded.' );

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

	public function get_params( $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key )) {
			$api_key = wp_fusion()->settings->get( 'mailerlite_key' );
		}

		$this->params = array(
			'timeout'     => 30,
			'headers'     => array(
				'X-MailerLite-ApiKey' => $api_key,
				'Content-Type'  	  => 'application/json'
			)
		);

		return $this->params;
	}


	/**
	 * AgileCRM sometimes requires an email to be submitted when contacts are modified
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users( array( 'meta_key'   => 'mailerlite_contact_id',
		                           'meta_value' => $contact_id,
		                           'fields'     => array( 'user_email' )
		) );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;
			
		} else {
			
			// Try an API lookup

			$data = $this->load_contact( $contact_id );

			if( ! is_wp_error( $data ) && ! empty( $data['user_email'] ) ) {

				return $data['user_email'];

			} else {

				return false;

			}

		}


	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.mailerlite.com/api/v2/groups';
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

		$offset = 0;
		$continue = true;

		while ( $continue == true ) {

			$request  = 'https://api.mailerlite.com/api/v2/groups?offset=' . $offset;
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json as $row ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}

			if( count( $body_json ) < 2 ) {
				$continue = false;
			}

			$offset = $offset + 100;

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
		$request    =  "https://api.mailerlite.com/api/v2/fields";
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $key => $field_data ) {
			$crm_fields[$field_data['key']] =  ucwords( str_replace( '_', ' ', $field_data[ 'key' ] ) );
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
		$request      = 'https://api.mailerlite.com/api/v2/subscribers/' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) && $response->get_error_message() == 'Subscriber not found' ) {

			return false;

		} elseif( is_wp_error( $response ) ) {

			return $response;
			
		}

		$body_json    = json_decode( $response['body'], true );

		if ( empty( $body_json['fields'] ) ) {
			return false;
		}

		return $body_json['id'];
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

		$tags 		= array();
		$request    = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id . '/groups';
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return false;
		}

		foreach ( $body_json as $row ) {
			$tags[] = $row['id'];
		}

		// Check if we need to update the available tags list
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $body_json as $row ) {
			if( !isset( $available_tags[ $row['id'] ] ) ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$email = $this->get_email_from_cid( $contact_id );

		foreach ($tags as $tag) {

			$request      		= 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers';
			$params           	= $this->params;
			$params['method'] 	= 'POST';
			$params['body']  	= json_encode(array('email' => $email));

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

		foreach ($tags as $tag) {

			$request                = 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers/' . $contact_id;
			$params           		= $this->params;
			$params['method'] 		= 'DELETE';
			
			$response     		    = wp_remote_post( $request, $params );

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


		$url              = 'https://api.mailerlite.com/api/v2/subscribers';
		$params           = $this->params;
		$params['body']   = json_encode( $data );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;

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

		$url              = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
		$data['objectID']  = 0;
		$data['id']        = $contact_id;
		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = json_encode( $data );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		// Check for changes in email address if enabled

		if( wp_fusion()->settings->get( 'email_changes' ) == 'duplicate' ) {

			$contact_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if( $contact_data['email'] != $data['email'] ) {

				// Add new contact with updated email
				$original_email = $contact_data['email'];

				$contact_data['email'] = $data['email'];
				unset( $contact_data['id'] );

				$url              = 'https://api.mailerlite.com/api/v2/subscribers';
				$params           = $this->params;
				$params['body']   = json_encode( $contact_data );

				$response = wp_remote_post( $url, $params );

				if( is_wp_error( $response ) ) {
					return $response;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				// Save the new contact ID
				$user_id = wp_fusion()->user->get_user_id( $contact_id );
				update_user_meta( $user_id, 'mailerlite_contact_id', $body->id );

				// Get the contact's previous tags
				$tags = $this->get_tags( $contact_id );

				if( ! empty( $tags ) ) {

					// Apply the tags to the new contact
					$this->apply_tags( $tags, $body->id );

				}

				// Delete the original contact
				$params = $this->params;
				$params['method'] = 'DELETE';

				wp_remote_request( 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id, $params );

				wp_fusion()->logger->handle( 'notice', $user_id, 'User email changed from <strong>' . $original_email . '</strong> to <strong>' . $data['email'] . '</strong>. Contact ID updated from <strong>' . $contact_id . '</strong> to <strong>' . $body->id . '</strong>.', array( 'source' => 'mailerlite' ) );


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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url      = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $body_json['fields'] as $field ) {
			
			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $field['key'] == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field['value'];
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

		$url     = 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers';
		$results = wp_remote_get( $url, $this->params );

		if( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		foreach ( $body_json as $row => $contact ) {
			$contact_ids[] = $contact['id'];
		}

		return $contact_ids;

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return array Rule IDs
	 */

	public function register_webhooks( $type ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$event_types = array();

		if( $type == 'add' ) {

			$event_types[] = 'add_to_group';

		} elseif( $type == 'update' ) {

			$event_types[] = 'update';
			$event_types[] = 'add_to_group';
			$event_types[] = 'remove_from_group';

		}

		$access_key = wp_fusion()->settings->get('access_key');

		$ids = array();

		foreach( $event_types as $event_type ) {

			if( $event_type == 'update' ) {
				$type = 'update';
			} else {
				$type = 'update_tags';
			}

			$data = array(
				'url'   => get_home_url( null, '/?wpf_action=' . $type . '&access_key=' . $access_key ),
				'event' => 'subscriber.' . $event_type
			);

			$request      		= 'http://api.mailerlite.com/api/v2/webhooks';
			$params           	= $this->params;
			$params['method'] 	= 'POST';
			$params['body']  	= json_encode( $data );

			$response = wp_remote_post( $request, $params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

			if(is_object($result)) {
				$ids[] = $result->id;
			}

		}

		return $ids;

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

		$request                = 'http://api.mailerlite.com/api/v2/webhooks/' . $rule_id;
		$params           		= $this->params;
		$params['method'] 		= 'DELETE';

		$response     		    = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

}