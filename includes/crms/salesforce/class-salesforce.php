<?php

class WPF_Salesforce {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'salesforce';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Salesforce';

	/**
	 * The client ID.
	 *
	 * @var string
	 */
	public $client_id = '3MVG9CEn_O3jvv0xMf5rhesocm_5czidz9CFtu_qNZ2V0Zw.bmL0LTRRylD5fhkAKYwGxRDDRXjV4TOowpNmg';

	/**
	 * The client secret.
	 *
	 * @var string
	 */
	public $client_secret = '9BB0BD5237B1EA6ED8AFE2618053BDB181459DD61AB3B49567A8BA5013C35D76';

	/**
	 * Contains API params
	 */

	public $params = array();

	/**
	 * Contains SF instance URL
	 */

	public $instance_url;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */
	public $supports = array();

	/**
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type = 'Contact';

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */

	public $tag_type = 'Topic';


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @var string
	 */

	public $edit_url = '';

	/**
	 * Used for initial authorization and token refreshes.
	 *
	 * @var string
	 */
	public $auth_url;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		// Figure out what kinds of tags we're using.

		$tag_type = wpf_get_option( 'sf_tag_type', 'Topics' );

		if ( 'Topics' !== $tag_type ) {
			$this->tag_type = 'Tag';
		}

		if ( 'Picklist' === $tag_type ) {
			$this->supports[] = 'add_tags';
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 ); // up here so it can run when testing the connection.

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Salesforce_Admin( $this->slug, $this->name, $this );
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
		add_action( 'wpf_api_success', array( $this, 'api_success' ), 10, 2 );
		add_action( 'wpf_api_fail', array( $this, 'api_success' ), 10, 2 );

		$instance_url = wpf_get_option( 'sf_instance_url' );

		if ( ! empty( $instance_url ) ) {
			$this->edit_url = trailingslashit( $instance_url ) . 'lightning/r/' . $this->object_type . '/%s/view';
		}

		$this->auth_url    = apply_filters( 'wpf_salesforce_auth_url', 'https://login.salesforce.com/services/oauth2/token' );
		$this->object_type = apply_filters( 'wpf_crm_object_type', wpf_get_option( 'sf_object_type', $this->object_type ) );

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

		$xml = simplexml_load_string( file_get_contents( 'php://input' ) );

		if ( ! is_object( $xml ) ) {
			wp_die( 'Invalid XML payload: ' . file_get_contents( 'php://input' ) );
		}

		$data = $xml->children( 'http://schemas.xmlsoap.org/soap/envelope/' )->Body->children( 'http://soap.sforce.com/2005/09/outbound' )->notifications;

		$notifications_count = count( $data->Notification );

		if ( $notifications_count == 1 ) {

			$post_data['contact_id'] = sanitize_text_field( (string) $data->Notification[0]->sObject->children( 'urn:sobject.enterprise.soap.sforce.com' )->Id );

		} else {

			$debug = '?wpf_action=' . $post_data['wpf_action'];

			if ( isset( $post_data['send_notification'] ) ) {
				$debug .= '&send_notification=' . $post_data['send_notification'];
			}

			wpf_log( 'info', 0, 'Webhook received with <code>' . $debug . '</code>. ' . $notifications_count . ' contact records detected in payload.', array( 'source' => 'api' ) );

			wp_fusion()->batch->includes();
			wp_fusion()->batch->init();

			$contacts_added = array();

			$key = 0;

			$args = array();

			if ( isset( $post_data['role'] ) ) {
				$args['role'] = $post_data['role'];
			}

			if ( isset( $post_data['send_notification'] ) && 'true' == $post_data['send_notification'] ) {
				$args['notify'] = true;
			}

			while ( $key !== $notifications_count ) {

				$contact_id = sanitize_text_field( (string) $data->Notification[ $key ]->sObject->children( 'urn:sobject.enterprise.soap.sforce.com' )->Id );

				$key++;

				// Don't import the same one twice
				if ( in_array( $contact_id, $contacts_added ) ) {
					continue;
				}

				$contacts_added[] = $contact_id;

				// Exclude contacts from update if they don't already have an account

				if ( 'update' == $post_data['wpf_action'] ) {

					$args = array(
						'meta_key'   => WPF_CONTACT_ID_META_KEY,
						'meta_value' => $contact_id,
						'fields'     => array( 'ID' ),
					);

					$users = get_users( $args );

					if ( empty( $users ) ) {
						wpf_log( 'notice', 0, 'Update webhook received but no matching user found for contact ID <strong>' . $contact_id . '</strong>', array( 'source' => 'api' ) );
						continue;
					}
				}

				wpf_log( 'info', 0, 'Adding contact ID <strong>' . $contact_id . '</strong> to import queue (' . $key . ' of ' . $notifications_count . ').', array( 'source' => 'api' ) );

				wp_fusion()->batch->process->push_to_queue( array( 'wpf_batch_import_users', array( $contact_id, $args ) ) );

			}

			wp_fusion()->batch->process->save()->dispatch();

			do_action( 'wpf_api_success', false, false ); // this calls WPF_Salesforce::api_success(), sends the success message, and dies.

		}

		return $post_data;

	}

	/**
	 * Sends a SOAP success after Salesforce API actions so they show as success in the app
	 *
	 * @access public
	 * @return mixed SOAP message
	 */

	public function api_success( $user_id, $method ) {

		if ( doing_wpf_webhook() ) {

			echo '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';
			echo '<soap:Body>';
			echo '<notificationsResponse xmlns:ns2="urn:sobject.enterprise.soap.sforce.com" xmlns="http://soap.sforce.com/2005/09/outbound">';

			if ( did_action( 'wpf_api_success' ) ) {
				echo '<Ack>true</Ack>';
			} else {
				echo '<Ack>false</Ack>';
			}

			echo '</notificationsResponse>';
			echo '</soap:Body>';
			echo '</soap:Envelope>';

			die();

		}

	}

	/**
	 * Formats user entered data to match Salesforce field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( in_array( $field, wpf_get_option( 'read_only_fields', array() ) ) ) {

			// Don't sync read only fields, they'll just throw an error anyway.

			return '';

		} elseif ( 'date' === $field_type && empty( $value ) ) {

			// Erases empty dates, since 3.42.12.
			return null;

		} elseif ( 'date' === $field_type && is_numeric( $value ) ) {

			// Adjust formatting for date fields.
			$date = gmdate( 'Y-m-d', $value );

			return $date;

		} elseif ( 'checkbox' === $field_type ) {

			if ( empty( $value ) ) {
				return 'false';
			} else {
				return 'true';
			}
		} elseif ( is_array( $value ) ) {

			// Multiselects
			return implode( ';', array_filter( $value ) );

		} else {

			return $value;

		}

	}

	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since  3.38.20
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$url       = apply_filters( 'wpf_salesforce_auth_url', 'https://login.salesforce.com/services/oauth2/token' );
		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'action'   => 'wpf_get_salesforce_token',
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=salesforce' ),
		);

		if ( 'https://login.salesforce.com/services/oauth2/token' === $url ) {

			// Standard URL.
			return apply_filters( 'wpf_salesforce_init_auth_url', add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );

		} elseif ( 'https://test.salesforce.com/services/oauth2/token' === $url ) {

			$args['sandbox'] = true;

			// Sandbox URLs.
			return apply_filters( 'wpf_salesforce_init_auth_url', add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );

		} else {

			// Custom URL, we don't need to send it through wpfusion.com.
			return $url;

		}

	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @since  3.38.17
	 *
	 * @return string|WP_Error Access token or error.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( 'sf_refresh_token' );

		if ( ! empty( $refresh_token ) ) {

			// New OAuth flow since 3.38.17.

			$params = array(
				'user-agent' => 'WP Fusion; ' . home_url(),
				'body'       => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'refresh_token' => $refresh_token,
				),
			);

			// Prevent the error handling looping on itself.
			remove_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

			$response = wp_safe_remote_post( $this->auth_url, $params );

			add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {
				return new WP_Error( 'error', 'Error refreshing access token: ' . $body->error_description );
			}

			if ( ! isset( $body->access_token ) ) {
				return new WP_Error( 'error', 'Unknown error refreshing access token: <pre>' . print_r( $body, true ) . '</pre>' );
			}

			$this->get_params( $body->access_token );

			wp_fusion()->settings->set( 'sf_access_token', $body->access_token );

			return $body->access_token;

		} else {

			// Old password based refresh, for people who haven't re-authorized via OAuth yet.

			$token    = wpf_get_option( 'sf_combined_token' );
			$username = wpf_get_option( 'sf_username' );

			$auth_args = array(
				'grant_type'    => 'password',
				'client_id'     => '3MVG9CEn_O3jvv0xMf5rhesocmw9vf_OV6x9fHYfh4bnqRC1zUohKbulHXLyuMdCaXEliMqXtW6XVAMiNa55K',
				'client_secret' => '6100590890846411326',
				'username'      => rawurlencode( $username ),
				'password'      => rawurlencode( htmlspecialchars_decode( $token ) ),
			);

			$auth_url = add_query_arg( $auth_args, $this->auth_url );
			$response = wp_safe_remote_post( $auth_url );

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {
				return new WP_Error( $body->error, $body->error_description );
			}

			wp_fusion()->settings->set( 'sf_access_token', $body->access_token );
			wp_fusion()->settings->set( 'sf_instance_url', $body->instance_url );

			// Set params for subsequent ops.
			$this->get_params( $body->access_token );

			return $body->access_token;

		}
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'salesforce' ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

			$response_code    = wp_remote_retrieve_response_code( $response );
			$response_message = wp_remote_retrieve_response_message( $response );
			$body             = json_decode( wp_remote_retrieve_body( $response ) );

			if ( 401 === $response_code && 'INVALID_SESSION_ID' === $body[0]->errorCode ) {

				if ( strpos( $body[0]->message, 'expired' ) !== false ) {

					// Refresh the access token.
					$access_token = $this->refresh_token();

					if ( is_wp_error( $access_token ) ) {
						return $access_token;
					}

					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} else {
					// For example: "This session is not valid for use with the REST API".
					$response = new WP_Error( 403, 'Invalid API credentials: ' . $body[0]->message );
				}
			} elseif ( $response_code != 200 && $response_code != 201 && $response_code != 204 && ! empty( $response_message ) ) {

				if ( is_array( $body ) && ! empty( $body[0] ) && ! empty( $body[0]->message ) ) {

					$response_message = $response_message . ' - ' . $body[0]->message;

				} elseif ( is_object( $body ) && isset( $body->error_description ) ) {

					$response_message = $response_message . ' - ' . $body->error_description;

				}

				$response = new WP_Error( (int) $response_code, $response_message );

			} elseif ( isset( $body->{'compositeResponse'} ) ) {

				// Composite requests (like from Enhanced Ecommerce) have errors in an array.

				$errors = array();

				foreach ( $body->{'compositeResponse'} as $res ) {

					if ( 200 !== $res->{'httpStatusCode'} && 201 !== $res->{'httpStatusCode'} ) {
						$errors[] = '<pre>' . wpf_print_r( $res->body, true ) . '</pre>';
					}
				}

				if ( ! empty( $errors ) ) {
					return new WP_Error( 'error', implode( '', $errors ) );
				}

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

	public function get_params( $access_token = null ) {

		// Get saved data from DB.
		if ( empty( $access_token ) ) {
			$access_token = wpf_get_option( 'sf_access_token' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 20,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		$this->instance_url = apply_filters( 'wpf_salesforce_instance_url', wpf_get_option( 'sf_instance_url' ) );
		$this->object_type  = apply_filters( 'wpf_crm_object_type', wpf_get_option( 'sf_object_type', $this->object_type ) ); // set it again in case it's changed.

		return $this->params;

	}


	/**
	 * Test the connection.
	 *
	 * @since  3.2.0
	 *
	 * @param  string $instance_url The instance url.
	 * @param  string $access_token The access token.
	 * @param  bool   $test         Whether to test the connection.
	 * @return bool|WP_Error The connection result.
	 */
	public function connect( $instance_url = null, $access_token = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( ! $test ) {
			return true;
		}

		$response = wp_safe_remote_get( $this->instance_url . '/services/data/v53.0/search/', $this->params );

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

		$this->sync_objects();
		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}

	/**
	 * Gets all available objects and saves them to options.
	 *
	 * @since 3.41.6
	 *
	 * @return array Objects.
	 */
	public function sync_objects() {

		$response = wp_safe_remote_get( $this->instance_url . '/services/data/v42.0/sobjects/', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$objects = array();

		foreach ( $body->sobjects as $object ) {

			if ( $object->createable && $object->searchable && $object->updateable ) {
				$objects[ $object->name ] = $object->{'labelPlural'};
			}
		}

		update_option( 'wpf_salesforce_objects', $objects, false );

		return $objects;

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

		$tag_type = wpf_get_option( 'sf_tag_type', 'Topics' );

		if ( 'Topics' === $tag_type ) {

			$continue   = true;
			$query_args = array( 'q' => 'SELECT%20Name,%20Id%20from%20Topic' );
			$url        = $this->instance_url . '/services/data/v42.0/query';

			while ( $continue ) {

				$request  = add_query_arg( $query_args, $url );
				$response = wp_safe_remote_get( $request, $this->params );

				if ( is_wp_error( $response ) ) {

					// For accounts without topics.
					if ( strpos( $response->get_error_message(), "'Topic' is not supported" ) !== false ) {
						return array();
					}

					return $response;
				}

				$response = json_decode( wp_remote_retrieve_body( $response ) );

				if ( ! empty( $response->records ) ) {

					foreach ( $response->records as $tag ) {
						$available_tags[ $tag->Id ] = $tag->Name;
					}
				}

				if ( ! empty( $response->{'nextRecordsUrl'} ) ) {
					$url = $this->instance_url . $response->{'nextRecordsUrl'};
				} else {
					$continue = false;
				}
			}
		} elseif ( 'Picklist' === $tag_type ) {

			$request  = $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/describe/';
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->fields as $field ) {

				if ( wpf_get_option( 'sf_tag_picklist' ) === $field->name ) {

					if ( 'multipicklist' !== $field->type ) {
						return new WP_Error( 'error', 'The selected field ' . $field->name . ' is not a <code>multipicklist</code> field.' );
					}

					foreach ( $field->{'picklistValues'} as $value ) {
						$available_tags[ $value->label ] = $value->label;
					}

					break;
				}
			}

		} else {

			$query_args = array( 'q' => 'SELECT%20Name,%20Id%20from%20TagDefinition' );

			$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {

				// For accounts without tags
				if ( strpos( $response->get_error_message(), "'TagDefinition' is not supported" ) !== false ) {
					return array();
				}

				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->records ) ) {

				foreach ( $response->records as $tag ) {
					$available_tags[ $tag->Id ] = $tag->Name;
				}
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

		$standard_fields  = array();
		$custom_fields    = array();
		$read_only_fields = array();
		$required_fields  = array();

		$request  = $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/describe/';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->fields as $field ) {

			if ( false === $field->updateable ) {
				$field->label      .= ' (' . __( 'read only', 'wp-fusion-lite' ) . ')';
				$read_only_fields[] = $field->name;
			}

			if ( 'address' == $field->type ) {
				$field->label .= ' (' . __( 'compound field', 'wp-fusion-lite' ) . ')';
			}

			if ( true === $field->custom ) {
				$custom_fields[ $field->name ] = $field->label;
			} else {
				$standard_fields[ $field->name ] = $field->label;
			}

			if ( false === $field->nillable && true === $field->createable && false === $field->{'defaultedOnCreate'} ) {
				$required_fields[] = $field->name;
			}

		}

		asort( $standard_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $standard_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );
		wp_fusion()->settings->set( 'read_only_fields', $read_only_fields );
		wp_fusion()->settings->set( 'required_fields', $required_fields );

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

		// Allow using a different field than email address for lookups.

		$lookup_field = wp_fusion()->crm->get_crm_field( 'user_email', 'Email' );

		$lookup_field = apply_filters( 'wpf_salesforce_lookup_field', $lookup_field );

		$email_address = urlencode( $email_address );

		$query_args = array( 'q' => "SELECT Id from {$this->object_type} WHERE {$lookup_field} = '{$email_address}'" );

		$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'get_contact_id', $email_address );

		$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) || empty( $response->records ) ) {
			return false;
		}

		return $response->records[0]->Id;

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

		$contact_tags = array();
		$tag_type     = wpf_get_option( 'sf_tag_type', 'Topics' );

		if ( 'Topics' === $tag_type ) {

			$query_args = array( 'q' => "SELECT TopicId from TopicAssignment WHERE EntityId = '" . $contact_id . "'" );

			$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'get_topics', $contact_id );

			$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response ) || empty( $response->records ) ) {
				return $contact_tags;
			}

			foreach ( $response->records as $tag ) {
				$contact_tags[] = $tag->{'TopicId'};
			}
		} elseif ( 'Personal' === $tag_type || 'Public' === $tag_type ) {

			$query_args = array( 'q' => "SELECT TagDefinitionId from ContactTag WHERE ItemId = '" . $contact_id . "'" );

			$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'get_tags', $contact_id );

			$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response ) || empty( $response->records ) ) {
				return $contact_tags;
			}

			foreach ( $response->records as $tag ) {

				$contact_tags[] = $tag->{'TagDefinitionId'};

			}
		} elseif ( 'Picklist' === $tag_type ) {

			$tags_field = wpf_get_option( 'sf_tag_picklist' );

			$response = wp_safe_remote_get( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/' . $contact_id, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $body->{ $tags_field } ) ) {
				$contact_tags = explode( ';', $body->{ $tags_field } );
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

		$params = $this->get_params();

		$tag_type = wpf_get_option( 'sf_tag_type', 'Topics' );

		if ( 'Topics' === $tag_type ) {

			foreach ( $tags as $tag_id ) {

				$body = array(
					'EntityId' => $contact_id,
					'TopicId'  => $tag_id,
				);

				$params['body'] = wp_json_encode( $body );

				$response = wp_safe_remote_post( $this->instance_url . '/services/data/v42.0/sobjects/TopicAssignment/', $params );

			}
		} elseif ( 'Picklist' === $tag_type ) {

			$current_tags = $this->get_tags( $contact_id );

			if ( is_wp_error( $current_tags ) ) {
				$current_tags = array(); // if loading them failed for some reason.
			}

			$tags  = array_merge( $current_tags, $tags );
			$tags  = implode( ';', array_filter( $tags ) );
			$field = wpf_get_option( 'sf_tag_picklist' );

			$data = array( $field => $tags );

			$params['body']   = wp_json_encode( $data );
			$params['method'] = 'PATCH';

			$response = wp_safe_remote_request( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/' . $contact_id, $params );

		} else {

			foreach ( $tags as $tag_id ) {

				$label = wp_fusion()->user->get_tag_label( $tag_id );

				$body = array(
					'Type'   => $tag_type,
					'ItemID' => $contact_id,
					'Name'   => $label,
				);

				$params['body'] = wp_json_encode( $body );

				$response = wp_safe_remote_post( $this->instance_url . '/services/data/v42.0/sobjects/ContactTag/', $params );

			}
		}

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

		$params               = $this->get_params();
		$sf_tag_ids_to_remove = array();

		// First get the tag type.
		$tag_type = wpf_get_option( 'sf_tag_type', 'Topics' );

		if ( 'Topics' === $tag_type ) {

			$query_args = array( 'q' => "SELECT Id, TopicId from TopicAssignment WHERE EntityId = '" . $contact_id . "'" );
			$request    = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response ) || empty( $response->records ) ) {
				return false;
			}

			foreach ( $response->records as $tag ) {

				if ( in_array( $tag->TopicId, $tags ) ) {
					$sf_tag_ids_to_remove[] = $tag->Id;
				}
			}

			if ( ! empty( $sf_tag_ids_to_remove ) ) {

				$params['method'] = 'DELETE';

				foreach ( $sf_tag_ids_to_remove as $tag_id ) {

					$response = wp_safe_remote_request( $this->instance_url . '/services/data/v42.0/sobjects/TopicAssignment/' . $tag_id, $params );

					if ( is_wp_error( $response ) ) {
						return $response;
					}
				}
			}
		} elseif ( 'Picklist' === $tag_type ) {

			$current_tags = $this->get_tags( $contact_id );

			$tags = array_diff( $current_tags, $tags );

			$tags = implode( ';', array_filter( $tags ) );

			$data = array(
				wpf_get_option( 'sf_tag_picklist' ) => $tags,
			);

			$params['body']   = wp_json_encode( $data );
			$params['method'] = 'PATCH';

			$response = wp_safe_remote_request( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/' . $contact_id, $params );
		} else {

			$query_args = array( 'q' => "SELECT Id, TagDefinitionId from ContactTag WHERE ItemId = '" . $contact_id . "'" );
			$request    = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response ) || empty( $response->records ) ) {
				return false;
			}

			foreach ( $response->records as $tag ) {

				if ( in_array( $tag->TagDefinitionId, $tags ) ) {
					$sf_tag_ids_to_remove[] = $tag->Id;
				}
			}

			if ( ! empty( $sf_tag_ids_to_remove ) ) {

				$params['method'] = 'DELETE';

				foreach ( $sf_tag_ids_to_remove as $tag_id ) {

					$response = wp_safe_remote_request( $this->instance_url . '/services/data/v42.0/sobjects/ContactTag/' . $tag_id, $params );

					if ( is_wp_error( $response ) ) {
						return $response;
					}
				}
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

		$params = $this->get_params();

		// LastName is required to create a new contact.
		if ( $this->object_type === 'Contact' && ! isset( $data['LastName'] ) ) {
			$data['LastName'] = 'unknown';
		}

		// Since 3.41.45 we support checking against all required fields.
		$required_fields = wpf_get_option( 'required_fields', array() );

		foreach ( $required_fields as $field ) {

			if ( ! array_key_exists( $field, $data ) ) {
				$data[ $field ] = '-';
			}
		}

		$default_account = wpf_get_option( 'salesforce_account' );

		if ( ! empty( $default_account ) ) {
			$data['accountId'] = $default_account;
		}

		// format_field_value() can pass empty values for updates, but we can't create
		// a contact with empty values, so we'll remove them here.
		$data = array_filter( $data );

		$params['body'] = wp_json_encode( $data );
		$response       = wp_safe_remote_post( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/', $params );

		if ( is_wp_error( $response ) ) {
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

	public function update_contact( $contact_id, $data ) {

		$params = $this->get_params();

		foreach ( $data as $key => $value ) {
			// Allows erasing fields during updates.
			if ( '' === $value ) {
				$data[ $key ] = null;
			}
		}

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PATCH';

		$response = wp_safe_remote_request( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/' . $contact_id, $params );

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

		$response = wp_safe_remote_get( $this->instance_url . '/services/data/v42.0/sobjects/' . $this->object_type . '/' . $contact_id, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body           = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && array_key_exists( $field_data['crm_field'], $body ) ) {

				if ( 'multiselect' === $field_data['type'] || 'checkboxes' == $field_data['type'] ) {
					$user_meta[ $field_id ] = explode( ';', $body[ $field_data['crm_field'] ] );
				} elseif ( 'checkbox' === $field_data['type'] && false === $body[ $field_data['crm_field'] ] ) {
					$user_meta[ $field_id ] = null; // this lets us pass the is_null() check in WPF_User::set_user_meta() and load the empty value.
				} else {
					$user_meta[ $field_id ] = $body[ $field_data['crm_field'] ];
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

		$tag_type = wpf_get_option( 'sf_tag_type', 'Topics' );

		// Limited to 2000 contacts right now
		if ( $tag_type === 'Topics' ) {

			$query_args = array( 'q' => "SELECT EntityId from TopicAssignment WHERE TopicId = '" . $tag . "'" );
			$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'load_contacts', $tag );

			$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->records ) ) {

				foreach ( $response->records as $contact ) {
					$contact_ids[] = $contact->EntityId;
				}
			}
		} elseif ( 'Personal' === $tag_type || 'Public' === $tag_type ) {

			$query_args = array( 'q' => "SELECT ItemId, TagDefinitionId from ContactTag where TagDefinitionId = '" . $tag . "'" );
			$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'load_contacts', $tag );

			$request = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->records ) ) {

				foreach ( $response->records as $contact ) {
					$contact_ids[] = $contact->ItemId;
				}
			}
		} elseif ( 'Picklist' === $tag_type ) {

			$tags_field = wpf_get_option( 'sf_tag_picklist' );

			$query_args = array( 'q' => "SELECT Id from Contact where {$tags_field} includes ('{$tag}')" );
			$query_args = apply_filters( 'wpf_salesforce_query_args', $query_args, 'load_contacts', $tag );

			$request  = add_query_arg( $query_args, $this->instance_url . '/services/data/v42.0/query' );
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->records ) ) {

				foreach ( $response->records as $contact ) {

					$contact_ids[] = $contact->{'Id'};

				}
			}
		}

		return $contact_ids;

	}


}
