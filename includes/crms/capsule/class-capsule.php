<?php

class WPF_Capsule {

	/**
	 * (deprecated)
	 */

	public $app;

	/**
	 * Contains API params
	 */

	public $params;

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

		$this->slug     = 'capsule';
		$this->name     = 'Capsule';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Capsule_Admin( $this->slug, $this->name, $this );
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
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );

	}

	/**
	 * Formats user entered data to match Capsule field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

			return $date;

		} elseif ( $field_type == 'int' || is_int( $value ) ) {

			// Capsule doesn't like integer values in text fields
			return (string) $value;

		} else {

			return $value;

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'capsulecrm') !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->message ) ) {

				$error_string = $body_json->message;

				if( isset( $body_json->errors ) ) {

					foreach( $body_json->errors as $error ) {
						$error_string .= ': ' . $error->message;
					}

				}

				$response = new WP_Error( 'error', $error_string );

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

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'capsule_key' );
		}

		$this->params = array(
			'user-agent'  => 'WP Fusion; ' . home_url(),
			'timeout'     => 30,
			'headers'     => array( 
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json'
			)
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

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $api_key );
		}

		$request  = "https://api.capsulecrm.com/api/v2/parties/tags";
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body_json->message ) ) {
			return new WP_Error( 'error', 'Invalid authentication token. Make sure you\'re using a Personal Access Token' );
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

		$request  = "https://api.capsulecrm.com/api/v2/parties/tags";
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if( empty( $body_json['tags'] ) ) {
			return $available_tags;
		}

		foreach ( $body_json['tags'] as $row ) {
			$available_tags[ $row['id'] ] = $row['name'];
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

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/capsule-fields.php';

		$built_in_fields = array();

		foreach ( $capsule_fields as $index => $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		$custom_fields = array();

		$request    = "https://api.capsulecrm.com/api/v2/parties/fields/definitions";
		$response   = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( isset( $body_json['definitions'] ) && is_array( $body_json['definitions'] ) ) {

			foreach ( $body_json['definitions'] as $field_data ) {

				$custom_fields[ $field_data['id'] ] = $field_data['name'];

			}

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

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_info = array();
		$request      = "https://api.capsulecrm.com/api/v2/parties/search?q=" . urlencode( $email_address );
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );


		if ( empty( $body_json['parties'] ) ) {
			return false;
		}

		return $body_json['parties'][0]['id'];
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

		$tags = array();
		$request      = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id . "?embed=tags";
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json = json_decode( $response['body'], true );

		if ( empty( $body_json['party']['tags'] ) ) {
			return false;
		}

		foreach ( $body_json['party']['tags'] as $row ) {
			$tags[] = $row['id'];
		}

		// Check if we need to update the available tags list
		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach( $body_json['party']['tags'] as $row ) {
			if( ! isset( $available_tags[ $row['id'] ] ) ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $tags;
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

		$url                = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id;
		$nparams            = $this->params;
		$post_data 			= (object) array(
			"party"		=> (object) array(
				"tags" => array()
			)
		);

		foreach( $tags as $tag ) {
			$post_data->party->tags[] = (object) array( "id" => (int) $tag );
		}

		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $post_data );

		$response = wp_remote_post( $url, $nparams );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

		return true;
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

		$url                = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id;
		$nparams            = $this->params;
		$post_data 			= (object) array(
			"party"		=> (object) array(
				"tags" => array()
			)
		);

		foreach( $tags as $tag ) {
			$post_data->party->tags[] = (object) array( "id" => (int) $tag, "_delete" => true );
		}

		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $post_data );

		$response = wp_remote_post( $url, $nparams );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body_json    = json_decode( $response['body'], true );

		return true;
	}

	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( ! isset( $data['type'] ) ) {
			$data['type'] = 'person';
		}

		if( empty( $data['firstName'] ) && empty( $data['lastName'] ) ) {
			$data['lastName'] = 'unknown';
		}

		$update_data = (object) array(
			'party' => (object) array(
				'type'	=> $data['type']
			)
		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/capsule-fields.php';

		foreach( $data as $crm_field => $value ) {

			if( is_numeric( $crm_field ) ) {

				// Custom fields

				if( ! isset( $update_data->party->fields ) ) {
					$update_data->party->fields = array();
				}

				$update_data->party->fields[] = array( 'value' => $value, 'definition' => array( 'id' => $crm_field ) );

			} else {

				foreach( $capsule_fields as $meta_key => $field_data ) {

					if( $crm_field == $field_data['crm_field'] ) {

						// This means that we've found that field in capsule-fields.php and it needs to be treated specially
						if( $crm_field == 'organisation' ) {
							
							// Organisation needs to be sent as an array
							$update_data->party->{$crm_field} = array( 'name' => $value );

						} elseif( strpos($crm_field, '+') == false ) {

							// If there is NO "+" sign in the field
							$update_data->party->{$crm_field} = $value;

						} else {

							$exploded_field = explode('+', $crm_field);

							if( $exploded_field[0] == 'email' ) {

								if( ! isset( $update_data->party->emailAddresses ) ) {
									$update_data->party->emailAddresses =  array();
								}

								$update_data->party->emailAddresses[] = array('type' => $exploded_field[1], 'address' => $value);

							} elseif ( $exploded_field[0] == 'address' ) {

								if( ! isset( $update_data->party->addresses ) ) {

									$update_data->party->addresses =  array( array('type' => $exploded_field[1], $exploded_field[2] => $value) );

								} else {

									$found_address = false;
									foreach( $update_data->party->addresses as $i => $address ) {

										if( $address['type'] == $exploded_field[1] ) {

											$found_address = true;
											$update_data->party->addresses[$i][$exploded_field[2]] = $value;

										}

									}

									if( ! $found_address ) {
										$update_data->party->addresses[] = array( 'type' => $exploded_field[1], $exploded_field[2] => $value );
									}

								}

							} elseif ( $exploded_field[0] == 'phone' ) {

								if( ! isset( $update_data->party->phoneNumbers ) ) {
									$update_data->party->phoneNumbers =  array();
								}

								$update_data->party->phoneNumbers[] = array('type' => $exploded_field[1], 'number' => $value);

							} 

						}

					}

				}

			}

		}

		$urlp              = "https://api.capsulecrm.com/api/v2/parties";
		$nparams           = $this->params;
		$nparams['method'] = 'POST';
		$nparams['body']   = json_encode( $update_data );

		$response = wp_remote_post( $urlp, $nparams );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body_json['party']['id'];

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( empty( $data ) ) {
			return false;
		}

		if( ! isset( $data['type'] ) ) {
			$data['type'] = 'person';
		}

		$update_data = (object) array(
			'party' => (object) array(
				'type'	=> $data['type']
			)
		);

		// Determine if we need to load the contact first to get the field IDs

		$needs_ids = false;

		foreach( $data as $crm_field => $value ) {

			if( ! is_numeric( $crm_field ) ) {
				$needs_ids = true;
				break;
			}

		}

		$field_ids = array();

		if( $needs_ids ) {

			$url      = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id;
			$response = wp_remote_get( $url, $this->params );

			$loaded_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if( ! empty( $loaded_data['party']['phoneNumbers'] ) ) {

				foreach( $loaded_data['party']['phoneNumbers'] as $address ) {
					$field_ids[ 'phone+' . $address['type'] ] = $address['id'];
				}

			}

			if( ! empty( $loaded_data['party']['addresses'] ) ) {

				foreach( $loaded_data['party']['addresses'] as $address ) {
					$field_ids[ 'address+' . $address['type'] ] = $address['id'];
				}

			}

			if( ! empty( $loaded_data['party']['emailAddresses'] ) ) {

				foreach( $loaded_data['party']['emailAddresses'] as $address ) {
					$field_ids[ 'email+' . $address['type'] ] = $address['id'];
				}

			}

		}


		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/capsule-fields.php';

		foreach( $data as $crm_field => $value ) {

			if( is_numeric( $crm_field ) ) {

				// Custom fields

				if( ! isset( $update_data->party->fields ) ) {
					$update_data->party->fields = array();
				}

				$update_data->party->fields[] = array( 'value' => $value, 'definition' => array( 'id' => $crm_field ) );

			} else {

				// Built in fields

				foreach( $capsule_fields as $meta_key => $field_data ) {

					if( $crm_field == $field_data['crm_field'] ) {

						// This means that we've found that field in capsule-fields.php and it needs to be treated specially

						if( $crm_field == 'organisation' ) {
							
							// Organisation needs to be sent as an array
							$update_data->party->{$crm_field} = array( 'name' => $value );

						} elseif( strpos($crm_field, '+') == false ) {

							// If there is NO "+" sign in the field
							$update_data->party->{$crm_field} = $value;

						} else {

							$exploded_field = explode('+', $crm_field);

							if( $exploded_field[0] == 'email' ) {

								if( ! isset( $update_data->party->emailAddresses ) ) {
									$update_data->party->emailAddresses =  array();
								}

								$update_data->party->emailAddresses[] = array('type' => $exploded_field[1], 'address' => $value);

							} elseif ( $exploded_field[0] == 'address' ) {

								if( ! isset( $update_data->party->addresses ) ) {

									$update_data->party->addresses =  array( array('type' => $exploded_field[1], $exploded_field[2] => $value) );

								} else {

									$found_address = false;
									foreach( $update_data->party->addresses as $i => $address ) {


										if( $address['type'] == $exploded_field[1] ) {

											$found_address = true;
											$update_data->party->addresses[$i][$exploded_field[2]] = $value;

										}

									}

									if( ! $found_address ) {
		
										$update_data->party->addresses[] = array( 'type' => $exploded_field[1], $exploded_field[2] => $value );

									}

								}

							} elseif ( $exploded_field[0] == 'phone' ) {

								if( ! isset( $update_data->party->phoneNumbers ) ) {
									$update_data->party->phoneNumbers =  array();
								}

								$update_data->party->phoneNumbers[] = array('type' => $exploded_field[1], 'number' => $value);

							} 

						}

					}

				}

			}

		}
		
		// Merge in field IDs as needed

		if( ! empty( $field_ids ) ) {

			if( ! empty( $update_data->party->phoneNumbers ) ) {

				foreach( $update_data->party->phoneNumbers as $i => $address ) {

					if( isset( $field_ids[ 'phone+' . $address['type'] ] ) ) {

						$update_data->party->phoneNumbers[ $i ]['id'] = $field_ids[ 'phone+' . $address['type'] ];

					}

				}

			}

			if( ! empty( $update_data->party->emailAddresses ) ) {

				foreach( $update_data->party->emailAddresses as $i => $address ) {

					if( isset( $field_ids[ 'email+' . $address['type'] ] ) ) {

						$update_data->party->emailAddresses[ $i ]['id'] = $field_ids[ 'email+' . $address['type'] ];

					}

				}

			}

			if( ! empty( $update_data->party->addresses ) ) {

				foreach( $update_data->party->addresses as $i => $address ) {

					if( isset( $field_ids[ 'address+' . $address['type'] ] ) ) {

						$update_data->party->addresses[ $i ]['id'] = $field_ids[ 'address+' . $address['type'] ];

					}

				}

			}

		}

		$urlp              = "https://api.capsulecrm.com/api/v2/parties/". $contact_id . "?embed=fields";
		$nparams           = $this->params;
		$nparams['method'] = 'PUT';
		$nparams['body']   = json_encode( $update_data );

		$response = wp_remote_post( $urlp, $nparams );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

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

		$url      = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id . "?embed=fields";
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$email_misc = array();
		$phone_misc = array();

		$loaded_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if( $field_data['active'] != true || empty( $field_data['crm_field'] ) ) {
				continue;
			}

			if( strpos($field_data['crm_field'], '+') == false ) {

				// First level fields
				if( !empty( $body_json['party'][ $field_data['crm_field'] ] ) ) {
					$loaded_meta[ $field_id ] = $body_json['party'][ $field_data['crm_field'] ];
				}


			} else {

				$exploded_field = explode('+', $field_data['crm_field']);

				if( $exploded_field[0] == 'email' ) {

					// Email fields
					foreach( $body_json['party']['emailAddresses'] as $email_address ) {

						if( $email_address['type'] == $exploded_field[1] ) {

							// Handle misc field
							if( ! isset( $loaded_meta[ $field_id ] ) ) {

								$loaded_meta[ $field_id ] = $email_address['address'];

							} else {

								$email_misc[] = $email_address['address'];

							}
						}

					}

				} elseif( $exploded_field[0] == 'address' ) {

					// Address fields
					foreach( $body_json['party']['addresses'] as $address ) {

						if( $address['type'] == $exploded_field[1] ) {
							$loaded_meta[ $field_id ] = $address[ $exploded_field[2] ];
						}

					}


				} elseif( $exploded_field[0] == 'phone' ) {

					// Phone Numbers
					foreach( $body_json['party']['phoneNumbers'] as $phone_number ) {

						if( $phone_number['type'] == $exploded_field[1] ) {

							// Handle misc field
							if( ! isset( $loaded_meta[ $field_id ] ) ) {

								$loaded_meta[ $field_id ] = $phone_number['number'];

							} else {

								$phone_misc[] = $phone_number['number'];

							}

							
						}

					}

				}

			}

			// Custom fields

			if( ! empty( $body_json['party']['fields'] ) ) {

				foreach( $body_json['party']['fields'] as $field ) {

					if( $field['definition']['id'] == $field_data['crm_field'] ) {
						$loaded_meta[ $field_id ] = $field['value'];
					}

				}

			}

		}

		// Merge in misc fields

		if( ! empty( $phone_misc ) ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if( isset( $field_data['crm_field'] ) && $field_data['crm_field'] == 'phone+Misc' ) {
					
					$loaded_meta[ $field_id ] = implode(', ', $phone_misc);

				}

			}

		}

		// Merge in misc fields
		if( ! empty( $email_misc ) ) {

			foreach ( $contact_fields as $field_id => $field_data ) {

				if( isset( $field_data['crm_field'] ) && $field_data['crm_field'] == 'email+Misc' ) {
					
					$loaded_meta[ $field_id ] = implode(', ', $email_misc);

				}

			}

		}

		return $loaded_meta;

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

		$tag = wp_fusion()->user->get_tag_label( $tag );

		$query_data = (object) array(
			'filter' => (object) array(
				'conditions' => array( (object) array(
					'field' => 'tag',
					'operator' => 'is',
					'value' => $tag
					)
				)
			)
		);

		$contact_ids = array();
		$page = 1;
		$proceed = true;

		while($proceed == true) {

			$urlp              = "https://api.capsulecrm.com/api/v2/parties/filters/results?perPage=100&page=" . $page;
			$nparams           = $this->params;
			$nparams['method'] = 'POST';
			$nparams['body']   = json_encode( $query_data );

			$results = wp_remote_post( $urlp, $nparams );

			if( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );

			foreach ( $body_json['parties'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			$page++;

			if(count($body_json['parties']) < 100) {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

}