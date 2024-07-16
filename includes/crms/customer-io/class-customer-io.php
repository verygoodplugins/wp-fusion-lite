<?php

class WPF_Customer_IO {

	/**
	 * CRM name.
	 *
	 * @var string
	 * @since 3.42.2
	 */
	public $name = 'Customer.io';

	/**
	 * CRM slug.
	 *
	 * @var string
	 * @since 3.42.2
	 */
	public $slug = 'customer-io';

	/**
	 * Contains API url
	 *
	 * @var string
	 * @since 3.42.2
	 */

	public $url;

	/**
	 * Contains API params
	 *
	 * @var array
	 * @since 3.43.15
	 */
	public $params = array();

	/**
	 * Contains Tracking API url
	 *
	 * @var string
	 * @since 3.42.2
	 */

	public $tracking_url;

	/**
	 * Customer.io calls them segments instead of tags.
	 *
	 * @var string
	 * @since 3.42.5
	 */

	public $tag_type = 'Segment';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag
	 * IDs). With add_tags enabled, WP Fusion will allow users to type new tag
	 * names into the tag select boxes.
	 *
	 * @var array
	 * @since 3.42.2
	 */

	public $supports = array( 'add_fields', 'events', 'add_tags_api', 'events_multi_key' );

	/**
	 * Lets us link directly to editing a contact record in the CRM.
	 *
	 * Not supported by Customer.io, we can't get the account slug over the API.
	 *
	 * @var string
	 * @since 3.42.2
	 */
	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @since 3.42.2
	 */
	public function __construct() {

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-customer-io-admin.php';
			new WPF_Customer_IO_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.42.2
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ), 100 );

		$region             = wpf_get_option( "{$this->slug}_region" );
		$region             = ( $region === 'eu' ? '-' . $region : '' );
		$this->url          = 'https://api' . $region . '.customer.io/v1/';
		$this->tracking_url = 'https://track' . $region . '.customer.io/api/v1/';
	}

	/**
	 * Format field value.
	 *
	 * Formats outgoing data to match CRM field formats. This will vary
	 * depending on the data formats accepted by the CRM.
	 *
	 * @since  3.42.2
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field identifier.
	 * @return mixed  The field value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		// Customer.io uses timestamps for dates.
		return $value;
	}

	/**
	 * Gets the default fields.
	 *
	 * @since 3.42.8
	 *
	 * @return array<string, array> The default fields in the CRM.
	 */
	public static function get_default_fields() {

		return array(
			'user_email' => array(
				'crm_label' => 'Email Address',
				'crm_field' => 'email',
				'crm_type'  => 'email',
			),
			'user_registered' => array(
				'crm_label' => 'Created At',
				'crm_field' => 'created_at',
				'crm_type'  => 'date',
			),
		);

	}

	/**
	 * Formats POST data received from webhooks into standard format.
	 *
	 * we'll need to update this to create a background process in case there are
	 * multiple subscribers in the payload.
	 *
	 * @since  3.42.2
	 *
	 * @param array $post_data The data read out of the webhook URL.
	 * @return array $post_data The formatted data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( $payload ) {
			$post_data['contact_id'] = $payload->email;
		}

		return $post_data;
	}

	/**
	 * Gets params for API calls.
	 *
	 * @since  3.42.2
	 *
	 * @return array  $params The API parameters.
	 */
	public function get_params( $api_key = null ) {
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( "{$this->slug}_api_key" );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
		);

		return $this->params;
	}


	/**
	 * Gets tracking params for API calls.
	 *
	 * @since  3.42.2
	 *
	 * @return array  $params The API parameters.
	 */
	public function get_tracking_params( $site_id = null, $tracking_api_key = null ) {
		if ( empty( $site_id ) || empty( $tracking_api_key ) ) {
			$site_id          = wpf_get_option( "{$this->slug}_site_id" );
			$tracking_api_key = wpf_get_option( "{$this->slug}_tracking_api_key" );
		}

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $site_id . ':' . $tracking_api_key ),
				'Content-Type'  => 'application/json',
			),
		);

		return $params;
	}


	/**
	 * Output tracking code.
	 *
	 * @return mixed JavaScript tracking code.
	 *
	 * @since 3.42.2
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		$region  = wpf_get_option( "{$this->slug}_region" );
		$region  = ( $region === 'eu' ? '-' . $region : '' );
		$email   = wpf_get_current_user_email();
		$site_id = wpf_get_option( "{$this->slug}_site_id" );

		echo '<!-- Customer.io (via WP Fusion) -->';
		echo "
		<script>
			var _cio = _cio || [];
			(function() {
				var a,b,c;a=function(f){return function(){_cio.push([f].
				concat(Array.prototype.slice.call(arguments,0)))}};b=['load','identify',
				'sidentify','track','page','on','off'];for(c=0;c<b.length;c++){_cio[b[c]]=a(b[c])};
				var t = document.createElement('script'),
					s = document.getElementsByTagName('script')[0];
				t.async = true;
				t.id    = 'cio-tracker';
				t.setAttribute('data-site-id', '" . esc_js( $site_id ) . "');
				t.src = 'https://assets.customer.io/assets/track" . esc_js( $region ) . ".js'
				s.parentNode.insertBefore(t, s);
			})();
		</script>";

		if ( $email ) {
			echo "
			<script>
				_cio.identify({
					id: '" . esc_js( $email ) . "',
				});
			  </script>
			";
		}

		echo '<!-- end Customer.io -->';
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.42.2
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

			if ( 404 === $response_code ) {
				$response = new WP_Error( 'not_found', $body_json->error->message );

			} elseif ( isset( $body_json->meta ) && isset( $body_json->meta->error ) ) {
				$response = new WP_Error( 'error', $body_json->meta->error );

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
	 * @since  3.42.2
	 *
	 * @param  string $region The Region.
	 * @param  string $api_key The API Key.
	 * @param  bool   $test    Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $region = null, $api_key = null, $test = false ) {

		if ( false === $test ) {
			return true;
		}

		$region  = ( $region === 'eu' ? '-' . $region : '' );
		$request = 'https://api' . $region . '.customer.io/v1/segments';

		$response = wp_safe_remote_get( $request, $this->get_params( $api_key ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since 3.42.2
	 */
	public function sync() {

		$this->sync_tags();

		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since  3.42.2
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$request  = $this->url . 'segments';
		$response = wp_safe_remote_get( $request, $this->get_params() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->segments ) ) {
			return array();
		}

		$available_tags = array();

		foreach ( $response->segments as $segment ) {

			if ( 'manual' === $segment->type ) {
				$category = 'Manual Segments';
			} else {
				$category = 'Data Driven Segments (Read Only)';
			}

			$available_tags[ $segment->id ] = array(
				'label'    => $segment->name,
				'category' => $category,
			);
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list.
	 *
	 * @since  3.42.2
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		$crm_fields = array();

		foreach ( $this::get_default_fields() as $field ) {
			$crm_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}



	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since  3.42.2
	 *
	 * @param  string $email_address The email address to look up.
	 * @return string|false|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$request = $this->url . 'customers/?email=' . $email_address;

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->results ) ) {
			return false;
		}

		return $response->results[0]->email;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.42.2
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$request = $this->url . "customers/{$contact_id}/segments/?id_type=email";

		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return wp_list_pluck( $response->segments, 'id' );
	}

	/**
	 * Creates a new tag in Customer.io and returns the ID.
	 *
	 * @since  3.42.2
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {

		$params         = $this->get_params();
		$request        = $this->url . 'segments';
		$params['body'] = wp_json_encode(
			array(
				'segment' => array(
					'name' => $tag_name,
				),
			)
		);
		$response       = wp_safe_remote_post( $request, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->segment->id;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.42.2
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_tracking_params();

		$params['body'] = wp_json_encode(
			array(
				'ids' => array( $contact_id ),
			)
		);

		foreach ( $tags as $tag ) {
			$request  = $this->tracking_url . "segments/{$tag}/add_customers/?id_type=email";
			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since  3.42.2
	 *
	 * @param  array $tags       A numeric array of tags to remove from the contact.
	 * @param  int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params = $this->get_tracking_params();

		$params['body'] = wp_json_encode(
			array(
				'ids' => array( $contact_id ),
			)
		);

		foreach ( $tags as $tag ) {
			$request  = $this->tracking_url . "segments/{$tag}/remove_customers/?id_type=email";
			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Adds a new contact.
	 *
	 * @since 3.42.2
	 *
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$params           = $this->get_tracking_params();
		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PUT';
		$request          = $this->tracking_url . 'customers/' . $contact_data['email'];

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// This just returns a 200 so we'll assume it was created.

		return $contact_data['email'];
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.42.2
	 *
	 * @param int   $contact_id   The ID of the contact to update.
	 * @param array $contact_data An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		// "If you perform multiple requests in rapid succession when you create a person,
		// there's a danger that you could create multiple profiles. If you know that a
		// profile already exists and you want to update it, set _update:true, and
		// Customer.io will not create a new profile, even if the identifier in the path
		// isn't found." - https://customer.io/docs/api/track/#operation/identify.

		$contact_data['_update'] = true;

		$params           = $this->get_tracking_params();
		$params['body']   = wp_json_encode( $contact_data );
		$params['method'] = 'PUT';
		$request          = $this->tracking_url . 'customers/' . $contact_id;

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields
	 *
	 * @since 3.42.2
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		$params = $this->get_params();

		$request = $this->url . "customers/{$contact_id}/attributes/?id_type=email";

		$response = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$response       = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $response['customer']['attributes'] as $key => $value ) {
			$response[ $key ] = $value;
			unset( $response['customer']['attributes'] );
		}

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $response[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 3.42.2
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {
		$contact_ids = array();
		$params      = $this->get_params();
		$request     = $this->url . "segments/{$tag}/membership/?limit=100";
		$response    = wp_safe_remote_get( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $response->identifiers ) ) {

			foreach ( $response->identifiers as $contact ) {
				$contact_ids[] = $contact->email;
			}
		}

		return $contact_ids;
	}


	/**
	 * Track event.
	 *
	 * Track an event with the site tracking API.
	 *
	 * @since  3.42.2
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		if ( empty( $email_address ) && ! wpf_is_user_logged_in() ) {
			// Tracking only works if WP Fusion knows who the contact is.
			return;
		}

		// Get the email address to track.
		if ( empty( $email_address ) ) {
			$user          = wpf_get_current_user();
			$email_address = $user->user_email;
		}

		$data = array(
			'name' => $event,
			'data' => array(
				$event_data,
			),
		);

		$params         = $this->get_tracking_params();
		$params['body'] = wp_json_encode( $data );
		$request        = $this->tracking_url . "customers/{$email_address}/events";
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
}
