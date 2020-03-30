<?php

class WPF_ConvertKit {

	//
	// Unsubscribes: ConvertKit can return a contact ID and tags from an unsubscribed subscriber. Subscriber's with a Cancelled status can be tagged, not sure about others
	//

	/**
	 * Contains API secret
	 */

	public $api_secret;

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

		$this->slug     = 'convertkit';
		$this->name     = 'ConvertKit';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_ConvertKit_Admin( $this->slug, $this->name, $this );
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
		add_action( 'wpf_ck_unsubscribed', array( $this, 'process_unsubscribe' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Slow down the batch processses to get around the 120 requests per minute limit
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

	}

	/**
	 * Slow down batch processses to get around the 120 requests per minute limit
	 *
	 * @access public
	 * @return int Sleep time
	 */

	public function set_sleep_time( $seconds ) {

		return 1;

	}

	/**
	 * Formats POST data received from webhooks Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id']))
			return $post_data;

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if( is_object( $payload ) ) {

			$post_data['contact_id'] = $payload->subscriber->id;

			if ( true == wp_fusion()->settings->get( 'ck_import_notification' ) ) {
				$post_data['send_notification'] = true;
			}

			// Remove tags

			if( $_REQUEST['wpf_action'] == 'add' ) {

				$tag = wp_fusion()->settings->get('ck_add_tag');
				$this->remove_tags( array( $tag ), $post_data['contact_id'] );

			} elseif( $_REQUEST['wpf_action'] == 'update' ) {

				$tag = wp_fusion()->settings->get('ck_update_tag');
				$this->remove_tags( array( $tag ), $post_data['contact_id'] );

			}

		}

		return $post_data;

	}

	/**
	 * Handles unsubscribe notifications and sends notification email
	 *
	 * @access public
	 * @return void
	 */

	public function process_unsubscribe() {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( is_object( $payload ) ) {

			$contact_id = $payload->subscriber->id;

			$user_id = wp_fusion()->user->get_user_id( $contact_id );

			if ( ! empty( $user_id ) ) {

				$email = wp_fusion()->settings->get( 'ck_notify_email' );

				$user = get_user_by( 'id', $user_id );

				wp_mail( $email, 'WP Fusion - Unsubscribe Notification', 'User with email ' . $user->user_email . ' has unsubscribed from marketing in ConvertKit.' );

			}

		}

		wp_die( 'Success', 'Success', 200 );


	}

	/**
	 * Formats user entered data to match ConvertKit field formats
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

		if( strpos($url, 'convertkit') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 429 == wp_remote_retrieve_response_code( $response ) ) {

				$response = new WP_Error( 'error', 'API limits exceeded. Try again in one minute.' );

			}

		}

		return $response;

	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return int Rule ID
	 */

	public function register_webhook($type, $tag) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$access_key = wp_fusion()->settings->get('access_key');

		if ( $type == 'unsubscribe' ) {

			$data = array(
				'api_secret' 	=> $this->api_secret,
				'target_url'    => get_home_url(null, '/?wpf_action=ck_unsubscribed&access_key=' . $access_key ),
				'event'			=> array( 'name' => 'subscriber.subscriber_unsubscribe' )
			);


		} else {

			$data = array(
				'api_secret' 	=> $this->api_secret,
				'target_url'    => get_home_url(null, '/?wpf_action=' . $type . '&access_key=' . $access_key . '&send_notification=false'),
				'event'			=> array( 'name' => 'subscriber.tag_add', 'tag_id' => $tag )
			);

		}

		$response = wp_remote_post( 'https://api.convertkit.com/v3/automations/hooks', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $data ),
			'method'  => 'POST'
		) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if(is_object($result)) {
			return $result->rule->id;
		} else {
			return 0;
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function destroy_webhook( $rule_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$data = array(
			'api_secret' 	=> $this->api_secret,
		);

		$result = wp_remote_request( 'https://api.convertkit.com/v3/automations/hooks/' . $rule_id, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $data ),
			'method'  => 'DELETE'
		));

	}

	/**
	 * ConvertKit requires an email to be submitted when tags are applied/removed
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users( array( 'meta_key'   => 'convertkit_contact_id',
		                           'meta_value' => $contact_id,
		                           'fields'     => array( 'user_email' )
		) );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			// Try and get it via API call
			
			if ( is_wp_error( $this->connect() ) ) {
				return false;
			}

			$response = wp_remote_get( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '?api_secret=' . $this->api_secret );

			if( is_wp_error( $response ) ) {
				return false;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! isset( $result->subscriber ) ) {
				return false;
			}

			return $result->subscriber->email_address;

		}


	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_secret = null, $test = false ) {

		if ( ! empty( $this->api_secret ) ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $api_secret ) ) {
			$this->api_secret = wp_fusion()->settings->get( 'ck_secret' );
		}

		if ( $test == false ) {
			return true;
		}

		$result = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://api.convertkit.com/v3/subscribers?api_secret=' . $api_secret ) ) );

		if ( isset( $result->error ) ) {

			// Handling for users who may mistake API key with API secret
			$result = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://api.convertkit.com/v3/forms?api_key=' . $api_secret ) ) );

			if ( isset( $result->error ) ) {
				return new WP_Error( 'error', $result->error . ' - ' . $result->message );
			} else {
				return new WP_Error( 'warning', 'You\'ve entered your API Key. WP Fusion requires your <strong>API Secret</strong> to function properly. This can be found below the API key in your account settings.' );
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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = wp_remote_get( 'https://api.convertkit.com/v3/tags?api_secret=' . $this->api_secret );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if ( isset( $result->tags ) && is_array( $result->tags ) ) {

			foreach ( $result->tags as $tag ) {
				$available_tags[ $tag->id ] = $tag->name;
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

		$crm_fields = array(
			'first_name'    => 'First Name',
			'email_address' => 'Email'
		);

		$response = wp_remote_get( 'https://api.convertkit.com/v3/subscribers?api_secret=' . $this->api_secret );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $result->subscribers ) && is_array( $result->subscribers ) ) {

			foreach ( $result->subscribers[0]->fields as $field_key => $field_value ) {
				$crm_fields[ $field_key ] = ucwords( str_replace( '_', ' ', $field_key ) );
			}

		}

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = wp_remote_get( 'https://api.convertkit.com/v3/subscribers?api_secret=' . $this->api_secret . '&email_address=' . urlencode( $email_address ) . '&status=all' );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty($result) || empty( $result->subscribers ) || ! is_array( $result->subscribers ) ) {

			return false;

		}

		return $result->subscribers[0]->id;

	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_tags = array();

		$response = wp_remote_get( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '/tags?api_secret=' . $this->api_secret );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $body ) || empty( $body->tags ) ) {
			return $contact_tags;
		}

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $body->tags as $tag ) {
			$contact_tags[] = $tag->id;

			if( !isset( $available_tags[$tag->id] ) ) {
				$available_tags[$tag->id] = $tag->name;
			}

		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		// Possibly remove update / import tags

		if( isset( $_REQUEST['wpf_action'] ) ) {

			$update_tag = wp_fusion()->settings->get('ck_update_tag');
			$import_tag = wp_fusion()->settings->get('ck_add_tag');

			if( in_array( $update_tag[0], $contact_tags ) ) {

				unset( $contact_tags[$update_tag[0]] );
				$this->remove_tags( array($update_tag[0]), $contact_id );

			}

			if( in_array( $import_tag[0], $contact_tags ) ) {

				unset( $contact_tags[$import_tag[0]] );
				$this->remove_tags( array($import_tag[0]), $contact_id );

			}

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$tag_string = implode( ',', $tags );

		$email_address = $this->get_email_from_cid( $contact_id );

		$data = array(
			'api_secret' => $this->api_secret,
			'email'      => $email_address,
			'tags'       => $tag_string
		);

		$response = wp_remote_post( 'https://api.convertkit.com/v3/tags/' . $tags[0] . '/subscribe', array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => json_encode( $data ),
					'method'  => 'POST'
				) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		foreach ( $tags as $tag_id ) {

			$response = wp_remote_request( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '/tags/' . $tag_id . '?api_secret=' . $this->api_secret, array(
				'method' => 'DELETE'
			) );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return null;
		}

		if ( empty( $data['email_address'] ) ) {
			return false;
		}

		// Users can't be added without a tag, form, or sequence. For now we'll use a tag
		$assign_tags = wp_fusion()->settings->get( 'assign_tags' );

		// If no tags configured, pick the first one in the account so the request doesn't fail
		if ( empty( $assign_tags ) ) {

			$available_tags = wp_fusion()->settings->get( 'available_tags' );
			reset( $available_tags );
			$assign_tags = array( key( $available_tags ) );

		}

		$tag_string = implode( ',', $assign_tags );

		$post_data = array(
			'api_secret' => $this->api_secret,
			'email'      => $data['email_address'],
			'tags'       => $tag_string
		);

		// First name is included in the top level of the subscription data
		if ( isset( $data['first_name'] ) ) {
			$post_data['first_name'] = $data['first_name'];
			unset( $data['first_name'] );
		}

		// Remove email from custom fields
		unset( $data['email_address'] );

		$post_data['fields'] = $data;

		$response = wp_remote_post( 'https://api.convertkit.com/v3/tags/' . $assign_tags[0] . '/subscribe', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $post_data ),
			'method'  => 'POST'
		) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $result->subscription ) ) {
			return false;
		}

		return $result->subscription->subscriber->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( empty( $data ) ) {
			return false;
		}


		$post_data = array( 'api_secret' => $this->api_secret );

		// First name is included in the top level of the subscription data
		if ( isset( $data['first_name'] ) ) {
			$post_data['first_name'] = $data['first_name'];
			unset( $data['first_name'] );
		}

		if ( isset( $data['email_address'] ) ) {
			$post_data['email_address'] = $data['email_address'];
			unset( $data['email_address'] );
		}

		$post_data['fields'] = $data;

		$response = wp_remote_request( 'https://api.convertkit.com/v3/subscribers/' . $contact_id, array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => json_encode( $post_data ),
					'method'  => 'PUT'
				) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = wp_remote_get( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '?api_secret=' . $this->api_secret . '&status=all' );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $result->subscriber ) ) {
			return new WP_Error( 'notice', 'No contact with ID ' . $contact_id . ' found in ConvertKit.' );
		}

		$returned_contact_data = array(
			'first_name'    => $result->subscriber->first_name,
			'email_address' => $result->subscriber->email_address
		);

		if ( isset( $result->subscriber->fields ) ) {
			foreach ( $result->subscriber->fields as $field_key => $value ) {
				$returned_contact_data[ $field_key ] = $value;
			}
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $returned_contact_data[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $returned_contact_data[ $field_data['crm_field'] ];
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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_ids = array();
		$page = 1;
		$proceed = true;

		while( $proceed == true ) {

			$response = wp_remote_get( 'https://api.convertkit.com/v3/tags/' . $tag . '/subscriptions?api_secret=' . $this->api_secret . '&page=' . $page );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $result->subscriptions ) && is_array( $result->subscriptions ) ) {

				foreach ( $result->subscriptions as $subscription ) {
					$contact_ids[] = $subscription->subscriber->id;
				}

				if( $result->total_pages == $page ) {
					$proceed = false;
				} else {
					$page++;
				}

			} else {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

}