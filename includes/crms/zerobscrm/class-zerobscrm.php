<?php

class WPF_ZeroBSCRM {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	public $edit_url = '';


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'zerobscrm';
		$this->name     = 'Jetpack CRM';
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

		add_filter( 'wpf_api_preflight_check', array( $this, 'preflight_check' ) );

		// Don't watch ZBS for changes if staging mode is active
		if ( wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		add_action( 'zbs_tag_added_to_objid', array( $this, 'tag_added_removed' ), 10, 3 );
		add_action( 'zbs_tag_removed_from_objid', array( $this, 'tag_added_removed' ), 10, 3 );

		add_action( 'zbs_edit_customer', array( $this, 'edit_customer' ) );

		$this->edit_url = admin_url( 'admin.php?page=zbs-add-edit&action=view&zbstype=contact&zbsid=%s' );

	}

	/**
	 * Make sure Jetpack is active before using any of these methods.
	 *
	 * @since  3.40.15
	 *
	 * @param bool $check Whether the dependencies are met
	 *
	 * @return bool|WP_Error
	 */

	public function preflight_check( $check ) {

		if ( ! class_exists( 'ZeroBSCRM' ) ) {
			return new WP_Error( 'error', 'Jetpack CRM plugin not active.' );
		}

		return true;

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

			} else {

				global $zbs;

				$tag = $zbs->DAL->getTag( $tag_id ); //phpcs:ignore

				// Maybe import the user

				if ( in_array( $tag['name'], wpf_get_option( 'jetpack_import_tag', array() ) ) ) {

					wp_fusion()->user->import_user( $object_id );

				}
			}
		}

	}

	/**
	 * Load data from the CRM when a contact is edited.
	 *
	 * @since 3.37.31
	 *
	 * @param int $contact_id The contact ID.
	 */
	public function edit_customer( $contact_id ) {

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		if ( ! empty( $user_id ) ) {

			$user_meta = $this->load_contact( $contact_id );

			wp_fusion()->user->set_user_meta( $user_id, $user_meta );

		}

		remove_action( 'zbs_edit_customer', array( $this, 'edit_customer' ) ); // this runs twice for some reason, we only need it once

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

			return new WP_Error( 'error', 'Jetpack CRM plugin not active.' );

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

	public function add_contact( $data ) {

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

	public function update_contact( $contact_id, $data ) {

		global $zbs;

		$custom_fields = $zbs->DAL->getActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT ) );

		$fields = array();

		foreach ( $data as $key => $value ) {

			if ( ! isset( $custom_fields[ $key ] ) ) {
				$key = 'zbsc_' . $key; // when using limitedFields, standard field keys have to be prefixed with zbsc_
			}

			$fields[] = array(
				'key'  => $key,
				'val'  => $value,
				'type' => '%s',
			);
		}

		$args = array(
			'id'            => $contact_id,
			'limitedFields' => $fields, // only update, don't erase existing.
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
		$contact_fields = wpf_get_option( 'contact_fields' );

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
