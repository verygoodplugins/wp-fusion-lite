<?php

class WPF_KlickTipp {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;


	/**
	 * Lets us link directly to editing a contact record.
	 * No trials only paid plans.
	 * @var string
	 */

	public $edit_url = false;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'klick-tipp';
		$this->name     = 'Klick-Tipp';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_KlickTipp_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {}

	/**
	 * Klick-Tipp requires an email to be submitted when tags are applied/removed
	 *
	 * @access private
	 * @return string Email
	 */

	private function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => 'klick-tipp_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			// Try an API call

			if ( is_wp_error( $this->connect() ) ) {
				return new WP_Error( 'error', $this->app->get_last_error() );
			}

			$result = $this->app->subscriber_get( $contact_id );

			if ( ! empty( $result ) ) {
				return $result->email;
			} else {
				return false;
			}
		}

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $username = null, $password = null, $test = false ) {

		if ( is_object( $this->app ) && false == $test ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $username ) || empty( $password ) ) {
			$username = wpf_get_option( 'klicktipp_user' );
			$password = wpf_get_option( 'klicktipp_pass' );
		}

		require_once dirname( __FILE__ ) . '/includes/klicktipp.api.inc';

		$this->app = new WPF_KlicktippConnector();
		$result    = $this->app->login( $username, $password );

		if ( true !== $result ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		return $this->app;

	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$available_tags = $this->app->tag_index();

		asort( $available_tags );

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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$crm_fields = array( 'email' => 'Email' );

		$crm_fields = array_merge( $crm_fields, $this->app->field_index() );

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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$result = $this->app->subscriber_search( $email_address );

		if ( $result ) {
			return $result;
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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$result = $this->app->subscriber_get( $contact_id );

		if ( ! empty( $result ) && ! empty( $result->tags ) ) {
			return $result->tags;
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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$email = $this->get_email_from_cid( $contact_id );

		if ( false === $email ) {
			return new WP_Error( 'error', 'Unable to find email address for contact ID ' . $contact_id . '. Can\'t apply tags.' );
		}

		foreach ( $tags as $tag_id ) {

			$result = $this->app->tag( $email, $tag_id );

		}

		if ( true !== $result ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$email = $this->get_email_from_cid( $contact_id );

		if ( false === $email ) {
			return new WP_Error( 'error', 'Unable to find email address for contact ID ' . $contact_id . '. Can\'t remove tags.' );
		}

		foreach ( $tags as $tag_id ) {

			$result = $this->app->untag( $email, $tag_id );

		}

		if ( true !== $result ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$email = $data['email'];
		unset( $data['email'] );

		$result = $this->app->subscribe( $email, false, false, $data );

		if ( ! isset( $result->id ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		return $result->id;

	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		if ( isset( $data['email'] ) ) {
			$email = $data['email'];
			unset( $data['email'] );
		} else {
			$email = false;
		}

		$result = $this->app->subscriber_update( $contact_id, $data, $email );

		if ( true !== $result ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
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

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$result = $this->app->subscriber_get( $contact_id );

		$user_meta = array();

		// Map contact fields
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $result as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset( $field_data['crm_field'] ) && $field_data['crm_field'] == $field_name && $field_data['active'] == true ) {
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

	public function load_contacts( $tag ) {

		if ( is_wp_error( $this->connect() ) ) {
			return new WP_Error( 'error', $this->app->get_last_error() );
		}

		$contact_ids = array();

		$result = $this->app->subscriber_tagged( $tag );

		if ( ! empty( $result ) ) {

			foreach ( $result as $contact_id => $subscription_timestamp ) {
				$contact_ids[] = $contact_id;
			}
		}

		return $contact_ids;

	}

}
