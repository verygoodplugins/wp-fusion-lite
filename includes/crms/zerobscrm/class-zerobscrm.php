<?php

class WPF_ZeroBSCRM {


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

		$this->slug     = 'zerobscrm';
		$this->name     = 'Zero BS CRM';
		$this->supports = array( 'add_tags' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_ZeroBSCRM_Admin( $this->slug, $this->name, $this );
		}

	}


	/**
	 * Get things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {

		// Don't watch ZBS for changes if staging mode is active
		if ( wp_fusion()->settings->get( 'staging_mode' ) == true ) {
			return;
		}

		add_action( 'zbs_tag_added_to_objid', array( $this, 'tag_added_removed' ), 10, 3 );
		add_action( 'zbs_tag_removed_from_objid', array( $this, 'tag_added_removed' ), 10, 3 );

	}


	/**
	 * Update WPF tags when tags modified in ZeroBSCRM
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_added_removed( $tag_id, $object_type, $object_id ) {

		if ( ZBS_TYPE_CONTACT === $object_type ) {

			$user_id = wp_fusion()->user->get_user_id( $object_id );

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

		if ( ! class_exists( 'ZeroBSCRM' ) ) {

			return new WP_Error( 'error', 'Zero BS CRM plugin not active.' );

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

		$tags = zeroBSCRM_getContactTagsArr( $hide_empty = false );

		foreach ( $tags as $tag ) {
			$available_tags[ $tag['name'] ] = $tag['name'];
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

		$crm_fields = array();

		require dirname( __FILE__ ) . '/admin/zerobscrm-fields.php';

		foreach ( $zerobscrm_fields as $field ) {
			$crm_fields[ $field['crm_field'] ] = $field['crm_label'];
		}

		global $zbs;

		$custom_fields = $zbs->DAL->getActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT ) );

		foreach ( $custom_fields as $key => $field ) {

			$crm_fields[ $key ] = $field[1];

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

		global $zbs;

		$contact = $zbs->DAL->contacts->getContact( -1, array( 'email' => $email_address ) );

		if ( empty( $contact ) ) {
			return false;
		}

		return $contact['id'];

	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$contact_tags = array();

		global $zbs;

		$tags = $zbs->DAL->contacts->getContactTags( $contact_id );

		if ( empty( $tags ) ) {
			return $contact_tags;
		}

		foreach ( $tags as $tag ) {
			$contact_tags[] = $tag['name'];
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

		// Prevent looping
		remove_action( 'zbs_tag_added_to_objid', array( $this, 'tag_added_removed' ), 10, 3 );

		global $zbs;

		$args = array(
			'id'   => $contact_id,
			'tags' => $tags,
		);

		$result = $zbs->DAL->contacts->addUpdateContactTags( $args );

		return true;

	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		// Prevent looping
		remove_action( 'zbs_tag_removed_from_objid', array( $this, 'tag_added_removed' ), 10, 3 );

		global $zbs;

		$args = array(
			'id'   => $contact_id,
			'tags' => $tags,
			'mode' => 'remove',
		);

		$result = $zbs->DAL->contacts->addUpdateContactTags( $args );

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

		global $zbs;

		$args = array(
			'data' => $data,
		);

		$result = $zbs->DAL->contacts->addUpdateContact( $args );

		return $result;

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

		if ( empty( $data ) ) {
			return false;
		}

		global $zbs;

		$args = array(
			'id'   => $contact_id,
			'data' => $data,
		);

		$result = $zbs->DAL->contacts->addUpdateContact( $args );

		return true;

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		global $zbs;

		$contact = $zbs->DAL->contacts->getContact( $contact_id );

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $key => $data ) {

			if ( $data['active'] == true && ! empty( $data['crm_field'] ) && ! empty( $contact[ $data['crm_field'] ] ) ) {

				$user_meta[ $key ] = $contact[ $data['crm_field'] ];

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

		// Need the tag ID for this search
		global $zbs;

		$args = array(
			'objtype' => ZBS_TYPE_CONTACT,
			'slug'    => sanitize_title( $tag ),
			'onlyID'  => true,
		);

		$tag_id = $zbs->DAL->getTag( -1, $args );

		$args = array(
			'isTagged' => $tag_id,
		);

		$contacts = $zbs->DAL->contacts->getContacts( $args );

		$contact_ids = array();

		foreach ( $contacts as $contact ) {
			$contact_ids[] = $contact['id'];
		}

		return $contact_ids;

	}

}
