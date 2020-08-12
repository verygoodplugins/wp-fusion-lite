<?php

class WPF_ActiveCampaign {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * HTTP API parameters
	 */

	public $params;

	/**
	 * API url for the account
	 */

	public $api_url;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'activecampaign';
		$this->name     = 'ActiveCampaign';
		$this->supports = array( 'add_tags', 'add_lists', 'quick_update_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_ActiveCampaign_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		$this->get_params();

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

	}


	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$post_data['contact_id'] = $post_data['contact']['id'];

		return $post_data;

	}

	/**
	 * With a wpf_action=update_tags webhook we can read the tags out of the payload instead of making an API call back to AC
	 *
	 * @access public
	 * @return void
	 */

	public function quick_update_tags( $post_data, $user_id ) {

		$tags = explode( ', ', $post_data['contact']['tags'] );

		wp_fusion()->user->set_tags( $tags, $user_id );

		return $tags;

	}


	/**
	 * Formats user entered data to match AC field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' && ! empty( $value ) ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

		} elseif ( ( $field_type == 'checkboxes' || $field_type == 'multiselect' ) && ! empty( $value ) ) {

			return str_replace( ',', '||', $value );

		} elseif ( ( $field_type == 'checkboxes' || $field_type == 'multiselect' ) && empty( $value ) ) {

			$value = null;

		} else {

			return $value;

		}

	}

	/**
	 * Get common params for the HTTP API
	 *
	 * @access public
	 * @return array Params
	 */

	public function get_params() {

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Api-Token'    => wp_fusion()->settings->get( 'ac_key' ),
			),
		);

		$this->api_url = wp_fusion()->settings->get( 'ac_url' );
		$this->params  = $params;

		return $params;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		$api_url = wp_fusion()->settings->get( 'ac_url' );

		if ( ! empty( $api_url ) && strpos( $url, $api_url ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors[0]->title );

			} elseif ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->message . ': ' . $body_json->error );

			} elseif ( wp_remote_retrieve_response_code( $response ) == 500 ) {

				$response = new WP_Error( 'error', '500 Internal Server Error from ActiveCampaign.' );

			}
		}

		return $response;

	}

	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( wp_fusion()->settings->get( 'site_tracking' ) == false ) {
			return;
		}

		$cid = get_user_meta( wpf_get_current_user_id(), wp_fusion()->crm->slug . '_contact_id', true );

		if ( ! empty( $cid ) ) {
			$user  = get_userdata( wpf_get_current_user_id() );
			$email = $user->user_email;
		} else {
			$email = '';
		}

		$trackid = wp_fusion()->settings->get( 'site_tracking_id' );

		if ( empty( $trackid ) ) {
			$trackid = $this->get_tracking_id();
		}

		echo '<!-- Start ActiveCampaign site tracking -->';
		echo '<script type="text/javascript">';
		echo '(function(e,t,o,n,p,r,i){e.visitorGlobalObjectAlias=n;e[e.visitorGlobalObjectAlias]=e[e.visitorGlobalObjectAlias]||function(){(e[e.visitorGlobalObjectAlias].q=e[e.visitorGlobalObjectAlias].q||[]).push(arguments)};e[e.visitorGlobalObjectAlias].l=(new Date).getTime();r=t.createElement("script");r.src=o;r.async=true;i=t.getElementsByTagName("script")[0];i.parentNode.insertBefore(r,i)})(window,document,"https://diffuser-cdn.app-us1.com/diffuser/diffuser.js","vgo");';
		echo 'vgo("setAccount", "' . $trackid . '");';
		echo 'vgo("setTrackByDefault", true);';

		// This does not reliably work when the AC forms plugin is active or any other kind of AC site tracking
		if ( ! empty( $email ) ) {
			echo 'vgo("setEmail", "' . $email . '");';
		}

		echo 'vgo("process");';
		echo '</script>';
		echo '<!-- End ActiveCampaign site tracking -->';

	}

	/**
	 * Get site tracking ID
	 *
	 * @access  public
	 * @return  int Tracking ID
	 */

	public function get_tracking_id() {

		// Get site tracking ID
		$this->connect();

		$me = wp_fusion()->crm->app->api( 'user/me' );

		if ( is_wp_error( $me ) || ! isset( $me->trackid ) ) {
			return false;
		}

		return $me->trackid;

	}

	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_url = null, $api_key = null, $test = false ) {

		if ( isset( $this->app ) && $test == false ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $api_url ) || empty( $api_key ) ) {
			$api_url = wp_fusion()->settings->get( 'ac_url' );
			$api_key = wp_fusion()->settings->get( 'ac_key' );
		}

		if ( ! class_exists( 'WPF_ActiveCampaign_API' ) ) {
			require dirname( __FILE__ ) . '/includes/ActiveCampaign.class.php';
		}

		$app = new WPF_ActiveCampaign_API( $api_url, $api_key );

		if ( $test == true ) {

			if ( ! (int) $app->credentials_test() ) {
				return new WP_Error( 'error', __( 'Access denied: Invalid credentials (URL and/or API key).', 'wp-fusion-lite' ) );
			}
		}

		// Connection was a success
		$this->app = $app;

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
		$this->sync_lists();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Tags
	 */

	public function sync_tags() {

		$offset         = 0;
		$proceed        = true;
		$available_tags = array();

		while ( $proceed ) {

			$response = wp_remote_get( $this->api_url . '/api/3/tags?limit=100&offset=' . $offset, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->tags as $tag ) {

				$available_tags[ $tag->tag ] = $tag->tag;

			}

			if ( count( $response->tags ) < 100 ) {
				$proceed = false;
			}

			$offset += 100;

		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}

	/**
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		$response = wp_remote_get( $this->api_url . '/api/3/lists?limit=100', $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_lists = array();

		foreach ( $response->lists as $list ) {
			$available_lists[ $list->id ] = $list->name;
		}

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/activecampaign-fields.php';

		$built_in_fields = array();

		foreach ( $activecampaign_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Get custom fields
		$custom_fields = array();

		$response = wp_remote_get( $this->api_url . '/api/3/fields?limit=100', $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->fields as $field ) {
			$custom_fields[ 'field[' . $field->id . ',0]' ] = $field->title;
		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

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

		$response = wp_remote_get( $this->api_url . '/api/3/contacts?email=' . urlencode( $email_address ), $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		} else {
			return $response->contacts[0]->id;
		}

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$this->connect();

		$result = $this->app->api( 'contact/view?id=' . $contact_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( isset( $result->error ) ) {
			return new WP_Error( 'error', $result->error );
		}

		if ( empty( $result->tags ) ) {
			return array();
		}

		return $result->tags;

	}

	/**
	 * Applies tags to a contact. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$this->connect();

		$result = $this->app->api(
			'contact/tag_add', array(
				'id'   => $contact_id,
				'tags' => $tags,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( isset( $result->error ) ) {
			return new WP_Error( 'error', $result->error );
		}

		// Possibly update available tags if it's a newly created one
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		foreach ( $tags as $tag ) {
			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
				$needs_update           = true;
			}
		}

		if ( isset( $needs_update ) ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}

		return true;

	}


	/**
	 * Removes tags from a contact. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$this->connect();

		$result = $this->app->api(
			'contact/tag_remove', array(
				'id'   => $contact_id,
				'tags' => $tags,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( isset( $result->error ) ) {
			return new WP_Error( 'error', $result->error );
		}

		return true;

	}


	/**
	 * Adds a new contact (using v1 API since v3 doesn't support adding custom fields in the same API call)
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// Set lists
		$lists = wp_fusion()->settings->get( 'ac_lists' );

		// Allow filtering
		$lists = apply_filters( 'wpf_add_contact_lists', $lists );

		if ( ! empty( $lists ) ) {
			foreach ( $lists as $list_id ) {
				if ( ! empty( $list_id ) ) {
					$data[ 'p[' . $list_id . ']' ]                 = $list_id;
					$data[ 'instantresponders[' . $list_id . ']' ] = 1;
				}
			}
		}

		$result = $this->app->api( 'contact/sync', $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( isset( $result->error ) ) {
			return new WP_Error( 'error', $result->error );
		}

		return $result->subscriber_id;

	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		// Allow filtering
		$lists = apply_filters( 'wpf_update_contact_lists', array() );

		if ( ! empty( $lists ) ) {
			foreach ( $lists as $list_id ) {
				$data[ 'p[' . $list_id . ']' ] = $list_id;
			}
		}

		$data['id']        = $contact_id;
		$data['overwrite'] = 0;

		$result = $this->app->api( 'contact/edit', $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( isset( $result->error ) ) {
			return new WP_Error( 'error', $result->error );
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

		$this->connect();

		$result = $this->app->api( 'contact/view?id=' . $contact_id );

		if ( is_wp_error( $result ) ) {

			return $result;

		} elseif ( isset( $result->error ) ) {

			return new WP_Error( 'error', $result->error );

		}

		$user_meta = array();

		// Map contact fields
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		// Standard fields
		foreach ( $result as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset( $field_data['crm_field'] ) && $field_data['crm_field'] == $field_name && $field_data['active'] == true ) {
					$user_meta[ $meta_key ] = $value;
				}
			}
		}

		if ( ! empty( $result->fields ) ) {

			// Custom fields
			foreach ( $result->fields as $field_object ) {

				foreach ( $contact_fields as $meta_key => $field_data ) {

					if ( $field_data['active'] == true ) {

						// Get field ID from stored CRM field value
						$field_array = explode( ',', str_replace( 'field[', '', str_replace( ']', '', $field_data['crm_field'] ) ) );

						if ( $field_object->id == $field_array[0] ) {

							$value = $field_object->val;

							// Clean up the pipes from array type fields
							if ( strpos( $value, '||' ) !== false ) {

								// Remove pipes from beginning
								if ( substr( $value, 0, 2 ) == '||' ) {
									$value = substr( $value, 2 );
								}

								if ( substr( $value, -2 ) == '||' ) {
									$value = substr( $value, 0, strlen( $value ) - 2 );
								}

								$value = str_replace( '||', ',', $value );

							}

							$user_meta[ $meta_key ] = $value;

						}
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

	public function load_contacts( $tag_name ) {

		// For this to work we need the tag ID

		$response = wp_remote_get( $this->api_url . '/api/3/tags?search=' . urlencode( $tag_name ), $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->tags ) ) {

			wpf_log( 'error', 0, 'Unable to get tag ID for ' . $tag_name . ' cancelling import.' );
			return false;

		}

		$tag_id = $response->tags[0]->id;

		// Query will only return contacts on at least one list

		$contact_ids = array();
		$offset      = 0;
		$proceed     = true;

		while ( $proceed == true ) {

			$response = wp_remote_get( $this->api_url . '/api/3/contacts?limit=100&offset=' . $offset . '&tagid=' . $tag_id, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->contacts ) ) {

				foreach ( $response->contacts as $contact ) {

					$contact_ids[] = $contact->id;

				}

				$offset += 100;

			}

			if ( count( $response->contacts ) < 100 ) {

				$proceed = false;

			}
		}

		return $contact_ids;

	}

	//
	// Deep data stuff
	//

	/**
	 * Gets or creates an ActiveCampaign deep data connection
	 *
	 * @access public
	 * @since  3.24.11
	 * @return int
	 */

	public function get_connection_id() {

		$connection_id = get_option( 'wpf_ac_connection_id' );

		if ( ! empty( $connection_id ) ) {
			return $connection_id;
		}

		$api_url = wp_fusion()->settings->get( 'ac_url' );
		$api_key = wp_fusion()->settings->get( 'ac_key' );

		$body = array(
			'connection' => array(
				'service'    => 'WP Fusion',
				'externalid' => $_SERVER['SERVER_NAME'],
				'name'       => get_bloginfo(),
				'logoUrl'    => 'https://wpfusion.com/wp-content/uploads/2019/08/logo-mark-500w.png',
				'linkUrl'    => admin_url( 'options-general.php?page=wpf-settings#ecommerce' ),
			),
		);

		$args         = $this->get_params();
		$args['body'] = json_encode( $body );

		wpf_log( 'info', 0, 'Opening ActiveCampaign Deep Data connection', array( 'source' => 'wpf-ecommerce' ) );

		$response = wp_remote_post( $api_url . '/api/3/connections?api_key=' . $api_key, $args );

		if ( is_wp_error( $response ) && $response->get_error_message() == 'The integration already exists in the system.' ) {

			// Try to look up an existing connection

			unset( $args['body'] );

			$response = wp_remote_get( $api_url . '/api/3/connections?api_key=' . $api_key, $args );

			if ( ! is_wp_error( $response ) ) {

				$response = json_decode( wp_remote_retrieve_body( $response ) );

				foreach ( $response->connections as $connection ) {

					if ( $connection->service == 'WP Fusion' && $connection->externalid == $_SERVER['SERVER_NAME'] ) {

						update_option( 'wpf_ac_connection_id', $connection->id );

						return $connection->id;

					}
				}

			}

		}

		if ( is_wp_error( $response ) ) {

			wpf_log( 'info', 0, 'Unable to open Deep Data Connection: ' . $response->get_error_message(), array( 'source' => 'wpf-ecommerce' ) );
			update_option( 'wpf_ac_connection_id', false );

			return false;

		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $body ) ) {

			return false;

		} elseif ( isset( $body->message ) ) {

			// If Deep Data not enabled
			wpf_log( 'info', 0, 'Unable to open Deep Data Connection: ' . $body->message, array( 'source' => 'wpf-ecommerce' ) );
			update_option( 'wpf_ac_connection_id', false );

			return false;

		}

		update_option( 'wpf_ac_connection_id', $body->connection->id );

		return $body->connection->id;

	}

	/**
	 * Deletes a registered connection
	 *
	 * @since 3.24.11
	 * @return void
	 */

	public function delete_connection( $connection_id ) {

		$params = $this->get_params();

		$params['method'] = 'DELETE';

		wpf_log( 'notice', 0, 'Closing ActiveCampaign Deep Data connection ID <strong>' . $connection_id . '</strong>', array( 'source' => 'wpf-ecommerce' ) );

		wp_remote_request( $this->api_url . '/api/3/connections/' . $connection_id, $params );

		delete_option( 'wpf_ac_connection_id' );

	}

	/**
	 * Gets or creates an ActiveCampaign deep data customer
	 *
	 * @since 3.24.11
	 * @return int
	 */

	public function get_customer_id( $contact_id, $connection_id, $order_id = false ) {

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		if ( false !== $user_id ) {

			// Get the customer ID from the cache if it's a registered user

			$customer_id = get_user_meta( $user_id, 'wpf_ac_customer_id', true );

			if ( ! empty( $customer_id ) ) {
				return $customer_id;
			}
		}

		if ( false == $user_id ) {

			$external_id  = 'guest';
			$contact_data = $this->load_contact( $contact_id );

			if ( is_wp_error( $contact_data ) ) {

				wpf_log( 'error', $user_id, 'Error loading contact ID ' . $contact_id . ': ' . $contact_data->get_error_message(), array( 'source' => 'wpf-ecommerce' ) );
				return false;

			}

			$user_email = $contact_data['user_email'];

		} else {
			$external_id = $user_id;
			$user        = get_userdata( $user_id );
			$user_email  = $user->user_email;
		}

		$params = $this->get_params();

		// Try to look up an existing customer

		$response = wp_remote_get( $this->api_url . '/api/3/ecomCustomers?filters[email]=' . $user_email . '&filters[connectionid]=' . $connection_id, $params );

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body->ecomCustomers as $customer ) {

			if ( $customer->connectionid == $connection_id ) {

				return $customer->id;

			}
		}

		// If no customer was found, create a new one

		$body = array(
			'ecomCustomer' => array(
				'connectionid' => $connection_id,
				'externalid'   => $external_id,
				'email'        => $user_email,
			),
		);

		wpf_log(
			'info', $user_id, 'Registering new ecomCustomer:', array(
				'source'              => 'wpf-ecommerce',
				'meta_array_nofilter' => $body,
			)
		);

		$params['body'] = json_encode( $body );

		$response = wp_remote_post( $this->api_url . '/api/3/ecomCustomers', $params );

		$customer_id = false;

		if ( is_wp_error( $response ) ) {

			wpf_log( 'error', $user_id, 'Error creating customer: ' . $response->get_error_message(), array( 'source' => 'wpf-ecommerce' ) );
			return false;

		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_object( $body ) ) {
			$customer_id = $body->ecomCustomer->id;
		}

		if ( false === $customer_id ) {

			wpf_log( 'error', $user_id, 'Unable to create customer or find existing customer. Aborting.', array( 'source' => 'wpf-ecommerce' ) );
			return false;

		}

		if ( false !== $user_id ) {
			update_user_meta( $user_id, 'wpf_ac_customer_id', $customer_id );
		}

		return $customer_id;

	}


}
