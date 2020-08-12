<?php

class WPF_SendinBlue {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */

	public $tag_type = 'List';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'sendinblue';
		$this->name     = 'Sendinblue';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_SendinBlue_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

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

		if ( isset( $payload->email ) ) {

			$post_data['contact_id'] = $payload->email;

		} elseif ( isset( $payload->content ) ) {

			// Global webhooks

			$post_data['contact_id'] = $payload->content[0]->email;

			// Handle email changes

			if ( ! empty( $payload->content[0]->updated_email ) ) {

				$user = get_user_by( 'email', $payload->content[0]->email );

				if ( ! empty( $user ) ) {

					$userdata = array(
						'ID'         => $user->ID,
						'user_email' => $payload->content[0]->updated_email,
					);

					wp_update_user( $userdata );

					update_user_meta( $user->ID, 'sendinblue_contact_id', $payload->content[0]->updated_email );

					$post_data['contact_id'] = $payload->content[0]->updated_email;

				}

			}

		}

		return $post_data;

	}

	/**
	 * Formats user entered data to match SendinBlue field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( $field_type == 'checkbox' && $value == null ) {

			// Sendinblue only treats false as a No for checkboxes
			return false;

		} elseif ( $field_type == 'checkbox' && ! empty( $value ) ) {

			// Sendinblue only treats true as a Yes for checkboxes
			return true;

		} elseif ( $field_type == 'tel' ) {

			// Format phone. Sendinblue requires a country code and + for phone numbers. With or without dashes is fine

			if ( strpos( $value, '+' ) !== 0 ) {

				// Default to US if no country code is provided

				if ( strpos( $value, '1' ) === 0 ) {

					$value = '+' . $value;

				} else {

					$value = '+1' . $value;

				}

			}

			return $value;

		} elseif ( is_numeric( trim( str_replace( array( '-', ' ' ), '', $value ) ) ) ) {

			$length = strlen( trim( str_replace( array( '-', ' ' ), '', $value ) ) );

			// Maybe another phone number

			if ( $length == 10 ) {

				// Let's assume this is a US phone number and needs a +1

				$value = '+1' . $value;

			} elseif ( $length >= 11 && $length <= 13 && strpos( $value, '+' ) === false ) {

				// Let's assume this is a phone number and needs a plus??

				$value = '+' . $value;

			}

			return $value;

		} else {

			return $value;

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'sendinblue' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif ( isset( $body_json->code ) && $body_json->code == 'unauthorized' ) {

				$response = new WP_Error( 'error', 'Invalid API key' );

			} elseif ( isset( $body_json->code ) && $body_json->code == 'invalid_parameter' ) {

				$response = new WP_Error( 'error', $body_json->message );

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

	public function get_params( $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'sendinblue_key' );
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/json',
				'api-key'      => $api_key,
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

	public function connect( $api_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.sendinblue.com/v3/account';
		$response = wp_remote_get( $request, $this->params );

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

		$offset  = 0;
		$limit   = 50;
		$proceed = true;

		while ( $proceed == true ) {

			$request  = 'https://api.sendinblue.com/v3/contacts/lists?limit=' . $limit . '&offset=' . $offset;
			$response = wp_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['lists'] as $row ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}

			if ( count( $body_json['lists'] ) < $limit ) {
				$proceed = false;
			} else {
				$offset = $offset + $limit;
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

		$crm_fields = array( 'email' => 'Email Address' );
		$request    = 'https://api.sendinblue.com/v3/contacts/attributes';
		$response   = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['attributes'] as $field_data ) {
			$crm_fields[ $field_data['name'] ] = ucwords( str_replace( '_', ' ', $field_data['name'] ) );
		}

		asort( $crm_fields );
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

		$contact_info = array();
		$request      = 'https://api.sendinblue.com/v3/contacts/' . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['email'] ) ) {
			return false;
		}

		return $body_json['email'];
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
		$request      = 'https://api.sendinblue.com/v3/contacts/' . urlencode( $contact_id );
		$response     = wp_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) || empty( $body_json['listIds'] ) ) {
			return array();
		}

		return $body_json['listIds'];

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

		foreach ( $tags as $tag ) {

			$request          = 'https://api.sendinblue.com/v3/contacts/lists/' . $tag . '/contacts/add';
			$params           = $this->params;
			$params['method'] = 'POST';
			$params['body']   = json_encode( array( 'emails' => [ $contact_id ] ) );

			$response = wp_remote_post( $request, $params );

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		foreach ( $tags as $tag ) {

			$request          = 'https://api.sendinblue.com/v3/contacts/lists/' . $tag . '/contacts/remove';
			$params           = $this->params;
			$params['method'] = 'POST';
			$params['body']   = json_encode( array( 'emails' => [ $contact_id ] ) );

			$response = wp_remote_post( $request, $params );

		}

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

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return null;
		}

		if ( empty( $data['email'] ) ) {
			return false;
		}

		$post_data = array();

		// Email name is included in the top level of the contact data
		$post_data['email'] = $data['email'];
		unset( $data['email'] );

		if ( ! empty( $data ) ) {
			$post_data['attributes'] = $data;
		}

		$url            = 'https://api.sendinblue.com/v3/contacts';
		$params         = $this->params;
		$params['body'] = json_encode( $post_data );

		$response = wp_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $post_data['email'];

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return false;
		}

		// Email address changes

		if ( isset( $data['email'] ) && $data['email'] != $contact_id ) {

			$data['EMAIL'] = $data['email'];

			$user_id = wp_fusion()->user->get_user_id( $contact_id );

			if ( $user_id ) {

				update_user_meta( $user_id, 'sendinblue_contact_id', $data['email'] );

			}

		}

		unset( $data['email'] );

		$post_data = array( 'attributes' => $data );

		$url                     = 'https://api.sendinblue.com/v3/contacts/' . urlencode( $contact_id );
		$post_data['attributes'] = $data;
		$params                  = $this->params;
		$params['method']        = 'PUT';
		$params['body']          = json_encode( $post_data );

		$response = wp_remote_post( $url, $params );

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

		$url      = 'https://api.sendinblue.com/v3/contacts/' . urlencode( $contact_id );
		$response = wp_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$user_meta['user_email'] = $body_json['email'];

		foreach ( $body_json['attributes'] as $field => $value ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $field == $field_data['crm_field'] ) {

					// Checkboxes

					if ( $value === false ) {
						$value = null;
					}

					$user_meta[ $field_id ] = $value;

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

		$url     = 'https://api.sendinblue.com/v3/contacts/lists/' . $tag . '/contacts?limit=500';
		$results = wp_remote_get( $url, $this->params );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$body_json = json_decode( $results['body'], true );

		foreach ( $body_json['contacts'] as $row => $contact ) {
			$contact_ids[] = $contact['email'];
		}

		return $contact_ids;

	}

}
