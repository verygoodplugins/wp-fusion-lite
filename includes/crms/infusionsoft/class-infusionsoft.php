<?php

class WPF_Infusionsoft_iSDK {

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */

	public $app;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'add_tags_api' );

	/**
	 * Holds connection errors
	 */

	private $error;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.36.10
	 * @var  string
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
		$this->menu_name = 'Infusionsoft / Keap';

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
		$app_name = wpf_get_option( 'app_name' );

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
		$cookies[] = 'affiliate';

		return $cookies;

	}

	/**
	 * Infusionsoft default password field is limited to 16 chars so we'll keep WP passwords shorter than 16 chars as well
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function generate_password( $password ) {

		if ( is_admin() ) {
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

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		echo '<script type="text/javascript" src="' . esc_url( 'https://' . wpf_get_option( 'app_name' ) . '.infusionsoft.com/app/webTracking/getTrackingCode' ) . '"></script>';

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contactId'] ) ) {
			$post_data['contact_id'] = absint( $post_data['contactId'] );
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

		if ( ! is_array( $value ) && strpos( $value, '&' ) !== false ) {
			$value = str_replace( '&', '&amp;', $value );
		}

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.
			$date = date( 'Ymd\TH:i:s', $value );

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ',', array_filter( $value ) );

		} elseif ( 'country' === $field_type ) {

			$countries = include dirname( __FILE__ ) . '/includes/countries.php';

			if ( isset( $countries[ $value ] ) ) {

				return $countries[ $value ];

			} else {

				return $value;

			}
		} else {

			return sanitize_text_field( $value ); // fixes "Error adding: java.lang.Integer cannot be cast to java.lang.String".

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
				return 'Text';
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

		if ( ! class_exists( 'iSDK' ) ) {
			require_once dirname( __FILE__ ) . '/includes/isdk.php';
		}

		$app = new iSDK();

		// Get saved data from DB
		if ( empty( $app_name ) && empty( $api_key ) ) {
			$app_name = wpf_get_option( 'app_name' );
			$api_key  = wpf_get_option( 'api_key' );
		}

		$result = $app->cfgCon( $app_name, $api_key, 'off' );

		if ( is_wp_error( $result ) ) {
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

		// Retrieve tag categories.

		$fields = array( 'CategoryName', 'Id' );
		$query  = array( 'Id' => '%' );

		$tags = array();

		$categories = $this->app->dsQuery( 'ContactGroupCategory', 1000, 0, $query, $fields );

		if ( is_wp_error( $categories ) ) {
			wpf_log( 'error', 0, $categories->get_error_message() . '.<br /><br />The categories have not been loaded.', array( 'source' => 'infusionsoft' ) );
			return false;
		}

		$fields = array( 'Id', 'GroupName', 'GroupCategoryId' );

		foreach ( $categories as $category ) {

			// Retrieve tags.

			$page    = 0;
			$proceed = true;

			while ( $proceed ) {

				$query  = array( 'GroupCategoryId' => $category['Id'] );
				$result = $this->app->dsQuery( 'ContactGroup', 1000, $page, $query, $fields );

				if ( is_wp_error( $result ) ) {
					wpf_log( 'error', 0, $result->get_error_message() . '.<br /><br />The tags from the <strong>' . $category['CategoryName'] . '</strong> category have not been loaded.', array( 'source' => 'infusionsoft' ) );
					continue;
				}

				foreach ( $result as $tag ) {
					$tags[ $tag['Id'] ]['label']    = sanitize_text_field( $tag['GroupName'] );
					$tags[ $tag['Id'] ]['category'] = sanitize_text_field( $category['CategoryName'] );
				}

				if ( count( $result ) < 1000 ) {
					$proceed = false;
				} else {
					$page++;
				}
			}
		}

		// For tags with no category.

		$page    = 0;
		$proceed = true;

		while ( $proceed ) {

			$query  = array( 'GroupCategoryId' => '' );
			$result = $this->app->dsQuery( 'ContactGroup', 1000, $page, $query, $fields );

			if ( is_wp_error( $result ) ) {

				wpf_log( 'error', 0, $result->get_error_message() . '.<br /><br />Tags with <strong>no category</strong> have not been loaded.', array( 'source' => 'infusionsoft' ) );

			} else {

				foreach ( $result as $tag ) {
					$tags[ $tag['Id'] ]['label']    = sanitize_text_field( $tag['GroupName'] );
					$tags[ $tag['Id'] ]['category'] = 'No Category';
				}
			}

			if ( count( $result ) < 1000 ) {
				$proceed = false;
			} else {
				$page++;
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

			wpf_log( 'error', 0, $result->get_error_message() );
			return $result;
		}

		foreach ( $result as $key => $data ) {
			$custom_fields[ '_' . $data['Name'] ] = $data['Label'];
		}

		asort( $custom_fields );

		// Social fields
		$social_fields = array();

		foreach ( $infusionsoft_social_fields as $index => $data ) {
			$social_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $social_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
			'Social Fields'   => $social_fields,
		);
		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}


	/**
	 * Creates a new tag in Infusionsoft and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int    $tag_id the tag id returned from API.
	 */
	public function add_tag( $tag_name ) {
		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		$response = $this->app->dsAdd(
			'ContactGroup',
			array(
				'GroupName' => $tag_name,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
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

		$fields = array( 'Groups' );
		$query  = array( 'Id' => $contact_id );
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

				if ( strpos( $result->get_error_message(), 'Error loading contact' ) !== false ) {

					$user_id    = wp_fusion()->user->get_user_id( $contact_id );
					$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

					if ( ! empty( $contact_id ) ) {

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

				if ( strpos( $result->get_error_message(), 'Error loading contact' ) !== false ) {

					$user_id    = wp_fusion()->user->get_user_id( $contact_id );
					$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

					if ( ! empty( $contact_id ) ) {

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
	 * Extract social media fields from contact data so it dees not throw an
	 * error while adding/updating contact.
	 *
	 * @since  3.38.35
	 *
	 * @param  array $data   The update data.
	 * @return array The modified update data.
	 */
	private function extract_social_fields( $data ) {

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		if ( ! isset( $crm_fields['Social Fields'] ) ) {
			return array();
		}

		return array_intersect_key( $data, $crm_fields['Social Fields'] );
	}

	/**
	 * Add social accounts to a contact.
	 *
	 * @since  3.38.35
	 *
	 * @param  array $data       The update data.
	 * @param  int   $contact_id The contact ID.
	 */
	private function add_social_accounts( $data, $contact_id ) {

		$fields = array( 'AccountType', 'AccountName', 'Id' );
		$query  = array( 'ContactId' => $contact_id );
		$result = $this->app->dsQuery( 'SocialAccount', 1000, 0, $query, $fields );

		foreach ( $data as $key => $value ) {

			foreach ( $result as $row ) {

				// See if it exists.

				if ( $key === $row['AccountType'] ) {

					if ( $value !== $row['AccountName'] ) { // Only update it if it's changed.

						$this->app->dsUpdate(
							'SocialAccount',
							$row['Id'],
							array(
								'ContactId'   => $contact_id,
								'AccountName' => $value,
								'AccountType' => $key,
							)
						);

					}

					continue 2;

				}
			}

			// No matches found. Add.

			$this->app->dsAdd(
				'SocialAccount',
				array(
					'ContactId'   => $contact_id,
					'AccountName' => $value,
					'AccountType' => $key,
				)
			);

		}

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// The social fields use their own API.
		$social_data = $this->extract_social_fields( $data );

		// If we try to sync social data with the main data we'll get an error.
		$data = array_diff( $data, $social_data );

		// addCon instead of addWithDupCheck because addWithDupCheck has random errors with custom fields
		$contact_id = $this->app->addCon( $data );

		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}

		$this->app->optIn( $data['Email'] );

		if ( ! empty( $social_data ) ) {
			$this->add_social_accounts( $social_data, $contact_id );
		}

		return $contact_id;

	}


	/**
	 * Update contact, with error handling
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		if ( is_wp_error( $this->connect() ) ) {
			return $this->error;
		}

		// The social fields use their own API.
		$social_data = $this->extract_social_fields( $data );

		// If we try to sync social data with the main data we'll get an error.
		$data = array_diff( $data, $social_data );

		$result = $this->app->updateCon( $contact_id, $data );

		if ( is_wp_error( $result ) ) {

			if ( strpos( $result->get_error_message(), 'Record not found' ) !== false ) {

				// If CID changed, try and update.

				$user_id    = wp_fusion()->user->get_user_id( $contact_id );
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

		if ( ! empty( $social_data ) ) {
			$this->add_social_accounts( $social_data, $contact_id );
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
		$social_map    = array();

		// Load social fields.

		require dirname( __FILE__ ) . '/admin/infusionsoft-fields.php';
		$social_crm_fields = array_column( $infusionsoft_social_fields, 'crm_field' );

		foreach ( wpf_get_option( 'contact_fields', array() ) as $field_id => $field_data ) {

			if ( $field_data['active'] && ! empty( $field_data['crm_field'] ) ) {

				if ( in_array( $field_data['crm_field'], $social_crm_fields ) ) {
					$social_map[ $field_id ] = $field_data['crm_field'];
					continue;
				}

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

		// Load social user meta if it exist
		if ( ! empty( $social_map ) ) {
			$social_fields = array( 'AccountType', 'AccountName' );
			$social_query  = array( 'ContactId' => $contact_id );
			$social_result = $this->app->dsQuery( 'SocialAccount', 1000, 0, $social_query, $social_fields );
			if ( is_wp_error( $social_result ) ) {
				return $social_result;
			}

			foreach ( $social_map as $user_meta_key => $infusionsoft_key ) {
				foreach ( $social_result as $social_value ) {
					if ( strtolower( $social_value['AccountType'] ) === strtolower( $infusionsoft_key ) ) {
						$field_data = $social_value['AccountName'];
					} else {
						continue;
					}

					$user_meta[ $user_meta_key ] = $field_data;
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
			return $this->error;
		}

		$contact_ids   = array();
		$return_fields = array( 'Contact.Id' );

		$proceed = true;
		$page    = 0;

		while ( $proceed == true ) {

			$results = $this->app->dsQuery( 'ContactGroupAssign', 1000, $page, array( 'GroupId' => $tag ), $return_fields );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			foreach ( $results as $id => $result ) {
				$contact_ids[] = $result['Contact.Id'];
			}

			$page++;

			if ( count( $results ) < 1000 ) {
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

		if ( wpf_get_option( 'api_call' ) == true ) {

			if ( is_wp_error( $this->connect() ) ) {
				return $this->error;
			}

			$integration = wpf_get_option( 'api_call_integration' );
			$call_name   = wpf_get_option( 'api_call_name' );

			$result = $this->app->achieveGoal( $integration, $call_name, $contact_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;

		}

	}

}
