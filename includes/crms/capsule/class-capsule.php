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
			$date = date( 'm/d/Y', $value );

			return $date;

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

		// Get saved data from DB
		if ( empty( $api_key ) ) {
			$api_key = wp_fusion()->settings->get( 'capsule_key' );
		}

		$this->params = array(
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
		$offset         = 0;
		$continue       = true;

		while( $continue == true ) {

			$request  = "https://api.capsulecrm.com/api/v2/parties/tags";
			$response = wp_remote_get( $request, $this->params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

			$body_json = json_decode( $response['body'], true );

			foreach ( $body_json['tags'] as $row ) {
				$available_tags[ $row['id'] ] = $row['name'];
			}

			if ( count( $body_json['parties'] ) < 50 ) {
				$continue = false;
			}

			$offset = $offset + 50;
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

		foreach( $body_json as $row ) {
			if( !isset( $available_tags[ $row['id'] ] ) ) {
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

		$update_data = (object) array(
			'party' => (object) array(
				'type'	=> 'person'
			)

		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/capsule-fields.php';

		foreach( $data as $crm_field => $value ) {

			foreach( $capsule_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// This means that we've found that field in capsule-fields.php and it needs to be treated specially
					if( strpos($crm_field, '+') == false ) {

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

		$update_data = (object) array(
			'party' => (object) array(
				'type'	=> 'person'
			)

		);

		// Load built in fields to get field types and subtypes
		require dirname( __FILE__ ) . '/admin/capsule-fields.php';

		foreach( $data as $crm_field => $value ) {

			foreach( $capsule_fields as $meta_key => $field_data ) {

				if( $crm_field == $field_data['crm_field'] ) {

					// This means that we've found that field in capsule-fields.php and it needs to be treated specially
					if( strpos($crm_field, '+') == false ) {

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

		$urlp              = "https://api.capsulecrm.com/api/v2/parties/". $contact_id;
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

		$url      = "https://api.capsulecrm.com/api/v2/parties/" . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		$loaded_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

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

						if( $email_address['type'] == $exploded_field[1] || $email_address['type'] == null ) {
							$loaded_meta[ $field_id ] = $email_address['address'];
						}

					}

				} elseif( $exploded_field[0] == 'address' ) {

					// Address fields
					foreach( $body_json['party']['addresses'] as $address ) {

						if( $address['type'] == $exploded_field[1] && !empty( $address[ $exploded_field[2] ] ) ) {

							$loaded_meta[ $field_id ] = $address[ $exploded_field[2] ];

						}

					}


				} elseif( $exploded_field[0] == 'phone' ) {

					// Phone Numbers
					foreach( $body_json['party']['phoneNumbers'] as $phone_number ) {

						if( $phone_number['type'] == $exploded_field[1] || $phone_number['type'] == null ) {
							$loaded_meta[ $field_id ] = $phone_number['number'];
						}

					}

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

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

		foreach ($available_tags as $key => $value) {

			$update_data = (object) array(
				'filter' => (object) array(
					'conditions' => array( (object) array(
						'field' => 'tag',
						'operator' => 'is',
						'value' => $value
						)
					
					)
				)
			);

		}

		$contact_ids = array();
		$offset = 0;
		$proceed = true;

		while($proceed == true) {

			$urlp              = "https://api.capsulecrm.com/api/v2/parties/filters/results";
			$nparams           = $this->params;
			$nparams['method'] = 'POST';
			$nparams['body']   = json_encode( $update_data );

			$results = wp_remote_post( $urlp, $nparams );

			if( is_wp_error( $results ) ) {
				return $results;
			}

			$body_json = json_decode( $results['body'], true );


			foreach ( $body_json['parties'] as $row => $contact ) {
				$contact_ids[] = $contact['id'];
			}

			$offset = $offset + 50;

			if(count($body_json['data']) < 50) {
				$proceed = false;
			}

		}

		return $contact_ids;

	}

}