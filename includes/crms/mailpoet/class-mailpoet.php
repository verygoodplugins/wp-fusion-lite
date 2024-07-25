<?php

class WPF_MailPoet {


	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'mailpoet';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'MailPoet';

	/**
	 * Contains API params
	 */
	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */
	public $supports = array();

	/**
	 * MailPoet API
	 */
	public $app;

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */
	public $tag_type = 'List';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->name = __( 'MailPoet', 'wp-fusion-lite' ); // lets people translate it.

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_MailPoet_Admin( $this->slug, $this->name, $this );
		}

	}


	/**
	 * Get things started
	 *
	 * @access  public
	 * @return  void
	 */

	public function init() {}


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
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $test = false ) {

		if ( true == $test && ! class_exists( \MailPoet\API\API::class ) ) {

			return new WP_Error( 'error', 'MailPoet plugin not active.' );

		}

		$this->app = \MailPoet\API\API::MP( 'v1' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		$this->connect();

		$available_tags = array();

		$lists = $this->app->getLists();

		foreach ( $lists as $list ) {
			$available_tags[ $list['id'] ] = $list['name'];
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

		$this->connect();

		$crm_fields = array();

		$fields = $this->app->getSubscriberFields();

		foreach ( $fields as $field ) {
			$crm_fields[ $field['id'] ] = $field['name'];
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

		$this->connect();

		try {

			$contact = $this->app->getSubscriber( $email_address );

		} catch ( Exception $e ) {

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

		$this->connect();

		$tags = array();

		try {

			$contact = $this->app->getSubscriber( $contact_id );

		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		return wp_list_pluck( $contact['subscriptions'], 'segment_id' );

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$this->connect();

		$send_confirmation = wpf_get_option( 'mailpoet_send_confirmation', true );

		try {

			$options = array(
				'send_confirmation_email'      => $send_confirmation,
				'schedule_welcome_email'       => true,
				'skip_subscriber_notification' => true,
			);

			$result = $this->app->subscribeToLists( $contact_id, $tags, $options );

		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

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

		$this->connect();

		try {

			$contact = $this->app->unsubscribeFromLists( $contact_id, $tags );

		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

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

		$this->connect();

		try {

			$contact = $this->app->addSubscriber( $data );

		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		return $contact['id'];

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		$this->connect();

		$data['id'] = $contact_id;

		$this->app->updateSubscriber( $data['email'], $data );

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$this->connect();

		try {

			$contact = $this->app->getSubscriber( $contact_id );

		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

		}

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

		// Not supported
	}

}
