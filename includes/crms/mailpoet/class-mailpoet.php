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
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */
	public function __construct() {

		$this->name = __( 'MailPoet', 'wp-fusion-lite' ); // lets people translate it.

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_MailPoet_Admin( $this->slug, $this->name, $this );
		}
	}


	/**
	 * Get things started
	 *
	 * @since 3.46.4 Added hooks for bidirectional sync with MailPoet tags and lists.
	 *
	 * @access  public
	 * @return  void
	 */
	public function init() {

		// Disable the API queue.
		add_filter( 'wpf_get_setting_enable_queue', '__return_false' );
		// Don't use the tag cache / prevent re-applying tags.
		add_filter( 'wpf_get_setting_prevent_reapply', '__return_false' );

		// Don't sync changes if staging mode is active.
		if ( wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		// Bidirectional sync for lists and tags.
		add_action( 'mailpoet_subscriber_deleted', array( $this, 'subscriber_deleted' ) );

		// Bidirectional sync for tags.
		add_action( 'mailpoet_subscriber_tag_added', array( $this, 'tag_added_removed' ) );
		add_action( 'mailpoet_subscriber_tag_removed', array( $this, 'tag_added_removed' ) );
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
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */
	public function connect( $test = false ) {

		if ( ! class_exists( \MailPoet\API\API::class ) ) {

			return new WP_Error( 'error', 'MailPoet plugin not active.' );

		}

		$this->app = \MailPoet\API\API::MP( 'v1' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @since 3.46.4 Added support for MailPoet tags in addition to lists.
	 *
	 * @access public
	 * @return array Lists and tags
	 */
	public function sync_tags() {

		$this->connect();

		$available_tags = array();

		// Add hardcoded lists that don't get returned by getLists().
		$available_tags['1'] = array(
			'label'    => 'WordPress Users',
			'category' => 'Lists',
		);

		$available_tags['2'] = array(
			'label'    => 'WooCommerce Customers',
			'category' => 'Lists',
		);

		// Get all lists (segments).
		$lists = $this->app->getLists();

		foreach ( $lists as $list ) {
			$available_tags[ $list['id'] ] = array(
				'label'    => $list['name'],
				'category' => 'Lists',
			);
		}

		// Get all tags via JSON API.
		if ( class_exists( '\MailPoet\API\JSON\v1\Tags' ) ) {
			try {
				$tags_api      = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\API\JSON\v1\Tags' );
				$tags_response = $tags_api->listing();

				if ( ! empty( $tags_response->data ) ) {
					foreach ( $tags_response->data as $tag ) {
						$available_tags[ 'tag_' . $tag['id'] ] = array(
							'label'    => $tag['name'],
							'category' => 'Tags',
						);
					}
				}
			} catch ( Exception $e ) {
				wpf_log( 'error', 0, 'Error fetching MailPoet tags: ' . $e->getMessage() );
			}
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
	 * @since 3.46.4 Added support for MailPoet tags in addition to lists.
	 *
	 * @access public
	 * @return array User tags and lists
	 */
	public function get_tags( $contact_id ) {

		$this->connect();

		$user_tags = array();

		try {

			$contact = $this->app->getSubscriber( $contact_id );

			// Get list memberships (segments).
			$list_ids  = wp_list_pluck( $contact['subscriptions'], 'segment_id' );
			$user_tags = array_merge( $user_tags, $list_ids );

			// Get tag memberships if available.
			if ( ! empty( $contact['tags'] ) ) {
				foreach ( $contact['tags'] as $tag ) {
					$user_tags[] = 'tag_' . $tag['id'];
				}
			}
		} catch ( Exception $e ) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		return $user_tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @since 3.46.4 Added support for MailPoet tags in addition to lists.
	 *
	 * @access public
	 * @return bool
	 */
	public function apply_tags( $tags, $contact_id ) {

		$this->connect();

		$send_confirmation = wpf_get_option( 'mailpoet_send_confirmation', true );

		// Separate lists and tags.
		$lists   = array();
		$tag_ids = array();

		foreach ( $tags as $tag_id ) {
			if ( 0 === strpos( $tag_id, 'tag_' ) ) {
				$tag_ids[] = str_replace( 'tag_', '', $tag_id );
			} else {
				$lists[] = $tag_id;
			}
		}

		// Subscribe to lists.
		if ( ! empty( $lists ) ) {
			try {
				$options = array(
					'send_confirmation_email'      => $send_confirmation,
					'schedule_welcome_email'       => true,
					'skip_subscriber_notification' => true,
				);

				$result = $this->app->subscribeToLists( $contact_id, $lists, $options );

			} catch ( Exception $e ) {

				return new WP_Error( 'error', $e->getMessage() );

			}
		}

		// Apply tags via repository.
		if ( ! empty( $tag_ids ) && class_exists( '\MailPoet\Subscribers\SubscriberTagRepository' ) ) {
			try {
				$subscriber_tag_repository = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Subscribers\SubscriberTagRepository' );
				$subscriber_repository     = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Subscribers\SubscribersRepository' );
				$tag_repository            = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Tags\TagRepository' );

				$subscriber = $subscriber_repository->findOneById( $contact_id );
				if ( ! $subscriber ) {
					return new WP_Error( 'error', 'Subscriber not found' );
				}

				foreach ( $tag_ids as $tag_id ) {
					$tag = $tag_repository->findOneById( $tag_id );
					if ( $tag ) {
						// Check if relationship already exists
						$existing_relation = $subscriber_tag_repository->findOneBy(
							array(
								'subscriber' => $subscriber,
								'tag'        => $tag,
							)
						);

						if ( ! $existing_relation ) {
							$subscriber_tag = new \MailPoet\Entities\SubscriberTagEntity( $tag, $subscriber );
							$subscriber_tag_repository->persist( $subscriber_tag );
						}
					}
				}

				$subscriber_tag_repository->flush();
			} catch ( Exception $e ) {
				wpf_log( 'error', wpf_get_current_user_id(), 'Error applying MailPoet tags: ' . $e->getMessage() );
			}
		}

		return true;
	}


	/**
	 * Removes tags from a contact
	 *
	 * @since 3.46.4 Added support for MailPoet tags in addition to lists.
	 *
	 * @access public
	 * @return bool
	 */
	public function remove_tags( $tags, $contact_id ) {

		$this->connect();

		// Separate lists and tags.
		$lists   = array();
		$tag_ids = array();

		foreach ( $tags as $tag_id ) {
			if ( 0 === strpos( $tag_id, 'tag_' ) ) {
				$tag_ids[] = str_replace( 'tag_', '', $tag_id );
			} else {
				$lists[] = $tag_id;
			}
		}

		// Unsubscribe from lists.
		if ( ! empty( $lists ) ) {
			try {
				$contact = $this->app->unsubscribeFromLists( $contact_id, $lists );
			} catch ( Exception $e ) {
				return new WP_Error( 'error', $e->getMessage() );
			}
		}

		// Remove tags via repository.
		if ( ! empty( $tag_ids ) && class_exists( '\MailPoet\Subscribers\SubscriberTagRepository' ) ) {
			try {
				$subscriber_tag_repository = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Subscribers\SubscriberTagRepository' );
				$subscriber_repository     = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Subscribers\SubscribersRepository' );
				$tag_repository            = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Tags\TagRepository' );

				$subscriber = $subscriber_repository->findOneById( $contact_id );
				if ( ! $subscriber ) {
					return new WP_Error( 'error', 'Subscriber not found' );
				}

				foreach ( $tag_ids as $tag_id ) {
					$tag = $tag_repository->findOneById( $tag_id );
					if ( $tag ) {
						// Find the existing relationship
						$existing_relation = $subscriber_tag_repository->findOneBy(
							array(
								'subscriber' => $subscriber,
								'tag'        => $tag,
							)
						);

						if ( $existing_relation ) {
							$subscriber_tag_repository->remove( $existing_relation );
						}
					}
				}

				$subscriber_tag_repository->flush();
			} catch ( Exception $e ) {
				wpf_log( 'error', wpf_get_current_user_id(), 'Error removing MailPoet tags: ' . $e->getMessage() );
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

		// Use the new API method if it's available.
		if ( method_exists( $this->app, 'updateSubscriber' ) ) {
			$this->app->updateSubscriber( $contact_id, $data );
		} else {
			\MailPoet\Models\Subscriber::createOrUpdate( $data );
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

	/**
	 * Remove tags when a subscriber is deleted in MailPoet.
	 *
	 * @since 3.46.4
	 *
	 * @param int $subscriber_id The subscriber ID.
	 */
	public function subscriber_deleted( $subscriber_id ) {

		// Find user by subscriber ID.
		$users = get_users(
			array(
				'meta_key'   => 'mailpoet_subscriber_id',
				'meta_value' => $subscriber_id,
				'fields'     => 'ID',
			)
		);

		if ( ! empty( $users ) ) {
			foreach ( $users as $user_id ) {
				// Clear the user's tags.
				wp_fusion()->user->set_tags( array(), $user_id );
			}
		}
	}

	/**
	 * Sync tags when a tag is added or removed from a subscriber in MailPoet.
	 *
	 * @since 3.46.4
	 *
	 * @param SubscriberTagEntity $subscriber_tag The subscriber tag entity.
	 */
	public function tag_added_removed( $subscriber_tag ) {

		if ( ! is_object( $subscriber_tag ) || ! method_exists( $subscriber_tag, 'getSubscriber' ) ) {
			return;
		}

		$subscriber = $subscriber_tag->getSubscriber();
		if ( ! $subscriber ) {
			return;
		}

		// Get the WordPress user ID.
		$user_id = $subscriber->getWpUserId();
		if ( empty( $user_id ) ) {
			// Try to find user by email.
			$user = get_user_by( 'email', $subscriber->getEmail() );
			if ( $user ) {
				$user_id = $user->ID;
			}
		}

		if ( ! empty( $user_id ) ) {
			// Refresh the user's tags from MailPoet.
			wp_fusion()->user->get_tags( $user_id, true, false );
		}
	}
}
