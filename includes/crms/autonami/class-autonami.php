<?php

class WPF_Autonami {

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $supports = array();

	/**
	 * API authentication parameters and headers.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $params;

	/**
	 * API URL.
	 *
	 * @var array
	 * @since 3.37.14
	 */

	public $url;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.14
	 * @var  string
	 */

	public $edit_url = '';


	/**
	 * Get things started
	 *
	 * @since 3.37.14
	 */

	public function __construct() {

		$this->slug = 'autonami';
		$this->name = 'Autonami';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-autonami-admin.php';
			new WPF_Autonami_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.37.14
	 */

	public function init() {

		$url = wpf_get_option( 'autonami_url' );

		if ( ! empty( $url ) ) {
			$this->url      = trailingslashit( $url ) . 'wp-json/autonami-admin';
			$this->edit_url = trailingslashit( $url ) . 'wp-admin/admin.php?page=autonami&path=/contact/%d#/';
		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @param WP_HTTP_Response $response The HTTP response.
	 * @param array $args The HTTP request arguments.
	 * @param string $url The HTTP request URL.
	 *
	 * @return WP_HTTP_Response $response The response.
	 * @since  3.37.14
	 *
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( wp_remote_retrieve_body( $response ) ) ) {

				$response = new WP_Error( 'error', 'No response was returned. You may need to <a href="https://wordpress.org/support/article/using-permalinks/#mod_rewrite-pretty-permalinks" target="_blank">enable pretty permalinks</a>.' );

			} elseif ( wp_remote_retrieve_response_code( $response ) > 204 || ( isset( $body->code ) && $body->code > 204 ) ) {

				if ( ! empty( $body->code ) ) {

					if ( 'rest_no_route' == $body->code ) {

						$body->message .= ' <strong>' . __( 'This could mean the Autonami Marketing Automations Pro plugin isn\'t active.', 'wp-fusion-lite' ) . '</strong>';
						$body->message .= ' ' . __( 'Try again or <a href="http://wpfusion.com/contact">contact support</a>.', 'wp-fusion-lite' ) . ' (URL: ' . $url . ')';

					}

					$response = new WP_Error( 'error', $body->message );

				} else {

					$response = new WP_Error( 'error', wp_remote_retrieve_response_message( $response ) );

				}
			}
		}

		return $response;

	}

	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @return bool
	 * @since  3.37.14
	 *
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
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @param string $url The api url.
	 * @param string $username The application username.
	 * @param string $password The application password.
	 * @param bool $test Whether to validate the credentials.
	 *
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 * @since  3.37.14
	 *
	 */

	public function connect( $url = null, $username = null, $password = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $url, $username, $password );
		}

		if ( false === $test ) {
			return true;
		}

		$request  = $this->url . '/contacts';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Gets params for API calls.
	 *
	 * @param string $url The api url.
	 * @param string $username The application username.
	 * @param string $password The application password.
	 *
	 * @return array  $params The API parameters.
	 * @since  3.37.14
	 */

	public function get_params( $url = null, $username = null, $password = null ) {

		if ( $this->params ) {
			return $this->params; // already set up
		}

		// Get saved data from DB
		if ( ! $url || ! $username || ! $password ) {
			$url      = wpf_get_option( 'autonami_url' );
			$username = wpf_get_option( 'autonami_username' );
			$password = wpf_get_option( 'autonami_password' );
		}

		$this->url = trailingslashit( $url ) . 'wp-json/autonami-admin';

		$this->params = array(
			'timeout'    => 15,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'sslverify'  => false,
			'headers'    => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
			),
		);

		return $this->params;
	}

	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 * @since  3.37.14
	 *
	 */
	public function sync_tags() {

		$available_tags = array();
		$continue       = true;
		$limit          = 100;
		$offset         = 0;
		$page           = 1;

		while ( $continue ) {

			$request  = $this->url . '/tags?limit=' . $limit . '&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->result ) ) {

				foreach ( $response->result as $tag ) {

					$available_tags[ $tag->ID ] = $tag->name;
				}
			}

			if ( empty( $response->result ) || count( $response->result ) < 100 ) {
				$continue = false;
			} else {
				$offset = ( $page > 1 ) ? ( $limit * ( $page - 1 ) ) : 0;
				$page ++;
			}
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}

	/**
	 * Gets all available lists and saves them to options.
	 *
	 * @return array|WP_Error Either the available lists in the CRM, or a WP_Error.
	 * @since  3.37.14
	 *
	 */
	public function sync_lists() {

		$available_lists = array();
		$continue        = true;
		$limit           = 100;
		$offset          = 0;
		$page            = 1;

		while ( $continue ) {

			$request  = $this->url . '/lists?limit=' . $limit . '&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->result ) ) {

				foreach ( $response->result as $list ) {

					$available_lists[ $list->ID ] = $list->name;
				}
			}

			if ( empty( $response->result ) || count( $response->result ) < 100 ) {
				$continue = false;
			} else {
				$offset = ( $page > 1 ) ? ( $limit * ( $page - 1 ) ) : 0;
				$page ++;
			}
		}

		asort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;

	}


	/**
	 * Gets all available fields from the CRM and saves them to options.
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 * @since  3.37.14
	 *
	 */
	public function sync_crm_fields() {

		// Standard fields

		$standard_fields = array();

		foreach ( WPF_Autonami_Admin::get_default_fields() as $field ) {
			$standard_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		// Custom fields

		$custom_fields = array();

		$request  = $this->url . '/groupfields';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$extra_fields = $response->result->extra_fields;
		$groupfields  = $response->result->fields;
		foreach ( $extra_fields as $field ) {
			$custom_fields[ $field->ID ] = $field->name;
		}

		foreach ( $groupfields as $group ) {
			if ( empty( $group->fields ) ) {
				continue;
			}
			foreach ( $group->fields as $field ) {

				if ( ! isset( $standard_fields[ $field->id ] ) ) {
					$custom_fields[ $field->id ] = $field->name;
				}
			}
		}
		if ( isset( $custom_fields['address'] ) ) {
			unset( $custom_fields['address'] );
		}

		asort( $standard_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $standard_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @param string $email_address The email address to look up.
	 *
	 * @return int|WP_Error The contact ID in the CRM.
	 * @since  3.37.14
	 */
	public function get_contact_id( $email_address ) {

		$request  = $this->url . '/contacts?search=' . urlencode( $email_address );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->result ) ) {
			return false;
		}
		return $response->result[0]->id;

	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 *
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 * @since  3.37.14
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . '/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$tags = array();

		foreach ( $response->result->tags as $tag ) {
			$tags[] = $tag->ID;
		}

		return $tags;

	}


	/**
	 * Applies tags to a contact.
	 *
	 * @param array $tags A numeric array of tags to apply to the
	 *                                   contact.
	 * @param int $contact_id The contact ID to apply the tags to.
	 *
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 * @since  3.37.14
	 *
	 */
	public function apply_tags( $tags, $contact_id ) {
		$prepared_data = [];
		foreach ( $tags as $tag_id ) {
			$prepared_data[] = [ 'id' => $tag_id ];
		}
		$body           = array(
			'tags' => $prepared_data,
		);
		$params         = $this->get_params();
		$params['body'] = json_encode( $body );

		$request  = $this->url . '/contacts/' . $contact_id . '/tags';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Removes tags from a contact.
	 *
	 * @param array $tags A numeric array of tags to remove from
	 *                                   the contact.
	 * @param int $contact_id The contact ID to remove the tags from.
	 *
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 * @since  3.37.14
	 *
	 */
	public function remove_tags( $tags, $contact_id ) {
		$body = array(
			'tags' => $tags,
		);

		$params           = $this->get_params();
		$params['method'] = 'DELETE';
		$params['body']   = json_encode( $body );

		$request = $this->url . '/contacts/' . $contact_id . '/tags';
		;
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Adds a new contact.
	 *
	 * @param array $data An associative array of contact
	 *                                       fields and field values.
	 * @param bool $map_meta_fields Whether to map WordPress meta keys
	 *                                       to CRM field keys.
	 *
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 * @since  3.37.14
	 *
	 */
	public function add_contact( $data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$contact_data = array(
			'email'      => $data['email'],
			'f_name'     => isset( $data['f_name'] ) ? $data['f_name'] : '',
			'l_name'     => isset( $data['l_name'] ) ? $data['l_name'] : '',
			'contact_no' => isset( $data['contact_no'] ) ? $data['contact_no'] : '',
			'status'     => true,
		);

		$params         = $this->get_params();
		$params['body'] = json_encode( $contact_data );

		$request  = $this->url . '/contacts';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		// Remove internal fields and then update the contact if there's anything left

		$data = array_diff( $data, $contact_data );

		if ( ! empty( $data ) ) {
			$this->update_contact( $response->result->id, $data, false );
		}

		return $response->result->id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @param int $contact_id The ID of the contact to update.
	 * @param array $data An associative array of contact
	 *                                        fields and field values.
	 * @param bool $map_meta_fields Whether to map WordPress meta keys
	 *                                        to CRM field keys.
	 *
	 * @return bool|WP_Error Error if the API call failed.
	 * @since  3.37.14
	 *
	 */
	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$contact_data = array(
			'fields' => $data,
			'status' => true,
		);

		$params         = $this->get_params();
		$params['body'] = json_encode( $contact_data );

		$request  = $this->url . '/contacts/' . $contact_id . '/fields';
		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress
	 * fields.
	 *
	 * @param int $contact_id The ID of the contact to load.
	 *
	 * @return array|WP_Error User meta data that was returned.
	 * @since  3.37.14
	 *
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/contacts/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $response['result']['fields'] ) ) {
			$response['result'] = array_merge( $response['result'], $response['result']['fields'] ); // merge custom fields for quicker mapping
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( ! empty( $field_data['active'] ) ) {

				if ( isset( $response['result'][ $field_data['crm_field'] ] ) ) {

					// Core fields
					$user_meta[ $field_id ] = $response['result'][ $field_data['crm_field'] ];

				} elseif ( isset( $response['result']['fields'][ $field_data['crm_field'] ] ) ) {

					$user_meta[ $field_id ] = $response['result']['fields'][ $field_data['crm_field'] ];

				}

				// Maybe decode arrays

				if ( null !== json_decode( $user_meta[ $field_id ] ) ) {
					$user_meta[ $field_id ] = json_decode( $user_meta[ $field_id ] );
				}
			}
		}

		return $user_meta;

	}


	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @param string $tag The tag ID or name to search for.
	 *
	 * @return array  Contact IDs returned.
	 * @since  3.37.14
	 */
	public function load_contacts( $tag ) {

		// At the moment WP Fusion is storing the tag slug, but FCRM uses the ID for searches, so we need to look it up
		if ( ! is_numeric( $tag ) ) {
			$request  = $this->url . '/tags?search=' . $tag . '&limit=1';
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->result ) ) {
				return new WP_Error( 'error', 'Unable to determine tag ID from tag ' . $tag );
			}

			$tag_id = $response->result[0]->ID;
		}

		$tag_id = absint( $tag );

		$contact_ids = array();
		$proceed     = true;
		$page        = 1;
		$limit       = 100;
		$offset      = 0;

		while ( $proceed ) {

			$request  = $this->url . '/contacts?limit=' . $limit . '&filters[tags_any][0]=' . $tag_id . '&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->result as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if ( count( $response->result ) < 100 ) {
				$proceed = false;
			} else {
				$offset = ( $page > 1 ) ? ( $limit * ( $page - 1 ) ) : 0;
				$page ++;
			}
		}

		return $contact_ids;

	}


}
