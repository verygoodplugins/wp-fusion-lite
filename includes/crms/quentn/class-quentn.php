<?php

class WPF_Quentn {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * API url for the account
	 */

	public $api_url;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;


	/**
	 * Lets us link directly to editing a contact record.
	 * @var string
	 */

	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'quentn';
		$this->name     = 'Quentn';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Quentn_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		$api_url = wpf_get_option( 'quentn_url' );

		if ( ! empty( $api_url ) ) {
			$host_url       = parse_url( $api_url, PHP_URL_HOST );
			$api_url        = trailingslashit( $host_url );
			$this->edit_url = 'https://' . $api_url . 'adm/contacts/%d/edit/nojs';
		}

	}

	/**
	 * Slow down batch processses to get around API throttling
	 *
	 * @since 3.35.17
	 *
	 * @param int $seconds The number of seconds to sleep
	 * @return int Sleep time
	 */

	public function set_sleep_time( $seconds ) {

		return 1;

	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'quentn' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body->error ) ) {

				$response = new WP_Error( 'error', $body->message );

			} elseif ( 403 == wp_remote_retrieve_response_code( $response ) ) {

				$response = new WP_Error( 'error', 'Invalid API key.' );

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

	public function get_params( $api_url = null, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_url ) ) {
			$api_url = wpf_get_option( 'quentn_url' );
		}

		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'quentn_key' );
		}

		$this->params = array(
			'timeout'    => 20,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
		);

		$this->api_url = trailingslashit( $api_url );

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_url = null, $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_url, $api_key );
		}

		$request  = $this->api_url . 'terms';
		$response = wp_safe_remote_get( $request, $this->params );

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

		$available_tags = array();

		$request  = $this->api_url . 'terms';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body as $term ) {
			$available_tags[ $term->id ] = $term->name;
		}

		asort( $available_tags );

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

		$built_in_fields = array();

		// Load built in fields
		require dirname( __FILE__ ) . '/admin/quentn-fields.php';

		foreach ( $quentn_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request  = $this->api_url . 'custom-fields';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $body as $field ) {
			$custom_fields[ $field->field_name ] = $field->label;
		}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = $this->api_url . 'contact/' . urlencode( $email_address ) . '?fields=id';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {

			if ( false !== strpos( $response->get_error_message(), 'not found' ) ) {
				return false;
			} else {
				return $response;
			}
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) ) {
			return false;
		}

		return $body[0]->id;
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

		$tags = array();

		$request  = $this->api_url . 'contact/' . $contact_id . '/terms/';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) ) {
			return $tags;
		}

		foreach ( $body as $tag ) {
			$tags[] = $tag->id;
		}

		// Check if we need to update the available tags list
		$available_tags = wpf_get_option( 'available_tags', array() );

		foreach ( $body as $tag ) {
			if ( ! isset( $available_tags[ $tag->id ] ) ) {
				$available_tags[ $tag->id ] = $tag->name;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$params           = $this->params;
		$params['body']   = wp_json_encode( $tags );
		$params['method'] = 'PUT';

		$request  = $this->api_url . 'contact/' . $contact_id . '/terms';
		$response = wp_safe_remote_request( $request, $params );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params           = $this->params;
		$params['body']   = wp_json_encode( $tags );
		$params['method'] = 'DELETE';

		$request  = $this->api_url . 'contact/' . $contact_id . '/terms';
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params         = $this->params;
		$params['body'] = wp_json_encode( array( 'contact' => $data ) );

		$request  = $this->api_url . 'contact';
		$response = wp_safe_remote_post( $request, $params );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$params           = $this->params;
		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'PUT';

		$request  = $this->api_url . 'contact/' . $contact_id;
		$response = wp_safe_remote_request( $request, $params );

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

		// Build up list of fields to load
		$load_fields = array();

		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true == $field_data['active'] ) {

				$load_fields[] = $field_data['crm_field'];

			}
		}

		$request  = $this->api_url . 'contact/' . $contact_id . '?fields=' . implode( ',', $load_fields );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		$user_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true == $field_data['active'] && ! empty( $body->{ $field_data['crm_field'] } ) ) {
				$user_meta[ $field_id ] = $body->{ $field_data['crm_field'] };
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

		// Not possible
		return array();

	}

}
