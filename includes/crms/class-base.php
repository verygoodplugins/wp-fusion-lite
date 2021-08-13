<?php

class WPF_CRM_Base {

	/**
	 * Contains an array of installed APIs and their details
	 *
	 * @var available_crms
	 */

	public $available_crms = array();


	/**
	 * Contains the class object for the currently active CRM
	 *
	 * @var crm
	 */

	public $crm;

	/**
	 * Contains the class object for the currently active CRM (queue disabled)
	 *
	 * @var crm_no_queue
	 */

	public $crm_no_queue;

	/**
	 * Contains the field mapping array between WordPress fields and their corresponding CRM fields
	 *
	 * @since 3.35.14
	 * @var contact_fields
	 */

	public $contact_fields;


	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @var tag_type
	 */

	public $tag_type = 'Tag';


	public function __construct() {

		$this->includes();

		$configured_crms = wp_fusion()->get_crms();

		// Initialize classes
		foreach ( $configured_crms as $slug => $classname ) {

			if ( class_exists( $classname ) ) {

				$crm = new $classname();

				if ( true == wpf_get_option( 'connection_configured' ) && wpf_get_option( 'crm' ) == $slug ) {
					$this->crm_no_queue = $crm;

					if ( method_exists( $this->crm_no_queue, 'init' ) ) {
						$this->crm_no_queue->init();
					}

					if ( isset( $crm->tag_type ) ) {
						$this->tag_type = $crm->tag_type;
					}
				}

				$this->available_crms[ $slug ] = array( 'name' => $crm->name );

				if ( isset( $crm->menu_name ) ) {
					$this->available_crms[ $slug ]['menu_name'] = $crm->menu_name;
				} else {
					$this->available_crms[ $slug ]['menu_name'] = $crm->name;
				}
			}
		}

		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ) );

		// Default field value formatting

		if ( ! isset( $this->crm_no_queue->override_filters ) ) {

			add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 5, 3 );

		}

		// AJAX CRM connection and sync
		add_action( 'wp_ajax_wpf_sync', array( $this, 'sync' ) );

		// Sets up "turbo" mode
		if ( defined( 'WPF_DISABLE_QUEUE' ) || wpf_get_option( 'enable_queue', true ) == false ) {

			$this->crm = $this->crm_no_queue;

		} else {

			$this->queue();

		}

		$this->contact_fields = wpf_get_option( 'contact_fields', array() );

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

			// Make an API call

			$contact = $this->crm->load_contact( $contact_id );

			if ( is_wp_error( $contact ) ) {
				return false;
			}

			return $contact['user_email'];

		}

	}

	/**
	 * When resetting the WPF settings page, the old CRM gets loaded before the save_options() function runs on the init hook to clear out the settings
	 * For lack of a better solution, we'll check here to see if the settings are being reset, and if so load all CRMs so the next one can be selectec
	 *
	 * @access  private
	 * @since   3.35.3
	 * @return  bool Doing Reset
	 */

	private function doing_reset() {

		if ( ! empty( $_POST ) && isset( $_POST['wpf_options'] ) && ! empty( $_POST['wpf_options']['reset_options'] ) ) {
			return true;
		}

		return false;

	}


	/**
	 * Load available CRMs
	 *
	 * @access  private
	 * @since   1.0
	 * @return  void
	 */

	private function includes() {

		$slug = sanitize_file_name( wpf_get_option( 'crm' ) );

		if ( wpf_get_option( 'connection_configured' ) && ! empty( $slug ) && false == $this->doing_reset() ) {

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

	}

	/**
	 * Enables turbo mode
	 *
	 * @access public
	 */

	public function queue() {

		require_once WPF_DIR_PATH . 'includes/crms/class-queue.php';
		$this->crm = new WPF_CRM_Queue( $this->crm_no_queue );

	}


	/**
	 * Adds the available CRMs to the select dropdown on the setup page
	 *
	 * @access  public
	 * @since   1.0
	 * @return  array
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

	public function sync() {

		$result = wp_fusion()->crm->sync();

		if ( $result == true ) {

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

		if ( ! is_array( $user_meta ) ) {
			return false;
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

				$value = apply_filters( 'wpf_format_field_value', $user_meta[ $field ], $field_data['type'], $field_data['crm_field'] );

				if ( 'raw' === $field_data['type'] ) {

					// Allow overriding the empty() check by setting the field type to raw.

					$update_data[ $field_data['crm_field'] ] = $value;

				} elseif ( is_null( $value ) ) {

					// Allow overriding empty() check by returning null from wpf_format_field_value.

					$update_data[ $field_data['crm_field'] ] = '';

				} elseif ( 0 === $value || '0' === $value ) {

					$update_data[ $field_data['crm_field'] ] = 0;

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

		if ( 'datepicker' === $field_type || 'date' === $field_type ) {

			if ( ! is_numeric( $value ) && ! empty( $value ) ) {
				$value = strtotime( $value );
			}

			// absint() in case it's a string timestamp, this will make sure subsequent calls to date() don't throw a warning.

			return absint( $value );

		} elseif ( false !== strpos( $field, 'add_tag_' ) ) {

			// Don't modify it if it's a dynamic tag field
			// (this needs to stay so that WPF_Forms_Helper can still do dynamic tagging).

			return $value;

		} elseif ( is_array( $value ) || 'multiselect' === $field_type ) {

			// Mulitselects.

			if ( 'multiselect' === $field_type ) {

				// Any formatting of arrays is now handled in the CRM integration class.

				if ( ! is_array( $value ) ) { // If it's being synced as multiselect but it's not an array.
					$value = array( $value );
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

}
