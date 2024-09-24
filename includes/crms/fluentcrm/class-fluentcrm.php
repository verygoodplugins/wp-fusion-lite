<?php

class WPF_FluentCRM {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'fluentcrm';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'FluentCRM';

	/**
	 * The CRM menu name.
	 *
	 * @var string
	 */
	public $menu_name = 'FluentCRM (This Site)';

	/**
	 * The features supported by the CRM.
	 */
	public $supports = array( 'add_tags_api', 'events', 'events_multi_key', 'same_site', 'lists' );

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.3
	 * @var  string
	 */
	public $edit_url = '';

	/**
	 * Prevent syncing back tags that were just applied.
	 *
	 * @since 3.44.1
	 *
	 * @var bool Skip tag sync
	 */
	public $wpf_modifying_tags = false;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.35
	 */

	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_FluentCRM_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_api_preflight_check', array( $this, 'preflight_check' ) );

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Don't watch FluentCRM for changes if staging mode is active.

		if ( wpf_is_staging_mode() || ! defined( 'FLUENTCRM' ) ) {
			return;
		}

		// Sync global tag and list changes

		add_action( 'fluentcrm_list_created', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_list_deleted', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_tag_created', array( $this, 'sync_tags' ), 10, 0 );
		add_action( 'fluentcrm_tag_deleted', array( $this, 'sync_tags' ), 10, 0 );

		// Sync contact tags when modified

		add_action( 'fluentcrm_contact_added_to_tags', array( $this, 'contact_tags_added_removed' ), 5, 2 );
		add_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'contact_tags_added_removed' ), 5, 2 );

		// Sync contact fields when modified
		add_action( 'fluentcrm_contact_updated', array( $this, 'contact_updated' ) );
		add_action( 'fluentcrm_contact_custom_data_updated', array( $this, 'contact_custom_data_updated' ), 10, 2 );

		// Contact edit URL on the admin profile
		$this->edit_url = admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/%d/' );

		/*
		 * List added to removed hook
		 * WP Fusion may implement this in future
		 *
		add_action( 'fluentcrm_contact_added_to_lists', array($this,'contact_lists_added_removed' ), 10, 2);
		add_action( 'fluentcrm_contact_removed_from_lists', array($this,'contact_lists_added_removed' ), 10, 2);
		 */
	}

	/**
	 * Make sure FluentCRM is active before using any of these methods
	 *
	 * @since  3.35.16
	 *
	 * @param bool $check Whether the dependencies are met
	 *
	 * @return bool|WP_Error
	 */

	public function preflight_check( $check ) {

		if ( ! defined( 'FLUENTCRM' ) ) {
			return new WP_Error( 'error', 'FluentCRM plugin not active.' );
		}

		return true;
	}

	/**
	 * Gets the contact ID and tags out of webhook payloads.
	 *
	 * @since  3.39.4
	 *
	 * @param  array $post_data The POSTed data from the webhook.
	 * @return array The populated POST data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( stripslashes( file_get_contents( 'php://input' ) ) );

		wpf_log( 'notice', 0, 'Doing it wrong! WP Fusion is connected to FluentCRM on the same site. There\'s no reason to send webhooks. Tag and field changes are synced automatically in the background.', array( 'source' => 'fluentcrm' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = absint( $payload->id );

		if ( ! empty( $payload->tags ) ) {
			$post_data['tags'] = wp_list_pluck( (array) $payload->tags, 'slug' );
		}

		return $post_data;
	}

	/**
	 * Adjust field formatting
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.
			$date = gmdate( 'Y-m-d h:i:s', $value );

			return $date;

		} else {

			return $value;

		}
	}

	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function connect( $test = false ) {

		if ( true == $test && ! defined( 'FLUENTCRM' ) ) {
			return new WP_Error( 'error', 'FluentCRM plugin is not active.' );
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

		$this->sync_lists();
		$this->sync_tags();
		$this->sync_crm_fields();
		do_action( 'wpf_sync' );
		return true;
	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Tags
	 */

	public function sync_tags() {

		$all_tags       = FluentCrmApi( 'tags' )->all();
		$available_tags = array();
		foreach ( $all_tags as $tag ) {
			$available_tags[ $tag->id ] = $tag->title;
		}

		asort( $available_tags );

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {
		$lists           = FluentCrmApi( 'lists' )->all();
		$available_lists = array();
		foreach ( $lists as $list ) {
			$available_lists[ $list->id ] = $list->title;
		}

		asort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		$built_in_fields           = FluentCrmApi( 'contacts' )->getInstance()->mappables();
		$built_in_fields['status'] = 'Status';
		$built_in_fields['avatar'] = 'Avatar URL';
		$custom_fields             = $this->get_custom_fields();

		asort( $built_in_fields );
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

		$contact = FluentCrmApi( 'contacts' )->getContact( $email_address );

		if ( $contact ) {
			return absint( $contact->id );
		}

		return false;
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return array
	 */

	public function get_tags( $contact_id ) {

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'not_found', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		$tags = array();

		foreach ( $contact->tags as $tag ) {
			$tags[] = $tag->id;
		}

		return $tags;
	}

	/**
	 * Applies tags to a contact. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$this->wpf_modifying_tags = true; // Prevents infinite loop (see contact_tags_added_removed() below).

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'error', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		$contact->attachTags( $tags );

		return true;
	}


	/**
	 * Removes tags from a contact. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$this->wpf_modifying_tags = true; // Prevents infinite loop (see contact_tags_added_removed() below).

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'error', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		$contact->detachTags( $tags );

		return true;
	}


	/**
	 * Adds a new contact (using v1 API since v3 doesn't support adding custom fields in the same API call)
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		$custom_fields = $this->get_custom_fields();
		$custom_data   = array_filter( \FluentCrm\Framework\Support\Arr::only( $data, array_keys( $custom_fields ) ) );

		if ( $custom_data ) {
			$data['custom_values'] = $custom_data;
		}

		$lists = wpf_get_option( 'fluentcrm_lists', array() );

		if ( get_user_by( 'email', $data['email'] ) ) {
			// Default lists for new users.
			$lists = array_merge( $lists, wpf_get_option( 'assign_lists', array() ) );
		}

		if ( ! empty( $lists ) ) {
			$data['lists'] = $lists;
		}

		if ( empty( $data['status'] ) ) {
			$data['status'] = wpf_get_option( 'default_status', 'subscribed' );
		}

		if ( 'susbcribed' === $data['status'] ) {
			$data['status'] = 'subscribed'; // fixes typo between v3.40.40 and 3.40.57.
		}

		$model = FluentCrmApi( 'contacts' )->getInstance();

		// Prevent looping.
		remove_action( 'fluentcrm_contact_updated', array( $this, 'contact_updated' ) );
		remove_action( 'fluentcrm_contact_custom_data_updated', array( $this, 'contact_custom_data_updated' ), 10, 2 );

		$contact = $model->updateOrCreate( $data );

		/*
		 * Now sure how WP Fusion handle double optin
		 * By default status is subscribed if status is not given
		 * If status is pending given in data then sending a double optin
		 */
		if ( 'pending' === $contact->status ) {
			$contact->sendDoubleOptinEmail();
		}

		add_action( 'fluentcrm_contact_updated', array( $this, 'contact_updated' ) );
		add_action( 'fluentcrm_contact_custom_data_updated', array( $this, 'contact_custom_data_updated' ), 10, 2 );

		return $contact->id;
	}


	/**
	 * Creates a new tag in FluentCRM and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		$data = array(
			'title' => $tag_name,
			'slug'  => sanitize_title( $tag_name ),
		);

		$model = FluentCrmApi( 'tags' )->getInstance();
		$tag   = $model->updateOrCreate( $data );

		$attributes = $tag->getAttributes();

		$tag_id = $attributes['id'];

		return $tag_id;
	}



	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		$custom_fields = $this->get_custom_fields();
		$custom_data   = array_intersect_key( $data, $custom_fields );

		$model = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $model ) {
			return new WP_Error( 'error', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		if ( isset( $data['email'] ) && strtolower( $data['email'] ) !== strtolower( $model->email ) && $this->get_contact_id( $data['email'] ) ) {

			// This prevents a fatal error when trying to update a subscriber's email address to one that already exists.

			wpf_log( 'notice', wpf_get_user_id( $contact_id ), 'Attempted to update contact #' . $contact_id . '\'s email address from <strong>' . $model->email . '</strong> to <strong>' . $data['email'] . '</strong>, but <strong>' . $data['email'] . '</strong> is already subscribed. The email address change will be ignored.' );
			unset( $data['email'] );

		}

		// Prevent looping.
		remove_action( 'fluentcrm_contact_updated', array( $this, 'contact_updated' ) );
		remove_action( 'fluentcrm_contact_custom_data_updated', array( $this, 'contact_custom_data_updated' ), 10, 2 );

		try {

			$model->fill( $data )->save();

		} catch ( Exception $e ) {

			// For example updating a contact with the email of another contact.
			return new WP_Error( 'error', $e->getMessage() );

		}

		if ( $custom_data ) {
			$model->syncCustomFieldValues( $custom_data, true );
		}

		if ( ! empty( $data['lists'] ) ) {
			$model->attachLists( $data['lists'] );
		}

		add_action( 'fluentcrm_contact_updated', array( $this, 'contact_updated' ) );
		add_action( 'fluentcrm_contact_custom_data_updated', array( $this, 'contact_custom_data_updated' ), 10, 2 );

		return $model->id;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'error', 'Contact #' . $contact_id . ' not found.' );
		}

		$fields = array_merge( $contact->getAttributes(), $contact->custom_fields() );

		$user_meta = array();

		// Map contact fields.
		$contact_fields = wpf_get_option( 'contact_fields' );

		// Standard fields.
		foreach ( $fields as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( $field_data['active'] && $field_data['crm_field'] === $field_name ) {
					$user_meta[ $meta_key ] = $value;
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

	public function load_contacts( $tag_id = false ) {

		$model = FluentCrmApi( 'contacts' )->getInstance();

		if ( $tag_id ) {
			$contact_ids = $model->filterByTags( array( $tag_id ) )->get()->pluck( 'id' );
		} else {
			$contact_ids = $model->get()->pluck( 'id' );
		}

		return $contact_ids->all();
	}

	/**
	 * @param $tags array
	 * @param $subscriber object
	 */
	public function contact_tags_added_removed( $tags, $subscriber ) {

		if ( $this->wpf_modifying_tags && ! doing_action( 'fluentcrm_funnel_sequence_handle_add_contact_to_tag' ) && ! doing_action( 'fluentcrm_funnel_sequence_handle_detach_contact_from_tag' ) ) {
			// Don't sync tag changes if we've just applied them and aren't currently in a funnel sequence.
			return;
		}

		// If we're in a funnel let's wait until the end.

		// We need all the tags, not just the ones that were added / removed
		$tags = array();

		foreach ( $subscriber->tags as $tag ) {
			$tags[] = $tag->id;
		}

		$user_id = $subscriber->user_id;

		if ( ! $user_id ) {
			// find the user ID from contact ID
			$user_id = wpf_get_user_id( $subscriber->id );
		}

		if ( $user_id ) {

			if ( $this->wpf_modifying_tags ) {

				// If we're currently in the process of applying tags, then we need to wait
				// until WPF_User::apply_tags() has updated the usermeta before we can load them.

				$callback = function( $user_id ) use ( &$tags, &$callback ) {

					// Prevent infinite loop when set_tags() triggers wpf_tags_modified.
					remove_action( 'wpf_tags_modified', $callback );
				
					wp_fusion()->logger->add_source( 'fluentcrm' );
					wp_fusion()->user->set_tags( $tags, $user_id );

				};

				add_action( 'wpf_tags_modified', $callback );

			} else {

				wp_fusion()->logger->add_source( 'fluentcrm' );
				wp_fusion()->user->set_tags( $tags, $user_id );

			}

		}
	}

	/**
	 * @param $lists array
	 * @param $subscriber Object
	 */
	public function contact_lists_added_removed( $lists, $subscriber ) {
		// @todo: need to implement user List sync.
		// This will only call when a FluentCRM contact get a new tag or a list
		return;
		$user_id = $subscriber->user_id;
		if ( ! $user_id ) {
			// find the user ID from email
			$user = get_user_by( 'email', $subscriber->email );
			if ( ! $user ) {
				return;
			}
			$user_id = $user->ID;
		}
	}

	/**
	 * Loads fields from FluentCRM back to WordPress when a contact is updated.
	 *
	 * @since 3.37.3
	 *
	 * @param FluentCrm\App\Models\Subscriber $subscriber The subscriber.
	 */
	public function contact_updated( $subscriber ) {

		$user_id = $subscriber->user_id;

		if ( ! $user_id ) {
			$user_id = wp_fusion()->user->get_user_id( $subscriber->id );
		}

		if ( $user_id ) {

			// Set the log source
			wp_fusion()->logger->add_source( 'fluentcrm' );

			wp_fusion()->user->pull_user_meta( $user_id );
		}
	}

	/**
	 * Loads custom fields from FluentCRM back to WordPress when a contact is
	 * updated.
	 *
	 * @since 3.37.14
	 *
	 * @param array                           $new_values The new field values.
	 * @param FluentCrm\App\Models\Subscriber $subscriber The subscriber.
	 */
	public function contact_custom_data_updated( $new_values, $subscriber ) {

		if ( did_action( 'fluentcrm_contact_updated' ) ) {
			return;
		}

		$this->contact_updated( $subscriber );
	}

	/**
	 * Gets the custom fields.
	 *
	 * @since  3.37.3
	 *
	 * @return array The custom fields.
	 */
	private function get_custom_fields() {
		$all_custom_fields = fluentcrm_get_option( 'contact_custom_fields', array() );
		$custom_fields     = array();
		if ( $all_custom_fields ) {
			foreach ( $all_custom_fields as $item ) {
				$custom_fields[ $item['slug'] ] = $item['label'];
			}
		}
		return $custom_fields;
	}

	/**
	 * Track event.
	 *
	 * Track an event.
	 *
	 * @since  3.41.45
	 *
	 * @param  string      $event      The event title.
	 * @param  array       $event_data The event data.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error|FluentCrm\App\Models\EventTracker The event tracker or error.
	 */
	public function track_event( $event, $event_data = array(), $email_address = false ) {

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return false; // can't track without an email.
		}

		if ( function_exists( 'fcrm_events_add_event' ) ) {
			// Old WP Fusion way of doing it.
			fcrm_events_add_event( $email_address, 'wp_fusion', $event, $event_data );
		} else {

			if ( ! empty( $event_data ) && 1 === count( $event_data ) ) {
				$event_text = reset( $event_data );
			} else {
				$event_text = wp_json_encode( $event_data, JSON_NUMERIC_CHECK );
			}

			$data = array(
				'event_key' => sanitize_title( $event ),
				'title'     => $event,
				'value'     => $event_text,
				'email'     => $email_address,
				'provider'  => 'wp_fusion', // If left empty, 'custom' will be added.
			);

			$tracker = FluentCrmApi( 'event_tracker' )->track( $data, true );

			return $tracker;

		}
	}
}
