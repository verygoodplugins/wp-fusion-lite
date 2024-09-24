<?php

class WPF_Copper {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'copper';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Copper';

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'add_tags' );

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Copper_Admin( $this->slug, $this->name, $this );
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

		add_action( 'init', array( $this, 'get_actions' ), 5 );

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ), 10, 1 );
		//add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

		$account_id = wpf_get_option( 'account_id' );

		if ( ! empty( $account_id ) ) {
			$this->edit_url = 'https://app.copper.com/companies/' . $account_id . '/app#/contact/%d';
		}
	}


	/**
	 * Look for incoming Copper payloads
	 *
	 * @access public
	 * @return void
	 */

	public function get_actions() {
		if ( ! empty( file_get_contents( 'php://input' ) ) ) {

			$payload = json_decode( file_get_contents( 'php://input' ) );

			if ( ! empty( $payload ) ) {

				if ( isset( $payload->subscription_id ) ) {

					$_REQUEST['wpf_action'] = sanitize_text_field( $payload->secret );
					$_REQUEST['access_key'] = sanitize_text_field( $payload->key );

				}
			}
		}

	}


	/**
	 * Formats user entered data to match Copper field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type ) {

			// Make sure dates are ints and not strings.

			return (int) $value;

		} elseif ( is_array( $value ) ) {

			// Copper can handle arrays.

			return $value;

		} else {

			return $value;

		}

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

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		if ( $post_data['wpf_action'] == 'update' ) {

			$post_data['contact_id'] = $payload->ids[0];
			return $post_data;

		} elseif ( $post_data['wpf_action'] == 'add' && isset( $payload->updated_attributes->tags ) ) {

			$tag = wpf_get_option( 'copper_add_tag' );

			foreach ( $payload->updated_attributes->tags as $update_tag ) {

				if ( empty( $update_tag ) ) {
					continue;
				}

				if ( $update_tag[0] == $tag[0] ) {

					$post_data['contact_id'] = $payload->ids[0];
					return $post_data;

				}
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

		if ( strpos( $url, 'copper.com' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->success ) && $body_json->success == false ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error );

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

	public function get_params( $user_email = null, $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) || empty( $user_email ) ) {
			$access_key = wpf_get_option( 'copper_key' );
			$user_email = wpf_get_option( 'copper_user_email' );
		}

		$this->params = array(
			'timeout'    => 60,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'X-PW-AccessToken' => $access_key,
				'X-PW-Application' => 'developer_api',
				'X-PW-UserEmail'   => $user_email,
				'Content-type'     => 'application/json',

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

	public function connect( $email = null, $access_key = null, $test = false ) {
		if ( ! $this->params ) {
			$this->get_params( $email, $access_key );
		}

		if ( $test == false ) {
			return true;
		}

		$request  = 'https://api.copper.com/developer_api/v1/account';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Save account ID for later

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->message ) ) {
			return new WP_Error( 'error', $response->message );
		}

		wp_fusion()->settings->set( 'account_id', $response->id );

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

		// Can't currently list tags or list all contacts
		$tags     = array();
		$request  = 'https://api.copper.com/developer_api/v1/people/search';
		$response = wp_safe_remote_post( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return false;
		}

		foreach ( $body_json as $person => $fields ) {

			foreach ( $fields['tags'] as $person_tags ) {
				$tags[ $person_tags ] = $person_tags;
			}
		}

		//fix keys later
		$tags = array_unique( $tags );

		// Check if we need to update the available tags list
		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $body_json as $person => $fields ) {

			foreach ( $fields['tags'] as $person_tags ) {

				if ( ! isset( $available_tags[ $person_tags ] ) ) {
					$available_tags[ $person_tags ] = $person_tags;
				}
			}
		}

		$available_tags = array_unique( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $tags;

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
		require dirname( __FILE__ ) . '/admin/copper-fields.php';

		$built_in_fields = array();

		foreach ( $copper_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$request  = 'https://api.copper.com/developer_api/v1/custom_field_definitions';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		// For Dropdown type fields
		$option_ids = array();

		if ( ! empty( $response ) ) {

			foreach ( $response as $field ) {

				$custom_fields[ $field->id ] = ucwords( str_replace( '_', ' ', $field->name ) );

				if ( ! empty( $field->options ) ) {

					foreach ( $field->options as $option ) {
						$option_ids[ $option->name ] = $option->id;
					}
				}
			}

			asort( $custom_fields );

		}

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		if ( ! empty( $option_ids ) ) {
			wp_fusion()->settings->set( 'copper_option_ids', $option_ids );
		}

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

		$email = array( 'email' => $email_address );

		$params         = $this->params;
		$params['body'] = wp_json_encode( $email );

		$request  = 'https://api.copper.com/developer_api/v1/people/fetch_by_email';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) && $response->get_error_message() == 'Resource not found' ) {
			return false;
		} elseif ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) || empty( $response->id ) ) {
			return false;
		}

		return $response->id;

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

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		if ( empty( $response->tags ) ) {
			return false;
		}

		foreach ( $response->tags as $tag_name ) {

			$tag_name = explode( ',', $tag_name );

			foreach ( $tag_name as $tag ) {
				$tags[ $tag ] = $tag;
			}
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

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$original_tags = $response->tags;

		$new_tags = array_merge( $original_tags, $tags );

		$tags = array( 'tags' => $new_tags );

		$params           = $this->params;
		$params['body']   = wp_json_encode( $tags );
		$params['method'] = 'PUT';

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

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

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$original_tags = $response->tags;

		$new_tags = str_replace( $tags, '', $original_tags );

		$tags = array( 'tags' => $new_tags );

		$params           = $this->params;
		$params['body']   = wp_json_encode( $tags );
		$params['method'] = 'PUT';

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return true;

	}

	/**
	 * Takes an array of key / value pairs and formats them according to the
	 * Copper API standard.
	 *
	 * @since  3.38.11
	 *
	 * @param  array $data   The data.
	 * @return array The formatted data.
	 */
	public function format_contact_api_payload( $data ) {

		$option_ids = wpf_get_option( 'copper_option_ids', array() );

		foreach ( $data as $field => $value ) {

			if ( 'email' === $field ) {

				$update_data['emails'] = array(
					array(
						'email'    => $value,
						'category' => 'work',
					),
				);

			} elseif ( 'number' === $field ) {

				$updata_data['phone_numbers'] = array(
					array(
						'number'   => $value,
						'category' => 'work',
					),
				);

			} elseif ( in_array( $field, array( 'street', 'city', 'state', 'postal_code', 'country' ), true ) ) {

				if ( ! isset( $update_data['address'] ) ) {
					$update_data['address'] = array();
				}

				$update_data['address'][ $field ] = $value;

			} elseif ( is_int( $field ) ) {

				// Custom fields.

				if ( ! isset( $update_data['custom_fields'] ) ) {
					$update_data['custom_fields'] = array();
				}

				if ( is_array( $value ) ) {

					foreach ( $value as $i => $option ) {

						if ( isset( $option_ids[ $option ] ) ) {
							$value[ $i ] = absint( $option_ids[ $option ] );
						} else {
							wpf_log( 'notice', wpf_get_current_user_id(), 'An unknown value <code>' . $option . '</code> was provided for field <code>' . $field . '</code>. To use this value as a multiselect option, it must be first added to the dropdown options on the field in Copper. After adding a new option, click <strong>Refresh Available Tags and Fields</strong> in the WP Fusion settings to update the local cache.' );
							unset( $value[ $i ] ); // to avoid an error.
						}
					}
				} else {

					// Dropdowns.

					if ( isset( $option_ids[ $value ] ) ) {
						$value = absint( $option_ids[ $value ] );
					}
				}

				if ( empty( $value ) ) {
					$value = false; // checkboxes
				}

				$update_data['custom_fields'][] = array(
					'custom_field_definition_id' => $field,
					'value'                      => $value,
				);

			} else {

				$update_data[ $field ] = $value;

			}
		}

		$update_data['name'] = $update_data['first_name'] . ' ' . $update_data['last_name']; // Copper requires a name.

		return $update_data;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$update_data = $this->format_contact_api_payload( $data );

		$params         = $this->params;
		$params['body'] = wp_json_encode( $update_data );

		$request  = 'https://api.copper.com/developer_api/v1/people';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$update_data = $this->format_contact_api_payload( $data );

		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $update_data );

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

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

		$request  = 'https://api.copper.com/developer_api/v1/people/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) ) {
			return new WP_Error( 'error', 'Unable to find contact ID ' . $contact_id . ' in Copper.' );
		}

		$option_ids = wpf_get_option( 'copper_option_ids', array() );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( ! $field_data['active'] ) {
				continue;
			}

			if ( ! empty( $response->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $response->{ $field_data['crm_field'] };
			}

			if ( ! empty( $response->emails[0]->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $response->emails[0]->{ $field_data['crm_field'] };
			}

			if ( ! empty( $response->phone_numbers[0]->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $response->phone_numbers[0]->{ $field_data['crm_field'] };
			}

			// Address parts.

			if ( ! empty( $response->address ) ) {

				foreach ( $response->address as $key => $value ) {

					if ( $key == $field_data['crm_field'] && ! empty( $value ) ) {
						$user_meta[ $field_id ] = $value;
					}
				}
			}

			// Custom fields.

			if ( ! empty( $response->custom_fields ) ) {

				foreach ( $response->custom_fields as $field ) {

					if ( $field->custom_field_definition_id == $field_data['crm_field'] && ! empty( $field->value ) ) {

						if ( is_numeric( $field->value ) ) {

							// Dropdowns.

							$key = array_search( $field->value, $option_ids );

							if ( false !== $key ) {
								$field->value = $key;
							}
						} elseif ( is_array( $field->value ) ) {

							// Multiselects.

							foreach ( $field->value as $i => $value ) {

								$key = array_search( $value, $option_ids );

								if ( false !== $key ) {
									$field->value[ $i ] = $key;
								}
							}
						}

						$user_meta[ $field_id ] = $field->value;
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

		$tag = array( 'tags' => $tag );

		$params         = $this->params;
		$params['body'] = wp_json_encode( $tag );

		$url     = 'https://api.copper.com/developer_api/v1/people/search';
		$results = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		//Does not work because no way of telling how many contacts have a certain tag

		// if (isset($body_json[199])) {
		// 	$tag = array('tags' => $tag, 'page_size' => 200, 'page_number' => $page_number);
		// 	$params = $this->params;
		//  $params['body'] = wp_json_encode( $tag );

		// 	$url     = 'https://api.copper.com/developer_api/v1/people/search'];
		// 	$results = wp_safe_remote_get( $url, $this->params );
		// }

		foreach ( $body_json as $row => $contact ) {
			$contact_ids[] = $contact['id'];
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

		$access_key = wpf_get_option( 'access_key' );

		$data = array(
			'target' => get_home_url(),
			'type'   => 'person',
			'event'  => 'update',
			'secret' => array(
				'secret' => $type,
				'key'    => $access_key,
			),
		);

		$request          = 'https://api.copper.com/developer_api/v1/webhooks';
		$params           = $this->params;
		$params['method'] = 'POST';
		$params['body']   = wp_json_encode( $data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );

		if ( is_object( $result ) ) {
			return $result->id;
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

		$request          = 'https://api.copper.com/developer_api/v1/webhooks/' . $rule_id;
		$params           = $this->params;
		$params['method'] = 'DELETE';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


}
