<?php

class WPF_CRM_Base {

	/**
	 * Contains an array of installed CRMs and their details.
	 *
	 * @var available_crms
	 */

	public $available_crms = array();

	/**
	 * Contains the class object for the currently active CRM.
	 *
	 * @var crm
	 */

	public $crm;

	/**
	 * Contains the field mapping array between WordPress fields and their corresponding CRM fields
	 *
	 * @since 3.35.14
	 * @var contact_fields
	 */

	public $contact_fields;

	/**
	 * Buffer for queued API calls.
	 *
	 * @since 3.39.6
	 * @var   buffer
	 */

	private $buffer;

	/**
	 * Constructs a new instance.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->includes(); // load the integration classes.

		$this->init(); // initiate the CRM and set $this->crm.

		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ) );

		// Default field value formatting.
		// 5 so it runs before wpf_format_field_value at priority 10 in the individual CRM integrations.
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 5, 3 );

		// AJAX CRM connection and sync.
		add_action( 'wp_ajax_wpf_sync', array( $this, 'ajax_sync' ) );

		// Process queued actions at PHP shutdown.
		add_action( 'shutdown', array( $this, 'shutdown' ), 1 );

		// Load the field mapping into memory.
		$this->contact_fields = wpf_get_option( 'contact_fields', array() );

	}


	/**
	 * Passes get requests to the active CRM.
	 *
	 * @since  3.39.6
	 *
	 * @param  string $name   The parameter name.
	 * @return mixed The value.
	 */
	public function __get( $name ) {

		if ( is_object( $this->crm ) && property_exists( $this->crm, $name ) ) {
			return $this->crm->$name;
		} elseif ( 'supports' === $name ) {
			return array();
		} else {
			return false;
		}

	}

	/**
	 * Passes set requests to the active CRM.
	 *
	 * @since 3.39.6
	 *
	 * @param <type> $key    The key.
	 * @param <type> $value  The value.
	 */
	public function __set( $key, $value ) {

		if ( is_object( $this->crm ) ) {
			$this->crm->{$key} = $value;
		}

	}

	/**
	 * Check for properties in the active CRM.
	 *
	 * @since  3.39.6
	 *
	 * @param  string $name   The parameter name.
	 * @return bool Whether or not it's set.
	 */
	public function __isset( $name ) {

		if ( is_object( $this->crm ) && property_exists( $this->crm, $name ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Handles calls to CRM methods and routes them to the API class.
	 *
	 * @since  3.39.6
	 *
	 * @param  string $method The method.
	 * @param  array  $args   The arguments.
	 * @return mixed  The API result or WP_Error if failed.
	 */
	public function __call( $method, $args ) {

		if ( wpf_is_staging_mode() ) {

			wpf_log(
				'notice',
				wpf_get_current_user_id(),
				'Staging mode enabled (' . get_site_url() . '). Method <code>' . $method . '</code>.',
				array(
					'source' => $this->crm->slug,
				)
			);

			$staging = new WPF_Staging();

			return call_user_func_array( array( $staging, $method ), $args );

		}

		// Convert the meta field keys between WordPress fields and CRM fields, and apply formatting.

		if ( 'add_contact' === $method && ( ! isset( $args[1] ) || true === $args[1] ) ) {

			// Add contact.
			$args[0] = $this->map_meta_fields( $args[0] );
			$args[1] = false;

			if ( empty( $args[0] ) ) {
				return false; // no enabled fields.
			}
		} elseif ( 'update_contact' === $method && ( ! isset( $args[2] ) || true === $args[2] ) ) {

			// Update contact.
			$args[1] = $this->map_meta_fields( $args[1] );
			$args[2] = false;

			if ( empty( $args[1] ) ) {
				return false; // no enabled fields.
			}
		}

		$args = apply_filters( 'wpf_api_' . $method . '_args', $args );

		/**
		 * Allows bypassing the API call, for example if a required dependency was deactivated.
		 *
		 * @since 3.35.16
		 *
		 * @param bool|WP_Error $error  The error object
		 * @param string        $method The API method to be performed
		 * @param array         $args   The API arguments
		 */

		$error = apply_filters( 'wpf_api_preflight_check', true, $method, $args );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		// If the CRM supports custom objects, bypass the queue and call it.

		// This routes calls like wp_fusion()->crm->add_object( $data, 'Lead' ) to
		// wp_fusion()->crm->add_contact( $data, $map_meta_fields = false ), while changing
		// the object type to "Lead".
		//
		// @link https://wpfusion.com/documentation/functions/add_object/.

		if ( false !== strpos( $method, '_object' ) && isset( $this->crm->object_type ) && ! method_exists( $this->crm, $method ) ) {

			$method      = str_replace( '_object', '', $method );
			$object_type = array_pop( $args );

			// Switch the object type for the API call.
			add_filter(
				'wpf_crm_object_type',
				function() use ( &$object_type ) {
					return $object_type;
				}
			);

			// Set $map_meta_fields to always false.

			if ( 'add' === $method ) {
				$args[1] = false;
			} elseif ( 'update' === $method ) {
				$args[2] = false;
			}

			$result = call_user_func_array( array( $this->crm, "{$method}_contact" ), $args ); // "add" becomes "add_contact"
			$result = apply_filters( "wpf_api_{$method}_result", $result, $args );

			return $result;

		}

		if ( $this->is_api_queue_enabled( $method, $args ) && ( 'apply_tags' === $method || 'remove_tags' === $method || 'update_contact' === $method ) ) {

			// If the API queue is enabled and this data can be queued, add it to a buffer to be sent later.
			$this->add_to_buffer( $method, $args );

			return true;

		} else {

			// Can't be queued, execute right away.

			return $this->request( $method, $args );

		}

	}

	/**
	 * Gets the contact ID out of the array of arguments passed to the CRM.
	 *
	 * @since  3.40.2
	 *
	 * @param  string $method The API method.
	 * @param  array  $args   The arguments.
	 * @return string|bool The contact ID or false.
	 */
	public function get_contact_id_from_args( $method, $args ) {

		if ( 'update_contact' === $method || 'load_contact' === $method || 'get_tags' === $method ) {
			$contact_id = $args[0];
		} elseif ( 'apply_tags' === $method || 'remove_tags' === $method ) {
			$contact_id = $args[1];
		} else {
			$contact_id = false;
		}

		return $contact_id;

	}

	/**
	 * Make the request via the CRM class and handle the result.
	 *
	 * @since  3.40.2
	 *
	 * @param  string $method The API method.
	 * @param  array  $args   The arguments.
	 * @return mixed|WP_Error The API response or a WP_Error.
	 */
	private function request( $method, $args ) {

		$result = call_user_func_array( array( $this->crm, $method ), $args );

		$contact_id = $this->get_contact_id_from_args( $method, $args );

		// Error handling.
		if ( is_wp_error( $result ) ) {

			$user_id = wpf_get_user_id( $contact_id );

			// Contact ID changed, maybe retry.

			if ( 'not_found' === $result->get_error_code() && false !== $contact_id && false !== $user_id ) {

				$new_contact_id = wpf_get_contact_id( $user_id, true ); // force an update.

				if ( false !== $new_contact_id && $new_contact_id !== $contact_id ) {

					// Replace the contact ID and try again.

					foreach ( $args as $i => $val ) {
						if ( $val === $contact_id ) {
							$args[ $i ] = $new_contact_id;
						}
					}

					wpf_log( 'notice', $user_id, '"Contact not found" error while performing API method <code>' . $method . '</code> on contact ID #' . $contact_id . '. This probably means the contact was deleted or merged. We were able to find a new record with the same email, contact #' . $new_contact_id . '. Retrying API call...' );

					return $this->request( $method, $args );

				}
			}

			if ( doing_action( 'shutdown' ) ) {

				// We only need to log errors during the shutdown actions / queue
				// processing, otherwise errors are handled by the class that called
				// the API.
				//
				// @since 3.40.2.

				wpf_log(
					'error',
					$user_id,
					'Error while performing method <strong>' . $method . '</strong>: ' . $result->get_error_message(),
					array(
						'source' => $this->crm->slug,
						'args'   => $args,
					)
				);

			}

			do_action( 'wpf_api_error', $method, $args, $contact_id, $result );

			do_action( "wpf_api_error_{$method}", $args, $contact_id, $result );

		} else {

			do_action( "wpf_api_did_{$method}", $args, $contact_id, $result );

		}

		$result = apply_filters( "wpf_api_{$method}_result", $result, $args );

		$result = wpf_clean( $result ); // wp_kses recursive.

		return $result;

	}

	/**
	 * Load available CRMs.
	 *
	 * @access private
	 *
	 * @since  1.0
	 */
	private function includes() {

		$slug = sanitize_file_name( wpf_get_option( 'crm' ) );

		if ( wpf_get_option( 'connection_configured' ) && ! empty( $slug ) && ! $this->doing_reset() ) {

			// If the connection is configured then we just need to load the active CRM.

			if ( file_exists( WPF_DIR_PATH . 'includes/crms/' . $slug . '/class-' . $slug . '.php' ) ) {
				require_once WPF_DIR_PATH . 'includes/crms/' . $slug . '/class-' . $slug . '.php';
			}
		} else {

			// Load available CRM classes.
			foreach ( wp_fusion()->get_crms() as $filename => $integration ) {

				$filename = sanitize_file_name( $filename );

				if ( file_exists( WPF_DIR_PATH . 'includes/crms/' . $filename . '/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/crms/' . $filename . '/class-' . $filename . '.php';
				}
			}
		}

		// Load staging mode if needed.

		if ( wpf_is_staging_mode() ) {
			require_once WPF_DIR_PATH . 'includes/crms/staging/class-staging.php';
		}

	}

	/**
	 * Initialize the active CRM.
	 *
	 * @access private
	 *
	 * @since  3.39.6
	 */
	private function init() {

		$configured_crms = wp_fusion()->get_crms();

		if ( wpf_get_option( 'connection_configured' ) && ! $this->doing_reset() ) {

			// We only need to load the active CRM once the connection has been configured.

			$slug = wpf_get_option( 'crm' );

			if ( ! isset( $configured_crms[ $slug ] ) ) {
				return; // invalid CRM.
			}

			$this->crm = new $configured_crms[ $slug ](); // the value is the class name.

			if ( method_exists( $this->crm, 'init' ) ) {
				$this->crm->init();
			}

			$this->available_crms[ $slug ] = array(
				'name'      => $this->crm->name,
				'menu_name' => isset( $this->crm->menu_name ) ? $this->crm->menu_name : $this->crm->name,
			);

		} else {

			// Load all of them during setup.

			foreach ( $configured_crms as $slug => $classname ) {

				if ( class_exists( $classname ) ) {

					$crm = new $classname();

					$this->available_crms[ $slug ] = array( 'name' => $crm->name );

					if ( isset( $crm->menu_name ) ) {
						$this->available_crms[ $slug ]['menu_name'] = $crm->menu_name;
					} else {
						$this->available_crms[ $slug ]['menu_name'] = $crm->name;
					}
				}
			}
		}

	}

	/**
	 * Determines if API queue is enabled.
	 *
	 * @since  3.39.6
	 *
	 * @param  string $method The API method.
	 * @param  array  $args   The arguments.
	 * @return bool   True if API queue enabled, False otherwise.
	 */
	public function is_api_queue_enabled( $method = false, $args = array() ) {

		$enabled = true;

		if ( defined( 'WPF_DISABLE_QUEUE' ) && false === defined( 'WPF_DISABLE_QUEUE' ) ) {
			$enabled = false;
		} elseif ( ! wpf_get_option( 'enable_queue', true ) ) {
			$enabled = false;
		}

		return apply_filters( 'wpf_use_api_queue', $enabled, $method, $args );

	}

	/**
	 * Some CRMs require an email address to be used instead of a contact ID for
	 * certain operations.
	 *
	 * @since  3.36.17
	 *
	 * @param  string $contact_id The contact ID.
	 * @return string The email address.
	 */
	public function get_email_from_cid( $contact_id ) {

		$users = get_users(
			array(
				'meta_key'   => "{$this->crm->slug}_contact_id",
				'meta_value' => $contact_id,
				'fields'     => array( 'user_email' ),
			)
		);

		if ( ! empty( $users ) ) {

			return $users[0]->user_email;

		} else {

			// Make an API call.

			$contact = $this->crm->load_contact( $contact_id );

			if ( is_wp_error( $contact ) ) {
				return false;
			}

			return $contact['user_email'];

		}

	}

	/**
	 * When resetting the WPF settings page, the old CRM gets loaded before the
	 * save_options() function runs on the init hook to clear out the settings
	 * For lack of a better solution, we'll check here to see if the settings
	 * are being reset, and if so load all CRMs so the next one can be selected.
	 *
	 * @access private
	 *
	 * @since  3.35.3
	 *
	 * @return bool  Doing Reset
	 */
	private function doing_reset() {

		if ( ! empty( $_POST ) && isset( $_POST['wpf_options'] ) && ! empty( $_POST['wpf_options']['reset_options'] ) ) {
			return true;
		}

		return false;

	}


	/**
	 * Adds the available CRMs to the select dropdown on the setup page.
	 *
	 * @since  1.0
	 *
	 * @param  array $settings The settings.
	 * @return array The settings.
	 */
	public function configure_settings( $settings ) {

		$settings['crm']['choices'] = $this->get_crms_for_select();

		return $settings;

	}

	/**
	 * Returns the slug and menu name of each CRM for select fields
	 *
	 * @access  public
	 * @since   1.0
	 * @return  array
	 */

	public function get_crms_for_select() {

		$select_array = array();

		foreach ( $this->available_crms as $slug => $data ) {
			$select_array[ $slug ] = $data['menu_name'];
		}

		asort( $select_array );

		return $select_array;

	}

	/**
	 * Perform initial app sync
	 *
	 * @access public
	 * @return mixed
	 */

	public function ajax_sync() {

		$result = wp_fusion()->crm->sync();

		if ( true === $result ) {

			wp_send_json_success();

		} else {

			if ( is_wp_error( $result ) ) {

				wpf_log( 'error', 0, 'Error performing sync: ' . $result->get_error_message() );
				wp_send_json_error( $result->get_error_message() );

			} else {
				wp_send_json_error();
			}
		}

	}

	/**
	 * Maps local fields to CRM field names
	 *
	 * @access public
	 * @return array
	 */

	public function map_meta_fields( $user_meta ) {

		if ( ! is_array( $user_meta ) || empty( $user_meta ) ) {
			return array();
		}

		$update_data = array();

		foreach ( $this->contact_fields as $field => $field_data ) {

			if ( empty( $field_data['active'] ) || empty( $field_data['crm_field'] ) ) {
				continue;
			}

			// Don't send add_tag_ fields to the CRM as fields.
			if ( strpos( $field_data['crm_field'], 'add_tag_' ) !== false ) {
				continue;
			}

			// If field exists in form and sync is active.
			if ( isset( $user_meta[ $field ] ) ) {

				if ( empty( $field_data['type'] ) ) {
					$field_data['type'] = 'text';
				}

				$field_data['crm_field'] = strval( $field_data['crm_field'] );

				if ( 'datepicker' === $field_data['type'] ) {

					// We'd been using date and datepicker interchangeably up until
					// 3.38.11, which is confusing. We'll just use "date" going forward.

					$field_data['type'] = 'date';
				}

				/**
				 * Format field value.
				 *
				 * @since 1.0.0
				 *
				 * @link  https://wpfusion.com/documentation/filters/wpf_format_field_value/
				 *
				 * @param mixed  $value     The field value.
				 * @param string $type      The field type.
				 * @param string $crm_field The field ID in the CRM.
				 */

				$value = apply_filters( 'wpf_format_field_value', $user_meta[ $field ], $field_data['type'], $field_data['crm_field'] );

				if ( 'raw' === $field_data['type'] ) {

					// Allow overriding the empty() check by setting the field type to raw.

					$update_data[ $field_data['crm_field'] ] = $value;

				} elseif ( is_null( $value ) ) {

					// Allow overriding empty() check by returning null from wpf_format_field_value.

					$update_data[ $field_data['crm_field'] ] = '';

				} elseif ( false === $value ) {

					// Some CRMs (i.e. Sendinblue) need to be able to sync false as a value to clear checkboxes.

					$update_data[ $field_data['crm_field'] ] = false;

				} elseif ( 0 === $value || '0' === $value ) {

					$update_data[ $field_data['crm_field'] ] = 0;

				} elseif ( empty( $value ) && ! empty( $user_meta[ $field ] ) && 'date' === $field_data['type'] ) {

					// Date conversion failed.
					wpf_log( 'notice', wpf_get_current_user_id(), 'Failed to create timestamp from value <code>' . $user_meta[ $field ] . '</code>. Try setting the field type to <code>text</code> instead, or fixing the format of the input date.' );

				} elseif ( ! empty( $value ) ) {

					$update_data[ $field_data['crm_field'] ] = $value;

				}
			}
		}

		$update_data = apply_filters( 'wpf_map_meta_fields', $update_data, $user_meta );

		return $update_data;

	}

	/**
	 * Gets the CRM field ID of the primary field used for contact record
	 * lookups (usually email).
	 *
	 * @since  3.37.29
	 *
	 * @return string The field name in the CRM.
	 */
	public function get_lookup_field() {

		$field = ! empty( $this->contact_fields['user_email']['crm_field'] ) ? $this->contact_fields['user_email']['crm_field'] : 'email';

		return $field;

	}

	/**
	 * Get the CRM field for a single key
	 *
	 * @access public
	 * @return string / false
	 */
	public function get_crm_field( $meta_key, $default = false ) {

		if ( ! empty( $this->contact_fields[ $meta_key ] ) && ! empty( $this->contact_fields[ $meta_key ]['crm_field'] ) ) {
			return $this->contact_fields[ $meta_key ]['crm_field'];
		} else {
			return $default;
		}

	}

	/**
	 * Determines if a field is active
	 *
	 * @access public
	 * @return bool
	 */
	public function is_field_active( $meta_key ) {

		if ( ! empty( $this->contact_fields[ $meta_key ] ) && ! empty( $this->contact_fields[ $meta_key ]['active'] ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Get the field type (set on the Contact Fields list) for a given field
	 *
	 * @since  3.35.14
	 *
	 * @param  string $meta_key The meta key to look up.
	 * @param  string $default  The default value to return if no type is found.
	 * @return string The field type.
	 */
	public function get_field_type( $meta_key, $default = 'text' ) {

		if ( ! empty( $this->contact_fields[ $meta_key ] ) && ! empty( $this->contact_fields[ $meta_key ]['type'] ) ) {
			return $this->contact_fields[ $meta_key ]['type'];
		} else {
			return $default;
		}

	}

	/**
	 * Is a WordPress meta key a pseudo field and should only be sent to the
	 * CRM, not loaded
	 *
	 * @since  3.35.16
	 *
	 * @param  string $meta_key The meta key to look up.
	 * @return bool   Whether or not the field is a pseudo field.
	 */
	public function is_pseudo_field( $meta_key ) {

		if ( ! empty( $this->contact_fields[ $meta_key ] ) && isset( $this->contact_fields[ $meta_key ]['pseudo'] ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Gets the URL to edit the contact in the CRM.
	 *
	 * @since  3.37.29
	 *
	 * @param  string $contact_id The contact ID.
	 * @return bool|string The URL to edit the contact, or false.
	 */
	public function get_contact_edit_url( $contact_id ) {

		if ( empty( $contact_id ) ) {
			return false;
		}

		if ( in_array( 'web_id', $this->supports, true ) ) {

			// Mailchimp and Bento.

			$user_id = wpf_get_user_id( $contact_id );

			if ( ! $user_id ) {
				return false;
			}

			$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_web_id', true );

			if ( empty( $contact_id ) ) {
				return false;
			}
		}

		if ( isset( $this->crm->edit_url ) && ! empty( $this->crm->edit_url ) ) {
			return sprintf( $this->crm->edit_url, $contact_id );
		} else {
			return false;
		}

	}

	/**
	 * Formats user entered data to match CRM field formats
	 *
	 * @access public
	 * @return mixed
	 */
	public function format_field_value( $value, $field_type, $field ) {

		if ( 'date' === $field_type ) {

			if ( ! is_numeric( $value ) && ! empty( $value ) ) {

				$value = strtotime( $value );
			}

			// intval() in case it's a string timestamp, this will make sure subsequent calls to date() don't throw a warning.
			// can't use absint(), since dates less than 1/1/70 are negative numbers.

			if ( ! empty( $value ) ) {
				$value = intval( $value );
			}

			return $value;

		} elseif ( false !== strpos( $field, 'add_tag_' ) ) {

			// Don't modify it if it's a dynamic tag field
			// (this needs to stay so that WPF_Forms_Helper can still do dynamic tagging).

			return $value;

		} elseif ( is_array( $value ) || 'multiselect' === $field_type ) {

			// Mulitselects.

			if ( 'multiselect' === $field_type ) {

				// Any formatting of arrays is now handled in the CRM integration class.

				if ( ! is_array( $value ) ) { // If it's being synced as multiselect but it's not an array.
					$value = array_map( 'trim', explode( ',', $value ) );
				}

				// Don't sync multidimensional arrays.

				if ( count( $value ) !== count( $value, COUNT_RECURSIVE ) ) {

					foreach ( $value as $i => $x ) {
						if ( is_array( $x ) ) {
							unset( $value[ $i ] );
						}
					}
				}
			} elseif ( 'text' === $field_type && is_array( $value ) ) {

				// If it's explicitly supposed to be text.

				$value = implode( ', ', array_filter( $value ) );

			}

			return $value;

		} elseif ( 'checkbox' === $field_type ) {

			if ( empty( $value ) ) {
				// If checkbox is unselected.
				return null;
			} else {
				// If checkbox is selected.
				return 1;
			}
		} elseif ( 'text' === $field_type || 'textarea' === $field_type ) {

			return strval( $value );

		} elseif ( 'user_pass' === $field ) {

			// Don't update password if it's empty.
			if ( ! empty( $value ) ) {
				return $value;
			}
		} else {

			return $value;

		}

	}


	/**
	 * Adds API requests to the API buffer
	 *
	 * @access  private
	 * @return  void
	 */

	private function add_to_buffer( $method, $args ) {

		if ( $method == 'apply_tags' || $method == 'remove_tags' ) {

			$cid  = $args[1];
			$data = $args[0];

		} elseif ( $method == 'update_contact' ) {

			$cid  = $args[0];
			$data = $args[1];

		}

		if ( in_array( 'combined_updates', $this->crm->supports ) ) {

			// CRMs that support tags and contact data in the same request

			$update_data = array( $method => $data );

			if ( ! isset( $this->buffer['combined_update'] ) ) {

				$this->buffer['combined_update'] = array( $cid => $update_data );

			} elseif ( ! isset( $this->buffer['combined_update'][ $cid ] ) ) {

				$this->buffer['combined_update'][ $cid ] = $update_data;

			} else {

				if ( ! isset( $this->buffer['combined_update'][ $cid ][ $method ] ) ) {

					$this->buffer['combined_update'][ $cid ][ $method ] = $data;

				}

				if ( $method == 'apply_tags' ) {

					// Prevent tags getting added and removed in the same request

					if ( isset( $this->buffer['combined_update'][ $cid ]['remove_tags'] ) ) {

						foreach ( $data as $tag ) {

							$match = array_search( $tag, $this->buffer['combined_update'][ $cid ]['remove_tags'] );

							if ( $match !== false ) {
								unset( $this->buffer['combined_update'][ $cid ]['remove_tags'][ $match ] );
							}
						}
					}

					$this->buffer['combined_update'][ $cid ]['apply_tags'] = array_unique( array_merge( $this->buffer['combined_update'][ $cid ]['apply_tags'], $data ) );

				} elseif ( $method == 'remove_tags' ) {

					// Prevent tags getting added and removed in the same request

					if ( isset( $this->buffer['combined_update'][ $cid ]['apply_tags'] ) ) {

						foreach ( $data as $tag ) {

							$match = array_search( $tag, $this->buffer['combined_update'][ $cid ]['apply_tags'] );

							if ( $match !== false ) {
								unset( $this->buffer['combined_update'][ $cid ]['apply_tags'][ $match ] );
							}
						}
					}

					$this->buffer['combined_update'][ $cid ]['remove_tags'] = array_unique( array_merge( $this->buffer['combined_update'][ $cid ]['remove_tags'], $data ) );

				} elseif ( $method == 'update_contact' ) {

					$this->buffer['combined_update'][ $cid ]['update_contact'] = array_replace( $this->buffer['combined_update'][ $cid ]['update_contact'], $data );

				}
			}
		} else {

			// CRMs that require separate API calls for tags and contact data.

			if ( ! isset( $this->buffer[ $method ] ) ) {

				$this->buffer[ $method ] = array( $cid => $args );

			} elseif ( ! isset( $this->buffer[ $method ][ $cid ] ) ) {

				$this->buffer[ $method ][ $cid ] = $args;

			}

			if ( 'apply_tags' === $method ) {

				// Prevent tags getting added and removed in the same request.

				if ( isset( $this->buffer['remove_tags'] ) && isset( $this->buffer['remove_tags'][ $cid ] ) ) {
					$this->buffer['remove_tags'][ $cid ][0] = array_diff( $this->buffer['remove_tags'][ $cid ][0], $args[0] );
				}

				$this->buffer['apply_tags'][ $cid ][0] = array_unique( array_merge( $this->buffer['apply_tags'][ $cid ][0], $args[0] ) );

			} elseif ( 'remove_tags' === $method ) {

				// Prevent tags getting added and removed in the same request.

				if ( isset( $this->buffer['apply_tags'] ) && isset( $this->buffer['apply_tags'][ $cid ] ) ) {
					$this->buffer['apply_tags'][ $cid ][0] = array_diff( $this->buffer['apply_tags'][ $cid ][0], $args[0] );
				}

				$this->buffer['remove_tags'][ $cid ][0] = array_unique( array_merge( $this->buffer['remove_tags'][ $cid ][0], $args[0] ) );

			} elseif ( 'update_contact' === $method ) {

				$this->buffer[ $method ][ $cid ][1] = array_replace( $this->buffer[ $method ][ $cid ][1], $args[1] );

			}
		}

	}


	/**
	 * Executes the queued API requests on WordPress' shutdown hook.
	 *
	 * @since 3.39.6
	 */
	public function shutdown() {

		if ( empty( $this->buffer ) ) {
			return;
		}

		foreach ( $this->buffer as $method => $contacts ) {

			foreach ( $contacts as $cid => $args ) {

				// Don't send empty data.
				if ( ! empty( $args[0] ) && ! empty( $args[1] ) || $method == 'combined_update' ) {

					if ( 'combined_update' === $method ) {
						$args = array( $cid, $args );
					}

					$this->request( $method, $args );
				}
			}
		}

		$this->buffer = false; // in case we need to call it again later, clear it out.

	}

}
