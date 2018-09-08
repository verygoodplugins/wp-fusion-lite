<?php

class WPF_Drip {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Account ID (used for API queries)
	 */

	private $account_id;

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

		$this->slug     = 'drip';
		$this->name     = 'Drip';
		$this->supports = array( 'add_tags', 'add_fields', 'safe_add_fields' );

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Drip_Admin( $this->slug, $this->name, $this );
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

		// Add tracking code to footer
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );

	}
	

	/**
	 * Formats user entered data to match Drip field formats
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

			return stripslashes($value);

		}

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if(isset($post_data['contact_id']))
			return $post_data;

		$drip_payload = json_decode( file_get_contents( 'php://input' ) );

		if ( isset($drip_payload->event) && ($drip_payload->event == 'subscriber.applied_tag' || $drip_payload->event == 'subscriber.removed_tag' || $drip_payload->event == 'subscriber.updated_custom_field' || $drip_payload->event == 'subscriber.updated_email_address') ) {

			// Admin settings webhooks
			$post_data['contact_id'] = $drip_payload->data->subscriber->id;
			return $post_data;

		} elseif( isset($drip_payload->subscriber) ) {

			// Automations / rules triggers
			$post_data['contact_id'] = $drip_payload->subscriber->id;
			return $post_data;

		} else {
			wp_die( 'Unsupported method', 'Success', 200 );
		}

	}

	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if( wp_fusion()->settings->get( 'site_tracking' ) == false )
			return;

		$account_id = wp_fusion()->settings->get('drip_account');

		echo "<!-- Drip -->";
		echo '<script type="text/javascript">';
		echo "var _dcq = _dcq || [];";
		echo "var _dcs = _dcs || {};";
		echo "_dcs.account = '" . $account_id . "';";

		echo "(function() {";
		echo "var dc = document.createElement('script');";
		echo "dc.type = 'text/javascript'; dc.async = true;";
		echo "dc.src = '//tag.getdrip.com/" . $account_id . ".js';";
		echo "var s = document.getElementsByTagName('script')[0];";
		echo "s.parentNode.insertBefore(dc, s);";
		echo "})();";
		echo "</script>";
		echo "<!-- end Drip -->";

	}


	/**
	 * Drip requires an email to be submitted when contacts are updated
	 *
	 * @access public
	 * @return string Email
	 */

	public function get_email_from_cid( $contact_id ) {

		if(empty($contact_id))
			return false;

		$users = get_users( array(
			'meta_key'   => 'drip_contact_id',
			'meta_value' => $contact_id,
			'fields'     => array( 'user_email' )
		) );

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			$this->connect();
			
			// Try and get CID from Drip

			$result = $this->app->fetch_subscriber( array(
				'account_id'    => $this->account_id,
				'subscriber_id' => $contact_id
			) );

			if(!empty($result) && !empty($result['email'])) {
				return $result['email'];
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

	public function connect( $api_token = null, $account_id = null, $test = false ) {

		// If app is already running, don't try and restart it.
		if ( is_object( $this->app ) ) {
			return true;
		}

		if ( empty( $api_token ) || empty( $account_id ) ) {
			$api_token  = wp_fusion()->settings->get( 'drip_token' );
			$account_id = wp_fusion()->settings->get( 'drip_account' );
		}

		require dirname( __FILE__ ) . '/includes/Drip_API.class.php';
		$app = new WPF_Drip_API( $api_token );

		if ( $test == true ) {

			$accounts = $app->get_accounts();

			if( is_wp_error( $accounts ) ) {
				return $accounts;
			}

			$valid_id = false;
			foreach ( $accounts as $account ) {
				if ( $account['id'] == $account_id ) {
					$valid_id = true;
				}
			}

			if ( $valid_id == false ) {
				return new WP_Error( 'error', __( 'Access denied: Your API token doesn\'t have access to this account.', 'wp-fusion' ) );
			}

		}

		$this->account_id = $account_id;
		$this->app        = $app;

		return true;
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
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
	 * @return array Lists
	 */

	public function sync_tags() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$url      = 'https://api.getdrip.com/v2/' . $this->account_id . '/tags/';
		$response = $this->app->make_request( $url );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( $response['buffer'] );

		$available_tags = array();

		if ( ! empty( $response->tags ) ) {
			foreach ( $response->tags as $tag ) {
				$available_tags[ $tag ] = $tag;
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

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$url      = 'https://api.getdrip.com/v2/' . $this->account_id . '/custom_field_identifiers/';
		$response = $this->app->make_request( $url );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( $response['buffer'] );

		$crm_fields = array('email' => 'email');

		if ( ! empty( $response->custom_field_identifiers ) ) {
			foreach ( $response->custom_field_identifiers as $field_id ) {
				$crm_fields[ $field_id ] = $field_id;
			}
		}

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

		$result = $this->app->fetch_subscriber( array( 'account_id' => $this->account_id, 'email' => $email_address ) );

		if( is_wp_error( $result ) ) {

			// If no contact with that email
			return false;

		}

		if ( empty( $result ) || ! isset( $result['id'] ) ) {
			return false;
		}

		return $result['id'];

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		$this->connect();

		$result = $this->app->fetch_subscriber( array(
			'account_id'    => $this->account_id,
			'subscriber_id' => $contact_id
		) );

		if( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) || empty( $result['tags'] ) ) {
			return false;
		}

		// Set available tags
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		if ( ! is_array( $available_tags ) ) {
			$available_tags = array();
		}

		foreach ( $result['tags'] as $tag ) {

			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
			}

		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $result['tags'];

	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$email = $this->get_email_from_cid( $contact_id );

		if($email == false)
			return false;

		$this->connect();

		foreach ( $tags as $tag ) {

			$result = $this->app->tag_subscriber( array(
				'account_id' => $this->account_id,
				'email'      => $email,
				'tag'        => $tag
			) );

			if( is_wp_error( $result ) ) {
				return $result;
			}

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

		$email = $this->get_email_from_cid( $contact_id );

		if($email == false)
			return false;

		$this->connect();

		foreach ( $tags as $tag ) {

			$result = $this->app->untag_subscriber( array(
				'account_id' => $this->account_id,
				'email'      => $email,
				'tag'        => $tag
			) );

			if( is_wp_error( $result ) ) {
				return $result;
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

	public function add_contact( $data, $map_meta_fields = true ) {

		$this->connect();

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$email = $data['email'];
		unset( $data['email'] );

		$params = array(
			'account_id'    => $this->account_id,
			'email'         => $email,
			'custom_fields' => $data
		);

		$result = $this->app->create_or_update_subscriber( $params );

		if( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['id'];

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

		if( empty( $data ) ) {
			return false;
		}

		if( isset( $data['email'] ) ) {
			$provided_email = $data['email'];
			unset($data['email']);
		}

		$params = array(
			'account_id'    => $this->account_id,
			'id'            => $contact_id,
			'custom_fields' => $data
		);

		$result = $this->app->create_or_update_subscriber( $params );

		if( is_wp_error( $result ) ) {
			return $result;
		}

		// Check if we need to change the email address
		if( isset($provided_email) && $result['email'] != $provided_email ) {

			$params['new_email'] = $provided_email;

			$result = $this->app->create_or_update_subscriber( $params );

			if( is_wp_error( $result ) ) {
				return $result;
			}

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

		$result = $this->app->fetch_subscriber( array(
			'account_id'    => $this->account_id,
			'subscriber_id' => $contact_id
		) );

		if( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return false;
		}

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$user_meta      = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $result['custom_fields'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $result['custom_fields'][ $field_data['crm_field'] ];
			}

			if ( $field_id == 'user_email' ) {
				$user_meta['user_email'] = $result['email'];
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

		// Load all subscribers
		$url      = 'https://api.getdrip.com/v2/' . $this->account_id . '/subscribers/?tags=' . urlencode($tag) . '&per_page=1000';
		$result = $this->app->make_request( $url );

		if( is_wp_error( $result ) ) {
			return $result;
		}

		$result = json_decode( $result['buffer'] );

		if ( empty( $result->subscribers ) ) {
			return false;
		}

		foreach ( $result->subscribers as $subscriber ) {
			$contact_ids[] = $subscriber->id;
		}

		return $contact_ids;

	}

}