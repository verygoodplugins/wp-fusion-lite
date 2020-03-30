<?php

class WPF_MailEngine {

	/**
	 * Contains essential params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * SoapClient
	 */

	public $subscribe_service;

	/**
	 * Bypass the field filtering in WPF_CRM_Base so multiselects get passed as arrays
	 */

	public $override_filters;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'mailengine';
		$this->name     = 'MailEngine';
		$this->supports = array();

		$this->override_filters = true;

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_MailEngine_Admin( $this->slug, $this->name, $this );
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
	}


	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $wsdl_url = null, $client_id = null, $subscribe_id = null ) {

		// Get saved data from DB
		if ( empty( $wsdl_url ) || empty( $client_id ) || empty( $subscribe_id ) ) {
			$wsdl_url     = wp_fusion()->settings->get( 'mailengine_wsdl_url' );
			$client_id    = wp_fusion()->settings->get( 'mailengine_client_id' );
			$subscribe_id = wp_fusion()->settings->get( 'mailengine_subscribe_id' );
			$affiliate    = wp_fusion()->settings->get( 'mailengine_affiliate' );
		}

		$this->subscribe_service = new \SoapClient(
			$wsdl_url, [
				'cache_wsdl'  => WSDL_CACHE_NONE,
				'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | 9,
				'exceptions'  => false,
			]
		);

		$this->params = array(
			'wsdl_url'     => $wsdl_url,
			'client_id'    => $client_id,
			'subscribe_id' => $subscribe_id,
			'affiliate'    => $affiliate,
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $wsdl_url = null, $client_id = null, $subscribe_id = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $wsdl_url, $client_id, $subscribe_id );
		}

		// Validate the connection with a dummy userdata request
		$result = $this->subscribe_service->GetUserData( $this->params['client_id'], $this->params['subscribe_id'], 'id', 0 );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) || $result == 'invalid user' ) {
			return true;
		} else {
			return new WP_Error( 'invalid user', 'SOAP warning! The Soap request was done, but returned with unexpected result: <strong>invalid user</strong>. (Possible misconfiguration)' );
		}
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$result = $this->subscribe_service->GetMetaDataTags( $this->params['client_id'], $this->params['subscribe_id'] );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) ) {
			if ( ! empty( $result ) ) {
				foreach ( $result as $tag_id => $tag ) {
					$available_tags[ $tag ] = $tag;
				}
			}
		} else {
			return new WP_Error( 'no tags found', 'SOAP warning! The Soap request for syncing tags was done, but returned with empty result. (No tags for group?)' );
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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$crm_fields                     = array();
		$crm_fields_multiselect_mapping = array();

		$result = $this->subscribe_service->GetMetaDataUserFields( $this->params['client_id'], $this->params['subscribe_id'] );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) ) {
			if ( ! empty( $result ) ) {
				foreach ( $result as $field_id => $field ) {
					$crm_fields[ $field['variable_name'] ] = $field['variable_name'] . ' (' . $field['question'] . ')';
					if ( ! empty( $field['values'] ) ) {
						$crm_fields_multiselect_mapping[ $field['variable_name'] ] = array();
						foreach ( $field['values'] as $value_id => $multiselect_value ) {
							$crm_fields_multiselect_mapping[ $field['variable_name'] ][ mb_strtolower( $multiselect_value['enum_option'] ) ] = $value_id;
							$crm_fields_multiselect_values[ $field['variable_name'] ][ $value_id ] = $multiselect_value['enum_option'];
						}
					}
				}
			}
		} else {
			return new WP_Error( 'no crm fields found', 'SOAP warning! The Soap request for CRM fields was done, but returned with empty result (No fields for group?)' );
		}

		asort( $crm_fields );
		wp_fusion()->settings->set( 'crm_fields', $crm_fields );
		wp_fusion()->settings->set( 'crm_fields_multiselect_mapping', $crm_fields_multiselect_mapping );
		wp_fusion()->settings->set( 'crm_fields_multiselect_values', $crm_fields_multiselect_values );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$result = $this->subscribe_service->GetUserData( $this->params['client_id'], $this->params['subscribe_id'], 'email', $email_address );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) ) {
			if ( ! empty( $result['id'] ) ) {
				return $result['id'];
			} else {
				return false;
			}
		} elseif ( $result == 'invalid user' ) {
			return false;
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$result = $this->subscribe_service->GetUserData( $this->params['client_id'], $this->params['subscribe_id'], 'id', $contact_id );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		$contact_tags = array();

		if ( is_array( $result ) ) {
			if ( ! empty( $result['taglist'] ) ) {

			} else {
				return $contact_tags;
			}
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}

		$found_new      = false;
		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		foreach ( $result['taglist'] as $ŧag_id => $tag ) {
			$contact_tags[] = $tag;

			// Handle tags that might not have been picked up by sync_tags
			if ( ! isset( $available_tags[ $tag ] ) ) {
				$available_tags[ $tag ] = $tag;
				$found_new              = true;
			}
		}

		if ( $found_new ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}

		return $contact_tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( empty( $tags ) ) {
			return true;
		}

		$userdata = array(
			array( 'id', $contact_id ),
			array( 'activate-unsubscribed', 'no' ),
		);

		$result = $this->subscribe_service->Subscribe( $this->params['client_id'], $this->params['subscribe_id'], 'id', 'yes', intval( $this->params['affiliate'] ), array(), $userdata, $tags );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( $result == 'success' ) {
			return true;
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( empty( $tags ) ) {
			return true;
		}

		// Prefix with - sign for removal
		foreach ( $tags as $i => $tag ) {
			$tags[ $i ] = '-' . $tag;
		}

		$userdata = array(
			array( 'id', $contact_id ),
			array( 'activate-unsubscribed', 'no' ),
		);

		$result = $this->subscribe_service->Subscribe( $this->params['client_id'], $this->params['subscribe_id'], 'id', 'yes', intval( $this->params['affiliate'] ), array(), $userdata, $tags );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( $result == 'success' ) {
			return true;
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
	}


	/**
	 * Formats user entered data to match Mautic field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		$crm_fields_multiselect_mapping = wp_fusion()->settings->get( 'crm_fields_multiselect_mapping' );

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {
			if ( ! empty( $value ) ) {
				if ( is_numeric( $value ) ) {
					if ( $value == date( 'Ymd', strtotime( $value ) ) ) {
						return date( 'Y-m-d', strtotime( $value ) );
					} else {
						$date = strtotime( $value );
						return $date;
					}
				} else {
					return date( 'Y-m-d', $value );
				}
			} else {
				return $value;
			}
		} elseif ( false !== strpos( $field, 'add_tag_' ) ) {

			// Don't modify it if it's a dynamic tag field
			return $value;

		} elseif ( ( is_array( $value ) || $field_type == 'multiselect' ) && isset( $crm_fields_multiselect_mapping[ $field ] ) ) {
			if ( is_array( $value ) ) {
				$new_value = array();
				foreach ( $value as $i => $val ) {
					if ( isset( $crm_fields_multiselect_mapping[ $field ][ mb_strtolower( $val ) ] ) ) {
						$new_value[ $i ] = $crm_fields_multiselect_mapping[ $field ][ mb_strtolower( $val ) ];
					} else {
						wpf_log( 'warning', 0, 'Multiselect value mapping for CRM field <strong>' . $field . '</strong> value <strong>"' . $value . '"</strong> is missing a mapping.' );
						$new_value[ $i ] = $val;
					}
				}
			} else {
				if ( isset( $crm_fields_multiselect_mapping[ $field ][ mb_strtolower( $value ) ] ) ) {
					$new_value = $crm_fields_multiselect_mapping[ $field ][ mb_strtolower( $value ) ];
				} else {
					wpf_log( 'warning', 0, 'Multiselect value mapping for CRM field <strong>' . $field . '</strong> value <strong>"' . $value . '"</strong> is missing a mapping.' );
					$new_value = $value;
				}
			}

			return $new_value;
		} elseif ( $field_type == 'checkbox' || $field_type == 'checkbox-full' ) {

			if ( empty( $value ) ) {
				// If checkbox is unselected
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


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $contact_data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		$userdata = array(
			array( 'activate-unsubscribed', ( wp_fusion()->settings->get( 'mailengine_activate_unsubscribed' ) ? 'yes' : 'no' ) ),
		);
		foreach ( $contact_data as $field => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $val ) {
					$userdata[] = array( $field, $val );
				}
			} else {
				$userdata[] = array( $field, $value );
			}
		}

		$result = $this->subscribe_service->Subscribe( $this->params['client_id'], $this->params['subscribe_id'], 'email', ( wp_fusion()->settings->get( 'mailengine_hidden_subscribe' ) ? 'yes' : 'no' ), intval( $this->params['affiliate'] ), array(), $userdata, array() );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( $result == 'success' ) {
			$contact_id = $this->get_contact_id( $contact_data['email'] );
			if ( ! empty( $contact_id ) ) {
				return $contact_id;
			} else {
				return new WP_Error( $result, 'SOAP warning! The Soap <strong>Subscribe</strong> request was performed successfully, but no contact was found by <strong>' . $contact_data['email'] . '</strong>' );
			}
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $contact_data, $map_meta_fields = true, $tags = array() ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$contact_data = wp_fusion()->crm_base->map_meta_fields( $contact_data );
		}

		$userdata = array(
			array( 'id', $contact_id ),
			array( 'activate-unsubscribed', 'no' ),
		);
		foreach ( $contact_data as $field => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $val ) {
					$userdata[] = array( $field, $val );
				}
			} else {
				$userdata[] = array( $field, $value );
			}
		}

		$result = $this->subscribe_service->Subscribe( $this->params['client_id'], $this->params['subscribe_id'], 'id', 'yes', intval( $this->params['affiliate'] ), array(), $userdata, $tags );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( $result == 'success' ) {
			return true;
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
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

		$result = $this->subscribe_service->GetUserData( $this->params['client_id'], $this->params['subscribe_id'], 'id', $contact_id );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) ) {
			// var_dump($result);
			$user_meta                     = array();
			$contact_fields                = wp_fusion()->settings->get( 'contact_fields' );
			$crm_fields_multiselect_values = wp_fusion()->settings->get( 'crm_fields_multiselect_values' );
			// var_dump($contact_fields, $crm_fields_multiselect_values);
			foreach ( $contact_fields as $field_id => $field_data ) {
				if ( $field_data['active'] == true && isset( $result[ $field_data['crm_field'] ] ) ) {

					$value = $result[ $field_data['crm_field'] ];
					// var_dump($field_data['crm_field'],$crm_fields_multiselect_reverse_mapping);
					if ( ! empty( $value ) && $field_data['type'] == 'multiselect' && isset( $crm_fields_multiselect_values[ $field_data['crm_field'] ] ) ) {
						$crm_value_array = explode( ',', $value );
						$wpf_value_array = array();
						foreach ( $crm_value_array as $crm_array_item ) {
							if ( ! empty( $crm_array_item ) ) {
								$wpf_value_array[] = $crm_fields_multiselect_values[ $field_data['crm_field'] ][ $crm_array_item ];
							}
						}
						$value = $wpf_value_array;
					}

					$user_meta[ $field_id ] = $value;
				}
			}
			var_dump( $user_meta );

			return $user_meta;
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
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

		if ( ! empty( $tag ) ) {
			$result = $this->subscribe_service->GetMetaDataTags( $this->params['client_id'], $this->params['subscribe_id'] );
			$tag_id = null;

			if ( is_soap_fault( $result ) ) {
				return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
			}

			if ( is_array( $result ) ) {
				if ( ! empty( $result ) ) {
					foreach ( $result as $k => $tag_label ) {
						if ( $tag == $tag_label ) {
							$tag_id = $k;
							break;
						}
					}
				}
			} else {
				return new WP_Error( 'no tags found', 'SOAP warning! The Soap request for syncing tags was done, but returned with empty result. (No tags for group?)' );
			}
		}

		if ( ! empty( $tag ) && empty( $tag_id ) ) {
			return new WP_Error( 'no tags found', 'SOAP warning! No id found for tag <strong>' . $tag . '</strong>' );
		}

		if ( ! empty( $tag_id ) && intval( $tag_id ) ) {
			$filter = '[spec_tag_' . $tag_id . ']';
		} else {
			$filter = '';
		}

		$result = $this->subscribe_service->Export( $this->params['client_id'], $this->params['subscribe_id'], array( 'id' ), $filter );

		if ( is_soap_fault( $result ) ) {
			return new WP_Error( $result->faultcode, "SOAP Fault: (faultcode: {$result->faultcode}, faultstring: {$result->faultstring})" );
		}

		if ( is_array( $result ) ) {
			$contact_ids = array();
			foreach ( $result as $contact ) {
				$contact_ids[] = $contact[1];
			}

			return $contact_ids;
		} elseif ( $result == 'zero results for this query' ) {
			return array();
		} else {
			return new WP_Error( $result, "SOAP warning! The Soap request was done, but returned with the following error: {$result}" );
		}
	}

}
