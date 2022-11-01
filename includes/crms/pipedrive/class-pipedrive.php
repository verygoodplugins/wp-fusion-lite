<?php

class WPF_Pipedrive {

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.40.33
	 */

	public $url;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_fields" means that custom field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var array
	 * @since 3.40.33
	 */

	public $supports = array( 'add_fields' );

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.40.33
	 */
	public $params = array();

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * @var string
	 * @since 3.40.33
	 */
	public $edit_url = '';


	/**
	 * Client ID for OAuth (if applicable).
	 *
	 * @var string
	 * @since 3.40.33
	 */
	public $client_id = '6cc27b9043b3360f';

	/**
	 * Client secret for OAuth (if applicable).
	 *
	 * @var string
	 * @since 3.40.33
	 */
	public $client_secret = '6d185afb6780388f02547c645549dd4bb291f37c';


	/**
	 * Authorization URL for OAuth (if applicable).
	 *
	 * @var string
	 * @since 3.40.33
	 */
	public $auth_url = 'https://oauth.pipedrive.com/oauth/token';

	/**
	 * Get things started
	 *
	 * @since 3.40.33
	 */
	public function __construct() {

		$this->slug = 'pipedrive';
		$this->name = 'Pipedrive';

		// Set up admin options.
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-pipedrive-admin.php';
			new WPF_Pipedrive_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.40.33
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		$api_domain = wpf_get_option( 'pipedrive_api_domain' );

		$this->url = $api_domain . '/api/v1';

		if ( ! empty( $api_domain ) ) {
			$this->edit_url = $api_domain . '/person/%d#/';
		}

	}


	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.40.33
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			$date = gmdate( 'm/d/Y H:i:s', $value );

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
	 * Formats POST data received from webhooks into standard format.
	 *
	 * @since  3.40.33
	 *
	 * @param array $post_data The data read out of the webhook URL.
	 * @return array $post_data The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( isset( $payload->current->id ) ) {
			$post_data['contact_id'] = absint( $payload->current->id );
		}

		$tags_field = wpf_get_option( 'pipedrive_tag' );

		if ( ! empty( $payload->current->{ $tags_field } ) ) {
			$post_data['tags'] = explode( ',', $payload->current->{ $tags_field } );
		}

		return $post_data;

	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.40.33
	 *
	 * @param  string $access_token The access token.
	 * @param  string $api_domain The api domain.
	 * @return array  $params The API parameters.
	 */
	public function get_params( $access_token = null, $api_domain = null ) {

		// If it's already been set up.
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB.
		if ( empty( $access_token ) || empty( $api_domain ) ) {
			$access_token = wpf_get_option( 'pipedrive_token' );
			$api_domain   = wpf_get_option( 'pipedrive_api_domain' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		);

		$this->url = $api_domain . '/api/v1';

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token. Remove if not using OAuth.
	 *
	 * @since  3.40.33
	 *
	 * @return string An access token.
	 */
	public function refresh_token() {

		$refresh_token = wpf_get_option( "{$this->slug}_refresh_token" );

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'       => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_safe_remote_post( $this->auth_url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		wp_fusion()->settings->set( "{$this->slug}_token", $body_json->access_token );
		wp_fusion()->settings->set( "{$this->slug}_api_domain", $body_json->api_domain );
		wp_fusion()->settings->set( "{$this->slug}_refresh_token", $body_json->refresh_token );

		return $body_json->access_token;

	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.40.33
	 *
	 * @return array The default fields in the CRM.
	 */
	public static function get_default_fields() {

		return array(
			'first_name'   => array(
				'crm_label' => 'First Name',
				'crm_field' => 'first_name',
			),
			'last_name'    => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'last_name',
			),
			'user_email'   => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
			),
			'phone_number' => array(
				'crm_label' => 'Phone',
				'crm_field' => 'phone',
			),

		);

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.40.33
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {
		if ( $this->url && strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) { // check if the request came from us.

			$body_json     = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 401 === $response_code ) {

				// Handle refreshing an OAuth token. Remove if not using OAuth.

				if ( strpos( $body_json->error, 'invalid' ) !== false || strpos( $body_json->error, 'expired' ) !== false ) {

					$access_token                     = $this->refresh_token();
					$args['headers']['Authorization'] = 'Bearer ' . $access_token;

					$response = wp_safe_remote_request( $url, $args );

				} else {

					$response = new WP_Error( 'error', 'Invalid API credentials.' );

				}
			} elseif ( isset( $body_json->success ) && false === (bool) $body_json->success ) {
				$response = new WP_Error( 'error', $body_json->error );

			} elseif ( 500 === $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

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
	 * @since  3.40.33
	 *
	 * @param  string $access_token The first API credential.
	 * @param  string $api_domain The second API credential.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $access_token = null, $api_domain = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_token, $api_domain );
		}

		$request  = $this->url . '/persons';
		$response = wp_safe_remote_get( $request, $this->params );

		// Validate the connection.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.40.33
	 */
	public function sync() {

		$this->sync_crm_fields();

		$this->sync_tags();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.40.33
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {
		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->url . '/personFields';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$available_tags = array();

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'.
		if ( ! empty( $response->data ) ) {
			foreach ( $response->data as $tag ) {
				if ( $tag->key === wpf_get_option( 'pipedrive_tag' ) ) {

					// Check if the tag field is a select field.
					if ( $tag->field_type !== 'set' ) {
						return new WP_Error( 'error', 'The selected field ' . $tag->name . ' is not a multi select field.' );
					}

					foreach ( $tag->options as $option ) {
						$available_tags[ $option->id ] = $option->label;
					}

					break;
				}
			}
		}

		if ( empty( $available_tags ) ) {
			return false;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.40.33
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first.
		require dirname( __FILE__ ) . '/pipedrive-fields.php';

		foreach ( $pipedrive_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->url . '/personFields';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response      = json_decode( wp_remote_retrieve_body( $response ) );
		$custom_fields = array();

		if ( ! empty( $response->data ) ) {
			foreach ( $response->data as $field ) {

				// Skip built in fields.
				if ( in_array( $field->key, array_keys( $built_in_fields ) ) ) {
					continue;
				}

				// Email and phone have sub-fields so we'll treat them differently.
				if ( 'email' === $field->key || 'phone' === $field->key ) {
					continue;
				}

				$custom_fields[ $field->key ] = $field->name;

				if ( 'tags' === strtolower( $field->name ) && empty( wpf_get_option( 'pipedrive_tag' ) ) ) {
					wp_fusion()->settings->set( 'pipedrive_tag', $field->key );
				}

			}
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
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.40.33
	 *
	 * @param  string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request = $this->url . '/persons/search';
		$request = add_query_arg(
			array(
				'term'   => $email_address,
				'fields' => 'email',
			),
			$request
		);

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$items = $response->data->items;
		if ( empty( $items ) ) {
			return false;
		}

		return (int) $items[0]->item->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.40.33
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$tags_key = wpf_get_option( 'pipedrive_tag' );

		if ( ! $tags_key ) {
			wpf_log( 'notice', 0, __( 'No tags field selected, please create a field for tags. For more information, <a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-pipedrive-to-wordpress/#tags-with-pipedrive">see the documentation</a>', 'wp-fusion-lite' ) );
			return false;
		}

		$request = $this->url . '/persons/' . $contact_id;

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! property_exists( $response->data, $tags_key ) || empty( $response->data->$tags_key ) ) {
			return array();
		}

		$tags = array_map( 'intval', explode( ',', $response->data->$tags_key ) );

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.40.33
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$tags_key = wpf_get_option( 'pipedrive_tag' );

		if ( ! $tags_key ) {
			wpf_log( 'notice', 0, __( 'No tags field selected, please create a field for tags. For more information, <a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-pipedrive-to-wordpress/#tags-with-pipedrive">see the documentation</a>', 'wp-fusion-lite' ) );
			return false;
		}

		$current_tags = $this->get_tags( $contact_id );
		$tags         = array_unique( array_merge( $current_tags, $tags ) );

		$params  = $this->get_params();
		$request = $this->url . '/persons/' . $contact_id;

		$params['body'] = array(
			$tags_key => implode( ',', $tags ),
		);

		$params['method'] = 'PUT';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.40.33
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$tags_key = wpf_get_option( 'pipedrive_tag' );

		if ( ! $tags_key ) {
			wpf_log( 'notice', 0, __( 'No tags field selected, please create a field for tags. For more information, <a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-pipedrive-to-wordpress/#tags-with-pipedrive">see the documentation</a>', 'wp-fusion-lite' ) );
			return false;
		}

		$current_tags = $this->get_tags( $contact_id );

		if ( ! empty( $current_tags ) ) {
			foreach ( $tags as $tag ) {
				if ( false !== $key = array_search( $tag, $current_tags ) ) {
					unset( $current_tags[ $key ] );
				}
			}
		} else {
			return true;
		}

		$params  = $this->get_params();
		$request = $this->url . '/persons/' . $contact_id;

		$params['body'] = array(
			$tags_key => implode( ',', $current_tags ),
		);

		$params['method'] = 'PUT';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Sets default fields and formats.
	 *
	 * @since 3.40.33
	 *
	 * @param array $contact_data The contact data.
	 * @return array $contact_data The contact data.
	 */
	public function format_contact_data( $contact_data ) {

		// Name is required.

		$contact_data['name']  = isset( $contact_data['first_name'] ) ? $contact_data['first_name'] : '';
		$contact_data['name'] .= ' ' . isset( $contact_data['last_name'] ) ? $contact_data['last_name'] : '';

		// Compound fields.
		foreach ( $contact_data as $field => $value ) {

			if ( false !== strpos( $field, '+' ) ) {

				$field_parts = explode( '+', $field );

				$contact_data[ $field_parts[0] ][] = array(
					'label' => $field_parts[1],
					'value' => $value,
				);

				unset( $contact_data[ $field ] );

			}
		}

		return $contact_data;

	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.40.33
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$params  = $this->get_params();
		$request = $this->url . '/persons';

		$params['body'] = $this->format_contact_data( $contact_data );

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->data->id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.40.33
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		$params  = $this->get_params();
		$request = $this->url . '/persons/' . $contact_id;

		$params['body']   = $this->format_contact_data( $contact_data );
		$params['method'] = 'PUT';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.40.33
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$request  = $this->url . '/persons/' . $contact_id;
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		// First implode the compoubnt fields.
		foreach ( $response['data'] as $field => $value ) {

			if ( is_array( $value ) && isset( $value[0]['label'] ) ) {

				foreach ( $value as $data ) {
					$response['data'][ $field . '+' . $data['label'] ] = $data['value'];
				}
			}

		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response['data'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response['data'][ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.40.33
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		return array();
	}

}
