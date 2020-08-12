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
		$this->supports = array( 'add_tags' );

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

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_apply_tags', array( $this, 'create_new_tags' ) );

		// Don't watch GH for changes if staging mode is active

		if ( wp_fusion()->settings->get( 'staging_mode' ) == true ) {
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'remove_actions' ) );

		if ( $this->is_v2 ) {

			add_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );
			add_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );
			add_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );
			add_action( 'groundhogg/meta/contact/update', array( $this, 'contact_post_update_fallback' ), 10, 4 );

			// Tags
			add_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );
			add_action( 'groundhogg/db/post_delete/tag', array( $this, 'tag_deleted' ) );

		} else {

			add_action( 'wpgh_tag_applied', array( $this, 'tag_applied' ), 10, 2 );
			add_action( 'wpgh_tag_removed', array( $this, 'tag_removed' ), 10, 2 );

			// Tags
			add_action( 'wpgh_tag_created', array( $this, 'tag_created' ) );
			add_action( 'wpgh_delete_tag', array( $this, 'tag_deleted' ) );

		}

	}

	/**
	 * Let WP Fusion create contacts from users, not GH
	 *
	 * @access public
	 * @return void
	 */

	public function remove_actions() {

		remove_action( 'user_register', 'Groundhogg\convert_user_to_contact_when_user_registered' );

	}

	/**
	 * Formats user entered data to match GH field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field == 'gdpr_consent' || $field == 'terms_agreement' ) {

			if( ! empty( $value ) ) {
				$value = 'yes';
			} else {
				$value = 'no';
			}

		}

		if ( $field == 'optin_status' && ! is_numeric( $field ) ) {

			// Convert optin status strings to proper format

			$value = strtoupper( $value );

			$refl = new ReflectionClass( '\Groundhogg\Preferences' );
			$vars = $refl->getConstants();

			if ( isset( $vars[ $value ] ) ) {
				$value = $vars[ $value ];
			} else {
				$value = false;
			}

		}

		// Maybe fix Country values

		$countries = include dirname( __FILE__ ) . '/countries.php';

		foreach ( $countries as $abbr => $name ) {

			if ( $value == $name ) {
				$value = $abbr;
			}
		}

		return $value;

	}

	/**
	 * Creates new tags in Groundhogg if needed
	 *
	 * @access public
	 * @return array Tags
	 */

	public function create_new_tags( $tags ) {

		foreach ( $tags as $i => $tag ) {

			if ( is_numeric( $tag ) || empty( $tag ) ) {
				continue;
			}

			// Remove the tag with a label from the list of IDs
			unset( $tags[ $i ] );

			$available_tags = wp_fusion()->settings->get( 'available_tags' );

			if ( isset( $available_tags[ $tag ] ) ) {
				unset( $available_tags[ $tag ] );
			}

			remove_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );

			$id = Groundhogg\get_db( 'tags' )->add( [ 'tag_name' => $tag ] );

			$available_tags[ $id ] = $tag;
			wp_fusion()->settings->set( 'available_tags', $available_tags );

			$tags[] = $id;

		}

		return $tags;

	}


	/**
	 * Update WPF tags when tags applied in Groundhogg
	 *
	 * @access  public
	 * @return  void
	 */

	public function tag_applied( $contact, $tag_id ) {

		// This action triggers apply_tags_to_contact_from_new_roles in GH and can create a situation where recently applied tags get overwritten
		if ( did_action( 'add_user_role' ) > 0 || did_action( 'set_user_role' ) > 0 ) {
			return;
		}

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

	public function contact_post_update( $contact_id, $contact ) {

		if ( ! empty( $contact->user ) ) {

			$user_meta = $this->load_contact( $contact_id );

			wp_fusion()->user->set_user_meta( $contact->user->ID, $user_meta );

		}

	}


	/**
	 * Update user meta when contact meta updated (fallback for REST API updates)
	 *
	 * @access  public
	 * @return  void
	 */

	public function contact_post_update_fallback( $contact_id, $meta_key, $meta_value, $prev_value ) {

		if ( ! defined( 'REST_REQUEST' ) ) {
			return;
		}

		$contact = new \Groundhogg\Contact( $contact_id );

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
			$available_tags[ $row->tag_id ] = trim( $row->tag_name );
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

				if ( ! isset( $crm_fields[ $meta_key ] ) ) {
					$crm_fields[ $meta_key ] = ucwords( str_replace( '_', ' ', $meta_key ) );
				}
			}

			// Advanced Meta

			if ( class_exists( 'GroundhoggBetterMeta\Tab_Api\Fields' ) ) {

				$additional_fields = GroundhoggBetterMeta\Tab_Api\Fields::$instance->get_all();

				if ( ! empty( $additional_fields ) ) {
					foreach ( $additional_fields as $field ) {
						$crm_fields[ $field['meta'] ] = $field['name'];
					}
				}

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

		if ( ! empty( $data['user_id'] ) ) {
			$user_id = $data['user_id'];
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// If we're creating a contact from a user, pass that through

		if ( isset( $user_id ) ) {
			$data['user_id'] = $user_id;
		}

		// Set to opted in by default unless otherwise specified

		if ( ! isset( $data['optin_status'] ) ) {
			$data['optin_status'] = wp_fusion()->settings->get( 'gh_default_status', 2 );
		}

		if ( $this->is_v2 ) {

			remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

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

		// These things don't go into meta

		unset( $data['user_id'] );
		unset( $data['optin_status'] );
		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach ( $data as $key => $value ) {

			$contact->update_meta( $key, $value );

		}

		// Trigger user created benchmarks

		if ( isset( $user_id ) ) {

			$user = get_userdata( $user_id );
			do_action( 'groundhogg/contact_created_from_user', $user, $contact );

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

			remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

			$contact = new \Groundhogg\Contact( $contact_id );

		} else {

			remove_action( 'wpgh_contact_post_update', array( $this, 'contact_post_update' ), 10, 3 );

			$contact = new WPGH_Contact( $contact_id );

		}

		$contact->update( $data );

		unset( $data['user_id'] );
		unset( $data['optin_status'] );
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

				$value = $contact->{$data['crm_field']};

				if ( empty( $value ) ) {
					continue;
				}

				$user_meta[ $key ] = $value;

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
