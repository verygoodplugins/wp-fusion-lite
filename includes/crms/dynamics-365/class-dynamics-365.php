<?php

class WPF_Dynamics_365 {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'dynamics-365';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Dynamics 365';

	/**
	 * Contains API url
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */

	public $url;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * "add_fields" means that custom field / attrubute keys don't need to exist
	 * first in the CRM to be used. With add_fields enabled, WP Fusion will
	 * allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var  array
	 *
	 * @since 3.38.43
	 */

	public $supports = array();


	/**
	 * API parameters
	 *
	 * @var  array
	 *
	 * @since 3.38.43
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */
	public $edit_url = '';

	/**
	 * The client ID.
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */
	public $client_id = '6d2e39f8-e92c-4afa-944c-708d658a9fa0';

	/**
	 * The client secret.
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */
	public $client_secret = 'glH8Q~zL2OBS1Nc3zuwUwzLF3rc3CXetEP1aKb9~';

	/**
	 * The OAuth callback URL.
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */
	public $callback_url = 'https://wpfusion.com/oauth/';


	/**
	 * Lets outside functions override the object type (Leads for example)
	 *
	 * @var  string
	 *
	 * @since 3.41.19
	 */
	public $object_type = 'contacts';

	/**
	 * Lets outside functions override the object type (Lead for example)
	 *
	 * @access private
	 * @var    string
	 *
	 * @since 3.41.24
	 */
	private $object_type_singular = 'contact';

	/**
	 * Allows text to be overridden for CRMs that use different segmentation
	 * labels (groups, lists, etc)
	 *
	 * @var  string
	 *
	 * @since 3.38.43
	 */

	public $tag_type = 'List';

	/**
	 * Get things started
	 *
	 * @since 3.38.43
	 */
	public function __construct() {

		$this->url = rtrim( wpf_get_option( 'dynamics_365_rest_url' ), '/' ) . '/api/data/v9.0';

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/class-dynamics-365-admin.php';
			new WPF_Dynamics_365_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.38.43
	 */
	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		$this->object_type = apply_filters( 'wpf_crm_object_type', wpf_get_option( 'dynamics_365_object_type', $this->object_type ) );

		$this->object_type_singular = substr( $this->object_type, 0, -1 );

		if ( ! empty( wpf_get_option( 'dynamics_365_rest_url' ) ) ) {
			$this->edit_url = rtrim( wpf_get_option( 'dynamics_365_rest_url' ), '/' ) . '/main.aspx?forceUCI=1&pagetype=entityrecord&etn=' . $this->object_type_singular . '&id=%s';
		}
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format.
	 *
	 * Not currently supported. See https://cloudblogs.microsoft.com/dynamics365/no-audience/2016/01/15/sending-webhooks-with-microsoft-dynamics-crm/.
	 *
	 * @since  3.38.5
	 *
	 * @param  array $post_data The post data.
	 * @return array The post data.
	 */
	public function format_post_data( $post_data ) {

		$body = json_decode( file_get_contents( 'php://input' ) );

		if ( ! empty( $body ) && isset( $body->id ) ) {
			$post_data['contact_id'] = $body->id;
		}

		return $post_data;
	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.38.43
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} elseif ( 'multiselect' === $field_type && empty( $value ) ) {

			$value = null;

		} else {

			return $value;

		}
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since 3.38.43
	 *
	 * @return array $params The API parameters.
	 */
	public function get_params( $access_token = null ) {

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB
		if ( empty( $access_token ) ) {
			$access_token = wpf_get_option( 'dynamics_365_access_token' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 20,
			'headers'    => array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Authorization'    => 'Bearer ' . $access_token,
				'OData-MaxVersion' => '4.0',
				'OData-Version'    => '4.0',
				'If-None-Match'    => null,

			),
		);

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.38.43
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 401 === $response_code ) {

				$access_token = $this->refresh_token();

				if ( is_wp_error( $access_token ) ) {
					return $access_token;
				}

				$args['headers']['Authorization'] = 'Bearer ' . $access_token;

				$response = wp_safe_remote_request( $url, $args );

			} elseif ( 404 === $response_code ) {

				$response = new WP_Error( 'error', 'The requested resource was not found.' );

			} elseif ( 400 === $response_code ) {
				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif ( 413 === $response_code ) {

				$response = new WP_Error( 'error', 'the request length is too large.' );

			} elseif ( 429 === $response_code ) {

				$response = new WP_Error( 'error', 'You have maxed your number of API calls for the provided time window.' );

			}
		}

		return $response;
	}


	/**
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @since  3.38.43
	 *
	 * @param  string $access_token The Access token.
	 * @param  string $refresh_token   The Refresh token.
	 * @param  bool   $test      Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $access_token = null, $refresh_token = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		$request  = $this->url . '/emails?$top=1';
		$response = wp_safe_remote_get( $request, $this->get_params( $access_token ) );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.38.43
	 *
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
	 * Gets all available lists and saves them to options.
	 *
	 * At the moment we are using Static Marketing Lists for segmentation but I'm
	 * open to adding options for additional list types in a future update.
	 *
	 * @since  3.38.43
	 *
	 * @link https://docs.microsoft.com/en-us/dynamics365/marketing/segments-vs-lists
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {
		$request  = $this->url . '/lists?$select=listid,listname,type';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'.
		if ( ! empty( $response->value ) ) {

			foreach ( $response->value as $list ) {

				if ( empty( $list->type ) ) {
					$category = 'Static Lists';
				} else {
					$category = 'Dynamic Lists (Read Only)';
				}

				$available_tags[ $list->listid ] = array(
					'label'    => sanitize_text_field( $list->listname ),
					'category' => $category,
				);

			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  bool
	 */
	public function refresh_token( $refresh_token = null ) {
		if ( $refresh_token == null ) {
			$refresh_token = wpf_get_option( 'dynamics_365_refresh_token' );
		}

		$url = 'https://login.microsoftonline.com/common/oauth2/token/';

		$params = array(
			'headers' => array(
				'Content-type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'refresh_token' => $refresh_token,
				'client_id'     => $this->client_id,
				'grant_type'    => 'refresh_token',
				'resource'      => wpf_get_option( 'dynamics_365_rest_url' ),
				'client_secret' => $this->client_secret,
				'scope'         => 'openid offline_access https://graph.microsoft.com/user.read',
			),
		);

		$response = wp_remote_post( $url, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->error ) ) {
			return new WP_Error( 'error', $response->error->message );
		}

		wp_fusion()->settings->set( 'dynamics_365_access_token', $response->access_token );
		wp_fusion()->settings->set( 'dynamics_365_refresh_token', $response->refresh_token );

		return $response->access_token;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since 3.38.43
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$response = wp_safe_remote_get( $this->url . '/EntityDefinitions(LogicalName=\'' . $this->object_type_singular . '\')/Attributes', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->value as $field ) {

			if ( 'Virtual' === $field->{'AttributeType'} || empty( $field->{'DisplayName'}->{'UserLocalizedLabel'} ) ) {
				continue;
			}

			$id         = $field->{'LogicalName'};
			$label_base = $field->{'DisplayName'}->{'UserLocalizedLabel'}->{'Label'};

			$label_base = str_replace( '(', '&#40;', $label_base ); // normally () content gets made small so HTML-escaping it will avoid that.
			$label_base = str_replace( ')', '&#41;', $label_base );

			if ( 'Lookup' === $field->{'AttributeType'} || 'Owner' === $field->{'AttributeType'} || 'Customer' === $field->{'AttributeType'} ) {

				if ( 1 < count( $field->{'Targets'} ) ) {

					// Multi-valued navigation properties.
					// see https://learn.microsoft.com/en-us/troubleshoot/power-platform/power-apps/dataverse/web-api-client-errors#cause-4.

					foreach ( $field->{'Targets'} as $target ) {

						$id    = '_' . $id . '_' . $target . '_value';
						$label = $label_base . ' (Lookup Field &raquo; ' . ucwords( $target ) . ')';

						if ( $field->{'IsCustomAttribute'} ) {
							$custom_fields[ $id ] = $label;
						} else {
							$built_in_fields[ $id ] = $label;
						}
					}

					continue;

				}

				// Single-valued navigation properties.
				$id    = '_' . $id . '_value';
				$label = $label_base . ' (Lookup Field)';

			} else {
				$label = $label_base;
			}

			if ( $field->{'IsCustomAttribute'} ) {
				$custom_fields[ $id ] = $label;
			} else {
				$built_in_fields[ $id ] = $label;
			}
		}

		asort( $built_in_fields );
		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.38.43
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$lookup_field = wp_fusion()->crm->get_crm_field( 'user_email', 'emailaddress1' );
		$lookup_field = apply_filters( 'wpf_dynamics_365_lookup_field', $lookup_field );

		// Escape single quotes by doubling them.
		$email_address = str_replace( "'", "''", $email_address );

		$request  = $this->url . '/' . $this->object_type . '?$filter=' . $lookup_field . ' eq \'' . $email_address . '\'';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response ) || empty( $response->value ) ) {
			return false;
		}

		return $response->value[0]->{ $this->object_type_singular . 'id' };
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.38.43
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . '/listmembers?$filter=_entityid_value%20eq%20\'' . $contact_id . '\'&$select=_listid_value';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return wp_list_pluck( $response->value, '_listid_value' );
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.38.43
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_params();

		foreach ( $tags as $tag ) {

			$params['body'] = wp_json_encode( array( 'EntityId' => $contact_id ) );
			$request        = $this->url . '/lists(' . $tag . ')/Microsoft.Dynamics.CRM.AddMemberList';
			$response       = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.38.43
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params = $this->get_params();
		$body   = array();

		foreach ( $tags as $tag ) {
			$body['ListMember']['listmemberid'] = $contact_id;
			$params['body']                     = wp_json_encode( $body );
			$request                            = $this->url . '/lists(' . $tag . ')/Microsoft.Dynamics.CRM.RemoveMemberList';
			$response                           = wp_remote_post( $request, $params );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Formats lookup fields for the API.
	 *
	 * @link https://learn.microsoft.com/en-us/previous-versions/dynamicscrm-2016/developers-guide/mt607875(v=crm.8)
	 *
	 * @since 3.40.57
	 *
	 * @param array $data The contact data.
	 */
	public function format_lookup_fields( $data ) {

		foreach ( $data as $key => $value ) {

			if ( 0 === strpos( $key, '_' ) && false !== strpos( $key, '_value' ) ) {

				unset( $data[ $key ] );

				$key = trim( $key, '_' );
				$key = str_replace( '_value', '', $key );

				// Add the slash if needed.
				$value = '/' . ltrim( $value, '/\\' );

				if ( ! preg_match( '/^\/[\w-]+(\([0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\))$/', $value ) ) {
					wpf_log(
						'notice',
						wpf_get_current_user_id(),
						'Invalid lookup field value for <strong>' . $key . '</strong>: <code>' . $value . '</code>.<br />Values should be synced as a navigation proprty, for example <code>/systemusers(5cfb32d8-dbb4-ed11-9885-6045bd01004c)</code>. For more info see the <a href="https://wpfusion.com/documentation/crm-specific-docs/dynamics-365-associating-entities/" target="_blank">documentation on assocating entities</a>. To avoid an API error, this value will not be synced.',
						array( 'source' => 'dynamics-365' )
					);
					continue;
				}

				$data[ $key . '@odata.bind' ] = $value;

			}
		}

		return $data;
	}

	/**
	 * Adds a new contact.
	 *
	 * @since  3.38.43
	 *
	 * @param  array $data   The contact data.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $data ) {

		$data = $this->format_lookup_fields( $data );

		$request                     = $this->url . '/' . $this->object_type . '/';
		$params                      = $this->get_params();
		$params['body']              = wp_json_encode( $data );
		$params['headers']['Prefer'] = 'return=representation';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->{ $this->object_type_singular . 'id' };
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since  3.38.43
	 *
	 * @param  int   $contact_id      The ID of the contact to update.
	 * @param  array $data            An associative array of contact fields
	 *                                and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $data ) {

		$data = $this->format_lookup_fields( $data );

		$request          = $this->url . '/' . $this->object_type . '(' . $contact_id . ')';
		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = wp_json_encode( $data );
		$response         = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.38.43
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/' . $this->object_type . '(' . $contact_id . ')';
		$response = wp_safe_remote_get( $request, $this->get_params() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		$loaded_meta = array(
			'email' => $response['emailaddress1'],
		);

		if ( ! empty( $response ) ) {
			$loaded_meta = array_merge( $loaded_meta, $response );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $loaded_meta[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_meta[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.38.43
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		$request  = $this->url . '/listmembers?$filter=_listid_value eq \'' . $tag . '\'&$select=_entityid_value';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return wp_list_pluck( $response->value, '_entityid_value' );
	}
}
