<?php

class WPF_MailerLite {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'mailerlite';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'MailerLite';

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array();

	/**
	 * Base URL for API requests. Changes depending if the API key is v1 or v2.
	 *
	 * @since 3.40.55
	 * @var  string
	 */
	public $api_url = '';

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */
	public $edit_url = '';

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var string
	 */
	public $tag_type = 'Group';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_MailerLite_Admin( $this->slug, $this->name, $this );
		}

		// API URL.
		if ( $this->is_v2() ) {
			$this->api_url  = 'https://connect.mailerlite.com/api/';
			$this->edit_url = 'https://dashboard.mailerlite.com/subscribers/%d';
		} else {
			$this->api_url  = 'https://api.mailerlite.com/api/v2/';
			$this->edit_url = 'https://app.mailerlite.com/subscribers/single/%d';
		}

		// This has to run before init to be ready for WPF_Auto_Login::start_auto_login().
		add_filter( 'wpf_auto_login_contact_id', array( $this, 'auto_login_contact_id' ) );

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

		// Slow down the batch processses to get around API limits.
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

	}

	/**
	 * Checks the API version based on the length of the API key.
	 *
	 * @since 3.40.55
	 *
	 * @return bool True if v2, false if v1.
	 */
	public function is_v2() {

		if ( 32 < strlen( wpf_get_option( 'mailerlite_key' ) ) ) {
			return true;
		} else {
			return false;
		}

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
	 * Outputs the MailerLite tracking code in the header.
	 *
	 * @since 3.41.15
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		$account_id = wpf_get_option( 'ml_account_id' );

		if ( empty( $account_id ) ) {
			// in case they're activating the feature after setting up the plugin pre 3.41.15.
			$account_id = $this->connect( null, true );
		}

		?>

		<!-- MailerLite Universal (by WP Fusion) -->
		<script>
			(function(w,d,e,u,f,l,n){w[f]=w[f]||function(){(w[f].q=w[f].q||[])
			.push(arguments);},l=d.createElement(e),l.async=1,l.src=u,
			n=d.getElementsByTagName(e)[0],n.parentNode.insertBefore(l,n);})
			(window,document,'script','https://assets.mailerlite.com/js/universal.js','ml');
			ml('account', '<?php echo esc_js( $account_id ); ?>');
		</script>
		<!-- End MailerLite Universal -->


		<?php

	}


	/**
	 * Extract subscriber data from the webhook payload.
	 *
	 * @since 3.10.0
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @param array $post_data The data POSTed to the endpoint.
	 * @return array|bool The data to import or false if the webhook payload is invalid.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$contact_ids = array();

		if ( $this->is_v2() ) {

			// The v2 API sends a single event for each subscriber.

			if ( 'add' === $post_data['wpf_action'] ) {

				if ( wpf_get_user_id( $payload->subscriber->id ) ) {
					// If the user already exists.
					wp_die( '', 'Success', 200 );
				}

				// Verify the tag.
				$tag = wpf_get_option( 'mailerlite_add_tag' );

				if ( intval( $payload->group->id ) === intval( $tag[0] ) ) {

					$contact_ids[] = $payload->subscriber->id;

					if ( wpf_get_option( 'mailerlite_import_notification' ) ) {
						$post_data['send_notification'] = true;
					}

				} else {

					$received_name = wpf_get_tag_label( $payload->group->id );

					wpf_log( 'info', 0, 'Subscriber was added to group <strong>' . $received_name . '</strong> which triggered an import webhook. <strong>' . $received_name . '</strong> is not the selected import group, so no data will be imported.' );
					wp_die( '', 'Success', 200 );

				}
			} else {

				if ( isset( $payload->subscriber ) ) {
					$contact_ids[] = $payload->subscriber->id;
				} else {
					$contact_ids[] = $payload->id;
				}
			}
		} else {

			// The v1 API sends an array of events when multiple subscribers are edited.

			if ( 'update' === $post_data['wpf_action'] || 'update_tags' === $post_data['wpf_action'] ) {

				foreach ( $payload->events as $event ) {

					if ( ! in_array( $event->data->subscriber->id, $contact_ids ) ) {
						$contact_ids[] = absint( $event->data->subscriber->id );
					}
				}
			} elseif ( 'add' === $post_data['wpf_action'] ) {

				if ( wpf_get_option( 'mailerlite_import_notification' ) ) {
					$post_data['send_notification'] = true;
				}

				$tag = wpf_get_option( 'mailerlite_add_tag' );

				foreach ( $payload->events as $event ) {

					if ( $event->data->group->id == $tag[0] && ! in_array( $event->data->subscriber->id, $contact_ids ) ) {
						$contact_ids[] = absint( $event->data->subscriber->id );
					}
				}
			}

		}

		if ( empty( $contact_ids ) ) {

			// Nothing found.

			if ( 'add' === $post_data['wpf_action'] ) {

				$received_name = wpf_get_tag_label( absint( $event->data->group->id ) );

				wpf_log( 'info', 0, 'Subscriber was added to group <strong>' . $received_name . '</strong> which triggered an import webhook. <strong>' . $received_name . '</strong> is not the selected import group, so no data will be imported.' );
				wp_die( '', 'Success', 200 );

			}

			// Debug stuff.
			$post_data['payload'] = $payload;

			// No one found.
			$post_data['contact_id'] = false;

			return $post_data;

		} elseif ( 1 === count( $contact_ids ) ) {

			// Simple, one subscriber in payload.
			$post_data['contact_id'] = $contact_ids[0];

			return $post_data;

		} elseif ( 'add' === $post_data['wpf_action'] ) {

			// Multiple subscribers. Push to queue.
			wp_fusion()->batch->includes();
			wp_fusion()->batch->init();

			foreach ( $contact_ids as $contact_id ) {

				wp_fusion()->batch->process->push_to_queue( array( 'wpf_batch_import_users', array( $contact_id, $post_data ) ) );

			}

			wp_fusion()->batch->process->save()->dispatch();

			$post_data['message'] = 'Webhook received for multiple subscribers. Beginning background process to import ' . count( $contact_ids ) . ' subscribers.';

			return $post_data;

		} elseif ( 'update_tags' === $post_data['wpf_action'] ) {

			// Multiple subscribers. Push to queue.
			wp_fusion()->batch->includes();
			wp_fusion()->batch->init();

			foreach ( $contact_ids as $contact_id ) {

				wp_fusion()->batch->process->push_to_queue( array( 'wpf_batch_users_tags_sync', array( $contact_id ) ) );

			}

			wp_fusion()->batch->process->save()->dispatch();

			$post_data['message'] = 'Webhook received for multiple subscribers. Beginning background process to resync groups for ' . count( $contact_ids ) . ' subscribers.';

			return $post_data;

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
	 * Allows using an email address in the ?cid parameter
	 *
	 * @access public
	 * @return string Contact ID
	 */

	public function auto_login_contact_id( $contact_id ) {

		if ( is_email( urldecode( $contact_id ) ) ) {
			$contact_id = $this->get_contact_id( urldecode( $contact_id ) );
		}

		return $contact_id;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'mailerlite' ) !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif ( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( wp_remote_retrieve_response_code( $response ) == 429 ) {

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
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'mailerlite_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'X-MailerLite-ApiKey' => $api_key, // v1 API.
				'Authorization'       => 'Bearer ' . $api_key, // v2 API.
				'Content-Type'        => 'application/json',
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

	public function connect( $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.mailerlite.com/api/v2/me';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( 'ml_account_id', $response->account->id );

		return $response->account->id;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$offset   = 0;
		$continue = true;

		while ( $continue ) {

			$request  = 'https://api.mailerlite.com/api/v2/groups?offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json as $row ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}

			if ( count( $body_json ) < 2 ) {
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
		$request    = 'https://api.mailerlite.com/api/v2/fields';
		$response   = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $field_data ) {
			$crm_fields[ $field_data['key'] ] = ucwords( str_replace( '_', ' ', $field_data['key'] ) );
		}

		$crm_fields['type'] = 'Optin Status';

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

		$request  = 'https://api.mailerlite.com/api/v2/subscribers/' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) && false !== strpos( strtolower( $response->get_error_message() ), 'not found' ) ) {

			return false;

		} elseif ( is_wp_error( $response ) ) {

			return $response;

		}

		// JSON_BIGINT_AS_STRING so contact IDs don't get truncated when PHP_INT_MAX is only 32 bit.

		$body_json = json_decode( $response['body'], false, 512, JSON_BIGINT_AS_STRING );

		if ( empty( $body_json->fields ) ) {
			return false;
		}

		return $body_json->id;
	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		$tags     = array();
		$request  = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id . '/groups';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return $tags;
		}

		foreach ( $body_json as $row ) {
			$tags[] = $row['id'];
		}

		// Check if we need to update the available tags list.
		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $body_json as $row ) {
			if ( ! isset( $available_tags[ $row['id'] ] ) ) {
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

		if ( $this->is_v2() ) {

			// New faster way.

			$url              = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
			$params           = $this->get_params();
			$params['method'] = 'PUT';
			$params['body']   = wp_json_encode( array( 'groups' => $tags ) );

			$response = wp_safe_remote_request( $url, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		} else {

			// Old API still requires multiple calls.

			$email = wp_fusion()->crm->get_email_from_cid( $contact_id );

			foreach ( $tags as $tag ) {

				$request          = 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers';
				$params           = $this->get_params();
				$params['method'] = 'POST';
				$params['body']   = wp_json_encode( array( 'email' => $email ) );

				$response = wp_safe_remote_post( $request, $params );

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
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag ) {

			$request  = 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers/' . $contact_id;
			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;

	}

	/**
	 * Formats subscriber data and sets defaults.
	 *
	 * @since 3.40.45
	 *
	 * @param array $data The input data.
	 * @return array The formatted data.
	 */
	public function format_subscriber_data( $data, $contact_id = false ) {

		$send_data = array();

		if ( isset( $data['name'] ) ) {
			$send_data['name'] = $data['name'];
			unset( $data['name'] );
		}

		if ( isset( $data['email'] ) ) {
			$send_data['email'] = $data['email'];
			unset( $data['email'] );
		}

		// Checkboxes on checkout send bool values.
		if ( isset( $data['type'] ) && ( 1 === $data['type'] || true === $data['type'] || '1' === $data['type'] ) ) {
			$data['type'] = 'active';
		}

		// Handle the optin status.

		$default = wpf_get_option( 'mailerlite_optin', null );

		if ( ! isset( $data['type'] ) && false === $contact_id ) {

			$send_data['type'] = $default;

		} elseif ( empty( $data['type'] ) && false === $contact_id ) {

			$send_data['type']            = 'unsubscribed';
			$send_data['unsubscribed_at'] = gmdate( 'Y-m-d H:i:s' );

		} elseif ( empty( $data['type'] ) && false !== $contact_id ) {

			// existing subscribers, don't sync a status.
			$send_data['type'] = null;

		} elseif ( 'active' === $data['type'] && false !== $contact_id ) {

			if ( 'active' === $default || empty( $default ) ) {
				$send_data['type'] = 'active'; // existing subscribers, opted in, keep them active.
			} else {

				// If they're an existing subscriber and the default is not active, we
				// need to load their record and see if they're already active, so we don't
				// accidentally unsubscribe them.

				$url      = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
				$response = wp_safe_remote_get( $url, $this->get_params() );

				if ( is_wp_error( $response ) ) {
					wpf_log( 'error', 0, 'Error checking subscriber\'s status in  MailerLite. Subscriber will be set to <code>active</code>. (' . $response->get_error_message() . ')' );
					$send_data['type'] = 'active';
				} else {

					$body = json_decode( wp_remote_retrieve_body( $response ), true );

					if ( 'unsubscribed' === $body['type'] ) {

						// Updating a subscriber can only change them from unsubscribed to
						// active, not to unconfirmed.

						$send_data['type']        = 'active';
						$send_data['resubscribe'] = true;
						$send_data['opted_in_at'] = gmdate( 'Y-m-d H:i:s' );
						$send_data['optin_ip']    = wp_fusion()->user->get_ip();

						wpf_log( 'notice', 0, 'Subscriber with contact #' . $contact_id . ' opted in, but they were previously unsubscribed. They have been resubscribed as <code>active</code>.' );

					} else {
						$send_data['type'] = $body['type'];
					}
				}
			}
		} elseif ( 'active' === $data['type'] && false === $contact_id ) {

			// New subscribers should be set to uncormfirmed unless the default is active.

			if ( 'active' === $default ) {
				$send_data['type']        = 'active';
				$send_data['opted_in_at'] = gmdate( 'Y-m-d H:i:s' );
				$send_data['optin_ip']    = wp_fusion()->user->get_ip();
			} else {
				$send_data['type']          = 'unconfirmed';
				$send_data['subscribed_at'] = gmdate( 'Y-m-d H:i:s' );
			}

		} elseif ( ! in_array( $data['type'], array( 'unsubscribed', 'active', 'unconfirmed' ) ) ) {

			wpf_log( 'notice', 0, 'Invalid optin status <code>' . $data['type'] . '</code> passed to MailerLite. Optin status must be one of <code>unsubscribed</code>, <code>active</code>, or <code>unconfirmed</code>.' );

		} else {

			$send_data['type'] = $data['type'];

		}

		unset( $data['type'] );

		$send_data['fields']     = $data;
		$send_data['ip_address'] = wp_fusion()->user->get_ip();

		if ( $this->is_v2() ) {

			// Status is synced as "status" instead of "type" in v2.
			$send_data['status'] = $send_data['type'];
			unset( $send_data['type'] );

		}

		// We won't sync these if they're empty.

		if ( isset( $send_data['type'] ) && empty( $send_data['type'] ) ) {
			unset( $send_data['type'] );
		}

		if ( isset( $send_data['status'] ) && empty( $send_data['status'] ) ) {
			unset( $send_data['status'] );
		}

		return $send_data;

	}

	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		$data = $this->format_subscriber_data( $data );

		if ( empty( $data ) ) {
			return false;
		}

		$url            = 'https://api.mailerlite.com/api/v2/subscribers';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;

	}

	/**
	 * Update contact.
	 *
	 * @since 3.10.0
	 *
	 * @param string $contact_id The contact ID.
	 * @param array $data The data to update.
	 *
	 * @return string|WP_Error The contact ID or an error.
	 */

	public function update_contact( $contact_id, $data ) {

		$url              = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $this->format_subscriber_data( $data, $contact_id ) );

		$response = wp_safe_remote_request( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check for changes in email address if enabled
		if ( ! empty( $data['email'] ) && 'duplicate' === wpf_get_option( 'email_changes' ) ) {

			$contact_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( strtolower( $contact_data['email'] ) !== strtolower( $data['email'] ) ) {

				$user_id        = wpf_get_user_id( $contact_id );
				$original_email = $contact_data['email'];

				wpf_log( 'notice', $user_id, 'Email address change detected (from <strong>' . $original_email . '</strong> to <strong>' . $data['email'] . '</strong>). Proceeding to delete subscriber. To disable this, set <strong>Email Address Changes</strong> to <strong>Ignore</strong> in the Advanced settings of WP Fusion.', array( 'source' => 'mailerlite' ) );

				$contact_data['email'] = $data['email'];
				unset( $contact_data['id'] );

				$url            = 'https://api.mailerlite.com/api/v2/subscribers';
				$params         = $this->params;
				$params['body'] = wp_json_encode( $contact_data );

				$response = wp_safe_remote_post( $url, $params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				// Save the new contact ID
				if ( $user_id ) {
					update_user_meta( $user_id, 'mailerlite_contact_id', $body->id );
				}

				// Get the contact's previous tags
				$tags = $this->get_tags( $contact_id );

				if ( ! empty( $tags ) ) {

					// Apply the tags to the new contact
					$this->apply_tags( $tags, $body->id );

				}

				// Delete the original contact
				$params           = $this->params;
				$params['method'] = 'DELETE';

				wp_safe_remote_request( 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id, $params );

				wpf_log( 'notice', $user_id, 'User email changed from <strong>' . $original_email . '</strong> to <strong>' . $contact_data['email'] . '</strong>. Subscriber ID updated from <strong>' . $contact_id . '</strong> to <strong>' . $body->id . '</strong>.', array( 'source' => 'mailerlite' ) );

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

		$url      = 'https://api.mailerlite.com/api/v2/subscribers/' . $contact_id;
		$response = wp_safe_remote_get( $url, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $body_json['fields'] as $field ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] && $field['key'] === $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field['value'];
				}
			}

			unset( $body_json['fields'] );

		}

		// Now the regular ones.

		foreach ( $body_json as $key => $value ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] && $key === $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $value;
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

		$url     = 'https://api.mailerlite.com/api/v2/groups/' . $tag . '/subscribers?limit=1000';
		$results = wp_safe_remote_get( $url, $this->params );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		foreach ( $body_json as $row => $contact ) {
			$contact_ids[] = $contact['id'];
		}

		return $contact_ids;

	}

	/**
	 * Lists all webhooks.
	 *
	 * @since 3.32.1
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @return array|WP_Error The webhooks or an error object.
	 */
	public function get_webhooks() {

		$response = wp_safe_remote_get( $this->api_url . 'webhooks', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->webhooks ) ) {
			return $response->webhooks;
		} else {
			return $response->data; // webhooks are stored in data in v2.
		}

	}

	/**
	 * Creates a webhook.
	 *
	 * @since 3.10.0
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @param string $type The webhook type: add or update.
	 * @return array|WP_Error The created rule IDs or an error.
	 */
	public function register_webhooks( $type ) {

		$event_types = array();

		$access_key = wpf_get_option( 'access_key' );

		// Don't do this when the settings are being reset.
		if ( empty( $access_key ) ) {
			return false;
		}

		$ids = array();

		if ( $this->is_v2() ) {

			// V2 lets us send a single API call.

			if ( 'add' === $type ) {

				$event_types[] = 'subscriber.added_to_group';

			} elseif ( 'update' === $type ) {

				$event_types[] = 'subscriber.updated';
				$event_types[] = 'subscriber.added_to_group';
				$event_types[] = 'subscriber.removed_from_group';

			}

			$data = array(
				'name'   => 'WP Fusion - ' . home_url(),
				'url'    => get_home_url( null, '/?wpf_action=' . $type . '&access_key=' . $access_key ),
				'events' => $event_types,
			);

			wpf_log( 'info', 0, 'Registering webhook for ' . $type . ' events: <pre>' . print_r( $data, true ) . '</pre>' );

			$request        = $this->api_url . 'webhooks';
			$params         = $this->get_params();
			$params['body'] = wp_json_encode( $data );

			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $response->data->id ) ) {
				$ids[] = $response->data->id;
			}
		} else {

			// V1 requires a separate API call for each event type.

			if ( 'add' === $type ) {

				$event_types[] = 'add_to_group';

			} elseif ( 'update' === $type ) {

				$event_types[] = 'update';
				$event_types[] = 'add_to_group';
				$event_types[] = 'remove_from_group';

			}

			foreach ( $event_types as $event_type ) {

				if ( ( $type == 'update' && $event_type == 'add_to_group' ) || ( $type == 'update' && $event_type == 'remove_from_group' ) ) {
					$type = 'update_tags';
				}

				$data = array(
					'url'   => get_home_url( null, '/?wpf_action=' . $type . '&access_key=' . $access_key ),
					'event' => 'subscriber.' . $event_type,
				);

				wpf_log( 'info', 0, 'Registering webhook for ' . $type . ' event: <pre>' . print_r( $data, true ) . '</pre>' );

				$request        = $this->api_url . 'webhooks';
				$params         = $this->get_params();
				$params['body'] = wp_json_encode( $data );

				$response = wp_safe_remote_post( $request, $params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$result = json_decode( wp_remote_retrieve_body( $response ) );

				if ( is_object( $result ) ) {
					$ids[] = $result->id;
				}
			}
		}

		return $ids;

	}

	/**
	 * Destroys a webhook.
	 *
	 * @since 3.10.0
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @param int $rule_id The webhook ID.
	 * @return bool|WP_Error True or an error on failure.
	 */
	public function destroy_webhook( $rule_id ) {

		$request          = $this->api_url . 'webhooks/' . $rule_id;
		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		wpf_log( 'info', 0, 'Deleting webhook with ID ' . $rule_id );

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

}
