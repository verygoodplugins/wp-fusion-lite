<?php

class WPF_Platformly {

	/**
	 * Contains API params
	 */

	public $params;


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'platformly';
		$this->name     = 'Platform.ly';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Platformly_Admin( $this->slug, $this->name, $this );
		}

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

		if( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		if( isset( $post_data['contactID'] ) ) {
			$post_data['contact_id'] = $post_data['contactID'];
		}

		return $post_data;

	}

	/**
	 * Formats user entered data to match Mailerlite field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

		} else {

			return $value;

		}

	}

	/**
	 * Platform.ly requires an email to be submitted when updating a contact
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$args = array(
			'meta_key'   => 'platformly_contact_id',
			'meta_value' => $contact_id,
			'fields'     => array( 'user_email' )
		);

		$users = get_users( $args );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			// Try and get it via API call

			$response = $this->request( 'fetch_contact', array( 'contact_id' => $contact_id ) );

			if( is_wp_error( $response ) ) {
				return false;
			}

			if ( ! isset( $response->email ) ) {
				return false;
			}

			return $response->email;

		}

	}

	/**
	 * Perform a Platform.ly request
	 *
	 * @access  public
	 * @return  bool
	 */

	public function request( $action, $value, $api_key = null ) {

		// Get saved data from DB
		if ( empty( $api_key )) {
			$api_key = wp_fusion()->settings->get( 'platformly_key' );
		}

		$params = array(
			'timeout'   => 30,
			'headers'	=> array(
				'Content-type' => 'application/x-www-form-urlencoded'
			),
			'body'		=> array(
				'api_key' 	=> $api_key,
				'action'	=> $action,
				'value'		=> json_encode( $value )
			)
		);

		$response = wp_remote_post( 'https://api.platform.ly/', $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->status ) && $response->status == 'error' ) {
			return new WP_Error( 'error', $response->message );
		}

		return $response;

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

		$response = $this->request( 'list_projects', array(), $api_key );

		if( is_wp_error( $response ) ) {
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

		$this->sync_projects();
		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}

	/**
	 * Syncs available projects
	 *
	 * @access public
	 * @return array Projects
	 */

	public function sync_projects() {

		$projects = array();

		$response = $this->request( 'list_projects', array() );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		foreach( $response as $project ) {
			$projects[ $project->id ] = $project->name;
		}

		wp_fusion()->settings->set( 'available_projects', $projects );

		// Set default
		$default_project = wp_fusion()->settings->get( 'platformly_project', false );

		if( empty( $default_project ) ) {

			reset( $projects );
			$default_project = key( $projects );

			wp_fusion()->settings->set( 'platformly_project', $default_project );

		}

		asort( $projects );

		return $projects;

	}

	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Tags
	 */

	public function sync_tags() {

		$available_tags = array();

		$project = wp_fusion()->settings->get( 'platformly_project', false );

		$values = array(
			'project_id' => $project
		);

		$response = $this->request( 'list_tags', $values );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		foreach( $response as $tag ) {
			$available_tags[ $tag->id ] = $tag->name;
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

		$built_in_fields = array();
		$custom_fields = array();

		// Load built in fields
		require dirname( __FILE__ ) . '/admin/platformly-fields.php';

		foreach( $platformly_fields as $field ) {
			$built_in_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		$project = wp_fusion()->settings->get( 'platformly_project', false );

		$values = array(
			'project_id' => $project
		);

		$response = $this->request( 'list_custom_fields', $values );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		foreach( $response as $field ) {
			$custom_fields[ 'cf_' . $field->alias . '_' . $field->id ] = $field->name;
		}

		asort( $custom_fields );
		asort( $built_in_fields );

		$crm_fields = array( 'Standard Fields' => $built_in_fields, 'Custom Fields' => $custom_fields );

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

		$response = $this->request( 'fetch_contact', array( 'email' => $email_address ) );

		if( is_wp_error( $response ) && $response->get_error_message() == 'Contact Not Found' ) {

			return false;

		} elseif( is_wp_error( $response ) ) {

			return $response;

		}

		if( ! isset( $response->id ) ) {
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

		$tags = array();

		$project = wp_fusion()->settings->get( 'platformly_project', false );

		$values = array(
			'project_id' => $project,
			'contact_id' => $contact_id
		);

		$response = $this->request( 'list_tags', $values );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		if( empty( $response ) ) {
			return $tags;
		}

		$needs_update = false;
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $response as $tag ) {
			$tags[] = $tag->id;

			if( ! isset( $available_tags[ $tag->id ] ) ) {
				$available_tags[ $tag->id ] = $tag->name;
				$needs_update = true;
			}

		}

		if( $needs_update ) {

			asort( $available_tags );
			wp_fusion()->settings->set( 'available_tags', $available_tags );

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

		$values = array(
			'tag' 			=> implode(',', $tags),
			'contact_id' 	=> $contact_id
		);

		$response = $this->request( 'contact_tag_add', $values );

		if( is_wp_error( $response ) ) {
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

		$values = array(
			'tag' 			=> implode(',', $tags),
			'contact_id' 	=> $contact_id
		);

		$response = $this->request( 'contact_tag_remove', $values );

		if( is_wp_error( $response ) ) {
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

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$project = wp_fusion()->settings->get( 'platformly_project', false );

		$data['project_id'] = $project;

		$response = $this->request( 'add_contact', $data );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		if( isset( $response->data ) ) {
			return $response->data->cc_id;
		} else {
			return false;
		}

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$project = wp_fusion()->settings->get( 'platformly_project', false );

		$data['project_id'] = $project;

		if( ! isset( $data['email'] ) ) {
			$data['email'] = $this->get_email_from_cid( $contact_id );
		}

		$response = $this->request( 'update_contact', $data );

		if( is_wp_error( $response ) ) {
			
			if( $response->get_error_message() == 'The action was not processed.' ) {

				// Email address changes
				$email = $this->get_email_from_cid( $contact_id );

				if( $email == false ) {

					$this->add_contact( $data, false );

				} else {

					$data['email'] = $email;
					$this->update_contact( $contact_id, $data, false );

				}

			}

		}

		return true;

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data
	 */

	public function load_contact( $contact_id ) {

		$response = $this->request( 'fetch_contact', array( 'contact_id' => $contact_id ) );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$crm_fields 	= wp_fusion()->settings->get( 'crm_fields' );

		// Convert custom fields from label to key format

		if( ! empty( $crm_fields['Custom Fields'] ) ) {

			foreach( $crm_fields['Custom Fields'] as $key => $label ) {

				if( isset( $response->{$label} ) ) {
					$response->{$key} = $response->{$label};
				}

			}

		}

		foreach ( $response as $key => $data ) {
			
			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $key == $field_data['crm_field'] ) {
					$user_meta[ $field_id ] = $data;
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

		$contact_ids = array();

		// Not currently available

		return $contact_ids;

	}


}