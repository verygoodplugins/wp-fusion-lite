<?php

class WPF_Groundhogg {


	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;


	/**
	 * Check for verson 2.0 and higher
	 */

	public $is_v2 = true;


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

		// Compatibility check
		if ( ! defined( 'GROUNDHOGG_VERSION' ) || version_compare( GROUNDHOGG_VERSION, '2.0', '<' ) ) {
			$this->is_v2 = false;
		}

	}


	/**
	 * Get things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {

		if ( $this->is_v2 ) {

			add_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );
			add_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );
			add_action( 'groundhogg/contact/post_update', array( $this, 'contact_post_update' ), 10, 3 );

			// Tags
			add_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );
			add_action( 'groundhogg/db/post_delete/tag', array( $this, 'tag_deleted' ) );

		} else {

			add_action( 'wpgh_tag_applied', array( $this, 'tag_applied' ), 10, 2 );
			add_action( 'wpgh_tag_removed', array( $this, 'tag_removed' ), 10, 2 );
			add_action( 'wpgh_contact_post_update', array( $this, 'contact_post_update' ), 10, 3 );

			// Tags
			add_action( 'wpgh_tag_created', array( $this, 'tag_created' ) );
			add_action( 'wpgh_delete_tag', array( $this, 'tag_deleted' ) );

		}

	}


	/**
	 * Update WPF tags when tags applied in Groundhogg
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_applied( $contact, $tag_id ) {

		if ( $this->is_v2 ) {

			$user_id = $contact->get_user_id();

		} else {

			if ( ! empty( $contact->user ) ) {

				$user_id = $contact->user->ID;

			}
		}

		if ( ! empty( $user_id ) ) {

			wp_fusion()->user->get_tags( $user_id, true, false );

		}

	}


	/**
	 * Update WPF tags when tags removed in Groundhogg
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_removed( $contact, $tag_id ) {

		if ( $this->is_v2 ) {

			$user_id = $contact->get_user_id();

		} else {

			if ( ! empty( $contact->user ) ) {

				$user_id = $contact->user->ID;

			}
		}

		if ( ! empty( $user_id ) ) {

			wp_fusion()->user->get_tags( $user_id, true, false );

		}

	}

	/**
	 * Update user meta when contact meta updated
	 *
	 * @access  public
	 * @return  void
	 */

	public function contact_post_update( $updated, $contact_id, $data ) {

		if ( $this->is_v2 ) {

			$contact = new \Groundhogg\Contact( $contact_id );

		} else {

			$contact = new WPGH_Contact( $contact_id );

		}

		if ( ! empty( $contact->user ) ) {

			$user_meta = $this->load_contact( $contact_id );

			wp_fusion()->user->set_user_meta( $contact->user->ID, $user_meta );

		}

	}

	/**
	 * Add new tags to list when added via Groundhogg
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_created( $id ) {

		$this->sync_tags();

	}

	/**
	 * Remove tags from list when deleted via Groundhogg
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_deleted( $id ) {

		$this->sync_tags();

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

		if ( $test == false ) {
			return true;
		}

		if ( $this->is_v2 && ! class_exists( '\Groundhogg\Contact' ) ) {

			return new WP_Error( 'error', 'Groundhogg plugin not active.' );

		} elseif ( ! $this->is_v2 && ! function_exists( 'WPGH' ) ) {

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

		$available_tags = array();

		if ( $this->is_v2 ) {

			$data = \Groundhogg\Plugin::$instance->dbs->get_db( 'tags' )->search();

		} else {

			$data = WPGH()->tags->get_tags();

		}

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

		$crm_fields = array();

		if ( $this->is_v2 ) {

			require dirname( __FILE__ ) . '/admin/groundhogg-fields.php';

			foreach ( $groundhogg_fields as $field ) {
				$crm_fields[ $field['crm_field'] ] = $field['crm_label'];
			}

			$meta_keys = \Groundhogg\Plugin::$instance->dbs->get_db( 'contactmeta' )->get_keys();

			foreach ( $meta_keys as $meta_key ) {
				$crm_fields[ $meta_key ] = ucwords( str_replace( '_', ' ', $meta_key ) );
			}
		} else {

			$data = new WPGH_Contact( 0 );

			foreach ( $data as $key => $field_data ) {
				$crm_fields[ $key ] = ucwords( str_replace( '_', ' ', $key ) );
			}

			global $wpdb;

			$meta_keys = $wpdb->get_col(
				"SELECT DISTINCT meta_key FROM {$wpdb->prefix}gh_contactmeta ORDER BY meta_key DESC"
			);

			foreach ( $meta_keys as $meta_key ) {
				$crm_fields[ $meta_key ] = ucwords( str_replace( '_', ' ', $meta_key ) );
			}
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

		if ( $this->is_v2 ) {

			$contact = \Groundhogg\Plugin::$instance->dbs->get_db( 'contacts' )->get_contact_by( 'email', $email_address );

		} else {

			$contact = WPGH()->contacts->get_contact_by( 'email', $email_address );

		}

		if ( empty( $contact ) ) {
			return false;
		}

		return $contact->ID;

	}

	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( $this->is_v2 ) {

			$data = new \Groundhogg\Contact( $contact_id );

		} else {

			$data = new WPGH_Contact( $contact_id );

		}

		$tags = array();

		if ( empty( $data->tags ) ) {
			return $tags;
		}

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

		if ( $this->is_v2 ) {

			remove_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );

			$contact = new \Groundhogg\Contact( $contact_id );

			$contact->add_tag( $tags );

		} else {

			remove_action( 'wpgh_tag_applied', array( $this, 'tag_applied' ), 10, 2 );

			$contact = new WPGH_Contact( $contact_id );

			foreach ( $tags as $key => $value ) {

				$contact->add_tag( array( $contact_id => $value ) );

			}
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

		if ( $this->is_v2 ) {

			remove_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );

			$contact = new \Groundhogg\Contact( $contact_id );

			$contact->remove_tag( $tags );

		} else {

			remove_action( 'wpgh_tag_removed', array( $this, 'tag_removed' ), 10, 2 );

			$contact = new WPGH_Contact( $contact_id );

			foreach ( $tags as $key => $value ) {

				$contact->remove_tag( array( $contact_id => $value ) );

			}
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

		if ( $this->is_v2 ) {

			remove_action( 'groundhogg/contact/post_update', array( $this, 'contact_post_update' ), 10, 3 );

			$contact = new \Groundhogg\Contact( $data );

			if ( ! $contact->exists() ) {
				return new WP_Error( 'error', 'Contact creation failed.' );
			}

			$id = $contact->get_id();

		} else {

			remove_action( 'wpgh_contact_post_update', array( $this, 'contact_post_update' ), 10, 3 );

			$id = WPGH()->contacts->add( $data );

			$contact = new WPGH_Contact( $id );

		}

		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach ( $data as $key => $value ) {

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

		if ( empty( $data ) ) {
			return false;
		}

		if ( $this->is_v2 ) {

			remove_action( 'groundhogg/contact/post_update', array( $this, 'contact_post_update' ), 10, 3 );

			$contact = new \Groundhogg\Contact( $contact_id );

		} else {

			remove_action( 'wpgh_contact_post_update', array( $this, 'contact_post_update' ), 10, 3 );

			$contact = new WPGH_Contact( $contact_id );

		}

		$contact->update( $data );

		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach ( $data as $key => $value ) {

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

		if ( $this->is_v2 ) {

			$contact = new \Groundhogg\Contact( $contact_id );

		} else {

			$contact = new WPGH_Contact( $contact_id );

		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $contact_fields as $key => $data ) {

			if ( $data['active'] == true && ! empty( $data['crm_field'] ) ) {

				$user_meta[ $key ] = $contact->{$data['crm_field']};

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

		if ( $this->is_v2 ) {

			$contacts = \Groundhogg\Plugin::$instance->dbs->get_db( 'tag_relationships' )->get_contacts_by_tag( $tag );

		} else {

			$contacts = WPGH()->tag_relationships->get_contacts_by_tag( $tag );

		}

		$contact_ids = array();

		foreach ( $contacts as $row => $contact_id ) {
			$contact_ids[] = $contact_id;
		}

		return $contact_ids;

	}

}
