<?php

// Max 6 API requests second / 400 per minute https://developers.sendinblue.com/docs/api-limits

class WPF_SendinBlue {


	/**
	 * The integration slug.
	 *
	 * @since 3.16.0
	 * @var string $slug The slug.
	 */
	public $slug = 'sendinblue';

	/**
	 * The integration name.
	 *
	 * @since 3.16.0
	 * @var string $name The name.
	 */
	public $name = 'Brevo';

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Reserved events keys that users cannot use in keys fields.
	 *
	 * @since 3.41.31
	 *
	 * @var array
	 */
	public $reserved_events_keys = array( 'name', 'id' );

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports = array( 'events', 'events_multi_key' );

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var string
	 */
	public $tag_type = 'List';

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @var string
	 */

	public $edit_url = 'https://app.brevo.com/contact/index/%d';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_SendinBlue_Admin( $this->slug, $this->name, $this );
		}

		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		// Add tracking code to header.
		add_action( 'wp_head', array( $this, 'tracking_code_output' ) );

	}

	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( isset( $payload->properties ) ) {

			$post_data['contact_id'] = absint( $payload->properties->contact_id );

		} elseif ( isset( $payload->email ) ) {

			// Global webhooks.

			$email = sanitize_email( $payload->email );
			$user  = get_user_by( 'email', $email );

			if ( $user ) {
				$post_data['contact_id'] = wpf_get_contact_id( $user->ID );
			} else {
				$post_data['contact_id'] = $this->get_contact_id( $email );
			}

			// Handle email changes.

			if ( ! empty( $payload->content[0]->updated_email ) ) {

				$updated_email = sanitize_email( $payload->content[0]->updated_email );

				$user = get_user_by( 'email', $email );

				if ( ! empty( $user ) ) {

					$userdata = array(
						'ID'         => $user->ID,
						'user_email' => $updated_email,
					);

					wp_update_user( $userdata );

					update_user_meta( $user->ID, 'sendinblue_contact_id', $updated_email );

					$post_data['contact_id'] = wpf_get_contact_id( $user->ID );

				}
			}
		}

		return $post_data;

	}

	/**
	 * Formats user entered data to match SendinBlue field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		// Categories are stored numerically, like https://share.getcloudapp.com/L1uNjnLA.
		// We'll try and convert them back here.

		$dropdown_mappings = wpf_get_option( 'dropdown_mappings', array() );

		if ( isset( $dropdown_mappings[ $field ] ) ) {

			// We used this briefly for about a week in January 2024. Will keep it for now for backwards compat.

			if ( is_array( $value ) ) {

				foreach ( $value as $key => $val ) {

					if ( isset( $dropdown_mappings[ $field ][ $val ] ) ) {

						$value[ $key ] = $dropdown_mappings[ $field ][ $val ];
					}
				}
			} else {

				if ( isset( $dropdown_mappings[ $field ][ $value ] ) ) {
					$value = $dropdown_mappings[ $field ][ $value ];
				}
			}
		}

		if ( $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = gmdate( 'Y-m-d', intval( $value ) );

			return $date;

		} elseif ( $field_type == 'checkbox' && $value == null ) {

			// Brevo only treats false as a No for checkboxes
			return false;

		} elseif ( $field_type == 'checkbox' && ! empty( $value ) ) {

			// Brevo only treats true as a Yes for checkboxes
			return true;

		} elseif ( 'tel' === $field_type ) {

			// Format phone. Brevo requires a country code and + for phone numbers. With or without dashes is fine.

			if ( strpos( $value, '+' ) !== 0 ) {

				// Default to US if no country code is provided

				if ( strpos( $value, '1' ) === 0 ) {

					// The plus was causing an error with WhatsApp field.
					// $value = '+' . $value;

				} else {

					$value = '1' . $value;

				}

			}

			return $value;

		} elseif ( is_array( $value ) ) {

			return implode( ', ', array_filter( $value ) );

		} elseif ( 'raw' !== $field_type && is_numeric( trim( str_replace( array( '-', ' ' ), '', $value ) ) ) && 'tel' === wpf_get_remote_field_type( $field, 'tel' ) ) {

			$length = strlen( trim( str_replace( array( '-', ' ' ), '', $value ) ) );

			// Maybe another phone number

			if ( $length == 10 ) {

				// Let's assume this is a US phone number and needs a +1

				$value = '1' . $value;

			} elseif ( $length >= 11 && $length <= 13 && strpos( $value, '+' ) === false ) {

				// Let's assume this is a phone number and needs a plus??

				// Plus throws errors with some fields: https://developers.brevo.com/reference/createcontact .

				// $value = '+' . $value;

			}

			return $value;

		} elseif ( false !== wpf_get_remote_option_value( $value, $field ) ) {

			// Maybe a dropdown value

			return wpf_get_remote_option_value( $value, $field );

		} else {

			return $value;

		}

	}


	/**
	 * Output tracking code.
	 *
	 * @since 3.40.5
	 *
	 * @return mixed The HTML code output.
	 */
	public function tracking_code_output() {

		if ( ! wpf_get_option( 'site_tracking' ) ) {
			return;
		}

		$tracking_id = wpf_get_option( 'site_tracking_key' );

		if ( empty( $tracking_id ) ) {
			return;
		}

		echo '<script type="text/javascript">';
		echo '(function() {';
		echo '	window.sib = {';
		echo '        equeue: [],';
		echo '        client_key: "' . esc_js( $tracking_id ) . '"';
		echo '    };';
		if ( wpf_is_user_logged_in() ) {
			echo 'window.sib.email_id = "' . esc_js( wpf_get_current_user_email() ) . '";';
		}
		echo '    window.sendinblue = {};';
		echo '    for (var j = ["track", "identify", "trackLink", "page"], i = 0; i < j.length; i++) {';
		echo '    (function(k) {';
		echo '        window.sendinblue[k] = function() {';
		echo '            var arg = Array.prototype.slice.call(arguments);';
		echo '            (window.sib[k] || function() {';
		echo '                    var t = {};';
		echo '                    t[k] = arg;';
		echo '                    window.sib.equeue.push(t);';
		echo '                })(arg[0], arg[1], arg[2], arg[3]);';
		echo '            };';
		echo '        })(j[i]);';
		echo '    }';
		echo '    var n = document.createElement("script"),';
		echo '        i = document.getElementsByTagName("script")[0];';
		echo '    n.type = "text/javascript", n.id = "sendinblue-js", n.async = !0, n.src = "https://sibautomation.com/sa.js?key=" + window.sib.client_key, i.parentNode.insertBefore(n, i), window.sendinblue.page();';
		echo '})();';
		echo '</script>';

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if ( strpos( $url, 'brevo' ) !== false && $args['user-agent'] == 'WP Fusion; ' . home_url() ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $body_json->error ) ) {

				$response = new WP_Error( 'error', $body_json->error->message );

			} elseif ( isset( $body_json->code ) && $body_json->code == 'unauthorized' ) {

				$response = new WP_Error( 'error', 'Invalid API key' );

			} elseif ( isset( $body_json->code ) && $body_json->code == 'invalid_parameter' ) {

				$response = new WP_Error( 'error', $body_json->message );

			} elseif ( 400 === wp_remote_retrieve_response_code( $response ) ) {

				// Sales CRM responses.

				if ( ! empty( $body_json->data ) ) {
					$body_json->message .= ' ' . implode( ' ', $body_json->data );
				}

				$response = new WP_Error( 'error', $body_json->message );

			}
		}

		return $response;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $api_key = null ) {

		// Get saved data from DB.
		if ( empty( $api_key ) ) {
			$api_key = wpf_get_option( 'sendinblue_key' );
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/json',
				'api-key'      => $api_key,
			),
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $api_key = null, $test = false ) {

		if ( ! $test ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = 'https://api.brevo.com/v3/account';
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
		$this->sync_optin_templates();

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

		$available_tags = array();

		$offset  = 0;
		$limit   = 50;
		$proceed = true;

		while ( $proceed ) {

			$request  = 'https://api.brevo.com/v3/contacts/lists?limit=' . $limit . '&offset=' . $offset;
			$response = wp_safe_remote_get( $request, $this->get_params() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['lists'] as $row ) {
				$available_tags[ absint( $row['id'] ) ] = $row['name'];
			}

			if ( count( $body_json['lists'] ) < $limit ) {
				$proceed = false;
			} else {
				$offset = $offset + $limit;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array|WP_Error CRM Fields or error.
	 */

	public function sync_crm_fields() {

		$crm_fields = array(
			'Standard Fields' => array(
				'email'    => array(
					'crm_label' => 'Email Address',
					'crm_type'  => 'email',
				),
				'WHATSAPP' => array(
					'crm_label' => 'WhatsApp',
					'crm_type'  => 'tel',
				),
				'SMS' => array(
					'crm_label' => 'SMS',
					'crm_type'  => 'tel',
				),
			),
		);

		$request  = 'https://api.brevo.com/v3/contacts/attributes';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['attributes'] as $field_data ) {

			if ( 'SMS' === $field_data['name'] ) {
				continue; // this is a standard field.
			}

			$crm_fields['Custom Fields'][ $field_data['name'] ] = array(
				'crm_label' => ucwords( str_replace( '_', ' ', $field_data['name'] ) ),
				'crm_type'  => isset( $field_data['type'] ) ? $field_data['type'] : 'text',
			);

			// Handle category mappings.
			if ( isset( $field_data['enumeration'] ) ) {

				$crm_fields['Custom Fields'][ $field_data['name'] ]['crm_type'] = 'select';
				$crm_fields['Custom Fields'][ $field_data['name'] ]['choices']     = array();

				foreach ( $field_data['enumeration'] as $option ) {
					$crm_fields['Custom Fields'][ $field_data['name'] ]['choices'][ $option['value'] ] = $option['label'];
				}
			}
		}

		uasort( $crm_fields['Custom Fields'], 'wpf_sort_remote_fields' );
		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;

	}

	/**
	 * Syncs and stores the available optin templates.
	 *
	 * @since 3.42.5
	 *
	 * @return array|WP_Error The optin templates or error.
	 */
	public function sync_optin_templates() {

		$optin_templates = array();

		$request  = 'https://api.brevo.com/v3/smtp/templates?limit=100';
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		foreach ( $body_json['templates'] as $row ) {

			if ( 'optin' === $row['tag'] ) {
				$optin_templates[ $row['id'] ] = $row['name'];
			}
		}

		wp_fusion()->settings->set( 'optin_templates', $optin_templates );

		return $optin_templates;

	}

	/**
	 * Gets contact ID for a user based on email address.
	 *
	 * @since 3.16.0
	 *
	 * @param string $email_address Email address.
	 * @return int Contact ID.
	 */
	public function get_contact_id( $email_address ) {

		// Brevo converts email addresses to lowercase so we'll do the same for contact ID lookups.

		$request  = 'https://api.brevo.com/v3/contacts/' . rawurlencode( strtolower( $email_address ) );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( 404 === wp_remote_retrieve_response_code( $response ) || empty( $body_json ) ) {
			return false;
		}

		return absint( $body_json->id );
	}

	/**
	 * Gets all lists currently applied to the user.
	 *
	 * @since 3.16.0
	 *
	 * @param string|int $contact_id Contact ID or email.
	 * @return array|WP_Error List IDs or error.
	 */
	public function get_tags( $contact_id ) {

		$request  = 'https://api.brevo.com/v3/contacts/' . rawurlencode( $contact_id );
		$response = wp_safe_remote_get( $request, $this->get_params() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json ) || empty( $body_json['listIds'] ) ) {
			return array();
		}

		return $body_json['listIds'];

	}

	/**
	 * Applies lists to a contact.
	 *
	 * @since 3.16.0
	 *
	 * @param array      $tags       The list IDs to apply.
	 * @param string|int $contact_id Contact ID or email.
	 * @return WP_Error|bool True on success, WP_Error on failure.
	 */
	public function apply_tags( $tags, $contact_id ) {

		$body = array(
			'listIds' => array_map( 'absint', $tags ),
		);

		$request          = 'https://api.brevo.com/v3/contacts/' . rawurlencode( $contact_id );
		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $body );

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Removes lists from a contact.
	 *
	 * @since 3.16.0
	 *
	 * @param array      $tags       The list IDs to remove.
	 * @param string|int $contact_id Contact ID or email.
	 * @return WP_Error|bool True on success, WP_Error on failure.
	 */
	public function remove_tags( $tags, $contact_id ) {

		$body = array(
			'unlinkListIds' => array_map( 'absint', $tags ),
		);

		$request          = 'https://api.brevo.com/v3/contacts/' . rawurlencode( $contact_id );
		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $body );

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}


	/**
	 * Adds a new contact.
	 *
	 * @since 3.16.0
	 *
	 * @param array $data Contact data.
	 * @return int|WP_Error Contact ID on success or error on failure.
	 */

	public function add_contact( $data ) {

		$post_data = array();

		// Email name is included in the top level of the contact data.
		$post_data['email'] = $data['email'];
		unset( $data['email'] );

		if ( ! empty( $data ) ) {
			$post_data['attributes'] = $data;
		}

		if ( wpf_get_option( 'double_optin_template' ) && wpf_get_option( 'double_optin_lists' ) ) {

			// Add via double optin.

			$post_data['templateId']     = wpf_get_option( 'double_optin_template' );
			$post_data['redirectionUrl'] = wpf_get_option( 'double_optin_redirect_url' );
			$post_data['includeListIds'] = array_map( 'intval', wpf_get_option( 'double_optin_lists' ) );

			$url = 'https://api.brevo.com/v3/contacts/doubleOptinConfirmation';
		} else {

			if ( wpf_get_option( 'double_optin_template' ) && ! wpf_get_option( 'double_optin_lists' ) ) {
				// Log an error if double opt-in template is selected but no lists are specified.
				wpf_log( 'error', wpf_get_current_user_id(), 'A double opt-in template was selected, but no lists were specified in the WP Fusion settings. Contact will not not be added via double opt-in.' );
			}

			$url = 'https://api.brevo.com/v3/contacts';
		}
		$params         = $this->get_params();
		$params['body'] = wp_json_encode( $post_data );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body->id ) ) {
			return absint( $body->id );
		} elseif ( 201 === wp_remote_retrieve_response_code( $response ) ) {

			// Subscribers added via double opt-in don't get IDs until they're confirmed.
			return new WP_Error( 'notice', 'Subscriber was added to Brevo and sent the double opt-in email. You can\'t apply lists or update them until they have confirmed their subscription.' );

		} else {
			return new WP_Error( 'error', 'Unknown error adding contact:<pre>' . wpf_print_r( $body, true ) . '</pre>' );
		}

	}

	/**
	 * Update contact.
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data ) {

		// Email address changes.

		if ( isset( $data['email'] ) ) {

			$data['EMAIL'] = $data['email'];
			unset( $data['email'] );

		}

		$post_data = array( 'attributes' => $data );

		$url                     = 'https://api.brevo.com/v3/contacts/' . rawurlencode( $contact_id );
		$post_data['attributes'] = $data;
		$params                  = $this->get_params();
		$params['method']        = 'PUT';
		$params['body']          = wp_json_encode( $post_data );

		$response = wp_safe_remote_post( $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url      = 'https://api.brevo.com/v3/contacts/' . rawurlencode( $contact_id );
		$response = wp_safe_remote_get( $url, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 404 === wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'not_found', 'Contact not found.' );
		}

		$user_meta      = array();
		$contact_fields = wpf_get_option( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$user_meta['user_email'] = $body_json['email'];

		foreach ( $body_json['attributes'] as $field => $value ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if ( $field_data['active'] && $field === $field_data['crm_field'] ) {

					// Checkboxes.

					if ( false === $value ) {
						$value = null;
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
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();
		$continue    = true;
		$offset      = 0;

		while ( $continue ) {

			$url     = 'https://api.brevo.com/v3/contacts/lists/' . $tag . '/contacts?limit=500&offset=' . $offset;
			$results = wp_safe_remote_get( $url, $this->params );

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['contacts'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			if ( count( $body_json['contacts'] ) < 500 ) {
				$continue = false;
			} else {
				$offset += 500;
			}
		}

		return $contact_ids;

	}

	/**
	 * Track event.
	 *
	 * Track an event with the Brevo site tracking API.
	 *
	 * @since  3.38.16
	 *
	 * @link https://developers.brevo.com/docs/track-events-2.
	 *
	 * @param  string      $event      The event title.
	 * @param  bool|string $event_data The event description.
	 * @param  bool|string $email_address The user email address.
	 * @return bool|WP_Error True if success, WP_Error if failed.
	 */
	public function track_event( $event, $event_data = false, $email_address = false ) {

		// Get the email address to track.

		if ( empty( $email_address ) ) {
			$email_address = wpf_get_current_user_email();
		}

		if ( false === $email_address ) {
			return; // can't track without an email.
		}

		$key = wpf_get_option( 'site_tracking_key' );

		if ( empty( $key ) ) {
			wpf_log( 'notice', wpf_get_current_user_id(), 'To track events with Brevo you must first <a href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#sendinblue" target="_blank">add your client key to the settings</a>.' );
			return;
		}

		$body = array(
			'email' => $email_address,
			'event' => $event,
		);

		if ( is_array( $event_data ) ) {
			$body['properties'] = (object) $event_data;
		} else {
			$body['properties'] = (object) array( 'details' => $event_data );
		}

		$request                     = 'https://in-automate.sendinblue.com/api/v2/trackEvent';
		$params                      = $this->get_params();
		$params['body']              = wp_json_encode( $body );
		$params['headers']['ma-key'] = $key;
		$params['blocking']          = false;
		$response                    = wp_safe_remote_post( $request, $params );

		return true;
	}


}
