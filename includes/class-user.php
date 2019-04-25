<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_User {


	/**
	 * WPF_User constructor.
	 */
	
	public function __construct() {

		add_action( 'user_register', array( $this, 'user_register' ), 20 );
		add_action( 'profile_update', array( $this, 'profile_update'), 10, 2);
		add_action( 'delete_user', array( $this, 'user_delete') );
		add_action( 'password_reset', array( $this, 'password_reset' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );

		// Roles
		add_action( 'set_user_role', array( $this, 'update_user_role' ), 10, 3);
		add_action( 'add_user_role', array( $this, 'add_remove_user_role' ), 10, 2);
		add_action( 'remove_user_role', array( $this, 'add_remove_user_role' ), 10, 2);

		add_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		add_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

		add_action( 'wpf_user_imported', array( $this, 'return_password' ), 10, 2 );

		// Lead source tracking
		add_action( 'init', array( $this, 'set_lead_source' ) );
		add_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source' ) );

		// Profile update tags
		add_action( 'wpf_pushed_user_meta', array( $this, 'pushed_user_meta_tags' ), 10, 2 );

	}


	/**
	 * Used by create user to map post data for PHP versions less than 5.3
	 *
	 * @access public
	 * @return mixed
	 */

	public function map_user_meta( $a ) {
		return $a[0];
	}

	/**
	 * Tries to detect a leadsource for new visitors and makes the data available to integrations
	 *
	 * @access  public
	 * @return  void
	 */

	function set_lead_source() {

		$leadsource_vars = array(
			'leadsource',
			'utm_campaign',
			'utm_medium',
			'utm_source',
			'utm_term',
			'utm_content',
			'gclid',
		);

		$alt_vars = array(
			'original_ref',
			'landing_page'
		);

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach( $leadsource_vars as $var ) {

			if( isset($_GET[ $var ]) && isset( $contact_fields[ $var ] ) && $contact_fields[ $var ]['active'] == true ) {
				setcookie( 'wpf_leadsource[' . $var . ']', $_GET[ $var ], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

		}

		if( ! is_admin() && empty( $_COOKIE['wpf_ref'] ) ) {

			if( isset( $contact_fields['original_ref'] ) && $contact_fields['original_ref']['active'] == true && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				setcookie( 'wpf_ref[original_ref]', $_SERVER['HTTP_REFERER'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

			if( isset( $contact_fields['landing_page'] ) && $contact_fields['landing_page']['active'] == true ) {
				setcookie( 'wpf_ref[landing_page]', $_SERVER['REQUEST_URI'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

		}

	}

	/**
	 * Merges lead source variables on contact add
	 *
	 * @access  public
	 * @return  array Args
	 */

	function merge_lead_source( $args ) {

		if( ! isset( $_COOKIE['wpf_leadsource'] ) && ! isset( $_COOKIE['wpf_ref'] ) ) {
			return $args;
		}

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		// Possibly set lead sources from cookie
		if( isset($_COOKIE['wpf_leadsource']) && is_array( $_COOKIE['wpf_leadsource'] ) ) {

			foreach( $_COOKIE['wpf_leadsource'] as $key => $value ) {

				if( isset( $contact_fields[$key] ) && $contact_fields[$key]['active'] == true ) {

					$args[0][ $contact_fields[ $key ]['crm_field'] ] = $value;

				}

			}

		}

		if( isset($_COOKIE['wpf_ref']) && is_array( $_COOKIE['wpf_ref'] ) ) {

			foreach( $_COOKIE['wpf_ref'] as $key => $value ) {

				if( isset( $contact_fields[$key] ) && $contact_fields[$key]['active'] == true ) {

					$args[0][ $contact_fields[ $key ]['crm_field'] ] = $value;

				}

			}

		}

		return $args;

	}


	/**
	 * Triggered when a new user is registered. Creates the user in the CRM and stores contact ID
	 *
	 * @access public
	 *
	 * @param $user_id
	 * @param array $post_data
	 * @param bool $force
	 *
	 * @return mixed Contact ID
	 */

	public function user_register( $user_id, $post_data = null, $force = false ) {

		if ( wp_fusion()->settings->get( 'create_users' ) != true && $force == false ) {
			return false;
		}

		remove_action( 'profile_update', array( $this, 'profile_update'), 10, 2);

		do_action( 'wpf_user_register_start', $user_id, $post_data );

		if ( empty( $post_data ) && ! empty( $_POST ) ) {

			$post_data = $_POST;

		} elseif ( empty( $post_data ) ) {

			// If nothing's been supplied, get meta data from DB
			$post_data = array_map( array( $this, 'map_user_meta' ), get_user_meta( $user_id ) );

		}

		// Fill in some blanks from the DB if possible

		$userdata               		= get_userdata( $user_id );
		$post_data['user_id']			= $user_id;

		if( is_array( $userdata->roles ) ) {
			$post_data['role'] = $userdata->roles[0];
		}

		foreach( array( 'first_name', 'last_name', 'user_email', 'user_url', 'user_login', 'user_nicename', 'user_registered' ) as $meta_key ) {

			if( empty( $post_data[ $meta_key ] ) ) {
				$post_data[ $meta_key ] = $userdata->{$meta_key};
			}

		}

		// See if user role is elligible for being created as a contact
		$valid_roles = wp_fusion()->settings->get( 'user_roles', false );

		$valid_roles = apply_filters( 'wpf_register_valid_roles', $valid_roles, $user_id, $post_data );

		if ( is_array( $valid_roles ) && ! empty( $valid_roles[0] ) && ! in_array( $post_data['role'], $valid_roles ) && $force == false ) {

			wp_fusion()->logger->handle( 'notice', $user_id, 'User not added to ' . wp_fusion()->crm->name . ' because role <strong>' . $post_data['role'] . '</strong> isn\'t enabled for contact creation.' );

			return false;

		}

		// Get password from Admin >> Add New user screen
		if ( ! empty( $post_data['pass1'] ) ) {
			$post_data['user_pass'] = $post_data['pass1'];
		}

		// Allow outside modification of this data
		$post_data = apply_filters( 'wpf_user_register', $post_data, $user_id );

		// Allows for cancelling of registration via filter
		if ( $post_data == null || empty( $post_data['user_email'] ) ) {
			return false;
		}

		// Check if contact already exists in CRM
		$contact_id = $this->get_contact_id( $user_id, true );

		if ( $contact_id == false ) {

			// Logger
			wp_fusion()->logger->handle( 'info', $user_id, 'Adding contact to ' . wp_fusion()->crm->name . ':', array( 'meta_array' => $post_data ) );

			$contact_id = wp_fusion()->crm->add_contact( $post_data );

			// Error logging
			if( is_wp_error( $contact_id ) ) {

				wp_fusion()->logger->handle( $contact_id->get_error_code(), $user_id, 'Error adding contact to ' . wp_fusion()->crm->name . ': ' . $contact_id->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
				return false;

			}

			update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		} else {

			wp_fusion()->logger->handle( 'info', $user_id, 'Updating contact ID ' . $contact_id . ' in ' . wp_fusion()->crm->name . ': ', array( 'meta_array' => $post_data ) );

			// If contact exists, update data and pull down anything new from the CRM
			$result = wp_fusion()->crm->update_contact( $contact_id, $post_data );

			if( is_wp_error( $result ) ) {

				wp_fusion()->logger->handle( $result->get_error_code(), $user_id, 'Error updating contact: ' . $result->get_error_message() );
				return false;

			}

			$this->pull_user_meta( $user_id );
			$this->get_tags( $user_id, true );

		}

		// Assign any tags specified in the WPF settings page
		$assign_tags = wp_fusion()->settings->get( 'assign_tags' );

		if ( ! empty( $assign_tags ) ) {
			$this->apply_tags( $assign_tags, $user_id );
		}

		do_action( 'wpf_user_created', $user_id, $contact_id, $post_data );

		return $contact_id;


	}

	/**
	 * Triggered when profile updated
	 *
	 * @access public
	 * @return void
	 */

	public function profile_update( $user_id, $old_user_data ) {

		if( ! empty( $_POST ) ) {
			$this->push_user_meta( $user_id, $_POST );
		}

	}
	

	/**
	 * Triggered when a user is deleted or deletes their own account. Applies tag for tracking.
	 *
	 * @access public
	 * @return void
	 */

	public function user_delete( $user_id ) {

		$tags = wp_fusion()->settings->get( 'deletion_tags', array() );

		if( ! empty( $tags ) ) {
			$this->apply_tags( $tags, $user_id );
		}
		
	}


	/**
	 * Determine if a user has a contact record
	 *
	 * @access public
	 * @return bool
	 */

	public function has_contact_id( $user_id ) {

		$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true );

		if( ! empty( $contact_id ) ) {
			return true;
		} else {
			return false;
		}

	}


	/**
	 * Gets contact ID from user ID
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $user_id = false, $force_update = false ) {

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		do_action( 'wpf_get_contact_id_start', $user_id );

		$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true );

		// If contact ID is already set
		if ( ( ! empty( $contact_id ) || $contact_id == false ) && $force_update == false ) {
			return $contact_id;
		}

		$user = get_user_by( 'id', $user_id );

		if( empty( $user ) && defined( 'DOING_WPF_AUTO_LOGIN' ) ) {

			$user = new stdClass;
			$user->user_email = get_user_meta( $user_id, 'user_email', true );

		}

		// If no user email set, don't bother with an API call
		if ( empty( $user->user_email ) ) {
			return false;
		}

		$contact_id = wp_fusion()->crm->get_contact_id( $user->user_email );

		if( is_wp_error( $contact_id ) ) {

			wp_fusion()->logger->handle( $contact_id->get_error_code(), $user_id, 'Error getting contact ID for <strong>' . $user->user_email . '</strong>: ' . $contact_id->get_error_message() );
			return false;

		} else if ( $contact_id == false ) {

			// Error logging
			wp_fusion()->logger->handle( 'info', $user_id, 'No contact found in ' . wp_fusion()->crm->name . ' for <strong>' . $user->user_email . '</strong>' );

		}

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		do_action( 'wpf_got_contact_id', $user_id, $contact_id );

		return $contact_id;

	}

	/**
	 * Gets and saves updated user meta from the CRM
	 *
	 * @access public
	 * @return void
	 */

	public function pull_user_meta( $user_id ) {

		$contact_id = $this->get_contact_id( $user_id );

		do_action( 'wpf_pre_pull_user_meta', $user_id );
		
		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		// Error logging

		if ( is_wp_error( $user_meta ) ) {

			wp_fusion()->logger->handle( $user_meta->get_error_code(), $user_id, 'Error loading contact user meta: ' . $user_meta->get_error_message() );
			return false;

		} elseif ( empty( $user_meta ) ) {

			wp_fusion()->logger->handle( 'notice', $user_id, 'No elligible user meta loaded' );
			return false;

		}

		$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user_id );

		// Logger
		wp_fusion()->logger->handle( 'info', $user_id, 'Loaded meta data from ' . wp_fusion()->crm->name . ':', array( 'meta_array' => $user_meta ) );

		// Don't push updates back to CRM
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

		$this->set_user_meta( $user_id, $user_meta );

		do_action( 'wpf_user_updated', $user_id, $user_meta );

		return $user_meta;

	}

	/**
	 * Sets an array of meta data for the user
	 *
	 * @access public
	 * @return void
	 */

	public function set_user_meta( $user_id, $user_meta ) {

		// Don't send updates back
		remove_action( 'profile_update', array( $this, 'profile_update'), 10, 2);

		// Save all of it to usermeta table if doing auto login
		if( defined( 'DOING_WPF_AUTO_LOGIN' ) ) {

			foreach ( $user_meta as $key => $value ) {

				update_user_meta( $user_id, $key, $value );

			}

		} else {

			$user = get_userdata( $user_id );

			foreach ( $user_meta as $key => $value ) {

				if( empty( $value ) && $value != '0' ) {
					continue;
				}

				// Don't reset passwords for admins
				if ( $key == 'user_pass' && ! empty( $value ) && ! user_can( $user_id, 'manage_options' ) ) {

					// Only update pass if it's changed
					if ( wp_check_password( $value, $user->data->user_pass, $user_id ) == false ) {

						wp_fusion()->logger->handle( 'notice', $user_id, 'User password set to <strong>' . $value . '</strong>' );

						// Don't send it back again
						remove_action( 'password_reset', array( $this, 'password_reset' ), 10, 2 );
						wp_set_password( $value, $user_id );

					}


				} elseif ( $key == 'display_name' ) {

					wp_update_user( array( 'ID' => $user_id, 'display_name' => $value ) );

				} elseif ( $key == 'user_email' && $value != $user->user_email ) {

					wp_update_user( array( 'ID' => $user_id, 'user_email' => $value ) );

				} elseif ( $key == 'user_registered' ) {

					// Don't override the registered date
					continue;

				} elseif ( $key == 'user_url' ) {

					wp_update_user( array( 'ID' => $user_id, 'user_url' => $value ) );

				} elseif ( $key == 'role' && ! user_can( $user_id, 'manage_options' ) && wp_roles()->is_role( $value ) ) {

					// Don't send it back again
					remove_action( 'set_user_role', array( $this, 'update_user_role' ), 10, 3);
					wp_update_user( array( 'ID' => $user_id, 'role' => $value ) );

				} elseif ( $key == 'wp_capabilities' && ! user_can( $user_id, 'manage_options' ) ) {

					if( ! is_array( $value ) ) {
						$value = explode(',', $value);
					}

					if( is_array( $value ) ) {

						foreach( $value as $i => $role ) {

							if( ! wp_roles()->is_role( $role ) ) {
								unset( $value[$i] );
							}

						}

						if( ! empty( $value ) ) {
							update_user_meta( $user_id, $key, $value );
						}

					}

				} else {

					update_user_meta( $user_id, $key, $value );

				}

				do_action( 'wpf_user_meta_updated', $user_id, $key, $value );

			}

		}

	}

	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return array Tags applied to the user
	 */

	public function get_tags( $user_id = false, $force_update = false, $lookup_cid = true ) {

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		do_action( 'wpf_get_tags_start', $user_id );

		$user_tags = get_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', true );

		if ( is_array( $user_tags ) && $force_update == false ) {
			return $user_tags;
		}

		// If no tags
		if ( empty( $user_tags ) && $force_update == false ) {
			return array();
		}

		// Don't get the CID again if the request came from a webhook
		if( $lookup_cid == false ) {
			$force_update = false;
		}

		$contact_id = $this->get_contact_id( $user_id, $force_update );

		// If contact doesn't exist in CRM
		if ( $contact_id == false ) {

			update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', false );
			return array();

		}

		$tags = wp_fusion()->crm->get_tags( $contact_id );

		if ( is_wp_error( $tags ) ) {

			wp_fusion()->logger->handle( $tags->get_error_code(), $user_id, 'Failed loading tags: ' . $tags->get_error_message() );
			return $user_tags;

		} elseif( $tags == $user_tags ) { 

			// If nothing changed
			return $user_tags;

		} else {
			$user_tags = (array) $tags;
		}


		if( ! empty( $user_tags ) ) {
			wp_fusion()->logger->handle( 'info', $user_id, 'Loaded tag(s): ', array( 'tag_array' => $user_tags) );
		}

		// Check and see if new tags have been pulled, and if so, resync the available tags list
		if( is_admin() ) {

			$sync_needed = false;
			$available_tags = wp_fusion()->settings->get( 'available_tags' );

			foreach( (array) $user_tags as $tag ) {

				if(!isset($available_tags[$tag])) {
					$sync_needed = true;
				}

			}

			if( $sync_needed == true ) {
				wp_fusion()->crm->sync_tags();
			}
			
		}

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );

		do_action( 'wpf_tags_modified', $user_id, $user_tags );

		return $user_tags;

	}

	/**
	 * Applies an array of tags to a given user ID
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $user_id = false ) {

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		do_action( 'wpf_apply_tags_start', $user_id, $tags );

		$tags = apply_filters( 'wpf_apply_tags', $tags, $user_id );

		$contact_id = $this->get_contact_id( $user_id );

		// If no contact ID, don't try applying tags
		if ( $contact_id == false ) {
			return false;
		}

		// Save locally before trying to update CRM
		$user_tags = $this->get_tags( $user_id );

		// Maybe quit early if user already has the tag
		$diff = array_diff( (array) $tags, $user_tags );

		if( empty( $diff ) ) {
			return true;
		}

		$user_tags = array_unique( array_merge( $user_tags, $tags ) );
		
		// Logging
		wp_fusion()->logger->handle( 'info', $user_id, 'Applying tag(s): ', array( 'tag_array' => $tags) );

		$result = wp_fusion()->crm->apply_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wp_fusion()->logger->handle( $result->get_error_code(), $user_id, 'Error while applying tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;
		}

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );

		do_action( 'wpf_tags_applied', $user_id, $tags );
		do_action( 'wpf_tags_modified', $user_id, $user_tags );

		return true;

	}

	/**
	 * Removes an array of tags from a given user ID
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $user_id = false ) {

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		do_action( 'wpf_remove_tags_start', $user_id, $tags );

		$tags = apply_filters( 'wpf_remove_tags', $tags );

		$contact_id = $this->get_contact_id( $user_id );

		// If no contact ID, don't try applying tags
		if ( $contact_id == false ) {
			return false;
		}

		// Save locally before trying to update CRM
		$user_tags = $this->get_tags( $user_id );

		// Maybe quit early if user already has the tag
		$count_pre_remove = count( $user_tags );

		$user_tags = array_diff( $user_tags, $tags );

		if( $count_pre_remove == count( $user_tags ) )
			return true;

		// Logging
		wp_fusion()->logger->handle( 'info', $user_id, 'Removing tag(s): ', array( 'tag_array' => $tags) );

		$result = wp_fusion()->crm->remove_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wp_fusion()->logger->handle( $result->get_error_code(), $user_id, 'Error while removing tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;
		}

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );

		do_action( 'wpf_tags_removed', $user_id, $tags );
		do_action( 'wpf_tags_modified', $user_id, $user_tags );

		return true;

	}

	/**
	 * Triggered when a password is reset
	 *
	 * @access public
	 * @return void
	 */

	public function password_reset( $user, $new_pass ) {

		wp_fusion()->user->push_user_meta( $user->ID, array( 'user_pass' => $new_pass ) );

	}


	/**
	 * Returns generated password to CRM
	 *
	 * @access public
	 * @return void
	 */

	public function return_password( $user_id, $user_meta ) {

		if ( wp_fusion()->settings->get( 'return_password' ) != true ) {
			return;
		}

		$password_field = wp_fusion()->settings->get( 'return_password_field', array() );

		if( ! empty( $password_field['crm_field'] ) ) {

			$contact_id = $this->get_contact_id( $user_id );
			wp_fusion()->crm->update_contact( $contact_id, array( $password_field['crm_field'] => $user_meta['user_pass'] ), false );

			wp_fusion()->logger->handle( 'info', $user_id, 'Returning generated password <strong>' . $user_meta['user_pass'] . '</strong> to ' . wp_fusion()->crm->name );

			$this->push_user_meta( $user_id, array( 'user_login' => $user_meta['user_login'] ) );

		} else {

			$this->push_user_meta( $user_id, array('user_pass' => $user_meta['user_pass'], 'user_login' => $user_meta['user_login'] ) );

		}

	}


	/**
	 * Triggered when user role updated
	 *
	 * @access public
	 * @return void
	 */

	public function update_user_role( $user_id, $role, $old_roles ) {

		$this->push_user_meta( $user_id, array('role' => $role ));

	}

	/**
	 * Triggered when user role added or removed
	 *
	 * @access public
	 * @return void
	 */

	public function add_remove_user_role( $user_id, $role ) {

		$user = get_userdata( $user_id );

		if( ! empty( $user->caps ) && is_array( $user->caps ) ) {

			$roles = implode(', ', array_keys( $user->caps ) );

			$this->push_user_meta( $user_id, array( 'wp_capabilities' => $roles ));

		}

	}

	/**
	 * Update tags on login
	 *
	 * @access public
	 * @return void
	 */

	public function login( $user_login, $user = false ) {

		if( $user == false ) {
			$user = get_user_by( 'login', $user_login );
		}

		if( wp_fusion()->settings->get( 'login_sync' ) == true ) {

			$cid = $this->get_contact_id( $user->ID );

			if( ! empty( $cid ) ) {
				$this->get_tags( $user->ID, true );
			}

		}

		if( wp_fusion()->settings->get( 'login_meta_sync' ) == true ) {

			$cid = $this->get_contact_id( $user->ID );

			if( ! empty( $cid ) ) {
				$this->pull_user_meta( $user->ID );
			}

		}

	}

	/**
	 * Gets user ID from contact ID
	 *
	 * @access public
	 * @return int User ID
	 */

	public function get_user_id( $contact_id ) {

		$users = get_users( array(
			'meta_key'   => wp_fusion()->crm->slug . '_contact_id',
			'meta_value' => $contact_id,
			'fields'     => array( 'ID' )
		) );

		if ( ! empty( $users ) ) {
			return $users[0]->ID;
		} else {
			return false;
		}

	}

	/**
	 * Checks to see if a user has a given tag
	 *
	 * @access public
	 * @return bool
	 */

	public function has_tag( $tag, $user_id = false ) {

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		$user_tags = $this->get_tags( $user_id );

		// Allow overrides by admin bar
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) && get_query_var( 'wpf_tag' ) ) {

			if ( get_query_var( 'wpf_tag' ) == 'unlock-all' ) {
				return true;
			}

			if ( get_query_var( 'wpf_tag' ) == 'lock-all' ) {
				return false;
			}

			$user_tags[] = get_query_var( 'wpf_tag' );

		}

		if ( empty( $user_tags ) ) {
			return false;
		}

		// Get tag ID if label passed
		if ( ! is_numeric( $tag ) ) {
			$tag = $this->get_tag_id( $tag );
		}

		if ( in_array( $tag, $user_tags ) ) {
			return true;
		}


		return false;

	}

	/**
	 * Gets tag ID from tag name
	 *
	 * @access public
	 * @return int ID
	 */

	public function get_tag_id( $tag_name ) {

		$tag_name = trim( $tag_name );

		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		// If it's already an ID
		if( isset( $available_tags[ $tag_name ] ) ) {
			return $tag_name;
		}

		foreach ( $available_tags as $id => $data ) {

			if ( isset( $data['label'] ) && $data['label'] == $tag_name ) {

				return $id;

			} elseif ( $data == $tag_name ) {

				return $id;

			} elseif ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

				return $tag_name;

			}

		}

		return false;

	}

	/**
	 * Gets the display label for a given tag ID
	 *
	 * @access public
	 * @return string Label for given tag
	 */

	public function get_tag_label( $tag_id ) {

		$available_tags = wp_fusion()->settings->get( 'available_tags' );

		if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			return $tag_id;

		} elseif ( ! isset( $available_tags[ $tag_id ] ) ) {

			return '(Unknown Tag: ' . $tag_id . ')';

		} elseif ( is_array( $available_tags[ $tag_id ] ) ) {

			return $available_tags[ $tag_id ]['label'];

		} else {

			return $available_tags[ $tag_id ];

		}

	}

	/**
	 * Triggered when any single user_meta field is updated
	 *
	 * @access public
	 * @return void
	 */

	public function push_user_meta_single( $meta_id, $object_id, $meta_key, $_meta_value ) {

		// Allow itegrations to register fields that should always sync when modified

		$watched_fields = apply_filters( 'wpf_watched_meta_fields', array() );

		// Don't even try if the field isn't enabled for sync

		if ( wp_fusion()->settings->get( 'push_all_meta' ) != true && ! in_array( $meta_key, $watched_fields ) ) {
			return;
		}

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		if( empty( $contact_fields[ $meta_key ] ) || $contact_fields[ $meta_key ]['active'] != true && ! in_array( $meta_key, $watched_fields ) ) {
			return;
		}

		$this->push_user_meta( $object_id, array( $meta_key => $_meta_value ) );

	}


	/**
	 * Sends updated user meta to CRM
	 *
	 * @access public
	 * @return bool / WP Error
	 */

	public function push_user_meta( $user_id, $user_meta = false ) {

		if ( wp_fusion()->settings->get( 'push' ) != true ) {
			return;
		}

		do_action( 'wpf_push_user_meta_start', $user_id, $user_meta );

		// If we're submitting from a built in profile edit page
		if ( isset( $_POST['action'] ) && ( $_POST['action'] == 'createuser' || $_POST['action'] == 'update' ) && $user_meta == false ) {

			$user_meta = $_POST;
			$userdata          			= get_userdata( $user_id );
			$user_meta['user_login'] 	= $userdata->user_login;
			$user_meta['user_id'] 		= $user_id;

			if( is_array( $userdata->roles ) ) {
				$user_meta['role'] = $userdata->roles[0];
			}

		} elseif ( empty( $user_meta ) ) {

			// If nothing's been supplied, get the latest from the DB
			$user_meta         				= array_map( array( $this, 'map_user_meta' ), get_user_meta( $user_id ) );
			$userdata          				= get_userdata( $user_id );
			$user_meta['user_login'] 		= $userdata->user_login;
			$user_meta['user_email'] 		= $userdata->user_email;
			$user_meta['user_id'] 			= $user_id;
			$user_meta['user_registered'] 	= $userdata->user_registered;

			if( is_array( $userdata->roles ) ) {
				$user_meta['role'] = $userdata->roles[0];
			}

		}

		$user_meta = apply_filters( 'wpf_user_update', $user_meta, $user_id );

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $contact_id ) || empty( $user_meta ) ) {
			return;
		}

		wp_fusion()->logger->handle( 'info', $user_id, 'Pushing meta data to ' . wp_fusion()->crm->name . ': ', array( 'meta_array' => $user_meta ) );

		$result = wp_fusion()->crm->update_contact( $contact_id, $user_meta );

		if( is_wp_error( $result ) ) {

			wp_fusion()->logger->handle( $result->get_error_code(), $user_id, 'Error while updating meta data: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;

		} elseif( $result == false ) {

			// If nothing was updated
			return false;

		}

		do_action( 'wpf_pushed_user_meta', $user_id, $contact_id, $user_meta );

		return true;

	}

	/**
	 * Optionally apply tags after a profile has been updated
	 *
	 * @access public
	 * @return void
	 */

	public function pushed_user_meta_tags( $user_id, $contact_id ) {

		$update_tags = wp_fusion()->settings->get( 'profile_update_tags', false );

		if( ! empty( $update_tags ) ) {
			wp_fusion()->crm->apply_tags( $update_tags, $contact_id );
		}

	}

	/**
	 * Imports a user
	 *
	 * @access public
	 * @return int User ID of newly created user
	 */

	public function import_user( $contact_id, $send_notification = false, $role = false ) {

		// First see if user already exists
		$users = get_users( array(
			'meta_key'   => wp_fusion()->crm->slug . '_contact_id',
			'meta_value' => $contact_id,
			'fields'     => array( 'ID' )
		) );

		if ( ! empty( $users ) ) {

			$this->pull_user_meta( $users[0]->ID );
			$this->get_tags( $users[0]->ID, true );

			// Maybe change role (but not for admins)
			if ( ! empty( $role ) && ! user_can( $users[0]->ID, 'manage_options' ) && wp_roles()->is_role( $role ) ) {

				$user = new WP_User( $users[0]->ID );
				$user->set_role( $role );
			}

			return $users[0]->ID;

		}

		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		if ( is_wp_error( $user_meta ) ) {

			return $user_meta;

		} elseif( empty( $user_meta['user_email'] ) ) {

			return new WP_Error( 'error', 'No email provided for imported user' );

		}

		// See if user with matching email exists
		$user = get_user_by( 'email', $user_meta['user_email'] );

		if( is_wp_error( $user ) ) {

			wp_fusion()->logger->handle( 'error', 0, 'Error importing contact ID ' . $contact_id . ' with error: ' . $user->get_error_message() );
			return false;

		} elseif ( is_object( $user ) ) {

			$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user->ID );

			// Don't push updates back to CRM
			remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

			update_user_meta( $user->ID, wp_fusion()->crm->slug . '_contact_id', $contact_id );
			$this->set_user_meta( $user->ID, $user_meta );
			$this->get_tags( $user->ID, true );

			// Maybe change role (but not for admins)
			if ( ! empty( $role ) && ! user_can( $user->ID, 'manage_options' ) && wp_roles()->is_role( $role ) ) {

				$user = new WP_User( $user->ID );
				$user->set_role( $role );
				
			}

			do_action( 'wpf_user_updated', $user->ID, $user_meta );

			return $user->ID;

		}

		if ( empty( $user_meta['user_pass'] ) ) {

			// Generate a password if one hasn't been supplied
			$user_meta['user_pass'] = wp_generate_password( $length = 12, $include_standard_special_chars = false );
			$user_meta['generated_user_pass'] = 'true';

		} else {

			// If we're not generating a password, no need to send it back
			remove_action( 'wpf_user_imported', array( $this, 'return_password' ), 10, 2 );

		}

		// If user name is set
		if ( empty( $user_meta['user_login'] ) ) {
			$user_meta['user_login'] = $user_meta['user_email'];
		}

		if ( empty( $role ) || $role == 'administrator' ) {
			$user_meta['role'] = 'subscriber';
		} else {
			$user_meta['role'] = $role;
		}

		// Set contact ID
		$user_meta[ wp_fusion()->crm->slug . '_contact_id' ] = $contact_id;

		// Apply filters
		$user_meta = apply_filters( 'wpf_import_user', $user_meta );

		// Allows for cancelling via filter
		if ( $user_meta == null ) {
			return false;
		}

		// Prevent the default registration hook from running
		remove_action( 'user_register', array( $this, 'user_register' ), 20 );

		// Don't push updates back to CRM
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'set_user_role', array( $this, 'update_user_role' ), 10, 3 );

		// Prevent mail from being sent
		if ( $send_notification == false ) {
			add_filter('wp_mail', function() { return array('to' => '', 'subject' => '', 'message' => '' ); }, 100 );
		}

		// Insert user and store meta
		$user_id = wp_insert_user( $user_meta );

		if( is_wp_error( $user_id ) ) {

			wp_fusion()->logger->handle( 'error', 0, 'Error importing contact ID ' . $contact_id . ' with error: ' . $user_id->get_error_message() );
			return false;

		}

		// Send notification
		if ( $send_notification == true ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		// Logger
		wp_fusion()->logger->handle( 'info', $user_id, 'Imported contact ID <strong>' . $contact_id . '</strong>, with meta data: ', array( 'meta_array' => $user_meta ) );

		// Remove log data for generated pass
		unset( $user_meta['generated_user_pass'] );

		// Save any custom fields (wp insert user ignores them)
		$this->set_user_meta( $user_id, $user_meta );

		// Get tags
		$this->get_tags( $user_id, true );

		// Denote user was imported
		do_action( 'wpf_user_imported', $user_id, $user_meta );

		return $user_id;

	}


}