<?php

class WPF_FluentCRM {

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
	 * @since   3.35
	 */

	public function __construct() {

		$this->slug      = 'fluentcrm';
		$this->name      = 'FluentCRM';
		$this->menu_name = 'FluentCRM (This Site)';
		$this->supports  = array( 'add_tags_api' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
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

		// Disable the API queue

		add_filter( 'wpf_get_setting_enable_queue', '__return_false' );

		// Sync global tag and list changes

		add_action( 'fluentcrm_list_created', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_list_deleted', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_tag_created', array( $this, 'sync_tags' ), 10, 0 );
		add_action( 'fluentcrm_tag_deleted', array( $this, 'sync_tags' ), 10, 0 );

		// Sync contact tags when modified

		add_action( 'fluentcrm_contact_added_to_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );
		add_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

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

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = absint( $payload->id );
		$post_data['tags']       = wp_list_pluck( (array) $payload->tags, 'slug' );

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

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

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
			return $contact->id;
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

		// Prevent looping
		remove_action( 'fluentcrm_contact_added_to_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'error', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		$contact->attachTags( $tags );

		add_action( 'fluentcrm_contact_added_to_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

		return true;
	}


	/**
	 * Removes tags from a contact. This uses the old API since the v3 API only uses tag IDs
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		// Prevent looping
		remove_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

		$contact = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'error', 'No contact ID #' . $contact_id . ' found in FluentCRM.' );
		}

		$contact->detachTags( $tags );

		add_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

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

		$lists = wpf_get_option( 'fluentcrm_lists' );

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
		$data  = array(
			'title' => $tag_name,
			'slug'  => $tag_name,
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

	public function load_contacts( $tag_id ) {

		$model = FluentCrmApi( 'contacts' )->getInstance();

		$contact_ids = $model->filterByTags( array( $tag_id ) )->get()->pluck( 'id' );

		return $contact_ids->all();
	}

	/**
	 * @param $tags array
	 * @param $subscriber object
	 */
	public function contact_tags_added_removed( $tags, $subscriber ) {

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
			wp_fusion()->logger->add_source( 'fluentcrm' );
			wp_fusion()->user->set_tags( $tags, $user_id );
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
}
