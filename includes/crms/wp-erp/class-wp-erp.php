<?php

class WPF_WP_ERP {


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.33
	 */

	public function __construct() {

		$this->slug     = 'wp-erp';
		$this->name     = 'WP ERP';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_WP_ERP_Admin( $this->slug, $this->name, $this );
		}

	}


	/**
	 * Get things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {

		// Don't watch for changes if staging mode is active
		if ( wp_fusion()->settings->get( 'staging_mode' ) == true ) {
			return;
		}

		add_action( 'added_term_relationship', array( $this, 'tag_added_removed' ), 10, 3 );
		add_action( 'deleted_term_relationships', array( $this, 'tag_added_removed' ), 10, 3 );

	}


	/**
	 * Update WPF tags when tags modified in WP_ERP
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_added_removed( $contact_id, $tag_id, $taxonomy ) {

		if ( 'erp_crm_tag' === $taxonomy ) {

			$user_id = wp_fusion()->user->get_user_id( $contact_id );

			if ( ! empty( $user_id ) ) {

				wp_fusion()->user->get_tags( $user_id, true, false );

			}
		}

	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

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

		if ( false === $test ) {
			return true;
		}

		if ( ! class_exists( 'WeDevs_ERP' ) ) {

			return new WP_Error( 'error', 'WP ERP plugin not active.' );

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

		$available_tags = array();

		$args = array(
			'taxonomy'   => 'erp_crm_tag',
			'hide_empty' => false,
		);

		$terms = get_terms( $args );

		if ( ! empty( $terms ) ) {

			foreach ( $terms as $term ) {
				$available_tags[ $term->term_id ] = $term->name;
			}
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

		require dirname( __FILE__ ) . '/admin/wp-erp-fields.php';

		foreach ( $fields as $field ) {
			$built_in_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		asort( $built_in_fields );

		// Get custom fields if Custom Field Builder is active
		$custom_fields = array();

		$erp_fields = get_option( 'erp-contact-fields' );

		if ( ! empty( $erp_fields ) ) {

			foreach ( $erp_fields as $field ) {

				$custom_fields[ $field['name'] ] = $field['label'];

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
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$contact = erp_get_people_by( 'email', $email_address );

		if ( empty( $contact ) ) {
			return false;
		}

		return $contact->id;

	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$contact_tags = array();

		$tags = wp_get_object_terms( $contact_id, 'erp_crm_tag' );

		foreach ( $tags as $tag ) {
			$contact_tags[] = $tag->term_id;
		}

		return $contact_tags;

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$tags = array_map( 'intval', $tags );

		// Prevent looping
		remove_action( 'added_term_relationship', array( $this, 'tag_added_removed' ), 10, 3 );

		return wp_set_object_terms( $contact_id, $tags, 'erp_crm_tag', true );

	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$tags = array_map( 'intval', $tags );

		// Prevent looping
		remove_action( 'deleted_term_relationships', array( $this, 'tag_added_removed' ), 10, 3 );

		return wp_remove_object_terms( $contact_id, $tags, 'erp_crm_tag' );

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( true === $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$data['type'] = 'contact';

		$result = erp_insert_people( $data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'error', $result->get_error_message() );
		}

		return $result;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( true === $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$data['id']   = $contact_id;
		$data['type'] = 'contact';

		$result = erp_insert_people( $data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'error', $result->get_error_message() );
		}

		return $result;

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		$contact = erp_get_people( $contact_id );

		foreach ( $contact_fields as $key => $data ) {

			if ( true == $data['active'] && ! empty( $data['crm_field'] ) && isset( $contact->{ $data['crm_field'] } ) ) {

				$user_meta[ $key ] = $contact->{ $data['crm_field'] };

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

		global $wpdb;

		$table   = $wpdb->prefix . 'term_relationships';
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT object_id FROM `$table` WHERE `term_taxonomy_id` = %d", $tag ) );

		$contact_ids = array();

		foreach ( $results as $contact ) {
			$contact_ids[] = $contact->object_id;
		}

		return $contact_ids;

	}

}
