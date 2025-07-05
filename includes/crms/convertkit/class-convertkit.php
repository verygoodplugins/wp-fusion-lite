<?php

class WPF_ConvertKit {

	//
	// Unsubscribes: ConvertKit can return a contact ID and tags from an unsubscribed subscriber. Subscriber's with a Cancelled status can be tagged, not sure about others
	//

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'convertkit';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'ConvertKit';

	/**
	 * Contains API secret
	 *
	 * @var string
	 */
	public $api_secret;

	/**
	 * Contains API key.
	 *
	 * @since 3.41.16
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array();

	/**
	 * Holds the API parameters.
	 *
	 * @since 3.36.6
	 * @var   params
	 */
	public $params;


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = 'https://app.convertkit.com/subscribers/%d';


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */
	public function __construct() {

		$this->api_secret = wpf_get_option( 'ck_secret' );

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
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

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( is_object( $payload ) ) {

			$post_data['contact_id'] = absint( $payload->subscriber->id );

			if ( wpf_get_option( 'ck_import_notification' ) ) { // This setting was removed in 3.38.11 in favor of the global Send Welcome Email setting.
				$post_data['send_notification'] = true;
			}

			// Remove the update tag so it can be applied again.

			if ( 'update' === $post_data['wpf_action'] ) {

				$user_id = wpf_get_user_id( absint( $payload->subscriber->id ) );

				$tag = wpf_get_option( 'ck_update_tag' );

				if ( ! empty( $tag ) ) {

					wpf_log( 'notice', $user_id, 'Removing update tag <strong>' . wpf_get_tag_label( $tag[0] ) . '</strong> in ConvertKit, so that it can be reapplied later if needed.' );

					$this->remove_tags( $tag, $post_data['contact_id'] );

				}
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

			$contact_id = absint( $payload->subscriber->id );

			$user_id = wp_fusion()->user->get_user_id( $contact_id );

			if ( ! empty( $user_id ) ) {

				$email = wpf_get_option( 'ck_notify_email' );

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

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Format it as date + time only if the timestamp does not come out to midnight

			if ( gmdate( 'H:i:s', $value ) !== '00:00:00' ) {

				return gmdate( wpf_get_datetime_format(), $value );

			} else {

				return gmdate( get_option( 'date_format' ), $value );

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

		if ( strpos( $url, 'convertkit' ) !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 429 == wp_remote_retrieve_response_code( $response ) ) {

				$response = new WP_Error( 'error', 'API limits exceeded. Try again in one minute.' );

			}
		}

		return $response;
	}

	/**
	 * Gets the parameters for API calls.
	 *
	 * @since  3.36.10
	 *
	 * @param  string $api_secret The api secret, if different from what's in
	 *                            the database.
	 * @return array  The API parameters.
	 */
	public function get_params( $api_secret = null ) {

		if ( ! empty( $api_secret ) ) {
			$this->api_secret = $api_secret;
		}

		$this->api_key = wpf_get_option( 'ck_key' );

		$this->params = array(
			'timeout'    => 15,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type'    => 'application/json',
				'integration_key' => 'GkKOUTUIJ4saFnsVhLe9Kw',
			),
		);

		return $this->params;
	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return int Rule ID
	 */
	public function register_webhook( $type, $tag ) {

		$access_key = wpf_get_option( 'access_key' );

		if ( $type == 'unsubscribe' ) {

			$data = array(
				'api_secret' => $this->api_secret,
				'target_url' => get_home_url( null, '/?wpf_action=ck_unsubscribed&access_key=' . $access_key ),
				'event'      => array( 'name' => 'subscriber.subscriber_unsubscribe' ),
			);

		} else {

			$data = array(
				'api_secret' => $this->api_secret,
				'target_url' => get_home_url( null, '/?wpf_action=' . $type . '&access_key=' . $access_key . '&send_notification=false' ),
				'event'      => array(
					'name'   => 'subscriber.tag_add',
					'tag_id' => $tag,
				),
			);

		}

		$response = wp_safe_remote_post(
			'https://api.convertkit.com/v3/automations/hooks',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $data ),
				'method'  => 'POST',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_object( $result ) ) {
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

		$data = array(
			'api_secret' => $this->api_secret,
		);

		$result = wp_safe_remote_request(
			'https://api.convertkit.com/v3/automations/hooks/' . $rule_id,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $data ),
				'method'  => 'DELETE',
			)
		);
	}

	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */
	public function connect( $api_secret = null, $test = false ) {

		if ( false == $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_secret );
		}

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/account?api_secret=' . $api_secret, $this->get_params() );
		$result   = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $result->error ) ) {

			// Handling for users who may mistake API key with API secret
			$result = json_decode( wp_remote_retrieve_body( wp_safe_remote_get( 'https://api.convertkit.com/v3/forms?api_key=' . $api_secret ) ) );

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

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/tags?api_secret=' . $this->api_secret, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		if ( isset( $result->tags ) && is_array( $result->tags ) ) {

			foreach ( $result->tags as $tag ) {
				$available_tags[ $tag->id ] = array(
					'label'    => $tag->name,
					'category' => 'Tags',
				);
			}
		}

		// Now we get the forms

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/forms?api_secret=' . $this->api_secret, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $result->forms as $form ) {
			$available_tags[ 'form_' . $form->id ] = array(
				'label'    => $form->name,
				'category' => 'Forms',
			);
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

		$crm_fields = array(
			'first_name'    => 'First Name',
			'email_address' => 'Email',
		);

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/subscribers?api_secret=' . $this->api_secret, $this->get_params() );

		if ( is_wp_error( $response ) ) {
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

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/subscribers?api_secret=' . $this->api_secret . '&email_address=' . urlencode( $email_address ) . '&status=all', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $result ) || empty( $result->subscribers ) || ! is_array( $result->subscribers ) ) {

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

		$contact_tags = array();

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '/tags?api_secret=' . $this->api_secret, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) || empty( $body->tags ) ) {
			return $contact_tags;
		}

		foreach ( $body->tags as $tag ) {
			$contact_tags[] = $tag->id;
		}

		// Possibly remove update / import tags

		if ( isset( $_REQUEST['wpf_action'] ) ) {

			$update_tag = wpf_get_option( 'ck_update_tag' );
			$import_tag = wpf_get_option( 'ck_add_tag' );

			if ( in_array( $update_tag[0], $contact_tags ) ) {

				unset( $contact_tags[ $update_tag[0] ] );
				$this->remove_tags( array( $update_tag[0] ), $contact_id );

			}

			if ( in_array( $import_tag[0], $contact_tags ) ) {

				unset( $contact_tags[ $import_tag[0] ] );
				$this->remove_tags( array( $import_tag[0] ), $contact_id );

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

		// Let's see if we're dealing with a form.

		$form_ids = array();

		foreach ( $tags as $i => $tag ) {

			if ( strpos( $tag, 'form_' ) !== false ) {
				$form_ids[] = str_replace( 'form_', '', $tag );
				unset( $tags[ $i ] );
			}
		}

		if ( ! empty( $form_ids ) && ! $this->api_key ) {

			wpf_log( 'notice', wpf_get_user_id( $contact_id ), 'ConvertKit API Key not set. Unable to subscribe to forms. Please set your API key in the WP Fusion settings on the Setup tab.' );

		} elseif ( ! empty( $form_ids ) && $this->api_key ) {

			// Tagging while adding someone to a form (resubscribes unsubscribed subscribers).

			$data = array(
				'api_key' => $this->api_key,
				'email'   => wp_fusion()->crm->get_email_from_cid( $contact_id ),
				'tags'    => implode( ',', $tags ),
			);

			$params         = $this->get_params();
			$params['body'] = wp_json_encode( $data );

			foreach ( $form_ids as $form_id ) {

				$response = wp_safe_remote_post( 'https://api.convertkit.com/v3/forms/' . $form_id . '/subscribe', $params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}
			}
		} else {

			// Regular tagging.

			$data = array(
				'api_secret' => $this->api_secret,
				'email'      => wp_fusion()->crm->get_email_from_cid( $contact_id ),
				'tags'       => implode( ',', $tags ),
			);

			$params         = $this->get_params();
			$params['body'] = wp_json_encode( $data );

			$response = wp_safe_remote_post( 'https://api.convertkit.com/v3/tags/' . $tags[0] . '/subscribe', $params );

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

		foreach ( $tags as $tag_id ) {

			$params           = $this->get_params();
			$params['method'] = 'DELETE';

			$response = wp_safe_remote_request( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '/tags/' . $tag_id . '?api_secret=' . $this->api_secret, $params );

			if ( is_wp_error( $response ) ) {
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
	public function add_contact( $data ) {

		if ( empty( $data['email_address'] ) ) {
			return false;
		}

		// Users can't be added without a tag, form, or sequence. For now we'll use a tag
		$assign_tags = wpf_get_option( 'assign_tags' );

		// If no tags configured, pick the first one in the account so the request doesn't fail
		if ( empty( $assign_tags ) ) {

			$available_tags = wp_fusion()->settings->get_available_tags_flat();
			reset( $available_tags );
			$assign_tags = array( key( $available_tags ) );

			wpf_log( 'notice', wpf_get_current_user_id(), 'Heads up: ConvertKit requires all new subscribers to be created with a tag. To avoid an API error, WP Fusion will create this subscriber with the <strong>' . wpf_get_tag_label( key( $available_tags ) ) . '</strong> tag. To prevent this from happening in the future, please select a tag for new subscribers at Settings &raquo; WP Fusion &raquo; General &raquo; Assign Tags.' );

		}

		$tag_string = implode( ',', $assign_tags );

		$post_data = array(
			'api_secret' => $this->api_secret,
			'email'      => $data['email_address'],
			'tags'       => $tag_string,
		);

		// First name is included in the top level of the subscription data
		if ( isset( $data['first_name'] ) ) {
			$post_data['first_name'] = $data['first_name'];
			unset( $data['first_name'] );
		}

		// Remove email from custom fields
		unset( $data['email_address'] );

		$post_data['fields'] = $data;

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $post_data );

		$response = wp_safe_remote_post( 'https://api.convertkit.com/v3/tags/' . $assign_tags[0] . '/subscribe', $params );

		if ( is_wp_error( $response ) ) {
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
	public function update_contact( $contact_id, $data ) {

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

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( $post_data );
		$params['method'] = 'PUT';

		$response = wp_safe_remote_request( 'https://api.convertkit.com/v3/subscribers/' . $contact_id, $params );

		if ( is_wp_error( $response ) ) {
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

		$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/subscribers/' . $contact_id . '?api_secret=' . $this->api_secret . '&status=all', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $result->subscriber ) ) {
			return new WP_Error( 'notice', 'No contact #' . $contact_id . ' found in ConvertKit.' );
		}

		$returned_contact_data = array(
			'first_name'    => $result->subscriber->first_name,
			'email_address' => $result->subscriber->email_address,
		);

		if ( isset( $result->subscriber->fields ) ) {
			foreach ( $result->subscriber->fields as $field_key => $value ) {
				$returned_contact_data[ $field_key ] = $value;
			}
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

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

		$contact_ids = array();
		$page        = 1;
		$proceed     = true;

		while ( $proceed == true ) {

			$response = wp_safe_remote_get( 'https://api.convertkit.com/v3/tags/' . $tag . '/subscriptions?api_secret=' . $this->api_secret . '&page=' . $page, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $result->subscriptions ) && is_array( $result->subscriptions ) ) {

				foreach ( $result->subscriptions as $subscription ) {
					$contact_ids[] = $subscription->subscriber->id;
				}

				if ( $result->total_pages == $page ) {
					$proceed = false;
				} else {
					++$page;
				}
			} else {
				$proceed = false;
			}
		}

		return $contact_ids;
	}
}
