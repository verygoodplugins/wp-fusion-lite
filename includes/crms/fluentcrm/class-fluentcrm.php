<?php

class WPF_FluentCRM {

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.35
	 */

	public function __construct() {

		$this->slug     = 'fluentcrm';
		$this->name     = 'FluentCRM';
		$this->supports = array( 'add_lists' );

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

		// Don't watch FluentCRM for changes if staging mode is active

		if ( true == wp_fusion()->settings->get( 'staging_mode' ) || ! defined( 'FLUENTCRM' ) ) {
			return;
		}

		/*
		 * Global Tag and List Actions
		 */
		add_action( 'fluentcrm_list_created', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_list_deleted', array( $this, 'sync_lists' ), 10, 0 );
		add_action( 'fluentcrm_tag_created', array( $this, 'sync_tags' ), 10, 0 );
		add_action( 'fluentcrm_tag_deleted', array( $this, 'sync_tags' ), 10, 0 );

		/*
		 * Contact Specific Tag & List Actions
		 */
		add_action( 'fluentcrm_contact_added_to_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );
		add_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'contact_tags_added_removed' ), 10, 2 );

		/*
		 * List added to removed hook
		 * WPFusion May implement this in future
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
	 * Adjust field formatting
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( 'datepicker' == $field_type || 'date' == $field_type && ! empty( $value ) ) {

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
		$available_tags = [];
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
		$available_lists = [];
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
		$built_in_fields = FluentCrmApi( 'contacts' )->getInstance()->mappables();

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $this->get_custom_fields(),
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

	public function add_contact( $data, $map_meta_fields = true ) {
		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$custom_fields = $this->get_custom_fields();
		$custom_data   = array_filter( \FluentCrm\Includes\Helpers\Arr::only( $data, array_keys( $custom_fields ) ) );

		if ( $custom_data ) {
			$data['custom_values'] = $custom_data;
		}

		$lists = wp_fusion()->settings->get( 'fluentcrm_lists' );

		if ( ! empty( $lists ) ) {
			$data['lists'] = $lists;
		}

		$model = FluentCrmApi( 'contacts' )->getInstance();

		$contact = $model->updateOrCreate( $data );

		/*
		 * Now sure how WP Fusion handle double optin
		 * By default status is subscribed if status is not given
		 * If status is pending given in data then sending a double optin
		 */
		if ( 'pending' == $contact->status ) {
			$contact->sendDoubleOptinEmail();
		}

		return $contact->id;
	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {
		if ( $map_meta_fields ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$custom_fields = $this->get_custom_fields();
		$custom_data   = array_filter( \FluentCrm\Includes\Helpers\Arr::only( $data, array_keys( $custom_fields ) ) );

		$model = FluentCrmApi( 'contacts' )->getContact( $contact_id );

		$model->fill( $data )->save();
		if ( $custom_data ) {
			$model->syncCustomFieldValues( $custom_data, false );
		}

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

		// Map contact fields
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		// Standard fields
		foreach ( $fields as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset( $field_data['crm_field'] ) && $field_data['crm_field'] == $field_name && true == $field_data['active'] ) {
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

		$contact_ids = $model->filterByTags( [ $tag_id ] )->get()->pluck( 'id' );

		return $contact_ids;
	}

	/**
	 * @param $tags array
	 * @param $subscriber object
	 */
	public function contact_tags_added_removed( $tags, $subscriber ) {

		$user_id = $subscriber->user_id;

		if ( ! $user_id ) {
			// find the user ID from contact ID
			$user_id = wp_fusion()->user->get_user_id( $subscriber->id );
		}

		if ( $user_id ) {
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

	private function get_custom_fields() {
		$all_custom_fields = fluentcrm_get_option( 'contact_custom_fields', [] );
		$custom_fields     = [];
		if ( $all_custom_fields ) {
			foreach ( $all_custom_fields as $item ) {
				$custom_fields[ $item['slug'] ] = $item['label'];
			}
		}
		return $custom_fields;
	}
}
