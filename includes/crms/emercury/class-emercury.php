<?php

class WPF_Emercury {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'emercury';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'Emercury';

	/**
	 * Contains API interface.
	 *
	 * @var object
	 * @since 3.37.8
	 */

	public $app;

	/**
	 * Contains API url.
	 *
	 * @var string
	 * @since 3.37.8
	 */

	public $url = 'https://panel.emercury.net/api.php';

	/**
	 * Declares how this CRM handles tags and fields.
	 *
	 * "add_tags" means that tags are applied over the API as strings (no tag IDs).
	 * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
	 *
	 * "add_fields" means that emercury field / attrubute keys don't need to exist first in the CRM to be used.
	 * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
	 *
	 * @var array
	 * @since 3.37.8
	 */

	public $supports = array( 'add_tags' );

	/**
	 * API parameters
	 *
	 * @var array
	 * @since 3.37.8
	 */

	public $params = array();


	/**
	 * Lets us link directly to editing a contact record.
	 * No edit page available only through ajax.
	 *
	 * @var string
	 */

	public $edit_url = false;

	/**
	 * Get things started
	 *
	 * @since 3.37.8
	 */
	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once __DIR__ . '/class-emercury-admin.php';
			new WPF_Emercury_Admin( $this->slug, $this->name, $this );
		}

		// Error handling
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
	}

	/**
	 * Sets up hooks specific to this CRM.
	 *
	 * This function only runs if this CRM is the active CRM.
	 *
	 * @since 3.37.8
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

		// Add tracking code to header.
		add_action( 'wp_footer', array( $this, 'tracking_code_output' ) );
	}


	/**
	 * Slow down batch processses to get around the 3600 requests per hour
	 * limit.
	 *
	 * @since  3.37.8
	 *
	 * @link   http://help.emercury.net/en/articles/1783995-emercury-api-xml
	 *
	 * @param  int $seconds The seconds.
	 * @return int   Sleep time
	 */
	public function set_sleep_time( $seconds ) {

		return 1;
	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @since  3.37.8
	 *
	 * @param  array $post_data The POST data.
	 * @return array The POST data.
	 */
	public function format_post_data( $post_data ) {

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$emercury_list = wpf_get_option( 'emercury_list' );

		if ( ! empty( $payload ) && ! empty( $emercury_list ) && ! empty( $payload->subscriber_email ) ) {

			$post_data['contact_id'] = absint( $emercury_list ) . '_' . sanitize_email( $payload->subscriber_email );

			if ( 'subscribe_add' === (string) $payload->event && (int) $payload->list_id != $emercury_list ) {

				wpf_log( 'notice', 0, 'add webhook received but, Subscriber does not belong to the selected list' );
				wp_die( '', 'Success', 200 );
			}

			if ( ! empty( $payload->tags ) ) {

				if ( 'tag_add' === (string) $payload->event ) {

					$user_id = wp_fusion()->user->get_user_id( $post_data['contact_id'] );

					if ( $user_id ) {

						$user_tags = wp_fusion()->user->get_tags( $user_id, false, false );

						foreach ( $payload->tags as $tag ) {
							$tags_add[] = $tag->tag_name;
						}

						$tags = array_diff( $user_tags, $tags_add );

						$post_data['tags'] = array_merge( array_values( $tags ), array_values( $tags_add ) );

					}
				} elseif ( 'tag_remove' === (string) $payload->event ) {

					$user_id = wp_fusion()->user->get_user_id( $post_data['contact_id'] );

					if ( $user_id ) {

						$user_tags = wp_fusion()->user->get_tags( $user_id, false, false );

						foreach ( $payload->tags as $tag ) {
							$tags_add[] = $tag->tag_name;
						}

						$post_data['tags'] = array_diff( $user_tags, $tags_add );

					}
				}
			}
		}

		return $post_data;
	}


	/**
	 * Formats user entered data to match Emercury field formats.
	 *
	 * @since  3.37.8
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type.
	 * @param  string $field      The CRM field
	 * @return mixed  The value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'datepicker' == $field_type || 'date' == $field_type ) {

			// Adjust formatting for date fields
			$date = date( 'm/d/Y', $value );

			return $date;

		} else {

			return $value;

		}
	}


	/**
	 * Gets params for API calls.
	 *
	 * @since  3.37.8
	 *
	 * @param  string $api_url The api url.
	 * @param  string $api_key The api key.
	 * @return array  $params The API parameters.
	 */
	public function get_params( $api_url = null, $api_key = null ) {

		// If it's already been set up

		if ( $this->params ) {
			return $this->params;
		}

		// Get saved data from DB
		if ( empty( $api_url ) || empty( $api_key ) ) {
						$api_email = wpf_get_option( 'emercury_email' );
			$api_key               = wpf_get_option( 'emercury_key' );
		}

		$this->params = array(
			'user-agent' => 'WP Fusion; ' . home_url(),
			'timeout'    => 15,
			'headers'    => array(
				'Api-Key' => $api_key,
			),
		);

		return $this->params;
	}


	/**
	 * Check HTTP Response for errors and return WP_Error if found.
	 *
	 * @since  3.37.8
	 *
	 * @param  WP_HTTP_Response $response The HTTP response.
	 * @param  array            $args     The HTTP request arguments.
	 * @param  string           $url      The HTTP request URL.
	 * @return WP_HTTP_Response $response The response.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() == $args['user-agent'] ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( isset( $body_json->success ) && false == $body_json->success ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 500 == $response_code ) {

				$response = new WP_Error( 'error', __( 'An error has occurred in API server. [error 500]', 'wp-fusion-lite' ) );

			}
		}

		return $response;
	}


	/**
	 * Initialize connection.
	 *
	 * This is run during the setup process to validate that the user has
	 * entered the correct API credentials.
	 *
	 * @since  3.37.8
	 *
	 * @param  string $api_email The first API credential.
	 * @param  string $api_key   The second API credential.
	 * @param  bool   $test      Whether to validate the credentials.
	 * @return bool|WP_Error A WP_Error will be returned if the API credentials are invalid.
	 */
	public function connect( $api_email = null, $api_key = null, $test = false ) {

		if ( isset( $this->app ) && $test == false ) {
			return true;
		}

		// Get saved data from DB
		if ( empty( $api_email ) || empty( $api_key ) ) {
			$api_url   = wpf_get_option( 'emercury_url' );
			$api_email = wpf_get_option( 'emercury_email' );
			$api_key   = wpf_get_option( 'emercury_key' );
		}

		if ( ! class_exists( 'WPF_Emercury_API' ) ) {
			require_once 'class-emercury-api.php';
		}

		$app = new WPF_Emercury_API( $api_email, $api_key );

		if ( true == $test ) {

			$response = $app->getCustomFields();

			if ( 'error' == $response['code'] || ! isset( $response['message']->Fields ) ) {
				return new WP_Error( 'error', __( 'Access denied: Invalid credentials (Email and/or API key).', 'wp-fusion-lite' ) );
			}
		}

		// Connection was a success
		$this->app = $app;

		return true;
	}


	/**
	 * Performs initial sync once connection is configured.
	 *
	 * @since  3.37.8
	 *
	 * @return bool
	 */
	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();

		$this->sync_crm_fields();

		$this->sync_lists();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options.
	 *
	 * @since 3.37.8
	 *
	 * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
	 */
	public function sync_tags() {

		$available_tags = array();

		$response = $this->app->getTags();

		if ( $response['code'] == 'ok' && isset( $response['message']->tags->tag ) ) {

			foreach ( $response['message']->tags->tag as $key => $value ) {

				$available_tags[ (string) $value->tag_name ] = (string) $value->tag_name;

			}
		}

		// Load available tags into $available_tags like 'tag_id' => 'Tag Label'
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

		$available_lists = array();

		$audience = $this->app->getAudience();

		if ( $audience['code'] == 'ok' && isset( $audience['message']->audiences->audience ) ) {

			foreach ( $audience['message']->audiences->audience as $key => $value ) {

				if ( (string) $value->name !== 'All Subscribers' ) {

					$available_lists[ (string) $value->id ] = (string) $value->name;

				}
			}
		}

		asort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;
	}


	/**
	 * Loads all emercury fields from CRM and merges with local list.
	 *
	 * @since 3.37.8
	 *
	 * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
	 */
	public function sync_crm_fields() {

		// Load built in fields
		require_once __DIR__ . '/emercury-fields.php';

		$built_in_fields = array();

		foreach ( $emercury_fields as $index => $data ) {
			if ( isset( $data['crm_field_2'] ) ) {
				$built_in_fields[ $data['crm_field_2'] ] = $data['crm_field'];
			}
		}

		$crm_fields = array();

		$custom_fields = $this->app->getCustomFields();

		if ( $custom_fields['code'] == 'ok' && isset( $custom_fields['message']->Fields->Field ) ) {

			foreach ( $custom_fields['message']->Fields->Field as $key => $value_fields ) {

				$field_key = isset( $built_in_fields[ (string) $value_fields->real_name ] ) ? $built_in_fields[ (string) $value_fields->real_name ] : (string) $value_fields->real_name;

				$crm_fields[ $field_key ] = (string) $value_fields->name;

			}
		}

		// Load available fields into $crm_fields like 'field_key' => 'Field Label'

		// asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since 3.37.8
	 *
	 * @param string $email_address The email address to look up.
	 * @return int|WP_Error The contact ID in the CRM.
	 */
	public function get_contact_id( $email_address ) {

		$emercury_list = wpf_get_option( 'emercury_list' );

		if ( empty( $emercury_list ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$email_address = strtolower( $email_address );

		$response = $this->app->getSubscribers( $emercury_list, $email_address );

		if ( $response['code'] == 'ok' && isset( $response['message']->subscribers->subscriber->email ) && (string) strtolower( $response['message']->subscribers->subscriber->email ) === $email_address ) {
			$contact_id = $emercury_list . '_' . strtolower( $response['message']->subscribers->subscriber->email );
		} else {
			$contact_id = false;
		}

		// Parse response for contact ID here.

		return $contact_id;
	}


	/**
	 * Gets all tags currently applied to the contact in the CRM.
	 *
	 * @since 3.37.8
	 *
	 * @param int $contact_id The contact ID to load the tags for.
	 * @return array|WP_Error The tags currently applied to the contact in the CRM.
	 */
	public function get_tags( $contact_id ) {

		$tags = array();

		list( $emercury_list, $email_address ) = explode( '_', $contact_id );

		if ( empty( $email_address ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->getSubscriberTags( $email_address );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] == 'ok' && isset( $response['message']->subscribers->subscriber->tags->tag ) ) {
			foreach ( $response['message']->subscribers->subscriber->tags->tag as $key => $value_fields ) {
					$tags[] = (string) $value_fields->tag_name;
			}
		}

		// Parse response to create an array of tag ids. $tags = array(123, 678, 543); (should not be an associative array)

		return $tags;
	}

	/**
	 * Applies tags to a contact.
	 *
	 * @since 3.37.8
	 *
	 * @param array $tags       A numeric array of tags to apply to the contact.
	 * @param int   $contact_id The contact ID to apply the tags to.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function apply_tags( $tags, $contact_id ) {

		list( $emercury_list, $email_address ) = explode( '_', $contact_id );

		if ( empty( $email_address ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->addSubscriberTag( implode( ',', $tags ), $email_address );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact.
	 *
	 * @since 3.37.8
	 *
	 * @param array $tags       A numeric array of tags to remove from the contact.
	 * @param int   $contact_id The contact ID to remove the tags from.
	 * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
	 */
	public function remove_tags( $tags, $contact_id ) {

		list($emercury_list, $email_address) = explode( '_', $contact_id );

		if ( empty( $email_address ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->deleteSubscriberTag( implode( ',', $tags ), $email_address );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.37.8
	 *
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return int|WP_Error Contact ID on success, or WP Error.
	 */
	public function add_contact( $contact_data ) {

		$emercury_list = wpf_get_option( 'emercury_list' );

		if ( empty( $emercury_list ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->updateSubscribers( $contact_data, $emercury_list );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['code'] == 'ok' && isset( $response['message']->subscribers->subscriber->email ) ) {
			$contact_id = $emercury_list . '_' . strtolower( $response['message']->subscribers->subscriber->email );
		}

		// Get new contact ID out of response
		return $contact_id;
	}

	/**
	 * Updates an existing contact record.
	 *
	 * @since 3.37.8
	 *
	 * @param int   $contact_id      The ID of the contact to update.
	 * @param array $contact_data    An associative array of contact fields and field values.
	 * @return bool|WP_Error Error if the API call failed.
	 */
	public function update_contact( $contact_id, $contact_data ) {

		list( $emercury_list ) = explode( '_', $contact_id );

		if ( empty( $emercury_list ) ) {
			$emercury_list = wpf_get_option( 'emercury_list' );
		}

		if ( empty( $emercury_list ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->updateSubscribers( $contact_data, $emercury_list );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact record from the CRM and maps CRM fields to WordPress fields.
	 *
	 * @since 3.37.8
	 *
	 * @param int $contact_id The ID of the contact to load.
	 * @return array|WP_Error User meta data that was returned.
	 */
	public function load_contact( $contact_id ) {

		list( $emercury_list, $email_address ) = explode( '_', $contact_id );

		if ( empty( $emercury_list ) || empty( $email_address ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->getSubscribers( $emercury_list, $email_address );

		if ( $response['code'] == 'ok' && isset( $response['message']->subscribers->subscriber->email ) ) {
			$contact_id = $emercury_list . '_' . (string) $response['message']->subscribers->subscriber->email;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta = array();

		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] && isset( $response['message']->subscribers->subscriber->{ $field_data['crm_field'] } ) ) {

				$user_meta[ $field_id ] = (string) $response['message']->subscribers->subscriber->{ $field_data['crm_field'] };

			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag.
	 *
	 * @since 3.37.8
	 *
	 * @param string $tag The tag ID or name to search for.
	 * @return array Contact IDs returned.
	 */
	public function load_contacts( $tag ) {

		$emercury_list = wpf_get_option( 'emercury_list' );

		if ( empty( $emercury_list ) ) {
			return false;
		}

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$contact_ids = array();

		$response = $this->app->getSubscribersByTag( $tag, $emercury_list );

		if ( $response['code'] == 'ok' && isset( $response['message']->subscribers->subscriber ) ) {
			foreach ( $response['message']->subscribers->subscriber as $contact ) {
				$contact_ids[] = $emercury_list . '_' . (string) $contact->email;
			}
		}

		// Iterate over the contacts returned in the response and build an array such that $contact_ids = array(1,3,5,67,890);

		return $contact_ids;
	}

	/**
	 * Gets tracking ID for site tracking script.
	 *
	 * @since  3.38.14
	 *
	 * @return int   Tracking ID.
	 */
	public function get_tracking_id() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$response = $this->app->getTrackingCode();

		if ( ! empty( $response ) && ! empty( $response['message'] ) ) {

			$code = strval( $response['message']->code );

			wp_fusion()->settings->set( 'site_tracking_id', $code );
			return $code;

		} else {
			return false;
		}
	}

	/**
	 * Output tracking code.
	 *
	 * @since 3.38.14
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) || wpf_get_option( 'staging_mode' ) ) {
			return;
		}

		$trackid = wpf_get_option( 'site_tracking_id' );

		echo '<!-- Start of Emercury.net Tracking Code via WP Fusion -->';
		echo '<script>';
		echo '!function(e, t, n, o, p, i, a) {';
		echo 'e[o] || ((p = e[o] = function() {';
		echo 'p.process ? p.process.apply(p, arguments) : p.queue.push(arguments)';
		echo '}).queue = [], p.t = +new Date, (i = t.createElement(n)).async = 1, i.src = "https://tracking.emercury.net/em.analytics.js?t=1.0.1", (a = t.getElementsByTagName(n)[0]).parentNode.insertBefore(i, a))';
		echo '}(window, document, "script", "emer");';
		echo 'emer("init", "' . esc_js( $trackid ) . '");';
		echo 'emer("event", "pageload");';

		if ( wpf_is_user_logged_in() || isset( $_COOKIE['wpf_guest'] ) ) {

			// This will also merge historical tracking data that was accumulated before a visitor registered.

			$email = wpf_get_current_user_email();

			echo 'var identity = {';
			echo 'email : "' . esc_js( $email ) . '"';
			echo '}' . PHP_EOL;
		}

		echo 'emer("param", "auth", {identity : identity});';
		echo '</script>';

		echo '<!-- End of Emercury.net Tracking Code via WP Fusion -->';
	}
}
