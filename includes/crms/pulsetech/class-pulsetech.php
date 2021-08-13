<?php

/**
 * Pulsetech CRM integtation.
 *
 * Thanks to @devguar.
 *
 * @link https://github.com/verygoodplugins/wp-fusion-lite/pull/16
 *
 * @package WP Fusion
 * @since 3.37.21
 */

class WPF_PulseTechnologyCRM {

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.37.21
	 */
	public $url                 = null;
	public $client_secret       = null;
	public $client_id           = null;
	public $token               = null;
	public $url_base            = null;
	public $oauth_url_authorize = null;
	public $oauth_url_token     = null;

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag IDs).
	 * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
	 *
	 * "add_fields" means that pulsetech field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var array
	 * @since 3.37.21
	 */
	public $supports = array();

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.37.21
	 */
	public $params = array();


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
	 * @since 3.37.21
	 */
	public function __construct() {
		$this->slug = 'pulsetech';
		$this->name = 'PulseTechnologyCRM';

		$api_url = wpf_get_option( 'pulsetech_url', '' );

		if ( strpos( $api_url, '.dev.thepulsespot.com' ) !== false ) {
			$portal_url = str_replace( '/app.', '/portal.', $api_url );
		} else {
			$portal_url = 'http://portal.thepulsespot.com/';
		}

		$this->url_base            = $portal_url . 'api/v1/';
		$this->oauth_url_authorize = $portal_url . 'oauth/authorize';
		$this->oauth_url_token     = $portal_url . 'oauth/token';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/class-pulsetech-admin.php';
			new WPF_PulseTechnologyCRM_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.37.21
	 */

	public function init() {
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		$api_url = wpf_get_option( 'pulsetech_url' );

		if ( ! empty( $api_url ) ) {
			$this->edit_url = trailingslashit( $api_url ) . 'crm/contact/%d/edit';
		}
	}

	/**
	 * Format the field value based on the type
	 *
	 * @param $value
	 * @param $field_type
	 * @param $field
	 *
	 * @return false|mixed|string[]
	 * @since 3.37.21
	 *
	 */
	public function format_field_value( $value, $field_type, $field ) {
		if ( 'multiselect' == $field_type ) {
			if ( ! is_array( $value ) ) {
				$value = explode( ',', $value );

				return $value;
			}
		}

		return $value;
	}


	/**
	 * Formats POST data received from Webhooks into standard format.
	 *
	 * @since  3.37.21
	 *
	 * @param  array $post_data The post data.
	 * @return array The post data.
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		if ( isset( $post_data['id'] ) ) {
			$post_data['contact_id'] = $post_data['id'];

			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( is_object( $payload ) ) {
			$post_data['contact_id'] = absint( $payload->id );
		}

		return $post_data;
	}


	/**
	 * Gets params for API calls.
	 *
	 * @return array $params The API parameters.
	 * @since 3.37.21
	 *
	 */
	public function get_params( $api_url = null, $client_id = null, $client_secret = null ) {
		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB
		if ( empty( $api_url ) || empty( $client_id ) || empty( $client_secret ) ) {
			$api_url       = wpf_get_option( 'pulsetech_url' );
			$client_secret = wpf_get_option( 'pulsetech_secret' );
			$client_id     = wpf_get_option( 'pulsetech_client_id' );
			$token         = wpf_get_option( 'pulsetech_token', null );
		} else {
			$token = null;
		}

		$this->url           = trailingslashit( $api_url );
		$this->client_secret = $client_secret;
		$this->client_id     = $client_id;
		$this->token         = $token;

		$this->params = [
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
		];

		if ( $token ) {
			$this->params['headers'] = [
				'Authorization' => 'Bearer ' . $this->token,
			];
		}

		return $this->params;
	}

	/**
	 * Refresh an access token from a refresh token
	 *
	 * @access  public
	 * @return  bool
	 * @since 3.37.21
	 *
	 */
	public function refresh_token() {
		$refresh_token = wpf_get_option( 'pulsetech_refresh_token' );

		$params = array(
			'headers' => array(
				'Content-type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_safe_remote_post( $this->oauth_url_token, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body_json->error ) ) {
			return new WP_Error( 'error', $body_json->error_description );
		}

		wp_fusion()->settings->set( 'pulsetech_token', $body_json->access_token );
		wp_fusion()->settings->set( 'pulsetech_refresh_token', $body_json->refresh_token );

		return $body_json->access_token;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @since  3.37.21
	 *
	 * @param  object $response The HTTP response.
	 * @param  array  $args     The HTTP request arguments.
	 * @param  string $url      The HTTP request URL.
	 * @return object $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( ! empty( $this->url ) && strpos( $url, strval( $this->url ) ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 500 == $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

			} elseif ( $response_code > 200 ) {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->error ) && 'Contact not found' != $body_json->error ) {

					$response = new WP_Error( 'error', $body_json->error );

				}
			}
		}

		return $response;
	}

	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 * @since 3.37.21
	 *
	 */
	public function connect( $access_token = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token );
		}

		if ( false === $test ) {
			return true;
		}

		$response = $this->pulse_api_get( 'tags' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @return bool
	 * @since 3.37.21
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
	 * Execute a GET request into the Pulse API
	 *
	 * @param $uri
	 *
	 * @return mixed
	 * @since 3.37.21
	 *
	 */
	private function pulse_api_get( $uri ) {
		$params  = $this->get_params();
		$request = $this->url . 'api/v1/' . $uri;

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {

			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Execute a POST request into the Pulse API
	 *
	 * @param $uri
	 * @param $data
	 *
	 * @return mixed
	 * @since 3.37.21
	 *
	 */
	private function pulse_api_post( $uri, $data ) {
		$params         = $this->get_params();
		$request        = $this->url . 'api/v1/' . $uri;
		$params['body'] = $data;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );

	}

	/**
	 * Execute a PUT request into the Pulse API
	 *
	 * @param $uri
	 * @param $data
	 *
	 * @return mixed
	 * @since 3.37.21
	 *
	 */
	private function pulse_api_put( $uri, $data ) {
		$params           = $this->get_params();
		$request          = $this->url . 'api/v1/' . $uri;
		$params['body']   = $data;
		$params['method'] = 'PUT';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {

			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 * @since 3.37.21
	 *
	 */
	public function sync_tags() {

		$page      = 1;
		$last_page = 1;
		$tags      = [];

		while ( $page <= $last_page ) {
			$uri = 'tags';

			if ( $page > 1 ) {
				$uri .= '?page=' . $page;
			}

			$response = $this->pulse_api_get( $uri );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$page ++;
			$last_page = $response->meta->last_page;

			if ( isset( $response->data ) && is_array( $response->data ) ) {
				foreach ( $response->data as $tag ) {
					$tags[ $tag->id ] = $tag->name;
				}
			}
		}

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'
		wp_fusion()->settings->set( 'available_tags', $tags );

		return $tags;
	}

	/**
	 * Loads all pulsetech fields from CRM and merges with local list.
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 * @since 3.37.21
	 *
	 */
	public function sync_crm_fields() {

		$response = $this->pulse_api_get( 'contact/available-fields' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$fields     = $response->data;
		$crm_fields = [];

		$hide_fields = [
			'lead_source_id',
			//We can add more fields here to avoid showing them on WPFusion since the api is generic
		];

		foreach ( $fields as $key => $field ) {
			if ( ! in_array( $key, $hide_fields ) ) {
				if ( isset( $field->label ) ) {
					$label = $field->label;
				} else {
					$label = ucwords( str_replace( '_', ' ', $key ) );
				}

				if ( $this->str_contains( $key, 'address_' ) && $this->str_contains( $key, '.country' ) ) {
					$key .= '_name';
				}

				$crm_fields[ $key ] = $label;
			}
		}

		// Load available fields into $crm_fields like 'field_key' => 'Field Label'dd
		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}

	/**
	 * Check if a string contains a substring
	 *
	 * @param $haystack
	 * @param $needles
	 *
	 * @return bool
	 * @since 3.37.21
	 *
	 */
	private function str_contains( $haystack, $needles ) {
		foreach ( (array) $needles as $needle ) {
			if ( $needle !== '' && mb_strpos( $haystack, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @param string $email_address The email address to look up.
	 *
	 * @return int|WP_Error The contact ID in the CRM.
	 * @since 3.37.21
	 *
	 */
	public function get_contact_id( $email_address ) {

		$response = $this->pulse_api_post( 'contact/find/', [ 'email' => $email_address ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->error ) && 'Contact not found' == $response->error ) {
			return false;
		}

		return $response->id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 *
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 * @since 3.37.21
	 *
	 */
	public function get_tags( $contact_id ) {

		$page      = 1;
		$last_page = 1;
		$tags      = [];

		while ( $page <= $last_page ) {
			$uri = "contacts/$contact_id/tags";

			if ( $page > 1 ) {
				$uri .= '?page=' . $page;
			}

			$response = $this->pulse_api_get( $uri );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$page ++;
			$last_page = $response->meta->last_page;

			if ( isset( $response->data ) && is_array( $response->data ) ) {
				foreach ( $response->data as $tag ) {
					$tags[] = $tag->id;
				}
			}
		}

		// Parse response to create an array of tag ids. $tags = array(123, 678, 543); (should not be an associative array)
		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @param array $tags A numeric array of tags to apply to the contact.
	 * @param int $contact_id The contact ID to apply the tags to.
	 *
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 * @since 3.37.21
	 *
	 */
	public function apply_tags( $tags, $contact_id ) {

		$response = $this->pulse_api_post( "contacts/$contact_id/tag-apply", [ 'tag_id' => $tags ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @param array $tags A numeric array of tags to remove from the contact.
	 * @param int $contact_id The contact ID to remove the tags from.
	 *
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 * @since 3.37.21
	 *
	 */
	public function remove_tags( $tags, $contact_id ) {

		$response = $this->pulse_api_post( "contacts/$contact_id/untag", [ 'tag_id' => $tags ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Adds a new contact.
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @param bool $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 *
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 * @since 3.37.21
	 *
	 */
	public function add_contact( $contact_data, $map_meta_fields = true ) {

		if ( true == $map_meta_fields ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		$contact_data['is_marketable'] = true;

		$response = $this->pulse_api_post( 'contacts', $contact_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Get new contact ID out of response
		return $response->id;

	}

	/**
	 * Updates an existing contact record.
	 *
	 * @param int $contact_id The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @param bool $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
	 *
	 * @return bool|WP_Error Error if the API call failed.
	 * @since 3.37.21
	 *
	 */
	public function update_contact( $contact_id, $contact_data, $map_meta_fields = true ) {

		if ( true == $map_meta_fields ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		$response = $this->pulse_api_put( "contacts/$contact_id", $contact_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @param int $contact_id The ID of the contact to load.
	 *
	 * @return array|WP_Error User meta data that was returned.
	 * @since 3.37.21
	 *
	 */
	public function load_contact( $contact_id ) {

		$response = $this->pulse_api_get( "contacts/$contact_id" );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$fields = (array) $response;

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {
			if ( $field_data['active'] && isset( $fields[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $fields[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @param string $tag The tag ID or name to search for.
	 *
	 * @return array Contact IDs returned.
	 * @since 3.37.21
	 *
	 */
	public function load_contacts( $tag ) {

		if ( is_integer( $tag ) ) {
			$url_base = 'contacts/tagged/?tag_id=' . urlencode( $tag );
		} else {
			$url_base = 'contacts/tagged/?tag_name=' . urlencode( $tag );
		}

		$page        = 1;
		$last_page   = 1;
		$contact_ids = [];

		while ( $page <= $last_page ) {
			$uri = $url_base;

			if ( $page > 1 ) {
				$uri .= '?page=' . $page;
			}

			$response = $this->pulse_api_get( $uri );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$page ++;
			$last_page = $response->meta->last_page;

			if ( isset( $response->data ) && is_array( $response->data ) ) {
				foreach ( $response->data as $contact ) {
					$contact_ids[] = $contact->id;
				}
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $contact_ids;
	}
}
