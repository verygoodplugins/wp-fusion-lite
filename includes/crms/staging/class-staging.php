<?php

class WPF_Staging {

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

		$this->slug     = 'staging';
		$this->name     = 'Staging';
		$this->supports = array( 'add_tags', 'add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Staging_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Catchall for unknown methods.
	 *
	 * @since 3.37.3
	 *
	 * @param string $method The method.
	 * @param array  $args   The arguments.
	 */
	public function __call( $method, $args ) {

		wpf_log(
			'notice',
			wpf_get_current_user_id(),
			'Staging mode enabled (' . get_site_url() . '). Method <code>' . $method . '</code>.',
			array(
				'source'              => wp_fusion()->crm->slug,
				'meta_array_nofilter' => $args,
			)
		);

		return false;

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_url = null, $api_key = null, $test = false ) {

		return true;

	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		$this->connect();

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

		return wpf_get_option( 'available_tags', array() );

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		$fields = array(
			'email' => 'Email Address',
		);

		array_merge( wpf_get_option( 'crm_fields', array() ), $fields );

	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return false;
		}

		$staging_id = get_user_meta( $user->ID, WPF_CONTACT_ID_META_KEY, true );

		if ( ! empty( $staging_id ) ) {
			return $staging_id;
		} else {
			return false;
		}

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		$user_id = wp_fusion()->user->get_user_id( $contact_id );

		$staging_tags = get_user_meta( $user_id, WPF_TAGS_META_KEY, true );

		if ( ! empty( $staging_tags ) ) {
			return $staging_tags;
		} else {
			return array();
		}

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		return true;

	}


	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		// Generate a random contact ID.
		return 'staging_' . substr( md5( microtime() . wp_rand() ), 0, 10 );

	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		return true;

	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		return array();

	}

	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		return array();

	}

	/**
	 * Track event.
	 *
	 * @since 3.41.42
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		return true;

	}


}


