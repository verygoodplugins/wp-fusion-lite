<?php

class WPF_Groundhogg {

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

		$this->slug     = 'groundhogg';
		$this->name     = 'Groundhogg';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Groundhogg_Admin( $this->slug, $this->name, $this );
		}

	}


	/**
	 * Get things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		$this->connect();

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $test = false ) {

		if( $test == false ) {
			return true;
		}

		if( ! function_exists('WPGH') ) {

			return new WP_Error( 'error', 'Groundhogg plugin not active.' );

		}

		return true;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		$data = WPGH()->tags->get_tags();

		foreach ( $data as $row ) {
			$available_tags[ $row->tag_id ] = $row->tag_name;
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

		global $wpdb;

		$data = new WPGH_Contact(0);

		foreach ( $data as $key => $field_data ) {
			$crm_fields[$key] =  ucwords( str_replace( '_', ' ', $key ) );
		}

		$meta_keys = $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM wp_gh_contactmeta ORDER BY meta_key DESC"
        );

		foreach ($meta_keys as $meta_key) {
			$meta_fields[$meta_key] = ucwords( str_replace( '_', ' ', $meta_key ) );
		}

		$final_array = array_merge($crm_fields, $meta_fields);

		asort( $final_array );

		wp_fusion()->settings->set( 'crm_fields', $final_array );

		return $crm_fields;
	}

	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$contact = WPGH()->contacts->get_contact_by( 'email', $email_address );

		return $contact->ID;
		
	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$data = new WPGH_Contact($contact_id);

		foreach ( $data->tags as $row ) {
			$tags[] = $row;
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

		foreach ($tags as $key => $value) {

			$contact = new WPGH_Contact( $contact_id );

			$contact->add_tag( array( $contact_id => $value ) );

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

		foreach ($tags as $key => $value) {

			$contact = new WPGH_Contact( $contact_id );

			$contact->remove_tag( array( $contact_id => $value ) );

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

		$id = WPGH()->contacts->add( $data );

        $contact = new WPGH_Contact( $id );

		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach( $data as $key => $value ) {

			$contact->update_meta( $key, $value );

		}

		return $id;

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

		if( empty( $data ) ) {
			return false;
		}

		$contact = new WPGH_Contact( $contact_id );

		$contact->update( $data );

		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach( $data as $key => $value ) {

			$contact->update_meta( $key, $value );

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

		$contact = new WPGH_Contact( $contact_id );

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact as $field => $value ) {
			
			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] == true && $field == $field_data['crm_field'] ) {
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

		$contacts = WPGH()->tag_relationships->get_contacts_by_tag( $tag );

		$contact_ids = array();

		foreach ( $contacts as $row => $contact_id ) {
			$contact_ids[] = $contact_id;
		}

		return $contact_ids;

	}

}