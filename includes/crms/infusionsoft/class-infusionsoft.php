<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Infusionsoft_iSDK {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'infusionsoft';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Infusionsoft';

	/**
	 * The CRM menu name.
	 *
	 * @var string
	 */
	public $menu_name = 'Infusionsoft / Keap';


	/**
	 * Contains API params
	 */
	public $params;

	/**
	 * Allows for direct access to the API, bypassing WP Fusion
	 */
	public $app;

	/**
	 * API URL
	 *
	 * @var string
	 */
	public $url = 'https://api.infusionsoft.com/crm/rest/v2/';

	/**
	 * API URL (v1 API)
	 *
	 * @var string
	 */
	public $urlv1 = 'https://api.infusionsoft.com/crm/rest/v1/';

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

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_Infusionsoft_iSDK_Admin( $this->slug, $this->name, $this );
		}

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Magic getter method to allow property access like $this->app.
	 *
	 * @param string $property
	 * @return mixed
	 */
	public function __get( $property ) {
		if ( 'app' === $property ) {
			return $this->get_app();
		}

		return null;
	}

	/**
	 * Lazy-load the app instance.
	 *
	 * @return WPF_Infusionsoft_APP
	 */
	public function get_app() {
		if ( ! isset( $this->app ) ) {
			require_once __DIR__ . '/class-infusionsoft-app.php';
			$this->app = new WPF_Infusionsoft_App( $this->get_params() );
		}

		return $this->app;
	}


	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @return void
	 */
	public function init() {

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );
		add_action( 'wpf_contact_updated', array( $this, 'send_api_call' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_auto_login_query_var', array( $this, 'auto_login_query_var' ) );

		// Slow down the batch processses to get around API limits.
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		// Key update notices.
		add_action( 'wpf_settings_notices', array( $this, 'api_key_warning' ) );
		add_action( 'admin_notices', array( $this, 'api_key_warning' ) );

		// Add tracking code to header
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

		// Register cron hook for switching back to primary key
		add_action( 'wpf_infusionsoft_switch_to_primary_key', array( $this, 'switch_to_primary_key' ) );

		// Set edit link
		$app_name = wpf_get_option( 'app_name' );
		if ( ! empty( $app_name ) ) {
			$this->edit_url = 'https://' . $app_name . '.infusionsoft.com/Contact/manageContact.jsp?view=edit&ID=%s';
		}

		if ( ! $this->app ) {
			require_once __DIR__ . '/class-infusionsoft-app.php';
			$this->app = new WPF_Infusionsoft_APP( $this->get_params() );
		}
	}

	/**
	 * Check if we need to upgrade to the new Service Account Key.
	 *
	 * @since 3.44.0
	 */
	public function api_key_warning() {

		if ( false === strpos( wpf_get_option( 'api_key' ), 'KeapAK-' ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			// translators: %1$s is the opening link tag, %2$s is the closing link tag, %3$s is another opening link tag
			echo wp_kses_post( sprintf( __( '<strong>Heads up:</strong> WP Fusion\'s Infusionsoft / Keap integration has been updated to use Service Account Keys. Please %1$sgenerate a new Service Account Key%2$s and update it on the %3$sSetup tab in the WP Fusion settings%2$s to avoid service interruption.', 'wp-fusion-lite' ), '<a href="https://developer.infusionsoft.com/pat-and-sak/">', '</a>', '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings#setup' ) ) . '">' ) );

			echo '</p></div>';
		}

		// Display notice if using backup key.
		if ( get_transient( 'wpf_keap_backup_key' ) ) {
			echo '<div class="notice notice-warning wpf-notice"><p>';
			echo wp_kses_post( __( '<strong>Note:</strong> WP Fusion is currently using your backup Infusionsoft Service Account Key due to API throttling on the primary key. It will switch back to the primary key at 12am UTC.', 'wp-fusion-lite' ) );
			echo '</p></div>';
		}
	}

	/**
	 * Register cookies allowed in the async process
	 *
	 * @since unknown
	 *
	 * @param array $cookies The cookies.
	 * @return array The cookies.
	 */
	public function allowed_cookies( $cookies ) {

		$cookies[] = 'is_aff';
		$cookies[] = 'is_affcode';
		$cookies[] = 'affiliate';

		return $cookies;
	}


	/**
	 * Output tracking code
	 *
	 * @since 3.7.6
	 *
	 * @return mixed The tracking code output.
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
	 * @since 1.0.0
	 *
	 * @param array $post_data The post data.
	 * @return array The formatted post data.
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
	 * @since Unknown
	 *
	 * @param string $var The query var.
	 * @return string The query var.
	 */
	public function auto_login_query_var( $var ) {

		return 'contactId';
	}

	/**
	 * Slow down batch processses to get around the 10 API calls per second limit
	 *
	 * @since 3.44.2
	 *
	 * @param int $seconds The seconds.
	 * @return int Sleep time.
	 */
	public function set_sleep_time( $seconds ) {
		return 2;
	}

	/**
	 * Handle HTTP response.
	 *
	 * Check HTTP Response for errors and return a WP_Error if found.
	 *
	 * @since 3.34.0
	 *
	 * @param object $response The HTTP response.
	 * @param array  $args     The HTTP request arguments.
	 * @param string $url      The HTTP request URL.
	 * @return WP_HTTP_Response|WP_Error The response, or error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'https://api.infusionsoft.com/crm/rest/' ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 === $response_code || 201 === $response_code || 204 === $response_code ) {

				return $response; // Success. Nothing more to do.

			} elseif ( 500 === $response_code ) {

				$request_body = json_decode( $args['body'], true );

				if ( $request_body && isset( $request_body['owner_id'] ) ) {

					$response = new WP_Error( 'error', sprintf( __( 'Owner ID %s is not valid.', 'wp-fusion-lite' ), $request_body['owner_id'] ) );

				} elseif ( $request_body && isset( $request_body['message'] ) ) {

					$response = new WP_Error( 'error', $request_body['message'] );

				} else {

					$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );
				}
			} elseif ( 401 === $response_code ) {

				$response = new WP_Error( 'error', 'Invalid API credentials.' );

			} elseif ( 405 === $response_code ) {

				$response = new WP_Error( 'error', 'Method not allowed.' );

			} elseif ( 404 === $response_code ) {

				if ( strpos( $url, 'contacts' ) !== false ) {
					// Triggers a lookup again by email.
					$response = new WP_Error( 'not_found', 'Not found [error 404]: ' . $url );
				} else {
					$response = new WP_Error( 'error', 'Not found [error 404]: ' . $url );
				}
			} elseif ( ( 429 === $response_code || 503 === $response_code ) ) {

				if ( ! isset( $args['doing_retry'] ) ) {

					// Too many requests. Sleep and try again.

					wpf_log( 'notice', 0, 'Too many requests (' . $response_code . ' error). Waiting 2 seconds and trying again.', array( 'source' => 'infusionsoft' ) );

					sleep( 2 );
					$args['doing_retry'] = true;

					$response = wp_remote_request( $url, $args );

				} elseif ( wpf_get_option( 'backup_api_key' ) && ! get_transient( 'wpf_keap_backup_key' ) ) {

					$backup_api_key = wpf_get_option( 'backup_api_key' );

					$args['headers']['X-Keap-API-Key'] = $backup_api_key;
					$response                          = wp_remote_request( $url, $args );

					if ( ! is_wp_error( $response ) ) {

						wpf_log( 'notice', 0, 'Switched to backup Service Account Key due to API throttling.', array( 'source' => 'infusionsoft' ) );

						// If the backup SAK worked, calculate time until midnight UTC
						$seconds_until_midnight = strtotime( 'tomorrow midnight UTC' ) - time();
						set_transient( 'wpf_keap_backup_key', $backup_api_key, $seconds_until_midnight );
					}
				} else {

					// Throttled request.
					$response = new WP_Error( 'error', 'Too many requests. Please try again later, or add a backup Service Account Key in the WP Fusion settings.' );
				}
			} else {

				$body_json = json_decode( wp_remote_retrieve_body( $response ) );

				if ( isset( $body_json->message ) ) {
					$response = new WP_Error( 'error', $body_json->message );
				} elseif ( isset( $body_json->fault ) ) {
					$response = new WP_Error( 'error', $body_json->fault->faultstring );
				} else {
					$response = new WP_Error( 'error', 'Unknown error. <pre>' . wpf_print_r( $body_json, true ) . '</pre>' );
				}
			}
		}

		return $response;
	}

	/**
	 * Formats user entered data to match Infusionsoft field formats
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value      The field data.
	 * @param string $field_type The field type.
	 * @param string $field      The field in the CRM.
	 * @return mixed The formatted field data.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( is_string( $value ) && strpos( $value, '&' ) !== false ) {
			$value = str_replace( '&', '&amp;', $value );
		}

		if ( 'date' === $field_type && ! empty( $value ) ) {

			// Adjust formatting for date fields.

			if ( 'date_time' === wpf_get_remote_field_type( $field ) ) {
				$date = wpf_get_iso8601_date( $value, true );
			} else {
				$date = gmdate( 'Y-m-d', $value );
			}

			return $date;

		} elseif ( is_array( $value ) ) {

			return implode( ',', array_filter( $value ) );

		} elseif ( false !== strpos( $field, 'State' ) ) {

			$state_code = wpf_state_to_iso3166( $value );

			if ( $state_code ) {
				return $state_code;
			} else {

				// Unknown state.
				return sanitize_text_field( $value );

			}
		} elseif ( false !== strpos( $field, 'Country' ) && is_string( $value ) ) {

			// See if it's a country code, country name, or state name.

			$country_code = wpf_country_to_iso3166( $value, 'alpha-3' );

			if ( $country_code ) {
				return $country_code;
			} else {

				// Unknown country.
				return false;
			}
		} else {
			return sanitize_text_field( $value ); // fixes "Error adding: java.lang.Integer cannot be cast to java.lang.String".
		}
	}


	/**
	 * Gets params for API calls
	 *
	 * Adds apiSecret for non-GET requests
	 *
	 * @since 3.44.0
	 *
	 * @return array $params The API params.
	 */
	public function get_params( $api_key = null ) {

		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'api_key' );

			if ( get_transient( 'wpf_keap_backup_key' ) ) {
				$api_key = get_transient( 'wpf_keap_backup_key' );
			}
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 20,
			'headers'    => array(
				'X-Keap-API-Key' => $api_key,
				'Content-Type'   => 'application/json',
			),
		);

		return $this->params;
	}

	/**
	 * Initialize connection to Infusionsoft
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @return bool|WP_Error True if connection is successful, error on failure.
	 */
	public function connect( $app_name = null, $api_key = null, $test = false ) {

		$params = $this->get_params( $api_key );

		if ( ! $test ) {
			return true;
		}

		$response = wp_remote_get( $this->url . 'contacts/', $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Performs initial sync once connection is configured
	 *
	 * @since 1.0.0
	 *
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
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @return array|WP_Error Tags or error.
	 */
	public function sync_tags() {

		// Get the categories first.

		$categories = array();

		$url = $this->url . 'tags/categories/?page_size=1000';

		while ( $url ) {

			$response = wp_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			$categories = array_merge( $categories, $response['tag_categories'] );

			if ( empty( $response['next_page_token'] ) ) {
				break;
			}

			if ( wp_http_validate_url( $response['next_page_token'] ) ) {
				$url = $response['next_page_token'];
			} else {
				// New V2 compliant spec: https://developer.infusionsoft.com/docs/restv2/#tag/Tags/operation/listTagsUsingGET_1.
				$url = add_query_arg( 'page_token', $response['next_page_token'], $url );
			}
		}

		// Then get the tags.

		$tags = array();

		$url = $this->url . 'tags/?page_size=1000';

		while ( $url ) {

			$response = wp_remote_get( $url, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			$tags = array_merge( $tags, $response['tags'] );

			if ( empty( $response['next_page_token'] ) ) {
				break;
			}

			if ( wp_http_validate_url( $response['next_page_token'] ) ) {
				$url = $response['next_page_token'];
			} else {
				// New V2 compliant spec: https://developer.infusionsoft.com/docs/restv2/#tag/Tags/operation/listTagsUsingGET_1.
				$url = add_query_arg( 'page_token', $response['next_page_token'], $url );
			}
		}

		$available_tags = array();

		foreach ( $tags as $tag ) {
			$available_tags[ $tag['id'] ]['label'] = $tag['name'];

			$category_name = 'No Category';

			$category_index = false;

			if ( isset( $tag['category']['id'] ) ) {
				$category_index = array_search( $tag['category']['id'], array_column( $categories, 'id' ) );
			}

			if ( $category_index ) {
				$category_name = $categories[ $category_index ]['name'];
			}

			$available_tags[ $tag['id'] ]['category'] = $category_name;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @return array|WP_Error CRM Fields or error.
	 */
	public function sync_crm_fields() {

		// Load built in fields first
		require __DIR__ . '/admin/infusionsoft-fields.php';

		$built_in_fields = array();

		foreach ( $infusionsoft_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = array(
				'crm_label' => $data['crm_label'],
				'crm_type'  => isset( $data['crm_type'] ) ? $data['crm_type'] : 'text',
			);
		}

		asort( $built_in_fields );

		// Get custom fields

		$custom_fields    = array();
		$custom_field_ids = array();

		$response = wp_remote_get( $this->url . 'contacts/model/', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->custom_fields as $field ) {

			$id = '_' . $this->remove_special_characters( $field->label );

			// The old API used labels as IDs so we'll keep that for backwards compatibility.
			$custom_fields[ $id ] = array(
				'crm_label' => $field->label,
				'crm_type'  => 'text',
			);

			if ( 'DATE' === $field->field_type ) {
				$custom_fields[ $id ]['crm_type'] = 'date';
			} elseif ( 'DATE_TIME' === $field->field_type ) {
				$custom_fields[ $id ]['crm_type'] = 'date_time';
			} elseif ( isset( $field->options ) ) {
				$custom_fields[ $id ]['crm_type'] = 'select';

				foreach ( $field->options as $option ) {
					$custom_fields[ $id ]['choices'][ $option->id ] = $option->label;
				}
			}

			$custom_field_ids[ $id ] = $field->id;

		}

		uasort( $custom_fields, 'wpf_sort_remote_fields' );

		// Social fields.
		$social_fields = array();

		foreach ( $infusionsoft_social_fields as $data ) {
			$social_fields[ $data['crm_field'] ] = array(
				'crm_label' => $data['crm_label'],
				'crm_type'  => 'text',
			);
		}

		asort( $social_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
			'Social Fields'   => $social_fields,
		);
		wp_fusion()->settings->set( 'crm_fields', $crm_fields );
		wp_fusion()->settings->set( 'api_custom_fields', $custom_field_ids );

		return $crm_fields;
	}


	/**
	 * Creates a new tag in Infusionsoft and returns the ID.
	 *
	 * @since  3.38.42
	 *
	 * @param  string $tag_name The tag name.
	 * @return int|WP_Error The tag ID or error.
	 */
	public function add_tag( $tag_name ) {

		$params  = $this->get_params();
		$request = $this->url . '/tags/';

		$body = array(
			'name' => $tag_name,
		);

		$params['body'] = wp_json_encode( $body );
		$response       = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return intval( $response->id );
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param string $email_address The email address to look up.
	 * @return int|bool|WP_Error Contact ID or false or error.
	 */
	public function get_contact_id( $email_address ) {

		$query_args = array(
			'filter' => 'email' . rawurlencode( '==' . $email_address ),
		);

		$query_args = apply_filters( 'wpf_infusionsoft_query_args', $query_args, 'get_contact_id', $email_address );

		$request = add_query_arg( $query_args, $this->url . 'contacts/' );

		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->contacts ) ) {
			return false;
		}

		return intval( $response->contacts[0]->id );
	}


	/**
	 * Gets all tags currently applied to the user
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error Tags or error.
	 */
	public function get_tags( $contact_id ) {

		$request  = $this->url . 'contacts/' . $contact_id . '?fields=tag_ids';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->tag_ids ) ) {
			return array();
		}

		return $response->tag_ids;
	}


	/**
	 * Applies tags to a contact
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$params         = $this->get_params();
		$data           = array( 'tagIds' => $tags );
		$params['body'] = wp_json_encode( $data );

		$request  = $this->urlv1 . 'contacts/' . $contact_id . '/tags';
		$response = wp_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Removes tags from a contact.
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param array $tags       A numeric array of tags to remove from the contact.
	 * @param int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		$request  = $this->urlv1 . 'contacts/' . $contact_id . '/tags?ids=' . rawurlencode( implode( ',', $tags ) );
		$response = wp_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Extract social media fields from contact data so it dees not throw an
	 * error while adding/updating contact.
	 *
	 * @since  3.38.35
	 *
	 * @return array The social fields.
	 */
	private function get_social_fields() {

		$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

		if ( ! isset( $crm_fields['Social Fields'] ) ) {
			return array();
		}

		return $crm_fields['Social Fields'];
	}

	/**
	 * Get fields mapping.
	 *
	 * @since 3.44.0
	 *
	 * @return array The fields mapping.
	 */
	public function fields_mapping() {
		return array(
			'Regular'   => array(
				'FirstName'    => 'given_name',
				'LastName'     => 'family_name',
				'OwnerID'      => 'owner_id',
				'Birthday'     => 'birth_date',
				'JobTitle'     => 'job_title',
				'Anniversary'  => 'anniversary_date',
				'ContactType'  => 'contact_type',
				'MiddleName'   => 'middle_name',
				'Leadsource'   => 'leadsource_id',
				'SpouseName'   => 'spouse_name',
				'TimeZone'     => 'time_zone',
				'Website'      => 'website',
				'ContactNotes' => 'notes',
				'Language'     => 'preferred_locale',
				'Nickname'     => 'preferred_name',
			),
			'addresses' => array(
				'BILLING'  => array(
					'StreetAddress1' => 'line1',
					'StreetAddress2' => 'line2',
					'City'           => 'locality',
					'PostalCode'     => 'postal_code',
					'Country'        => 'country_code',
					'State'          => 'region',
				),
				'SHIPPING' => array(
					'Address2Street1' => 'line1',
					'Address2Street2' => 'line2',
					'City2'           => 'locality',
					'PostalCode2'     => 'postal_code',
					'Country2'        => 'country_code',
					'State2'          => 'region',
				),
			),
			'Objects'   => array(
				'company'       => array(
					'CompanyID+id',
					'Company+company_name',
				),
				'phone_numbers' => array(
					'Phone1+number',
					'Phone1Ext+extension',
					'Phone1Type+type',
					'Phone2+number+',
					'Phone2Ext+extension+',
					'Phone2Type+type+',
					'Phone3+number++',
					'Phone4+number+++',
					'Phone5+number++++',
				),
			),
			'Emails'    => array(
				'Email'         => 'EMAIL1',
				'EmailAddress2' => 'EMAIL2',
				'EmailAddress3' => 'EMAIL3',
			),
		);
	}

	/**
	 * Format contact data before it's sent to be loaded up by WordPress.
	 *
	 * @param array $data The data to format.
	 * @return array The formatted data.
	 */
	public function format_load_contact( $data ) {
		// Fix fields.
		$fields_mapping = $this->fields_mapping();
		foreach ( $fields_mapping['Regular'] as $old => $new ) {
			if ( isset( $data[ $new ] ) ) {
				$data[ $old ] = $data[ $new ];
				unset( $data[ $new ] );
			}
		}

		// Objects.
		foreach ( $fields_mapping['Objects'] as $new => $old ) {
			if ( ! isset( $data[ $new ] ) ) {
				continue;
			}

			foreach ( $data[ $new ] as $key => $data_value ) {
				foreach ( $old as $value ) {
					$value     = explode( '+', $value );
					$old_value = $value[0];
					$new_value = $value[1];

					// Company.
					if ( ! is_array( $data_value ) && $key === $new_value ) {
						$data[ $old_value ] = $data_value;
						continue;
					}

					if ( $new === 'phone_numbers' ) {
						if ( strtolower( $data_value['field'] ) === strtolower( $old_value ) ) {
							$data[ $old_value ] = $data_value[ $new_value ];
						}
					} elseif ( isset( $data_value[ $new_value ] ) && ! empty( $data_value[ $new_value ] ) ) {
						if ( isset( $value[2] ) && $key > 0 ) {
							continue;
						}
							$data[ $old_value ] = $data_value[ $new_value ];
					}
				}
			}
			unset( $data[ $new ] );
		}

		// Addresses.

		if ( isset( $data['addresses'] ) ) {
			foreach ( $data['addresses'] as $address ) {
				$type = $address['field'];
				if ( isset( $fields_mapping['addresses'][ $type ] ) ) {

					foreach ( $fields_mapping['addresses'][ $type ] as $old => $new ) {

						if ( isset( $address[ $new ] ) ) {
							$data[ $old ] = $address[ $new ];
						}
					}
				}
			}
			unset( $data['addresses'] );
		}

		// Email addresses.
		if ( isset( $data['email_addresses'] ) ) {
			foreach ( $data['email_addresses'] as $value ) {
				foreach ( $fields_mapping['Emails'] as $old_email => $new_email ) {
					if ( $value['field'] === $new_email ) {
						$data[ $old_email ] = $value['email'];
						$data['optin']      = $value['email_opt_status'];
					}
				}
			}

			unset( $data['email_addresses'] );
		}

		// Social fields.
		$social_fields = $this->get_social_fields();

		if ( isset( $data['social_accounts'] ) ) {
			foreach ( $data['social_accounts'] as $value ) {
				foreach ( $social_fields as $social_field => $social_field_data ) {
					$social_field = strtoupper( str_replace( 'LinkedIn', 'LINKED_IN', $social_field ) );
					if ( $value['type'] === $social_field ) {
						$data[ ucfirst( strtolower( $social_field ) ) ] = $value['name'];
					}
				}
			}

			unset( $data['social_accounts'] );
		}

		// Custom fields.
		$api_custom_fields = array_flip( wpf_get_option( 'api_custom_fields', array() ) );

		if ( isset( $data['custom_fields'] ) ) {
			foreach ( $data['custom_fields'] as $value ) {
				if ( ! isset( $api_custom_fields[ $value['id'] ] ) ) {
					continue;
				}

				$data[ $api_custom_fields[ $value['id'] ] ] = $value['content'];

			}

			unset( $data['custom_fields'] );
		}

		return $data;
	}

	/**
	 * Format contact data before it's sent to the API.
	 *
	 * @since 3.44.0
	 *
	 * @param array $data The data to format.
	 * @return array The formatted data.
	 */
	public function format_contact_data( $data ) {

		$crm_data = array();

		// Regular fields.
		$fields_mapping = $this->fields_mapping();

		foreach ( $fields_mapping['Regular'] as $old => $new ) {
			if ( isset( $data[ $old ] ) ) {

				if ( 'preferred_locale' === $new && 5 !== strlen( $data[ $old ] ) ) {
					wpf_log( 'notice', wpf_get_current_user_id(), 'To sync the "Language" field you must specify a valid locale code, for example "en_US". <strong>' . $data[ $old ] . '</strong> is not a valid value.', array( 'source' => $this->slug ) );
					unset( $data[ $old ] );
					continue;
				}

				if ( 'leadsource_id' === $new && ! is_numeric( $data[ $old ] ) ) {
					wpf_log( 'notice', wpf_get_current_user_id(), 'The Lead Source ID field must be a numeric value. <strong>' . $data[ $old ] . '</strong> is not a valid value.', array( 'source' => $this->slug ) );
					unset( $data[ $old ] );
					continue;
				}

				$crm_data[ $new ] = $data[ $old ];
				unset( $data[ $old ] );
			}
		}

		if ( isset( $data['Password'] ) ) {

			// The password field was removed in the REST API.
			wpf_log( 'notice', 0, 'The "Password" standard field was removed from the Infusionsoft REST API. To continue syncing passwords with Infusionsoft/Keap, please create a new custom text field to store the password.' );
			unset( $data['Password'] );

		}

		if ( isset( $data['Username'] ) ) {

			// The password field was removed in the REST API.
			wpf_log( 'notice', 0, 'The "Username" standard field was removed from the Infusionsoft REST API. To continue syncing usernames with Infusionsoft/Keap, please create a new custom text field to store the username.' );
			unset( $data['Username'] );

		}

		// Addresses.

		foreach ( $fields_mapping['addresses'] as $address_type => $properties ) {

			foreach ( $properties as $old => $new ) {

				if ( ! empty( $data[ $old ] ) ) {

					if ( ! isset( $crm_data['addresses'] ) ) {
						$crm_data['addresses'] = array();
					}

					// See if we need to create the type.

					$found = false;
					$i     = 0;
					foreach ( $crm_data['addresses'] as $i => $address ) {
						if ( $address['field'] === $address_type ) {
							$found = true;
							break;
						}
					}

					// Create the array for that address type.

					if ( ! $found ) {
						$crm_data['addresses'][] = array(
							'field' => $address_type,
							$new    => $data[ $old ],
						);
					}

					$crm_data['addresses'][ $i ][ $new ] = $data[ $old ];

					unset( $data[ $old ] );

				}
			}
		}

		// Format address country and region codes.

		if ( ! empty( $crm_data['addresses'] ) ) {
			foreach ( $crm_data['addresses'] as $i => $address ) {

				if ( ! empty( $address['country_code'] ) && isset( $address['region'] ) && 2 === strlen( $address['region'] ) && ! is_numeric( $address['region'] ) ) {
					// Add country code to region code.
					$alpha_2_code                               = wpf_country_to_iso3166( $address['country_code'], 'alpha-2' );
					$crm_data['addresses'][ $i ]['region_code'] = $alpha_2_code . '-' . strtoupper( $address['region'] );
				} elseif ( empty( $address['country_code'] ) && isset( $address['region'] ) && wp_fusion()->iso_regions->is_us_state( $address['region'] ) ) {
					// Add USA country code if a US state is supplied.
					$crm_data['addresses'][ $i ]['region_code']  = 'US-' . strtoupper( $address['region'] );
					$crm_data['addresses'][ $i ]['country_code'] = 'USA';

				} elseif ( ! empty( $address['region'] ) && empty( $address['country_code'] ) ) {

					// Log a notice for unknown countries.
					wpf_log(
						'notice',
						wpf_get_current_user_id(),
						'Unable to determine country code for address region <code>' . $address['region'] . '</code>. Please ensure a Country field is enabled and mapped for this address.',
						array(
							'source'              => wp_fusion()->crm->slug,
							'meta_array_nofilter' => $crm_data['addresses'][ $i ],
						)
					);
				}
			}
		}

		// Objects.
		foreach ( $fields_mapping['Objects'] as $new => $old ) {
			$used = array();

			foreach ( $old as $value ) {
				$value     = explode( '+', $value );
				$old_value = $value[0];
				$new_value = $value[1];

				if ( isset( $data[ $old_value ] ) ) {

					if ( 'phone_numbers' === $new ) {
						$ar_key                        = count( $value ) - 1;
						$used[ $ar_key ][ $new_value ] = $data[ $old_value ];

						$field                    = 'PHONE' . $ar_key;
						$used[ $ar_key ]['field'] = $field;
					} else {
						$used[ $new_value ] = $data[ $old_value ];
					}
				}

				unset( $data[ $old_value ] );
			}

			if ( ! empty( $used ) ) {
				// Reset array keys for numbers and addresses.
				$crm_data[ $new ] = ( $new === 'company' ? $used : array_values( $used ) );
			}
		}

		// Email addresses.
		foreach ( $fields_mapping['Emails'] as $old_email => $new_email ) {
			if ( isset( $data[ $old_email ] ) ) {

				if ( ! isset( $crm_data['email_addresses'] ) ) {
					$crm_data['email_addresses'] = array();
				}

				$crm_data['email_addresses'][] = array(
					'email'         => $data[ $old_email ],
					'field'         => $new_email,
					'opt_in_reason' => __( 'Contact was opted in through the WP Fusion integration.', 'wp-fusion-lite' ),
				);
				unset( $data[ $old_email ] );
			}
		}

		// Social accounts.
		$social_data = $this->get_social_fields();
		if ( ! empty( $social_data ) ) {
			foreach ( $data as $key => $value ) {
				if ( array_search( $key, $social_data ) !== false ) {

					if ( ! isset( $crm_data['social_accounts'] ) ) {
						$crm_data['social_accounts'] = array();
					}

					$crm_data['social_accounts'][] = array(
						'name' => $value,
						'type' => strtoupper( str_replace( 'LinkedIn', 'LINKED_IN', $key ) ),
					);
					unset( $data[ $key ] );
				}
			}
		}

		// Custom fields.
		foreach ( $data as $crm_field => $value ) {

			$id = $this->get_custom_field_id( $crm_field );

			if ( ! $id ) {
				wpf_log( 'notice', wpf_get_current_user_id(), 'Custom field <strong>' . $crm_field . '</strong> is not a valid custom field.' );
				continue;
			}

			if ( ! isset( $crm_data['custom_fields'] ) ) {
				$crm_data['custom_fields'] = array();
			}

			$crm_data['custom_fields'][] = array(
				'content' => $value,
				'id'      => $id,
			);

			unset( $data[ $crm_field ] );
		}

		return $crm_data;
	}

	/**
	 * Removes special characters from a string (for custom field names).
	 *
	 * @since 3.44.3
	 *
	 * @param string $string The string to remove special characters from.
	 * @return string The string with special characters removed.
	 */
	private function remove_special_characters( $string ) {
		// Remove any special characters except letters, digits, and spaces
		return preg_replace( '/[^a-zA-Z0-9]/', '', $string );
	}

	/**
	 * Gets custom field id as old sdk only had custom field name and rest api requires the id.
	 *
	 * @since 3.44.0
	 *
	 * @param string $custom_field The custom field name.
	 * @return int|WP_Error The custom field ID or error.
	 */
	private function get_custom_field_id( $custom_field ) {

		$custom_field = $this->remove_special_characters( $custom_field );

		$new_custom_fields = wpf_get_option( 'api_custom_fields', array() );

		// Also check without special characters.
		foreach ( $new_custom_fields as $key => $value ) {
			$new_key                       = $this->remove_special_characters( $key );
			$new_custom_fields[ $new_key ] = $value;
		}

		if ( array_key_exists( $custom_field, $new_custom_fields ) ) {
			return $new_custom_fields[ $custom_field ];
		}

		$api_custom_fields = array();

		$response = wp_remote_get( $this->url . 'contacts/model/', $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach ( $response->custom_fields as $field ) {

			$id = '_' . $this->remove_special_characters( $field->label );

			$api_custom_fields[ $id ] = $field->id;
		}

		wp_fusion()->settings->set( 'api_custom_fields', $api_custom_fields );

		return ( isset( $api_custom_fields[ $custom_field ] ) ? (int) $api_custom_fields[ $custom_field ] : false );
	}


	/**
	 * Adds a new contact
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param array $data The data to add.
	 * @return int|WP_Error The contact ID or error.
	 */
	public function add_contact( $data ) {

		$data = $this->format_contact_data( $data );

		if ( isset( $data['OptinStatus'] ) ) {
			// This isn't a real field and can't be synced.
			unset( $data['OptinStatus'] );
		}

		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $data );

		$response = wp_remote_post( $this->url . 'contacts/', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return intval( $response->id );
	}


	/**
	 * Update contact, with error handling
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param int   $contact_id The contact ID.
	 * @param array $data       The data to update.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function update_contact( $contact_id, $data ) {

		$data = $this->format_contact_data( $data );

		if ( isset( $data['OptinStatus'] ) ) {
			// This isn't a real field and can't be synced.
			unset( $data['OptinStatus'] );
		}

		if ( isset( $data['notes'] ) ) {
			// Append to the existing notes.
			$existing_notes = $this->get_person_notes( $contact_id );

			if ( is_wp_error( $existing_notes ) ) {
				return $existing_notes;
			}

			$data['notes'] = $existing_notes . "\n" . $data['notes'];
		}

		$params           = $this->get_params();
		$params['method'] = 'PATCH';

		$params['body'] = wp_json_encode( $data );

		$response = wp_remote_request( $this->url . 'contacts/' . $contact_id, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Gets the Person Notes field on a contact.
	 *
	 * @since 3.44.1.1
	 *
	 * @param int $contact_id The contact ID.
	 * @return string|WP_Error Notes on success, error on failure.
	 */
	public function get_person_notes( $contact_id ) {

		$request  = $this->url . 'contacts/' . $contact_id . '?fields=notes';
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		return strval( $body_json->notes );
	}


	/**
	 * Loads a contact and returns local user meta.
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param int $contact_id The contact ID.
	 * @return array|WP_Error User meta data that was returned or error.
	 */
	public function load_contact( $contact_id ) {

		$return_fields = array( 'addresses', 'anniversary_date', 'birth_date', 'company', 'custom_fields', 'email_addresses', 'job_title', 'leadsource_id', 'owner_id', 'phone_numbers', 'social_accounts', 'website', 'notes' );

		$request  = $this->url . 'contacts/' . $contact_id . '?fields=' . implode( ',', $return_fields );
		$response = wp_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $result ) ) {
			return array();
		}

		$result    = $this->format_load_contact( $result );
		$user_meta = array();

		foreach ( $result as $field => $value ) {

			foreach ( wpf_get_option( 'contact_fields' ) as $field_id => $field_data ) {

				if ( $field_data['active'] && $field === $field_data['crm_field'] ) {

					// Check if result is a date field
					if ( DateTime::createFromFormat( 'Y-m-d\TH:i:s.u\Z', $value ) !== false ) {
						// Set to default WP date format
						$date_format = wpf_get_datetime_format();
						$value       = gmdate( $date_format, strtotime( $value ) );
					}

					$user_meta[ $field_id ] = $value;
				}
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param string $tag|bool The tag name or false to load all contacts.
	 * @return array|WP_Error The contact IDs or error.
	 */
	public function load_contacts( $tag = false ) {

		$contact_ids = array();
		$proceed     = true;

		if ( $tag ) {
			$request = $this->url . 'tags/' . $tag . '/contacts/';
		} else {
			$request = $this->url . 'contacts/';
		}

		while ( $proceed ) {
			$response = wp_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->contacts as $contact ) {
				$contact_ids[] = $contact->id;
			}

			if ( $response->next_page_token == '' ) {
				$proceed = false;
			}

			$request = $response->next_page_token;

		}

		return $contact_ids;
	}

	/**
	 * Optionally sends an API call after a contact has been updated
	 *
	 * @since 1.0.0
	 * @since 3.44.0 Updated to use REST API.
	 *
	 * @param int $contact_id The contact ID.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function send_api_call( $contact_id ) {

		if ( wpf_get_option( 'api_call' ) ) {

			$params = $this->get_params();

			$data = array(
				'contact_id'                    => $contact_id,
				'funnel_integration_trigger_id' => wpf_get_option( 'api_call_integration' ),
				'schema_data'                   => wpf_get_option( 'api_call_name' ),
			);

			$params['body'] = wp_json_encode( $data );
			$request        = $this->url . 'funnelIntegration/trigger/';
			$response       = wp_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return true;

		}
	}
}
