<?php

class WPF_Infusionsoft_iSDK {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Holds connection errors
	 */

	private $error;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @var string
	 * @since 3.36.10
	 */

	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function __construct() {

		$this->slug      = 'infusionsoft';
		$this->name      = 'Infusionsoft';
		$this->menu_name = 'Infusionsoft';
		$this->supports  = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Infusionsoft_iSDK_Admin( $this->slug, $this->name, $this );
		}

	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );
		add_action( 'wpf_contact_updated', array( $this, 'send_api_call' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_auto_login_query_var', array( $this, 'auto_login_query_var' ) );
		add_filter( 'random_password', array( $this, 'generate_password' ) );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

		// Set edit link
		$app_name = wp_fusion()->settings->get( 'app_name' );

		if ( ! empty( $app_name ) ) {
			$this->edit_url = 'https://' . $app_name . '.infusionsoft.com/Contact/manageContact.jsp?view=edit&ID=%s';
		}

	}

	/**
	 * Register cookies allowed in the async process
	 *
	 * @access public
	 * @return array Cookies
	 */

	public function allowed_cookies( $cookies ) {

		$cookies[] = 'is_aff';
		$cookies[] = 'is_affcode';

		return $cookies;

	}

	/**
	 * Infusionsoft default password field is limited to 16 chars so we'll keep WP passwords shorter than 16 chars as well
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function generate_password( $password ) {

		if(is_admin()) {
			$password = substr( $password, 0, 16 );
		}

		return $password;

	}


	/**
	 * Output tracking code
	 *
	 * @access public
	 * @return mixed
	 */

	public function tracking_code_output() {

		if ( false == wp_fusion()->settings->get( 'site_tracking' ) || true == wp_fusion()->settings->get( 'staging_mode' ) ) {
			return;
		}

		echo '<script type="text/javascript" src="https://' . wp_fusion()->settings->get('app_name') . '.infusionsoft.com/app/webTracking/getTrackingCode"></script>';

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contactId'] ) ) {
			$post_data['contact_id'] = $post_data['contactId'];
		}

		return $post_data;

	}

	/**
	 * Allow using contactId query var for auto login (redirect from Infusionsoft forms)
	 *
	 * @access public
	 * @return array
	 */

	public function auto_login_query_var( $var ) {

		return 'contactId';

	}


	/**
	 * Formats user entered data to match Infusionsoft field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( !is_array( $value ) & strpos($value, '&') !== false  ) {
			$value = str_replace('&', '&amp;', $value);
		}

		if ( $field_type == 'datepicker' || $field_type == 'date' && ! empty( $value ) ) {

			// Adjust formatting for date fields
			$date = date( "Ymd\T00:00:00", $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ',', array_filter( $value ) );

		} elseif ( $field_type == 'country' ) {

			$countries = include dirname( __FILE__ ) . '/includes/countries.php';

			if( isset( $countries[$value] ) ) {

				return $countries[$value];

			} else {

				return $value;

			}

		} else {

			return $value;

		}

	}


	/**
	 * Maps local field types to IS field types
	 *
	 * @access public
	 * @return string
	 */

	public function map_field_types( $field_type ) {

		switch ( $field_type ) {
			case 'text':
				return 'Text';

			case 'select':
				return 'Select';

			case 'multiselect':
				return 'MultiSelect';

			case 'textarea':
				return 'TextArea';

			case 'datepicker':
				return 'Date';

			case 'checkbox':
				return 'YesNo';

			case 'checkbox-full':
				return 'YesNo';

			case 'radio':
				return 'Radio';

			case 'radio-full':
				return 'Radio';

			default:
				// In case no matching datatype is found, fall back to plain text
				return "Text";
		}

	}


	/*
	 * Connect
	 *
	 * Initialize connection to Infusionsoft
	 *
	 * @return mixed
	 */

	public function connect( $app_name = null, $api_key = null, $test = false ) {

		// If app is already running, don't try and restart it.
		if ( is_object( $this->app ) ) {
			return true;
		}

		require dirname( __FILE__ ) . '/includes/isdk.php';

		$app = new WPF_iSDK;

		// Get saved data from DB
		if ( empty( $app_name ) && empty( $api_key ) ) {
			$app_name = wp_fusion()->settings->get( 'app_name' );
			$api_key  = wp_fusion()->settings->get( 'api_key' );
		}

		$result = $app->cfgCon( $app_name, $api_key, 'off' );

		if( is_wp_error( $result ) ) {
			$this->error = $result;
			return new WP_Error( 'error', __( $result->get_error_message() . '. Please verify your connection details are correct.', 'wp-fusion-lite' ) );
		}

		$this->app = $app;

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
			return $this->error;
		}

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Loads all available tags and categories from the CRM and saves them locally
	 *
	 * @access public
	 * @return array Tags
	 */

	public function sync_tags() {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// Retrieve tag categories

		$fields = array( 'CategoryName', 'Id' );
		$query  = array( 'Id' => '%' );

		$tags = array();

		$categories = $this->app->dsQuery( 'ContactGroupCategory', 1000, 0, $query, $fields );

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$fields = array( 'Id', 'GroupName', 'GroupCategoryId' );

		foreach ( $categories as $category ) {

			// Retrieve tags
			$query  = array( 'GroupCategoryId' => $category['Id'] );
			$result = $this->app->dsQuery( 'ContactGroup', 1000, 0, $query, $fields );

			if ( is_wp_error( $result ) ) {
				wpf_log( 'error', wpf_get_current_user_id(), $result->get_error_message() . '.<br /><br />The tags from the <strong>' . $category['CategoryName'] . '</strong> category have not been loaded.', array( 'source' => 'infusionsoft' ) );
				continue;
			}

			foreach ( $result as $tag ) {
				$tags[ $tag['Id'] ]['label']    = sanitize_text_field( $tag['GroupName'] );
				$tags[ $tag['Id'] ]['category'] = sanitize_text_field( $category['CategoryName'] );
			}

		}

		// For tags with no category
		$query  = array( 'GroupCategoryId' => '' );
		$result = $this->app->dsQuery( 'ContactGroup', 1000, 0, $query, $fields );

		if ( is_wp_error( $result ) ) {

			wpf_log( 'error', wpf_get_current_user_id(), $result->get_error_message() . '.<br /><br />Tags with <strong>no category</strong> have not been loaded.', array( 'source' => 'infusionsoft' ) );

		} else {

			foreach ( $result as $tag ) {
				$tags[ $tag['Id'] ]['label']    = sanitize_text_field( $tag['GroupName'] );
				$tags[ $tag['Id'] ]['category'] = 'No Category';
			}

		}

		wp_fusion()->settings->set( 'available_tags', $tags );

		return $tags;

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// Load built in fields first
		require dirname( __FILE__ ) . '/admin/infusionsoft-fields.php';

		$built_in_fields = array();

		foreach ( $infusionsoft_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Get custom fields

		$custom_fields = array();

		$fields = array( 'Name', 'Label' );
		$query  = array( 'FormId' => '-1' );

		$result = $this->app->dsQuery( 'DataFormField', 1000, 0, $query, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result as $key => $data ) {
			$custom_fields[ '_' . $data['Name'] ] = $data['Label'];
		}

		asort( $custom_fields );

		$crm_fields = array( 'Standard Fields' => $built_in_fields, 'Custom Fields' => $custom_fields );
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
			return $this->error;
		}

		// Pull the remote contact record based on local user's email address
		$fields = array( 'Id' );
		$query  = array( 'Email' => $email_address );
		$result = $this->app->dsQuery( 'Contact', 1, 0, $query, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result[0]['Id'] ) ) {

			return $result[0]['Id'];

		} else {

			return false;

		}

	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return array Tags
	 */

	public function get_tags( $contact_id ) {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		$fields  = array( 'Groups' );
		$query   = array( 'Id' => $contact_id );
		$result = $this->app->dsQuery( 'Contact', 1000, 0, $query, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result[0]['Groups'] ) ) {

			// If user has tags applied
			$tag_ids = explode( ',', $result[0]['Groups'] );

			return $tag_ids;

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
			return $this->error;
		}

		foreach ( $tags as $tag ) {

			$result = $this->app->grpAssign( $contact_id, $tag );

			if ( is_wp_error( $result ) ) {

				// If CID changed

				if( strpos($result->get_error_message(), 'Error loading contact') !== false ) {

					$user_id = wp_fusion()->user->get_user_id($contact_id);
					$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

					if( ! empty( $contact_id ) ) {

						$this->apply_tags( $tags, $contact_id );
						break;

					}

				} else {
					return $result;
				}

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

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		foreach ( $tags as $tag ) {

			$result = $this->app->grpRemove( $contact_id, $tag );

			if ( is_wp_error( $result ) ) {

				// If CID changed

				if( strpos($result->get_error_message(), 'Error loading contact') !== false ) {

					$user_id = wp_fusion()->user->get_user_id($contact_id);
					$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

					if( ! empty( $contact_id ) ) {

						$this->remove_tags( $tags, $contact_id );
						break;

					}

				} else {
					return $result;
				}

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

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// Allow functions to pass in pre-mapped data
		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// addCon instead of addWithDupCheck because addWithDupCheck has random errors with custom fields
		$contact_id = $this->app->addCon( $data );

		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}

		$this->app->optIn( $data['Email'] );

		return $contact_id;

	}


	/**
	 * Update contact, with error handling
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( empty( $data ) ) {
			return false;
		}

		$result = $this->app->updateCon( $contact_id, $data );

		if ( is_wp_error( $result ) ) {

			if( strpos($result->get_error_message(), 'Record not found') !== false ) {

				// If CID changed, try and update

				$user_id = wp_fusion()->user->get_user_id($contact_id);
				$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

				if ( $contact_id !== false ) {

					$this->update_contact( $contact_id, $data, false );

				} else {

					// If contact has been deleted, re-add
					$contact_id = $this->add_contact( $data, false );

				}

			} else {

				return $result;

			}

		}

		if ( isset( $data['Email'] ) ) {

			// Opt-in the email since email address changes cause opt-outs

			// "You can opt them from a new state, but once they opt out you can't change it via API. They have to go through an IS web form."
			// i.e. if they've opted out this won't opt them back in again.

			$this->app->optIn( $data['Email'] );

		}

		do_action( 'wpf_contact_updated', $contact_id );

		return true;

	}


	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		$return_fields = array();
		$field_map     = array();

		foreach ( wp_fusion()->settings->get( 'contact_fields' ) as $field_id => $field_data ) {

			if ( $field_data['active'] == true && ! empty( $field_data['crm_field'] ) ) {

				$return_fields[]        = $field_data['crm_field'];
				$field_map[ $field_id ] = $field_data['crm_field'];

			}

		}

		if ( empty( $return_fields ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// Quit if not contact record found
		$result = $this->app->loadCon( $contact_id, $return_fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_meta = array();

		foreach ( $field_map as $user_meta_key => $infusionsoft_key ) {

			if ( isset( $result[ $infusionsoft_key ] ) ) {
				$field_data = $result[ $infusionsoft_key ];
			} else {
				continue;
			}

			// Check if result is a date field
			if ( DateTime::createFromFormat( 'Ymd\TG:i:s', $field_data ) !== false ) {
				// Set to default WP date format
				$date_format = get_option( 'date_format' );
				$field_data  = date( $date_format, strtotime( $field_data ) );
			}

			$user_meta[ $user_meta_key ] = $field_data;

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
			return $this->error;
		}

		$contact_ids    = array();
		$return_fields  = array( 'Contact.Id' );

		$proceed = true;
		$page = 0;

		while($proceed == true) {

			$results = $this->app->dsQuery( "ContactGroupAssign", 1000, $page, array( 'GroupId' => $tag ), $return_fields );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $results as $id => $result ) {
				$contact_ids[] = $result['Contact.Id'];
			}

			$page++;

			if(count($results) < 1000) {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

	/**
	 * Optionally sends an API call after a contact has been updated
	 *
	 * @access public
	 * @return bool
	 */

	public function send_api_call( $contact_id ) {

		if ( wp_fusion()->settings->get( 'api_call' ) == true ) {

			if ( is_wp_error( $this->connect() ) ) {
				return $this->error;
			}

			$integration = wp_fusion()->settings->get( 'api_call_integration' );
			$call_name   = wp_fusion()->settings->get( 'api_call_name' );

			$result = $this->app->achieveGoal( $integration, $call_name, $contact_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;

		}

	}

}
