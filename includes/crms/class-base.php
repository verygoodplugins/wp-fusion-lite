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
	 * @var crm
	 */

	public $crm_no_queue;


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

				if ( wp_fusion()->settings->get( 'crm' ) == $slug ) {
					$this->crm_no_queue = $crm;
					$this->crm_no_queue->init();

					if( isset( $crm->tag_type ) ) {
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
		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 5, 3 );

		// AJAX CRM connection and sync
		add_action( 'wp_ajax_wpf_sync', array( $this, 'sync' ) );

		// Sets up "turbo" mode
		if ( defined( 'WPF_DISABLE_QUEUE' ) || wp_fusion()->settings->get( 'enable_queue', true ) == false ) {

			$this->crm = $this->crm_no_queue;

		} else {

			$this->queue();
			
		}

	}


	/**
	 * Load available CRMs
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	private function includes() {

		$slug = wp_fusion()->settings->get( 'crm' );

		if( wp_fusion()->settings->get('connection_configured') == true && ! empty( $slug ) ) {

			if( file_exists( WPF_DIR_PATH . 'includes/crms/' . $slug . '/class-' . $slug . '.php' ) ) {
				require_once WPF_DIR_PATH . 'includes/crms/' . $slug . '/class-' . $slug . '.php';
			}

		} else {

			// Load available CRM classes
			foreach ( wp_fusion()->get_crms() as $filename => $integration ) {
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

		asort($select_array);

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

			if( is_wp_error( $result ) ) {

				wp_fusion()->logger->handle( 'error', 0, 'Error performing sync: ' . $result->get_error_message() );
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

	public function map_meta_fields( $user_data ) {

		$update_data = array();

		foreach ( (array) wp_fusion()->settings->get( 'contact_fields' ) as $field => $field_data ) {

			// If field exists in form and sync is active
			if ( array_key_exists($field, $user_data) && $field_data['active'] == true && ! empty( $field_data['crm_field'] ) ) {

				if( empty( $field_data['type'] ) ) {
					$field_data['type'] = 'text';
				}

				$value = apply_filters( 'wpf_format_field_value', $user_data[ $field ], $field_data['type'], $field_data['crm_field'] );

				// Allow overriding empty() check by returning null from wpf_format_field_value
				if ( $value === null ) {
					$update_data[ $field_data['crm_field'] ] = '';
				} elseif( ! empty( $value ) ) {
					$update_data[ $field_data['crm_field'] ] = $value;
				}

			}
		}

		$update_data = apply_filters( 'wpf_map_meta_fields', $update_data );

		return $update_data;

	}

	/**
	 * Formats user entered data to match CRM field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			if( ! is_numeric( $value ) ) {
				$value = strtotime( $value );
			}

			return $value;

		} elseif ( ($field_type == 'multiselect' && is_array($value)) || is_array($value) ) {

			$value = implode( ",", $value );

			return $value;


		} elseif ( $field_type == 'checkbox' || $field_type == 'checkbox-full' ) {

			if ( empty( $value ) ) {
				//If checkbox is unselected
				return null;
			} else {
				// If checkbox is selected
				return 1;
			}
		
		} elseif ( $field == 'user_pass' ) {

			// Don't update password if it's empty
			if ( ! empty( $value ) ) {
				return $value;
			}

		} else {

			return $value;

		}

	}


}