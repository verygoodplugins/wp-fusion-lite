<?php

class WPF_AWeber {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	public $account;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * AWeber OAuth stuff
	 */

	public $consumer_key;

	public $consumer_secret;

	public $access_token;

	public $access_secret;

	/**
	 * List to use
	 */

	public $list;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'aweber';
		$this->name     = 'AWeber';
		$this->supports = array( 'add_tags', 'add_lists' );

		// OAuth
		$this->consumer_key 	= 'AkBvf5qeaNiprca16o4FVeX1';
		$this->consumer_secret 	= 'lkPZtfd6tvblkTxFD5eWlYpiufHLDAWYc9txyfOV';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_AWeber_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Slow down the batch processses to get around the 120 requests per minute limit
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

	}

	/**
	 * Slow down batch processses to get around the 3600 requests per hour limit
	 *
	 * @access public
	 * @return int Sleep time
	 */

	public function set_sleep_time( $seconds ) {

		return 1;
		
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id'])) {
			return $post_data;
		}

		$post_data['contact_id'] = $post_data['contact']['id'];

		return $post_data;

	}


	/**
	 * Formats user entered data to match AWeber field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

		} else {

			return $value;

		}

	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_token = null, $access_secret = null, $test = false ) {

		if ( ! empty( $this->app ) && $test == false ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $access_token ) || empty( $access_secret ) ) {
			$access_token = wp_fusion()->settings->get( 'aweber_token' );
			$access_secret = wp_fusion()->settings->get( 'aweber_secret' );
			$list = wp_fusion()->settings->get('aweber_list');
		}

		if( ! class_exists( 'AWeberAPI' ) ) {
			require dirname( __FILE__ ) . '/includes/aweber.php';
		}

		$this->consumer_key    = apply_filters( 'wpf_aweber_key', $this->consumer_key );
		$this->consumer_secret = apply_filters( 'wpf_aweber_secret', $this->consumer_secret );

		$app = new AWeberAPI($this->consumer_key, $this->consumer_secret);

		if ( $test == true ) {

			try {
				$this->account = $app->getAccount( $access_token, $access_secret );
			} catch (Exception $e) {
				return new WP_Error( 'error', $e->getMessage() );
			}

		} elseif( ! empty( $access_token ) && ! empty( $access_secret ) ) {
			$this->account = $app->getAccount( $access_token, $access_secret );
		}

		// Connection was a success
		$this->app = $app;
		$this->access_token = $access_token;
		$this->access_secret = $access_secret;
		$this->list = $list;

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

		$this->sync_lists();
		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}

	/**
	 * Gets all available lists and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_lists() {

		$this->connect();

		$available_lists = array();

		foreach( $this->account->lists->data['entries'] as $list ) {
			$available_lists[ $list['id'] ] = $list['name'];
		}

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		if( empty( $this->list ) ) {
			wp_fusion()->settings->set( 'aweber_list', $this->account->lists->data['entries'][0]['id'] );
			$this->list = $this->account->lists->data['entries'][0]['id'];
		}

		return $available_lists;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Tags
	 */

	public function sync_tags() {

		$this->connect();

		$available_tags = array();

		foreach( $this->account->lists as $list ) {

			if( $list->data['id'] == $this->list ) {

				foreach( $list->subscribers as $subscriber ) {

					foreach( $subscriber->data['tags'] as $tag ) {

						$available_tags[$tag] = $tag;

					}

				}

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

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/aweber-fields.php';
		$crm_fields = array();

		foreach( $aweber_fields as $field ) {
			$crm_fields[$field['crm_field']] = $field['crm_label'];
		}

		$this->connect();

		foreach( $this->account->lists as $list ) {

			if( $list->data['id'] == $this->list ) {

				foreach( $list->custom_fields->data['entries'] as $field ) {
					$crm_fields[$field['name']] = $field['name'];
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

		$this->connect();

		try {

			$subscriber =  $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/?ws.op=find&email=' . $email_address);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		if ( ! empty( $subscriber->data['entries'] ) ) {
			return $subscriber->data['entries'][0]['id'];
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

		$this->connect();

		try {

			$subscriber =  $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/' . $contact_id);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		if( empty( $subscriber->data ) || empty( $subscriber->data['tags'] ) ) {
			return array();
		}

		// Merge tags with available ones
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		if ( ! is_array( $available_tags ) ) {
			$available_tags = array();
		}

		foreach ( $subscriber->data['tags'] as $tag ) {
			$available_tags[ $tag ] = $tag;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $subscriber->data['tags'];

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$this->connect();

		try {

			$subscriber = $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/' . $contact_id);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );

		}

		$subscriber->tags = array(
			'add' => $tags
		);

		$subscriber->save();

		// Possibly update available tags if it's a newly created one
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		foreach ( $tags as $tag ) {

			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
				$needs_update           = true;
			}
		}

		if ( isset( $needs_update ) ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
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

			$subscriber = $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/' . $contact_id);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
			
		}

		$subscriber->tags = array(
			'remove' => $tags
		);

		$subscriber->save();

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$params = array( 'custom_fields' => array(), 'subscription_method' => 'WP Fusion' );
		$builtin_fields = array( 'name', 'email', 'postal_code', 'city', 'country' );

		foreach( $data as $key => $value ) {

			if( in_array($key, $builtin_fields) ) {
				$params[$key] = $value;
			} else {
				$params['custom_fields'][$key] = $value;
			}

		}

		if( empty( $params['custom_fields'] ) ) {
			unset( $params['custom_fields'] );
		}

		try {

			$list = $this->account->loadFromUrl('/accounts/' . $this->account->id . '/lists/' . $this->list);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
			
		}

		try {

			$subscriber = $list->subscribers->create( $params );

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );

		}
		

		return $subscriber->data['id'];

	}


	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		try {

			$subscriber = $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/' . $contact_id);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
		}

		$builtin_fields = array( 'name', 'email', 'postal_code', 'city', 'country' );

		foreach( $data as $key => $value ) {

			if( in_array($key, $builtin_fields) ) {
				$subscriber->{$key} = $value;
			} else {
				$subscriber->{'custom_fields'}[$key] = $value;
			}

		}

		try {

			$subscriber->save();

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
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

			$subscriber = $this->app->loadFromUrl('https://api.aweber.com/1.0/accounts/' . $this->account->id . '/lists/' . $this->list . '/subscribers/' . $contact_id);

		} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
			
		}

		if( empty( $subscriber->data ) ) {
			return new WP_Error( 'error', 'No subscriber found with contact ID ' . $contact_id );
		}

		$user_meta = array();

		// Map contact fields
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		// Standard fields
		foreach ( $subscriber->data as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset($field_data['crm_field']) && $field_data['crm_field'] == $field_name && $field_data['active'] == true ) {
					$user_meta[ $meta_key ] = $value;
				}

			}

		}

		// Custom fields
		foreach ( $subscriber->data['custom_fields'] as $field_name => $value ) {

			foreach ( $contact_fields as $meta_key => $field_data ) {

				if ( isset($field_data['crm_field']) && $field_data['crm_field'] == $field_name && $field_data['active'] == true ) {
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

		$this->connect();

		$contact_ids = array();

		try {

    		$subscribers = $this->account->findSubscribers( array( 'tags' => array( $tag ) ) );

    	} catch (Exception $e) {

			return new WP_Error( 'error', $e->getMessage() );
			
		}

    	if( ! empty( $subscribers->data['entries'] ) ) {

    		foreach( $subscribers->data['entries'] as $subscriber ) {

    			$contact_ids[] = $subscriber['id'];

    		}

    	}

		return $contact_ids;

	}


}