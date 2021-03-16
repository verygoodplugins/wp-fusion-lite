<?php

class WPF_Groundhogg {


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

		// $this->supports = array( 'add_tags' ); // Removed in 3.35.10

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

	public function init() {

		add_filter( 'wpf_api_preflight_check', array( $this, 'preflight_check' ) );

		// Don't watch GH for changes if staging mode is active

		if ( wp_fusion()->settings->get( 'staging_mode' ) == true || ! class_exists( '\Groundhogg\Contact' ) ) {
			return;
		}

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_apply_tags', array( $this, 'create_new_tags' ) );

		add_action( 'plugins_loaded', array( $this, 'remove_actions' ) );

		add_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );
		add_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );
		add_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );
		add_action( 'groundhogg/meta/contact/update', array( $this, 'contact_post_update_fallback' ), 10, 4 );

		// Tags
		add_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );
		add_action( 'groundhogg/db/post_delete/tag', array( $this, 'tag_deleted' ) );

	}

	/**
	 * Make sure Groundhogg is active before using any of these methods
	 *
	 * @since  3.35.16
	 *
	 * @param bool $check Whether the dependencies are met
	 *
	 * @return bool|WP_Error
	 */

	public function preflight_check( $check ) {

		if ( ! class_exists( '\Groundhogg\Contact' ) ) {
			return new WP_Error( 'error', 'Groundhogg plugin not active.' );
		}

		return true;

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

		} elseif ( $field == 'optin_status' && ! is_numeric( $field ) ) {

			// Convert optin status strings to proper format

			$value = strtoupper( $value );

			$refl = new ReflectionClass( '\Groundhogg\Preferences' );
			$vars = $refl->getConstants();

			if ( isset( $vars[ $value ] ) ) {
				$value = $vars[ $value ];
			} else {
				$value = false;
			}
		} elseif ( 'datepicker' == $field_type || 'date' == $field_type ) {

			// Adjust formatting for date fields
			$value = date( 'Y-m-d', $value );

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

		$user_id = $contact->get_user_id();

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

		$user_id = $contact->get_user_id();

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

		if ( false == $test ) {
			return true;
		}

		if ( ! class_exists( '\Groundhogg\Contact' ) ) {

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

		$data = \Groundhogg\Plugin::$instance->dbs->get_db( 'tags' )->search();

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

		$contact = \Groundhogg\Plugin::$instance->dbs->get_db( 'contacts' )->get_contact_by( 'email', $email_address );

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

		$data = new \Groundhogg\Contact( $contact_id );

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

		remove_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $contact_id );

		$contact->add_tag( $tags );

		return true;

	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		remove_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $contact_id );

		$contact->remove_tag( $tags );

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

		remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $data );

		if ( ! $contact->exists() ) {
			return new WP_Error( 'error', 'Contact creation failed.' );
		}

		$id = $contact->get_id();

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

		remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $contact_id );

		$contact->update( $data );

		unset( $data['user_id'] );
		unset( $data['owner_id'] );
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

		$contact = new \Groundhogg\Contact( $contact_id );

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields', array() );

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

		$contacts = \Groundhogg\Plugin::$instance->dbs->get_db( 'tag_relationships' )->get_contacts_by_tag( $tag );

		$contact_ids = array();

		foreach ( $contacts as $row => $contact_id ) {
			$contact_ids[] = $contact_id;
		}

		return $contact_ids;

	}

}
