<?php

class WPF_KlickTipp {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'klick-tipp';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Klick-Tipp';

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array();

	/**
	 * Lets us link directly to editing a contact record.
	 * No trials only paid plans.
	 *
	 * @var string
	 */

	public $edit_url = false;

	/**
	 * The base URL for API requests
	 *
	 * @var string
	 */
	private $base_url = 'https://api.klicktipp.com';

	/**
	 * The session cookie name
	 *
	 * @since 3.44.26
	 *
	 * @var string
	 */
	private $session_name;

	/**
	 * The session ID
	 *
	 * @since 3.44.26
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * The parameters for the request
	 *
	 * @since 3.44.26
	 *
	 * @var array
	 */
	public $params;

	/**
	 * Get things started
	 *
	 * @since 3.29.0
	 */
	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_KlickTipp_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * @since 3.29.0
	 */
	public function init() {
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Gets the contact ID out of outbound messages / webhooks.
	 *
	 * @since 3.44.2
	 *
	 * @param array $post_data The post data.
	 * @return array $post_data The post data.
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['SubscriberID'] ) ) {
			$post_data['contact_id'] = absint( $post_data['SubscriberID'] );
		}

		return $post_data;
	}

	/**
	 * Get the HTTP request arguments with session cookie if authenticated
	 *
	 * @since  3.44.26
	 *
	 * @param  array $data   The data to send.
	 * @return array  The arguments.
	 */
	private function get_params( $data = array() ) {

		$params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 30,
			'headers'    => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
		);

		if ( ! empty( $this->session_name ) && ! empty( $this->session_id ) ) {
			$params['headers']['Cookie'] = $this->session_name . '=' . $this->session_id;
		}

		if ( ! empty( $data ) ) {
			$params['body'] = $data;
		}

		$this->params = $params;

		return $params;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.44.26
	 *
	 * @param  mixed  $response The response.
	 * @param  array  $args     The arguments.
	 * @param  string $url      The URL.
	 * @return mixed|WP_Error Response or error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'api.klicktipp.com' ) !== false ) {

			$code = wp_remote_retrieve_response_code( $response );

			if ( ( 200 !== $code && 404 !== $code ) || ( 404 === $code && strpos( $url, '/search' ) !== false ) ) {

				if ( '["API Zugriff verweigert."]' === wp_remote_retrieve_body( $response ) ) {
					return new WP_Error( 'error', __( 'Access denied. This user account does not have access to the KlickTipp API. See the installation guide (click View Documentation above) for more information.', 'wp-fusion-lite' ) );
				}

				$message = wp_remote_retrieve_response_message( $response );

				$response = new WP_Error( 'error', $message );

			}
		}

		return $response;
	}

	/**
	 * Initialize connection
	 *
	 * @since  3.44.26
	 *
	 * @param  string $username The username.
	 * @param  string $password The password.
	 * @param  bool   $test     Whether to test the connection.
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function connect( $username = null, $password = null, $test = false ) {

		if ( ! empty( $this->session_id ) && false === $test ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $username ) || empty( $password ) ) {
			$username = wpf_get_option( 'klicktipp_user' );
			$password = wpf_get_option( 'klicktipp_pass' );
		}

		$params = $this->get_params(
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		$response = wp_remote_post( $this->base_url . '/account/login', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$this->session_name = $body->session_name;
		$this->session_id   = $body->sessid;

		if ( true === $test ) {

			// Check if the account has the API enabled.

			$response = wp_remote_get( $this->base_url . '/tag', $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
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
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$this->sync_tags();
		$this->sync_crm_fields();
		$this->sync_double_opt_in_processes();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @since  3.44.26
	 *
	 * @return array|WP_Error Tags
	 */
	public function sync_tags() {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$response = wp_remote_get( $this->base_url . '/tag', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body           = json_decode( wp_remote_retrieve_body( $response ) );
		$available_tags = array();

		if ( ! empty( $body ) ) {
			$available_tags = (array) $body;
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @since  3.44.26
	 *
	 * @return array|WP_Error CRM Fields
	 */
	public function sync_crm_fields() {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$response = wp_remote_get( $this->base_url . '/field', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body       = json_decode( wp_remote_retrieve_body( $response ) );
		$crm_fields = array( 'email' => 'Email' );

		if ( ! empty( $body ) ) {
			$custom_fields = (array) $body;
			$crm_fields    = array_merge( $crm_fields, $custom_fields );
		}

		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Sync Double Opt in Processes.
	 *
	 * @since 3.40.41
	 *
	 * @return array|WP_Error The double opt-in processes or error.
	 */
	public function sync_double_opt_in_processes() {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$response = wp_remote_get( $this->base_url . '/list', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ) );
		$processes = array();

		if ( ! empty( $body ) ) {
			foreach ( $body as $key => $value ) {
				$processes[ $key ] = ( $value ? $value : 'Default' );
			}
		}

		wp_fusion()->settings->set( 'double_optin_processes', $processes );

		return $processes;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @since  3.44.26
	 *
	 * @param  string $email_address The email address.
	 * @return int|bool|WP_Error Contact ID
	 */
	public function get_contact_id( $email_address ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$params = $this->get_params(
			array(
				'email' => $email_address,
			)
		);

		$response = wp_remote_post( $this->base_url . '/subscriber/search', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body ) ) {
			return $body[0];
		}

		return false;
	}

	/**
	 * Gets all tags currently applied to the user
	 *
	 * @since  3.44.26
	 *
	 * @param  int $contact_id The contact ID.
	 * @return array|WP_Error The tags or error.
	 */
	public function get_tags( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$response = wp_remote_get( $this->base_url . '/subscriber/' . $contact_id, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		$tags = array();

		if ( ! empty( $body->manual_tags ) ) {
			$tags = array_keys( (array) $body->manual_tags );
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @since  3.44.26
	 *
	 * @param  array $tags       The tags to apply.
	 * @param  int   $contact_id The contact ID.
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$email = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( false === $email ) {
			return new WP_Error( 'error', sprintf( __( 'Unable to find email address for contact ID %d. Can\'t apply tags.', 'wp-fusion-lite' ), $contact_id ) );
		}

		$params = $this->get_params(
			array(
				'email'  => $email,
				'tagids' => $tags,
			)
		);

		$response = wp_remote_post( $this->base_url . '/subscriber/tag', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @since  3.44.26
	 *
	 * @param  array $tags       The tags to remove.
	 * @param  int   $contact_id The contact ID.
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$email = wp_fusion()->crm->get_email_from_cid( $contact_id );

		if ( false === $email ) {
			return new WP_Error( 'error', sprintf( __( 'Unable to find email address for contact ID %d. Can\'t remove tags.', 'wp-fusion-lite' ), $contact_id ) );
		}

		foreach ( $tags as $tag ) {
			$params = $this->get_params(
				array(
					'email' => $email,
					'tagid' => $tag,
				)
			);

			$response = wp_remote_post( $this->base_url . '/subscriber/untag', $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}


	/**
	 * Adds a new contact
	 *
	 * @since  3.44.26
	 *
	 * @param  array $data The contact data.
	 * @return int|WP_Error Contact ID or error.
	 */
	public function add_contact( $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$params = array(
			'email' => $data['email'],
		);

		unset( $data['email'] );

		// Add double opt-in process if configured
		if ( wpf_get_option( 'kt_double_optin_id' ) ) {
			$params['listid'] = absint( wpf_get_option( 'kt_double_optin_id' ) );
		}

		// Add any additional fields
		if ( ! empty( $data ) ) {
			$params['fields'] = $data;
		}

		$api_params = $this->get_params( $params );

		$response = wp_remote_post( $this->base_url . '/subscriber', $api_params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->id ) ) {
			return $body->id;
		}

		return new WP_Error( 'error', __( 'Unable to add contact.', 'wp-fusion-lite' ) );
	}


	/**
	 * Updates an existing contact
	 *
	 * @since  3.44.26
	 *
	 * @param  int   $contact_id The contact ID to update.
	 * @param  array $data       The update data.
	 * @return bool|WP_Error True if successful, WP_Error if failed.
	 */
	public function update_contact( $contact_id, $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$params = array();

		// Handle email updates
		if ( isset( $data['email'] ) ) {
			$params['newemail'] = $data['email'];
			unset( $data['email'] );
		}

		// Add remaining fields
		if ( ! empty( $data ) ) {
			$params['fields'] = $data;
		}

		$api_params           = $this->get_params( $params );
		$api_params['method'] = 'PUT';

		$response = wp_remote_request( $this->base_url . '/subscriber/' . $contact_id, $api_params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @since  3.44.26
	 *
	 * @param  int $contact_id The contact ID.
	 * @return array|WP_Error User meta data or error.
	 */
	public function load_contact( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$response = wp_remote_get( $this->base_url . '/subscriber/' . $contact_id, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) ) {
			// translators: contact ID.
			return new WP_Error( 'error', sprintf( __( 'Unable to find contact ID %d.', 'wp-fusion-lite' ), $contact_id ) );
		}

		$user_meta = array();

		// Map contact fields
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $body as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset( $field_data['crm_field'] ) && $field_data['crm_field'] === $field_name && true === $field_data['active'] ) {
					$user_meta[ $meta_key ] = $value;
				}
			}
		}

		return $user_meta;
	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since  3.44.26
	 *
	 * @param  int $tag The tag ID.
	 * @return array|WP_Error Contact IDs or error.
	 */
	public function load_contacts( $tag ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', __( 'Unable to connect to KlickTipp.', 'wp-fusion-lite' ) );
		}

		$params = $this->get_params(
			array(
				'tagid' => absint( $tag ),
			)
		);

		$response = wp_remote_post( $this->base_url . '/subscriber/tagged', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ) );
		$contact_ids = array();

		if ( ! empty( $body ) ) {
			$contact_ids = array_keys( (array) $body );
		}

		return $contact_ids;
	}
}
