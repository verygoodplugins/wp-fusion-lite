<?php

class WPF_Groundhogg {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.3
	 * @var  string
	 */

	public $edit_url = '';


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug      = 'groundhogg';
		$this->name      = 'Groundhogg';
		$this->menu_name = 'Groundhogg (This Site)';
		$this->supports  = array( 'events', 'add_tags_api' );

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

		if ( function_exists( 'Groundhogg\white_labeled_name' ) ) {
			$this->name = Groundhogg\white_labeled_name(); // White Labelling support.
		}

		// Don't watch GH for changes if staging mode is active.

		if ( wpf_get_option( 'staging_mode' ) == true || ! class_exists( '\Groundhogg\Contact' ) ) {
			return;
		}

		// Contact edit URL on the admin profile
		$this->edit_url = admin_url( 'admin.php?page=gh_contacts&action=edit&contact=%d' );

		// Disable the API queue

		add_filter( 'wpf_get_setting_enable_queue', '__return_false' );

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_apply_tags', array( $this, 'create_new_tags' ) );

		// Stop GH syncing users to contacts.
		add_filter( 'groundhogg/should_convert_user_to_contact_when_user_registered', '__return_false' );

		remove_action( 'user_register', 'Groundhogg\convert_user_to_contact_when_user_registered' );
		remove_action( 'user_register', array( \Groundhogg\Plugin::instance()->user_syncing, 'sync_new_user' ) );
		remove_action( 'profile_update', array( \Groundhogg\Plugin::instance()->user_syncing, 'sync_existing_user' ) );

		// Syncs GH tags with the WP User.
		add_action( 'wpf_user_created', array( $this, 'user_registered' ), 10, 2 );

		add_action( 'groundhogg/contact/tag_applied', array( $this, 'tag_applied' ), 10, 2 );
		add_action( 'groundhogg/contact/tag_removed', array( $this, 'tag_removed' ), 10, 2 );
		add_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );
		add_action( 'groundhogg/meta/contact/update', array( $this, 'contact_post_update_fallback' ), 10, 4 );

		// Tags.
		add_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );
		add_action( 'groundhogg/db/post_delete/tag', array( $this, 'tag_deleted' ) );

		add_filter( 'wpf_map_meta_fields', array( $this, 'fix_consent_fields_dates' ), 10, 2 );

	}

	/**
	 * Add/Fix dates to consent fields.
	 *
	 * @since  3.38.0
	 *
	 * @param  array $update_data The update data to send to the CRM.
	 * @param  array $user_meta   The user meta that was updated in WordPress.
	 * @return array The update data to send to the CRM.
	 */
	public function fix_consent_fields_dates( $update_data, $user_meta ) {

		$consent_keys = array(
			'gdpr_consent',
			'terms_agreement',
			'marketing_consent',
		);

		foreach ( $consent_keys as $key ) {

			if ( isset( $update_data[ $key ] ) && ! empty( $update_data[ $key ] ) ) {
				$value = $update_data[ $key ];

				// Check if consent retruns true.

				if ( filter_var( $update_data[ $key ], FILTER_VALIDATE_BOOLEAN ) ) {
					$update_data[ $key ]           = 'yes';
					$update_data[ $key . '_date' ] = gmdate( 'Y-m-d', time() );
				} else {
					// Check if it's a valid date.
					if ( strtotime( $value ) ) {
						$update_data[ $key ]           = 'yes';
						$update_data[ $key . '_date' ] = $value;
					}
				}
			}
		}

		return $update_data;
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
	 * Formats user entered data to match GH field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'optin_status' === $field && 'checkbox' === $field_type ) {

			if ( empty( $value ) ) {
				$value = 1; // Unconfirmed.
			} else {
				$value = 2; // Confirmed.
			}
		} elseif ( 'optin_status' === $field && ! is_numeric( $value ) ) {

			// Convert optin status strings to proper format.

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

			$available_tags = wpf_get_option( 'available_tags' );

			if ( isset( $available_tags[ $tag ] ) ) {
				unset( $available_tags[ $tag ] );
			}

			remove_action( 'groundhogg/db/post_insert/tag', array( $this, 'tag_created' ) );

			$id = Groundhogg\get_db( 'tags' )->add( array( 'tag_name' => $tag ) );

			$available_tags[ $id ] = $tag;
			wp_fusion()->settings->set( 'available_tags', $available_tags );

			$tags[] = $id;

		}

		return $tags;

	}

	/**
	 * Loads tags applied by Groundhogg during user registration back to the WPF cache.
	 *
	 * @since 3.41.16
	 *
	 * @param int $user_id    The user ID.
	 * @param int $contact_id The contact ID.
	 */
	public function user_registered( $user_id, $contact_id ) {

		$tags = $this->get_tags( $contact_id );

		wp_fusion()->user->set_tags( $tags, $user_id );

	}


	/**
	 * Update WPF tags when tags applied in Groundhogg
	 *
	 * @since 3.41.16
	 * @access  public
	 * @param object $contact The Groundhogg Contact object.
	 * @param mixed  $tag_id The ID of the tag applied.
	 */
	public function tag_applied( $contact, $tag_id ) {

		// This action triggers apply_tags_to_contact_from_new_roles in GH and can create a situation where recently applied tags get overwritten.
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

		// Custom fields (since 3.40.40).

		if ( class_exists( 'Groundhogg\Properties' ) ) {
			$additional_fields = Groundhogg\Properties::instance()->get_fields();

			if ( ! empty( $additional_fields ) ) {
				foreach ( $additional_fields as $field ) {
					$crm_fields[ $field['name'] ] = $field['label'];
				}
			}
		}

		// Advanced Meta.

		if ( class_exists( 'GroundhoggBetterMeta\Tab_Api\Fields' ) && ! empty( GroundhoggBetterMeta\Tab_Api\Fields::$instance ) ) {

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

	public function add_contact( $data ) {

		// Make sure we have an email.

		if ( ! isset( $data['email'] ) || ! is_email( $data['email'] ) ) {
			return new WP_Error( 'error', 'Unable to create contact in Groundhogg, a missing or invalid <code>email</code> field was provided.' );
		}

		// If we're creating a contact from a user, pass that through.

		if ( ! empty( $data['user_id'] ) ) {
			$user_id = $data['user_id'];
		}

		// Set to opted in by default unless otherwise specified.

		if ( ! isset( $data['optin_status'] ) ) {
			$data['optin_status'] = wpf_get_option( 'gh_default_status', 2 );
		}

		// Prevent looping.
		remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $data );

		if ( ! $contact->exists() ) {
			return new WP_Error( 'error', 'Contact creation failed.' );
		}

		$id = $contact->get_id();

		// These things don't go into meta.

		unset( $data['user_id'] );
		unset( $data['optin_status'] );
		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach ( $data as $key => $value ) {

			$contact->update_meta( $key, $value );

		}

		// Trigger user created benchmarks.

		if ( isset( $user_id ) ) {

			$user = get_userdata( $user_id );
			do_action( 'groundhogg/contact_created_from_user', $user, $contact );

		}

		return $id;

	}



	/**
	 * Creates a new tag in Groundhogg and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$tag_ids = \Groundhogg\Plugin::$instance->dbs->get_db( 'tags' )->validate( array( $tag_name ) );

		if ( is_wp_error( $tag_ids ) ) {
			return $tag_ids;
		}

		return $tag_ids[0];
	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		remove_action( 'groundhogg/admin/contact/save', array( $this, 'contact_post_update' ), 10, 2 );

		$contact = new \Groundhogg\Contact( $contact_id );

		$result = $contact->update( $data );

		// Update failed for some reason.

		if ( isset( $data['email'] ) && strtolower( $data['email'] ) !== strtolower( $contact->email ) ) {
			$result = new WP_Error( 'notice', ' Could not update email address from <strong>' . $contact->email . '</strong> to <strong>' . $data['email'] . '</strong> because there is already a contact with that email address. Please merge the duplicate contacts and try again. For now the email address change will be ignored.' );
		}

		unset( $data['user_id'] );
		unset( $data['owner_id'] );
		unset( $data['optin_status'] );
		unset( $data['first_name'] );
		unset( $data['last_name'] );
		unset( $data['email'] );

		foreach ( $data as $key => $value ) {

			$contact->update_meta( $key, $value );

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

		$contact = new \Groundhogg\Contact( $contact_id );

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields', array() );

		foreach ( $contact_fields as $key => $data ) {

			if ( ! empty( $data['active'] ) && ! empty( $data['crm_field'] ) ) {

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

	/**
	 * Track event.
	 *
	 * Track an event.
	 *
	 * @since  3.38.16
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return; // can't track without an email.
		}

		$contact = \Groundhogg\get_contactdata( $email_address );

		if ( ! $contact ) {
			return;
		}

		$args = array(
			'contact_id' => $contact->ID,
		);

		if ( ! is_array( $event_data ) ) {
			// Single key/value events.
			$event_data = array(
				'event_name'  => $event,
				'event_value' => $event_data,
			);
		} else {
			$event_data['event_name'] = $event;
		}

		\Groundhogg\track_activity( $contact, 'wp_fusion', $args, $event_data );

		return true;
	}



}
