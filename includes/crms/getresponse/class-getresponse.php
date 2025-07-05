<?php
/**
 * WP Fusion GetResponse Integration.
 *
 * Contains the GetResponse API integration class.
 *
 * @package WP Fusion
 * @since   3.24.8
 */

/**
 * GetResponse API integration class.
 *
 * @since 3.24.8
 */
class WPF_GetResponse {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 * @since 3.24.8
	 */
	public $slug = 'getresponse';

	/**
	 * The CRM name.
	 *
	 * @var string
	 * @since 3.24.8
	 */
	public $name = 'GetResponse';

	/**
	 * Contains API params.
	 *
	 * @var array
	 * @since 3.24.8
	 */
	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM.
	 *
	 * @var array
	 * @since 3.24.8
	 */
	public $supports = array();

	/**
	 * Lets us link directly to editing a contact record.
	 * The edit page id is not available to get through the API.
	 *
	 * @var string
	 * @since 3.24.8
	 */
	public $edit_url = '';

	/**
	 * Get things started.
	 *
	 * @since  3.24.8
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_GetResponse_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * @since  3.24.8
	 */
	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format.
	 *
	 * @since  3.24.8
	 *
	 * @param  array $post_data The post data.
	 * @return array|bool The formatted post data or false if invalid.
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = absint( $payload->user->id );

		return $post_data;
	}

	/**
	 * Formats user entered data to match Getresponse field formats.
	 *
	 * @since  3.24.8
	 *
	 * @param  mixed  $value      The value to format.
	 * @param  string $field_type The field type.
	 * @param  string $field      The field name.
	 * @return mixed The formatted value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type ) {

			// Adjust formatting for date fields.
			$date = gmdate( 'm/d/Y', $value );

			return $date;

		} else {

			return $value;

		}
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.24.8
	 *
	 * @param  array  $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return array|WP_Error The response or WP_Error if error is found.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( false !== strpos( $url, 'getresponse' ) ) {

			$code = wp_remote_retrieve_response_code( $response );

			if ( 401 === $code ) {

				$response = new WP_Error( 'error', 'Invalid API key' );

			} elseif ( $code > 200 ) {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->message ) ) {

					$message = $body_json->message;

					if ( ! empty( $body_json->context ) ) {
						$message .= '<pre>';
						$message .= print_r( $body_json->context, true );
						$message .= '</pre>';
					}

					$response = new WP_Error( 'error', $message );
				}
			}
		}
		return $response;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.24.8
	 *
	 * @param  string|null $api_key The API key to use.
	 * @return array The API parameters.
	 */
	public function get_params( $api_key = null ) {

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'getresponse_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'X-Auth-Token' => 'api-key ' . $api_key,
				'Content-Type' => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Initialize connection.
	 *
	 * @since  3.24.8
	 *
	 * @param  string|null $api_key The API key to use.
	 * @param  bool        $test    Whether this is a connection test.
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function connect( $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.getresponse.com/v3/accounts';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since  3.24.8
	 *
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();
		$this->sync_crm_fields();
		$this->sync_lists();

		do_action( 'wpf_sync' );

		return true;
	}

	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.24.8
	 *
	 * @return array|WP_Error The available tags or WP_Error if API call failed.
	 */
	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$request  = 'http://api.getresponse.com/v3/tags';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $tag ) {
			$available_tags[ $tag['tagId'] ] = $tag['name'];
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all Campaigns lists.
	 *
	 * @since  3.24.8
	 *
	 * @return array|WP_Error The available lists or WP_Error if API call failed.
	 */
	public function sync_lists() {

		$request  = 'https://api.getresponse.com/v3/campaigns';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		$available_lists = array();

		foreach ( $body_json as $list ) {
			if ( is_object( $list ) ) {
				$available_lists[ $list->{'campaignId'} ] = $list->name;
			}
		}

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		if ( empty( wpf_get_option( 'getresponse_list' ) ) ) {
			// Set the first list as the default.
			wp_fusion()->settings->set( 'getresponse_list', array_keys( $available_lists )[0] );
		}

		return $available_lists;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.24.8
	 *
	 * @return array|WP_Error The CRM fields or WP_Error if API call failed.
	 */
	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields to get field types and subtypes.
		require __DIR__ . '/admin/getresponse-fields.php';

		$built_in_fields = array();

		foreach ( $getresponse_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();
		$request       = 'http://api.getresponse.com/v3/custom-fields/';
		$response      = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json as $field ) {
			$custom_fields[ $field['customFieldId'] ] = $field['name'];
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
	 * @since  3.24.8
	 *
	 * @param  string $email_address The email address to look up.
	 * @return string|bool|WP_Error The contact ID if found, false if not found, or WP_Error if API call failed.
	 */
	public function get_contact_id( $email_address ) {

		$request  = 'https://api.getresponse.com/v3/contacts?query%5Bemail%5D=' . $email_address;
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) ) {
			return false;
		}

		return $body_json[0]['contactId'];
	}

	/**
	 * Gets all tags currently applied to the user.
	 *
	 * @since  3.24.8
	 *
	 * @param  string $contact_id The contact ID.
	 * @return array|bool|WP_Error The tags if found, false if none found, or WP_Error if API call failed.
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$tags     = array();
		$request  = 'http://api.getresponse.com/v3/contacts/' . $contact_id . '?fields=tags';
		$response = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['tags'] ) ) {
			return false;
		}

		foreach ( $body_json['tags'] as $tag ) {
			$tags[] = $tag['tagId'];
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since  3.24.8
	 *
	 * @param  array  $tags       The tags to apply.
	 * @param  string $contact_id The contact ID.
	 * @return bool|WP_Error True if successful, or WP_Error if API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$apply_tags = array( 'tags' => array() );

		foreach ( $tags as $tag ) {
			$apply_tags['tags'][] = array( 'tagId' => $tag );
		}

		$request        = 'https://api.getresponse.com/v3/contacts/' . $contact_id . '/tags';
		$params         = $this->params;
		$params['body'] = wp_json_encode( $apply_tags );

		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * At the moment the only way to do this is to load the current tags, intersect
	 * with the tags to remove, and then patch the contact with the remaining tags.
	 *
	 * @since  3.41.19
	 *
	 * @param  array  $tags       The tags to remove.
	 * @param  string $contact_id The contact ID.
	 * @return bool|WP_Error True if successful, or WP_Error if API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$current_tags = $this->get_tags( $contact_id );

		if ( is_wp_error( $current_tags ) ) {
			return $current_tags;
		}

		if ( ! $current_tags ) {
			return false;
		}

		$data = array(
			'tags' => array_diff( $current_tags, $tags ),
		);

		return $this->update_contact( $contact_id, $data );
	}

	/**
	 * Adds a new contact.
	 *
	 * @since  3.24.8
	 *
	 * @link   https://apireference.getresponse.com/#operation/createContact
	 *
	 * @param  array $data The contact data.
	 * @return string|WP_Error The contact ID if successful, or WP_Error if API call failed.
	 */
	public function add_contact( $data ) {

		// All contacts need to be added to a list.
		$list = wpf_get_option( 'getresponse_list' );

		if ( empty( $list ) ) {
			// get the first list from the available lists.
			$list = array_keys( wpf_get_option( 'available_lists' ) )[0];
		}

		$contact_data = array(
			'dayOfCycle' => 0, // Add to the beginning of the autoresponder cycle.
			'ipAddress'  => wp_fusion()->user->get_ip(),
			'campaign'   => array(
				'campaignId' => $list,
			),
		);

		if ( isset( $data['name'] ) ) {
			$contact_data['name'] = $data['name'];
			unset( $data['name'] );
		}

		if ( isset( $data['email'] ) ) {
			$contact_data['email'] = $data['email'];
			unset( $data['email'] );
		}

		if ( ! empty( $data ) ) {

			$contact_data['customFieldValues'] = array();

			foreach ( $data as $key => $value ) {

				$contact_data['customFieldValues'][] = array(
					'customFieldId' => $key,
					'value'         => array( $value ),
				);
			}
		}

		$url            = 'https://api.getresponse.com/v3/contacts';
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $contact_data );

		$response = wp_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// GetResponse just gives us a 202 message, no contact ID, so we look it up.
		$contact_id = $this->get_contact_id( $contact_data['email'] );

		return $contact_id;
	}

	/**
	 * Update contact.
	 *
	 * @since  3.24.8
	 *
	 * @param  string $contact_id The contact ID.
	 * @param  array  $data       The contact data.
	 * @return bool|WP_Error True if successful, or WP_Error if API call failed.
	 */
	public function update_contact( $contact_id, $data ) {

		if ( empty( $data ) ) {
			return false;
		}

		$contact_data = array();

		$core_fields = array(
			'name',
			'email',
			'tags',
		);

		// Core fields.
		foreach ( $core_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$contact_data[ $field ] = $data[ $field ];
				unset( $data[ $field ] );
			}
		}

		$params = $this->get_params();

		if ( ! empty( $contact_data ) ) {

			// Update core fields.
			$url            = 'https://api.getresponse.com/v3/contacts/' . $contact_id;
			$params['body'] = wp_json_encode( $contact_data );

			$response = wp_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		// Custom fields (upsert so they don't erase previous values).
		if ( ! empty( $data ) ) {

			$contact_data = array(
				'customFieldValues' => array(),
			);

			foreach ( $data as $key => $value ) {

				$contact_data['customFieldValues'][] = array(
					'customFieldId' => $key,
					'value'         => (array) $value,
				);
			}

			$url            = 'https://api.getresponse.com/v3/contacts/' . $contact_id . '/custom-fields';
			$params['body'] = wp_json_encode( $contact_data );

			$response = wp_remote_post( $url, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta.
	 *
	 * @since  3.24.8
	 *
	 * @param  string $contact_id The contact ID.
	 * @return array|WP_Error The user meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$url      = 'https://api.getresponse.com/v3/contacts/' . $contact_id;
		$response = wp_remote_get( $url, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$user_meta = wpf_get_name_from_full_name( $body_json['name'] );

		// Move custom fields to the top of the user meta array.
		foreach ( $body_json['customFieldValues'] as $custom_field ) {
			if ( 'multi_select' === $custom_field['fieldType'] ) {
				$body_json[ $custom_field['customFieldId'] ] = $custom_field['value'];
			} else {
				$body_json[ $custom_field['customFieldId'] ] = $custom_field['value'][0];
			}
		}

		foreach ( $body_json as $key => $field ) {
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( true === $field_data['active'] && $key === $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $field;
				}
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @since  3.24.8
	 *
	 * @param  string $tag The tag ID or null to get all contacts.
	 * @return array|WP_Error The contact IDs or WP_Error if API call failed.
	 */
	public function load_contacts( $tag ) {

		$contact_ids      = array();
		$url              = 'https://api.getresponse.com/v3/search-contacts/contacts';
		$params           = $this->params;
		$params['method'] = 'POST';

		$search_data = array(
			'subscribersType'      => array( 'subscribed', 'unconfirmed' ),
			'sectionLogicOperator' => 'or',
			'section'              => array(
				array(
					'campaignIdsList'  => array_keys( wpf_get_option( 'available_lists' ) ),
					'logicOperator'    => 'and',
					'subscriberCycle'  => array( 'receiving_autoresponder', 'not_receiving_autoresponder' ),
					'conditions'       => array(),
					'subscriptionDate' => 'all_time',
				),
			),
		);

		if ( ! empty( $tag ) ) {
			$search_data['section'][0]['conditions'][] = array(
				'conditionType' => 'tag',
				'operatorType'  => 'exists',
				'operator'      => 'exists',
				'value'         => $tag,
			);
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $search_data );

		$response = wp_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body_json ) ) {
			return array();
		}

		foreach ( $body_json as $contact ) {
			$contact_ids[] = $contact->{'contactId'};
		}

		return $contact_ids;
	}
}
