<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles user registration, profile updates, and tags.
 *
 * @since 1.0.0
 */
class WPF_User {

	/**
	 * WPF_User constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Register and profile updates.
		add_action( 'user_register', array( $this, 'user_register' ), 20 ); // 20 so usermeta added by other plugins is saved
		add_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );
		add_action( 'add_user_to_blog', array( $this, 'add_user_to_blog' ) );
		add_filter( 'wpf_user_register', array( $this, 'maybe_set_first_last_name' ), 100, 2 ); // 100 so it runs after everything else

		// Deleted users.
		add_action( 'delete_user', array( $this, 'user_delete' ) );
		add_action( 'remove_user_from_blog', array( $this, 'user_delete' ) );

		add_action( 'password_reset', array( $this, 'password_reset' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );

		// Roles.
		add_action( 'set_user_role', array( $this, 'add_remove_user_role' ), 10, 2 );
		add_action( 'add_user_role', array( $this, 'add_remove_user_role' ), 10, 2 );
		add_action( 'remove_user_role', array( $this, 'add_remove_user_role' ), 10, 2 );

		// User meta.
		add_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		add_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

		// After-import actions.
		add_action( 'wpf_user_imported', array( $this, 'return_password' ), 10, 2 );

		// Dyanmic tagging (so so other plugins have had a chance to make their field changes).
		add_filter( 'wpf_user_update', array( $this, 'dynamic_tagging' ), 30, 2 );
		add_filter( 'wpf_user_register', array( $this, 'dynamic_tagging' ), 30, 2 );

	}

	/**
	 * Gets the current user ID, with support for auto-logged-in users
	 *
	 * @access public
	 * @return int User ID
	 */

	public function get_current_user_id() {

		if ( is_user_logged_in() ) {
			return get_current_user_id();
		}

		if ( doing_wpf_auto_login() ) {
			return wp_fusion()->auto_login->auto_login_user['user_id'];
		}

		return 0;

	}

	/**
	 * Gets the current user, with support for auto-logged-in users
	 *
	 * @since  3.37.3
	 *
	 * @return bool|WP_User The current user.
	 */

	public function get_current_user() {

		if ( is_user_logged_in() ) {
			return wp_get_current_user();
		}

		if ( doing_wpf_auto_login() ) {

			$user_id = wp_fusion()->auto_login->auto_login_user['user_id'];

			$user               = new WP_User();
			$user->ID           = $user_id;
			$user->user_email   = get_user_meta( $user_id, 'user_email', true );
			$user->first_name   = get_user_meta( $user_id, 'first_name', true );
			$user->last_name    = get_user_meta( $user_id, 'last_name', true );
			$user->user_login   = $user->user_email;
			$user->display_name = $user->first_name . ' ' . $user->last_name;
			$user->nickname     = $user->user_login;
			$user->user_status  = 0;

			return $user;

		}

		return false;

	}


	/**
	 * Checks if user is logged in, with support for auto-logged-in users
	 *
	 * @access public
	 * @return bool Logged In
	 */

	public function is_user_logged_in() {

		if ( is_user_logged_in() ) {
			return true;
		}

		if ( doing_wpf_auto_login() ) {
			return true;
		}

		return false;

	}

	/**
	 * Triggered when a new user is added to a blog in multisite. Applies the tags for this blog.
	 *
	 * @access public
	 * @param $user_id
	 * @return void
	 */

	public function add_user_to_blog( $user_id ) {

		// Don't need to do this if they've just registered.

		if ( did_action( 'user_register' ) ) {
			return;
		}

		$assign_tags = wpf_get_option( 'assign_tags' );

		if ( ! empty( $assign_tags ) ) {
			$this->apply_tags( $assign_tags, $user_id );
		}

	}


	/**
	 * Maybe set first / last name.
	 *
	 * In some cases (especially in WP Fusion Lite) we may not know the
	 * first_name and last_name values for the user at the time of the
	 * user_register hook. This attempts to find a match for those fields in the
	 * POST data if they aren't present.
	 *
	 * @since  3.36.12
	 *
	 * @param  array $post_data The POST data.
	 * @param  int   $user_id   The user identifier.
	 * @return array The POST data.
	 */
	public function maybe_set_first_last_name( $post_data, $user_id ) {

		if ( ! isset( $post_data['first_name'] ) || ( empty( $post_data['first_name'] ) && ! is_null( $post_data['first_name'] ) ) ) {

			$try = array( 'first_name', 'fname', 'first_' );

			foreach ( $try as $partial ) {

				foreach ( $post_data as $key => $value ) {

					if ( false !== strpos( $key, $partial ) && ! empty( $value ) ) {
						$post_data['first_name'] = $value;
						break 2;
					}
				}
			}
		}

		if ( ! isset( $post_data['last_name'] ) || ( empty( $post_data['last_name'] ) && ! is_null( $post_data['last_name'] ) ) ) {

			$try = array( 'last_name', 'lname' );

			foreach ( $try as $partial ) {

				foreach ( $post_data as $key => $value ) {

					if ( false !== strpos( $key, $partial ) && ! empty( $value ) ) {
						$post_data['last_name'] = $value;
						break 2;
					}
				}
			}
		}

		if ( empty( $post_data['first_name'] ) && empty( $post_data['last_name'] ) && ! empty( $post_data['name'] ) ) {

			// Set the first / last name from the "name" parameter

			$names                   = explode( ' ', $post_data['name'], 2 );
			$post_data['first_name'] = $names[0];

			if ( isset( $names[1] ) ) {
				$post_data['last_name'] = $names[1];
			}
		}

		return $post_data;

	}


	/**
	 * User register.
	 *
	 * Triggered when a new user is registered. Creates the user in the CRM and
	 * stores the user's CRM contact ID for later reference.
	 *
	 * @since  1.0.0
	 *
	 * @param  int         $user_id   The user ID.
	 * @param  array|bool  $post_data The registration data.
	 * @param  bool        $force     Whether or not to override role
	 *                                limitations.
	 * @return string|bool The contact ID of the new contact or false on failure.
	 */
	public function user_register( $user_id, $post_data = false, $force = false ) {

		remove_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );

		// Don't load tags or meta when someone registers.
		remove_action( 'wp_login', array( $this, 'login' ), 10, 2 );

		do_action( 'wpf_user_register_start', $user_id, $post_data );

		// Get posted data from the registration form.
		if ( false === $post_data && ! empty( $_POST ) && is_array( $_POST ) ) {
			$post_data = wpf_clean( wp_unslash( $_POST ) );
		} elseif ( empty( $post_data ) ) {
			$post_data = array();
		}

		$user_meta = $this->get_user_meta( $user_id );

		// Merge what's in the database with what was submitted on the form.
		$post_data = array_merge( $user_meta, $post_data );

		/**
		 * Allow modification of the registration data.
		 *
		 * @since 1.0.0
		 *
		 * @see   WPF_User::maybe_set_first_last_name()
		 * @see   WPF_User_Profile::filter_form_fields()
		 * @link  https://wpfusion.com/documentation/filters/wpf_user_register/
		 *
		 * @param array $post_data The registration data.
		 * @param int   $user_id   The user ID.
		 */

		$post_data = apply_filters( 'wpf_user_register', $post_data, $user_id );

		// Allows for cancelling of registration via filter.
		if ( null === $post_data ) {
			return false;
		}

		if ( empty( $post_data['user_email'] ) ) {

			wpf_log(
				'notice',
				$user_id,
				/* translators: %s: CRM Name */
				sprintf( __( 'User registration not synced to %s because email address wasn\'t detected in the submitted data.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			);

			return false;
		}

		// Check if contact already exists in CRM.
		$contact_id = $this->get_contact_id( $user_id, true );

		if ( wpf_get_option( 'create_users' ) != true && $force == false && $contact_id == false ) {

			wpf_log(
				'notice',
				$user_id,
				/* translators: %s: CRM Name */
				sprintf( __( 'User registration not synced to %s because "Create Contacts" is disabled in the WP Fusion settings. You will not be able to apply tags to this user.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			);

			return false;

		}

		if ( empty( $contact_id ) ) {

			// Contact does not exist in the CRM.

			// See if user role is elligible for being created as a contact.

			$valid_roles = wpf_get_option( 'user_roles', array() );

			$valid_roles = apply_filters( 'wpf_register_valid_roles', $valid_roles, $user_id, $post_data );

			if ( ! empty( $valid_roles ) && ! in_array( $post_data['role'], $valid_roles ) && false === $force ) {

				wpf_log(
					'notice',
					$user_id,
					/* translators: %1$s: CRM Name, %2$s New user's role slug */
					sprintf( __( 'User not added to %1$s because role %2$s isn\'t enabled for contact creation.', 'wp-fusion-lite' ), wp_fusion()->crm->name, '<strong>' . $post_data['role'] . '</strong>' )
				);
				return false;

			}

			// Log what's about to happen.

			wpf_log(
				'info',
				$user_id,
				/* translators: %s: CRM Name */
				sprintf( __( 'New user registration. Adding contact to %s:', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
				array(
					'meta_array' => $post_data,
				)
			);

			// Add the contact to the CRM.

			$contact_id = wp_fusion()->crm->add_contact( $post_data );

			if ( is_wp_error( $contact_id ) ) {

				// Error logging.

				wpf_log(
					$contact_id->get_error_code(),
					$user_id,
					/* translators: %s: Error message */
					sprintf( __( 'Error adding contact: %s', 'wp-fusion-lite' ), $contact_id->get_error_message() ),
					array(
						'source'              => 'user-register',
						'meta_array_nofilter' => $post_data,
					)
				);

				return false;

			}

			$contact_id = sanitize_text_field( $contact_id );

			update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		} else {

			// Contact already exists in the CRM, update them.

			wpf_log(
				'info',
				$user_id,
				/* translators: %1$s: Existing contact ID, %2$s CRM name */
				sprintf( __( 'New user registration. Updating contact ID %1$s in %2$s:', 'wp-fusion-lite' ), $contact_id, wp_fusion()->crm->name ),
				array(
					'meta_array' => $post_data,
				)
			);

			// Send the update data.

			$result = wp_fusion()->crm->update_contact( $contact_id, $post_data );

			if ( is_wp_error( $result ) ) {

				// If update failed.

				wpf_log(
					$result->get_error_code(),
					$user_id,
					/* translators: %s: Error message */
					sprintf( __( 'Error updating contact: %s', 'wp-fusion-lite' ), $result->get_error_message() ),
					array(
						'source'              => 'user-register',
						'meta_array_nofilter' => $post_data,
					)
				);

				return false;

			}

			// Load the tags from the existing contact record.

			$this->get_tags( $user_id, true, false );

		}

		// Assign any tags specified in the WPF settings page.
		$assign_tags = wpf_get_option( 'assign_tags' );

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

		$bypass = apply_filters( 'wpf_bypass_profile_update', false, wpf_clean( wp_unslash( $_REQUEST ) ) );

		// This doesn't need to run twice on a page load.
		remove_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );

		if ( ! empty( $_POST ) && false === $bypass ) {

			$post_data = wpf_clean( wp_unslash( $_POST ) );
			$this->push_user_meta( $user_id, $post_data );

		}

	}


	/**
	 * Triggered when a user is deleted or deletes their own account. Applies tag for tracking.
	 *
	 * @access public
	 * @return void
	 */
	public function user_delete( $user_id ) {

		// Users are removed from the main blog when added to a new site so we we'll ignore those.
		if ( doing_action( 'wpmu_activate_user' ) || doing_action( 'wpmu_activate_blog' ) ) {
			return;
		}

		$tags = wpf_get_option( 'deletion_tags', array() );

		if ( ! empty( $tags ) ) {
			$this->apply_tags( $tags, $user_id );
		}

	}


	/**
	 * Determine if a user has a contact record
	 *
	 * @access public
	 * @return bool
	 */
	public function has_contact_id( $user_id = false ) {

		if ( ! $user_id ) {
			$user_id = wpf_get_current_user_id();
		}

		$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true );

		if ( ! empty( $contact_id ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Gets the URL to edit a user's contact record.
	 *
	 * @since 3.37.3
	 *
	 * @param int   $user_id The user ID.
	 * @return string|bool The edit URL or false.
	 */
	public function get_contact_edit_url( $user_id = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $contact_id ) ) {
			return false;
		}

		return wp_fusion()->crm_base->get_contact_edit_url( $contact_id );

	}


	/**
	 * Gets contact ID from user ID.
	 *
	 * @since  1.0.0
	 *
	 * @param  int|bool $user_id      The user ID or false to use current user.
	 * @param  bool     $force_update Whether or not to force-check the
	 *                                contact ID by making an API call to the CRM.
	 * @return string|bool Contact ID or false if not found.
	 */
	public function get_contact_id( $user_id = false, $force_update = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		if ( false === $user_id ) {
			return false;
		}

		do_action( 'wpf_get_contact_id_start', $user_id );

		$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true );
		$user       = get_user_by( 'id', $user_id );

		if ( ! empty( $user ) ) {
			$email_address = $user->user_email;
		} elseif ( doing_wpf_auto_login() ) {
			$email_address = get_user_meta( $user_id, 'user_email', true );
		} else {
			$email_address = false;
		}

		// Allow filtering the email used for lookups.
		$email_address = apply_filters( 'wpf_get_contact_id_email', $email_address, $user_id );

		if ( empty( $contact_id ) && empty( $email_address ) ) {
			// We don't know the user or contact ID, so quit.
			return false;
		}

		// If contact ID is already set.
		if ( ( ! empty( $contact_id ) || $contact_id == false ) && $force_update == false ) {
			return apply_filters( 'wpf_contact_id', $contact_id, $email_address );
		}

		// If no user email set, don't bother with an API call.
		if ( ! is_email( $email_address ) ) {
			return false;
		}

		$contact_id = wp_fusion()->crm->get_contact_id( $email_address );

		if ( is_wp_error( $contact_id ) ) {

			wpf_log( $contact_id->get_error_code(), $user_id, 'Error getting contact ID for <strong>' . $email_address . '</strong>: ' . $contact_id->get_error_message() );
			return false;

		}

		$contact_id = apply_filters( 'wpf_contact_id', $contact_id, $email_address );

		if ( empty( $contact_id ) ) {

			// Error logging.
			wpf_log( 'info', $user_id, 'No contact found in ' . wp_fusion()->crm->name . ' for <strong>' . $email_address . '</strong>' );

		} else {
			$contact_id = sanitize_text_field( $contact_id );
		}

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		do_action( 'wpf_got_contact_id', $user_id, $contact_id );

		return $contact_id;

	}

	/**
	 * Gets and saves updated user meta from the CRM
	 *
	 * @access public
	 * @return array User Meta
	 */

	public function pull_user_meta( $user_id = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $contact_id ) ) {
			wpf_log( 'notice', $user_id, 'Error loading user meta: no contact record found.' );
			return false;
		}

		do_action( 'wpf_pre_pull_user_meta', $user_id );

		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		// Error logging
		if ( is_wp_error( $user_meta ) ) {

			wpf_log( $user_meta->get_error_code(), $user_id, 'Error loading contact user meta: ' . $user_meta->get_error_message() );
			return false;

		} elseif ( empty( $user_meta ) ) {

			wpf_log( 'notice', $user_id, 'No elligible user meta loaded.' );
			return false;

		}

		// Logger. This is before the filter so that keys that are unset() (i.e. XProfile data) can still be logged.
		wpf_log( 'info', $user_id, 'Loaded meta data from ' . wp_fusion()->crm->name . ':', array( 'meta_array' => $user_meta ) );

		$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user_id );

		// Allows for cancelling via filter.
		if ( null === $user_meta ) {
			return;
		}

		// Don't push updates back to CRM.
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

		$this->set_user_meta( $user_id, $user_meta );

		do_action( 'wpf_user_updated', $user_id, $user_meta );

		return $user_meta;

	}

	/**
	 * Get all the available metadata from the database for a user
	 *
	 * @access public
	 * @return array User Meta
	 */
	public function get_user_meta( $user_id ) {

		// Start by getting everything in the database.
		$user_meta = array_filter(
			array_map(
				function( $a ) {
					return $a[0];
				},
				get_user_meta( $user_id ),
			)
		);

		// get_userdata() doesn't work properly during an auto login session.

		if ( doing_wpf_auto_login() ) {
			return apply_filters( 'wpf_get_user_meta', $user_meta, $user_id );
		}

		$userdata = get_userdata( $user_id );

		$user_meta['user_id']         = $user_id;
		$user_meta['user_login']      = $userdata->user_login;
		$user_meta['user_email']      = $userdata->user_email;
		$user_meta['user_registered'] = $userdata->user_registered;
		$user_meta['user_nicename']   = $userdata->user_nicename;
		$user_meta['user_url']        = $userdata->user_url;
		$user_meta['display_name']    = $userdata->display_name;

		if ( is_array( $userdata->roles ) ) {
			$user_meta['role'] = reset( $userdata->roles );
		}

		if ( ! empty( $userdata->caps ) ) {
			$user_meta['wp_capabilities'] = array_keys( $userdata->caps );
		}

		$user_meta = apply_filters( 'wpf_get_user_meta', $user_meta, $user_id );

		return $user_meta;

	}

	/**
	 * Sets an array of meta data for the user
	 *
	 * @access public
	 * @return void
	 */

	public function set_user_meta( $user_id, $user_meta ) {

		$user_meta = apply_filters( 'wpf_set_user_meta', $user_meta, $user_id );

		$user_meta = wpf_clean( $user_meta ); // sanitize and clean.

		// Don't send updates back.
		remove_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

		// Save all of it to usermeta table if doing auto login
		if ( doing_wpf_auto_login() ) {

			foreach ( $user_meta as $key => $value ) {

				update_user_meta( $user_id, $key, $value );

			}
		} else {

			$user = get_userdata( $user_id );

			foreach ( $user_meta as $key => $value ) {

				if ( empty( $value ) && $value != '0' && $value !== null ) {
					continue;
				}

				if ( wpf_is_pseudo_field( $key ) ) {
					continue;
				}

				// Don't reset passwords for admins or if we're in the middle of logging in
				if ( $key == 'user_pass' && ! empty( $value ) && ! user_can( $user_id, 'manage_options' ) && ! doing_action( 'wp_login' ) ) {

					// Only update pass if it's changed
					if ( wp_check_password( $value, $user->data->user_pass, $user_id ) == false ) {

						wpf_log( 'notice', $user_id, 'User password set to <strong>' . $value . '</strong>' );

						// Don't send it back again
						remove_action( 'password_reset', array( $this, 'password_reset' ), 10, 2 );
						wp_set_password( $value, $user_id );

					}
				} elseif ( $key == 'display_name' ) {

					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => $value,
						)
					);

				} elseif ( $key == 'user_nicename' ) {

					wp_update_user(
						array(
							'ID'            => $user_id,
							'user_nicename' => $value,
						)
					);

				} elseif ( $key == 'user_email' && strtolower( $value ) != strtolower( $user->user_email ) && ! user_can( $user_id, 'manage_options' ) && ! doing_action( 'wp_login' ) ) {

					// Don't change admin user email addresses, for security reasons

					wp_update_user(
						array(
							'ID'         => $user_id,
							'user_email' => $value,
						)
					);

				} elseif ( $key == 'user_registered' ) {

					// Don't override the registered date
					continue;

				} elseif ( $key == 'user_url' ) {

					wp_update_user(
						array(
							'ID'       => $user_id,
							'user_url' => $value,
						)
					);

				} elseif ( $key == 'role' ) {

					if ( user_can( $user_id, 'manage_options' ) ) {
						continue; // Don't run on admins.
					}

					$slug = array_search( $value, wp_roles()->get_names() );

					if ( false !== $slug ) {
						$value = $slug;
					}

					if ( wp_roles()->is_role( $value ) && ! in_array( $value, (array) $user->roles ) ) {

						// Don't send it back again
						remove_action( 'set_user_role', array( $this, 'update_user_role' ), 10, 3 );
						wp_update_user(
							array(
								'ID'   => $user_id,
								'role' => $value,
							)
						);

						wpf_log( 'notice', $user_id, 'User role changed to <strong>' . $value . '</strong>.' );

					} elseif ( ! wp_roles()->is_role( $value ) ) {

						wpf_log( 'notice', $user_id, 'Role <strong>' . $value . '</strong> was loaded, but it is not a valid user role for this site.' );

					}
				} elseif ( $key == 'wp_capabilities' ) {

					if ( user_can( $user_id, 'manage_options' ) ) { // Don't run on admins.
						continue;
					}

					if ( ! is_array( $value ) ) {
						$value = explode( ',', $value );
					}

					if ( is_array( $value ) ) {

						$capabilities = array();

						foreach ( $value as $i => $role ) {

							if ( ! wp_roles()->is_role( $role ) ) {
								wpf_log( 'notice', $user_id, 'Role <strong>' . $role . '</strong> was loaded, but it is not a valid user role for this site.' );
								continue;
							}

							$capabilities[ $role ] = true;

						}

						if ( ! empty( $capabilities ) ) {

							$current_caps = get_user_meta( $user_id, 'wp_capabilities', true );

							if ( $capabilities != $current_caps ) {

								wpf_log( 'notice', $user_id, 'User capabilities changed to:', array( 'meta_array_nofilter' => $capabilities ) );
								update_user_meta( $user_id, 'wp_capabilities', $capabilities );

							}
						}
					}
				} else {

					update_user_meta( $user_id, $key, $value );

				}

				do_action( 'wpf_user_meta_updated', $user_id, $key, $value );

			}
		}

		add_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );
		add_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		add_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

	}

	/**
	 * Gets all tags currently applied to the user
	 *
	 * @access public
	 * @return array Tags applied to the user
	 */

	public function get_tags( $user_id = false, $force_update = false, $lookup_cid = true ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		do_action( 'wpf_get_tags_start', $user_id );

		$user_tags = get_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', true );

		if ( is_array( $user_tags ) && false === $force_update ) {
			return apply_filters( 'wpf_user_tags', $user_tags, $user_id );
		}

		// If no tags.
		if ( empty( $user_tags ) && false === $force_update ) {
			return apply_filters( 'wpf_user_tags', array(), $user_id );
		}

		if ( empty( $user_tags ) ) {
			$user_tags = array();
		}

		// Don't get the CID again if the request came from a webhook.
		if ( false === $lookup_cid ) {
			$force_update = false;
		}

		$contact_id = $this->get_contact_id( $user_id, $force_update );

		// If contact doesn't exist in CRM.
		if ( empty( $contact_id ) ) {
			return apply_filters( 'wpf_user_tags', array(), $user_id );
		}

		$tags = wp_fusion()->crm->get_tags( $contact_id );

		if ( is_wp_error( $tags ) ) {

			wpf_log( $tags->get_error_code(), $user_id, 'Failed loading tags: ' . $tags->get_error_message() );
			return apply_filters( 'wpf_user_tags', $user_tags, $user_id );

		}

		$this->set_tags( $tags, $user_id );

		return apply_filters( 'wpf_user_tags', $tags, $user_id );

	}

	/**
	 * Sets an array of tags to the DB and triggers relevant actions, does not send any API calls
	 *
	 * @access public
	 * @return void
	 */

	public function set_tags( $tags, $user_id ) {

		// Compare new tags to current tags to see what's changed.
		$user_tags = get_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', true );

		if ( empty( $user_tags ) ) {
			$user_tags = array();
		}

		// Tags should be stored as an array of strings.
		$tags = array_map( 'sanitize_text_field', (array) $tags );

		wpf_log( 'info', $user_id, __( 'Loaded tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		if ( ! empty( $tags ) && $tags == $user_tags ) {

			// Doing the action here so that automated enrollments are triggered.
			do_action( 'wpf_tags_modified', $user_id, $user_tags );

			// If nothing changed
			return;

		}

		// Check and see if new tags have been pulled, and if so, resync the available tags list.

		if ( is_admin() ) {

			$sync_needed    = false;
			$available_tags = wpf_get_option( 'available_tags' );

			foreach ( (array) $tags as $tag ) {

				if ( ! isset( $available_tags[ $tag ] ) ) {
					$sync_needed = true;
				}
			}

			if ( true === $sync_needed ) {
				wp_fusion()->crm->sync_tags();
			}
		}

		// Save it to the DB.

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $tags );

		// Check if tags were added.

		$tags_applied = array_diff( $tags, $user_tags );

		if ( ! empty( $tags_applied ) ) {

			/**
			 * Triggers after tags are loaded for the user, contains just the new tags that were applied
			 *
			 * @param int   $user_id      ID of the user that was updated
			 * @param array $tags_applied Tags that were applied to the user
			 */

			do_action( 'wpf_tags_applied', $user_id, $tags_applied );

		}

		// Check if tags were removed.

		$tags_removed = array_diff( $user_tags, $tags );

		if ( ! empty( $tags_removed ) ) {

			/**
			 * Triggers after tags are loaded for the user, contains just the tags that no longer are present
			 *
			 * @param int   $user_id      ID of the user that was updated
			 * @param array $tags_removed Tags that were removed from the user
			 */

			do_action( 'wpf_tags_removed', $user_id, $tags_removed );

		}

		/**
		 * Triggers after tags are loaded for a user, contains all of the user's tags
		 *
		 * @param int   $user_id   ID of the user that was updated
		 * @param array $user_tags The user's CRM tags
		 */

		do_action( 'wpf_tags_modified', $user_id, $tags );

	}

	/**
	 * Applies an array of tags to a given user ID
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $user_id = false ) {

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		// Remove any empty ones that may have snuck in.

		$tags = array_filter( $tags );

		// Sanitize!

		$tags = array_map( 'sanitize_text_field', $tags );

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		/**
		 * Triggers before tags are applied to the user.
		 *
		 * @param int   $user_id ID of the user being updated.
		 * @param array $tags    Tags to be applied to the user.
		 */

		do_action( 'wpf_apply_tags_start', $user_id, $tags );

		/**
		 * Filters the tags to be applied to the user.
		 *
		 * @param array $tags    Tags to be applied to the user.
		 * @param int   $user_id ID of the user being updated.
		 */

		$tags = apply_filters( 'wpf_apply_tags', $tags, $user_id );

		$contact_id = $this->get_contact_id( $user_id );

		// If no contact ID, don't try applying tags.

		if ( empty( $contact_id ) ) {

			wpf_log( 'notice', $user_id, __( 'No contact ID for user. Failed to apply tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
			return false;

		}

		$user_tags = $this->get_tags( $user_id );

		// Maybe quit early if user already has the tag.
		$diff = array_diff( (array) $tags, $user_tags );

		/**
		 * By default WP Fusion will not send an API call to apply tags that a user already has. This can be overridden here
		 *
		 * @param bool $prevent_reapply_tags Whether to prevent re-applying tags
		 */

		$prevent_reapply = apply_filters( 'wpf_prevent_reapply_tags', wpf_get_option( 'prevent_reapply', true ) );

		if ( empty( $diff ) && true === $prevent_reapply ) {

			wpf_log( 'info', $user_id, __( 'Applying Tag(s). No API call will be sent since the user already has the tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
			return true;

		}

		// If we're only applying tags the user doesn't have already.

		if ( true === $prevent_reapply ) {
			$tags = $diff;
		}

		// Check for chaining.

		if ( doing_action( 'wpf_tags_modified' ) ) {
			wpf_log( 'warning', $user_id, __( '<strong>Chaining situation detected</strong>. WP Fusion is about to apply tags as the result of an automated enrollment, which was triggered by other tags being applied. This kind of setup should be avoided and may result in unexpected behavior or site instability.', 'wp-fusion-lite' ) );
		}

		// Logging.

		wpf_log( 'info', $user_id, __( 'Applying tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		$result = wp_fusion()->crm->apply_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wpf_log( $result->get_error_code(), $user_id, 'Error while applying tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;
		}

		// Save to the database.

		$user_tags = array_unique( array_merge( $user_tags, $tags ) );

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );

		// If a new tag was just applied, update the available list.

		if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			$available_tags = wpf_get_option( 'available_tags' );

			foreach ( $tags as $tag ) {

				if ( ! isset( $available_tags[ $tag ] ) ) {

					$available_tags[ $tag ] = $tag;
					wp_fusion()->settings->set( 'available_tags', $available_tags );

				}
			}
		}

		/**
		 * Triggers after tags are applied to the user, contains just the tags that were applied
		 *
		 * @param int   $user_id ID of the user that was updated
		 * @param array $tags    Tags that were applied to the user
		 */

		do_action( 'wpf_tags_applied', $user_id, $tags );

		/**
		 * Triggers after tags are updated for a user, contains all of the user's tags
		 *
		 * @param int   $user_id   ID of the user that was updated
		 * @param array $user_tags The user's CRM tags
		 */

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

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		// Remove any empty ones that may have snuck in.

		$tags = array_filter( $tags );

		// Sanitize!

		$tags = array_map( 'sanitize_text_field', $tags );

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		/**
		 * Triggers before tags are removed from the user
		 *
		 * @param int   $user_id ID of the user being updated
		 * @param array $tags    Tags to be removed from the user
		 */

		do_action( 'wpf_remove_tags_start', $user_id, $tags );

		/**
		 * Filters the tags to be removed from the user
		 *
		 * @param array $tags    Tags to be removed from the user
		 * @param int   $user_id ID of the user being updated
		 */

		$tags = apply_filters( 'wpf_remove_tags', $tags, $user_id );

		$contact_id = $this->get_contact_id( $user_id );

		// If no contact ID, don't try applying tags.

		if ( empty( $contact_id ) ) {

			wpf_log( 'notice', $user_id, __( 'No contact ID for user. Failed to remove tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
			return false;

		}

		$user_tags = $this->get_tags( $user_id );

		if ( ! is_array( $user_tags ) ) {
			$user_tags = array();
		}

		$diff = array_intersect( $tags, $user_tags );

		// Maybe quit early if user doesn't have the tag anyway.

		if ( empty( $diff ) ) {

			// wpf_log( 'info', $user_id, __( 'Removing Tag(s). No API call will be sent since the user already doesn\'t have the tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
			return true;
		}

		$tags = $diff;

		// Check for chaining.

		if ( doing_action( 'wpf_tags_modified' ) ) {
			wpf_log( 'warning', $user_id, __( '<strong>Chaining situation detected</strong>. WP Fusion is about to remove tags as the result of an automated enrollment, which was triggered by other tags being removed. This kind of setup should be avoided and may result in unexpected behavior or site instability.', 'wp-fusion-lite' ) );
		}

		// Logging.

		wpf_log( 'info', $user_id, __( 'Removing tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		$result = wp_fusion()->crm->remove_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wpf_log( $result->get_error_code(), $user_id, 'Error while removing tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;
		}

		// Save to the database.

		$user_tags = array_unique( array_diff( $user_tags, $tags ) );

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );

		/**
		 * Triggers after tags are removed from the user, contains just the tags that were removed
		 *
		 * @param int   $user_id ID of the user that was updated
		 * @param array $tags    Tags that were removed from the user
		 */

		do_action( 'wpf_tags_removed', $user_id, $tags );

		/**
		 * Triggers after tags are updated for a user, contains all of the user's tags
		 *
		 * @param int   $user_id   ID of the user that was updated
		 * @param array $user_tags The user's CRM tags
		 */

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

		$this->push_user_meta( $user->ID, array( 'user_pass' => $new_pass ) );

	}


	/**
	 * Returns generated password to CRM
	 *
	 * @access public
	 * @return void
	 */

	public function return_password( $user_id, $user_meta ) {

		$password_field = wpf_get_option( 'return_password_field', array() );

		if ( wpf_get_option( 'return_password' ) && ! empty( $password_field['crm_field'] ) ) {

			wpf_log( 'info', $user_id, 'Returning generated password <strong>' . $user_meta['user_pass'] . '</strong> to ' . wp_fusion()->crm->name );

			$contact_id = $this->get_contact_id( $user_id );
			$result     = wp_fusion()->crm->update_contact( $contact_id, array( $password_field['crm_field'] => $user_meta['user_pass'] ), false );

			if ( is_wp_error( $result ) ) {
				wpf_log( $result->get_error_code(), $user_id, 'Error while returning password: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			}

			$this->push_user_meta(
				$user_id,
				array(
					'user_login'      => $user_meta['user_login'],
					'user_id'         => $user_id,
					'user_registered' => get_userdata( $user_id )->user_registered,
				)
			);

		} else {

			$this->push_user_meta(
				$user_id,
				array(
					'user_pass'       => $user_meta['user_pass'],
					'user_login'      => $user_meta['user_login'],
					'user_id'         => $user_id,
					'user_registered' => get_userdata( $user_id )->user_registered,
				)
			);

		}

	}

	/**
	 * Applies dynamic tags from field values
	 *
	 * @access public
	 * @return array User Meta
	 */

	public function dynamic_tagging( $user_meta, $user_id ) {

		if ( is_array( $user_meta ) && isset( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			$apply_tags = array();

			foreach ( $user_meta as $key => $value ) {

				if ( empty( $value ) ) {
					continue;
				}

				$crm_field = wp_fusion()->crm_base->get_crm_field( $key );

				if ( false !== strpos( $crm_field, 'add_tag_' ) && wp_fusion()->crm_base->is_field_active( $key ) ) {

					if ( is_array( $value ) ) {
						$apply_tags = array_merge( $apply_tags, $value );
					} else {
						$apply_tags[] = $value;
					}
				}
			}

			if ( ! empty( $apply_tags ) ) {

				$contact_id = get_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', true );

				if ( ! empty( $contact_id ) ) {

					// User update for existing contact ID, easy.
					$this->apply_tags( $apply_tags, $user_id );

				} else {

					// New user registration, harder.
					add_action(
						'wpf_user_created', function( $user_id, $contact_id, $post_data ) use ( &$apply_tags ) {

							$this->apply_tags( $apply_tags, $user_id );

						}, 10, 3
					);

				}
			}
		}

		return $user_meta;

	}


	/**
	 * Triggered when user role added or removed
	 *
	 * @access public
	 * @return void
	 */

	public function add_remove_user_role( $user_id, $role ) {

		if ( doing_action( 'user_register' ) || doing_action( 'set_current_user' ) ) {
			return; // User register will kick in later, and set_current_user sometimes causes errors because the CRM isn't set up yet
		}

		$user = get_userdata( $user_id );

		if ( ! empty( $user->caps ) && is_array( $user->caps ) ) {

			$roles = array_keys( $user->caps );

			if ( ! $this->get_contact_id( $user_id ) ) {

				if ( ! empty( array_intersect( wpf_get_option( 'user_roles', array() ), $roles ) ) ) {

					// If we're limiting user roles and the user's role was just changed to a valid one.

					$this->user_register( $user_id );

				}
			} else {

				$update_data = array(
					'wp_capabilities' => $roles,
					'role'            => $role,
				);

				$this->push_user_meta( $user_id, $update_data );
			}
		}

	}

	/**
	 * Update tags on login
	 *
	 * @access public
	 * @return void
	 */

	public function login( $user_login, $user = false ) {

		if ( ! wpf_get_option( 'login_sync' ) && ! wpf_get_option( 'login_meta_sync' ) ) {
			return;
		}

		if ( false === $user ) {
			$user = get_user_by( 'login', $user_login );
		}

		// No need if they don't have a contact record.

		if ( empty( $this->get_contact_id( $user->ID ) ) ) {
			return;
		}

		// Let's set the API timeout here to just 5 seconds for cases where the CRM is offline.

		if ( isset( wp_fusion()->crm->params ) ) {
			add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
		}

		if ( wpf_get_option( 'login_sync' ) ) {
			$this->get_tags( $user->ID, true, false );
		}

		if ( wpf_get_option( 'login_meta_sync' ) ) {
			$this->pull_user_meta( $user->ID );
		}

		// Remove the filter.

		if ( isset( wp_fusion()->crm->params ) ) {
			remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
		}

	}

	/**
	 * Gets user ID from contact ID
	 *
	 * @access public
	 * @return int User ID
	 */

	public function get_user_id( $contact_id ) {

		do_action( 'wpf_get_user_id_start', $contact_id );

		$user_id = apply_filters( 'wpf_get_user_id', false, $contact_id ); // Allow bypassing the database query, for performance.

		if ( false === $user_id ) {

			$users = get_users(
				array(
					'meta_key'   => wp_fusion()->crm->slug . '_contact_id',
					'meta_value' => $contact_id,
					'fields'     => array( 'ID' ),
					'blog_id'    => 0,
				)
			);

			if ( ! empty( $users ) ) {
				$user_id = $users[0]->ID;
			}
		}

		return apply_filters( 'wpf_get_user_id', $user_id, $contact_id );
	}

	/**
	 * Gets all users that have saved contact IDs.
	 *
	 * @since 3.37.21
	 *
	 * @return array User IDs.
	 */
	public function get_users_with_contact_ids() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'value'   => false,
					'compare' => '!=',
				),
			),
		);

		return get_users( $args );

	}

	/**
	 * Gets all users that have the tag.
	 *
	 * @since  3.37.27
	 *
	 * @param  string $tag    The tag.
	 * @return array  User IDs.
	 */
	public function get_users_with_tag( $tag ) {

		$tag = $this->get_tag_id( $tag );

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				array(
					'key'     => wp_fusion()->crm->slug . '_tags',
					'value'   => '"' . $tag . '"',
					'compare' => 'LIKE',
				),
			),
		);

		return get_users( $args );

	}

	/**
	 * Checks to see if a user has a given tag
	 *
	 * @access public
	 * @return bool
	 */

	public function has_tag( $tags, $user_id = false ) {

		// Allow overrides by admin bar
		if ( wpf_is_user_logged_in() && current_user_can( 'manage_options' ) && get_query_var( 'wpf_tag' ) ) {

			if ( get_query_var( 'wpf_tag' ) == 'unlock-all' ) {
				return true;
			}

			if ( get_query_var( 'wpf_tag' ) == 'lock-all' ) {
				return false;
			}
		}

		$user_tags = $this->get_tags( $user_id );

		if ( empty( $user_tags ) ) {
			return false;
		}

		if ( ! is_array( $tags ) ) {
			$tags = array( $tags );
		}

		$tags = array_map( array( $this, 'get_tag_id' ), $tags );

		if ( ! empty( array_intersect( $tags, $user_tags ) ) ) {
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

		if ( is_array( $tag_name ) ) {

			// Sometimes this comes through as an array and we're not sure why

			wpf_log( 'notice', 0, '(Debug) An array was passed to get_tag_id():', array( 'tag_array' => $tag_name ) );
			$tag_name = reset( $tag_name );

		}

		$tag_name = trim( $tag_name );

		// If it's already an ID

		if ( is_numeric( $tag_name ) ) {
			return $tag_name;
		}

		$available_tags = wpf_get_option( 'available_tags', array() );

		// If it's already an ID

		if ( isset( $available_tags[ $tag_name ] ) ) {
			return $tag_name;
		}

		$tag_name = strval( $tag_name );

		foreach ( $available_tags as $id => $data ) {

			if ( isset( $data['label'] ) && $data['label'] === $tag_name ) {

				return $id;

			} elseif ( is_string( $data ) && trim( $data ) === $tag_name ) {

				return $id;

			}
		}

		// If no match found, and CRM supports add_tags, return the label
		if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {
			return $tag_name;
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

		if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			// CRMs that support add_tags don't use IDs.
			// We'll do this before loading available_tags to avoid a database hit.

			return $tag_id;

		}

		$available_tags = wpf_get_option( 'available_tags' );

		if ( isset( $available_tags[ $tag_id ] ) && is_array( $available_tags[ $tag_id ] ) ) {

			// CRMs with tag optgroups.

			return $available_tags[ $tag_id ]['label'];

		} elseif ( isset( $available_tags[ $tag_id ] ) ) {

			// CRMs with id => label.

			return $available_tags[ $tag_id ];

		} elseif ( ! isset( $available_tags[ $tag_id ] ) ) {

			// Unknown tags.

			$tag_type = wpf_get_option( 'crm_tag_type', 'tag' );

			return '(Unknown ' . $tag_type . ': ' . $tag_id . ')';

		} else {

			return false;

		}

	}

	/**
	 * Triggered when any single user_meta field is updated
	 *
	 * @access public
	 * @return void
	 */

	public function push_user_meta_single( $meta_id, $object_id, $meta_key, $_meta_value ) {

		// Allow itegrations to register fields that should always sync when modified.
		$watched_fields = apply_filters( 'wpf_watched_meta_fields', array() );

		// Don't even try if the field isn't enabled for sync
		if ( ! wpf_get_option( 'push_all_meta' ) && ! in_array( $meta_key, $watched_fields ) ) {
			return;
		}

		$contact_fields = wpf_get_option( 'contact_fields' );

		if ( empty( $contact_fields[ $meta_key ] ) || $contact_fields[ $meta_key ]['active'] != true && ! in_array( $meta_key, $watched_fields ) ) {
			return;
		}

		$this->push_user_meta( $object_id, array( $meta_key => $_meta_value ) );

	}


	/**
	 * Sends updated user meta to CRM
	 *
	 * @access public
	 * @return bool
	 */

	public function push_user_meta( $user_id, $user_meta = false ) {

		if ( ! wpf_get_option( 'push' ) ) {
			return;
		}

		do_action( 'wpf_push_user_meta_start', $user_id, $user_meta );

		// If nothing's been supplied, get the latest from the DB.

		if ( false === $user_meta ) {
			$user_meta = $this->get_user_meta( $user_id );
		}

		$user_meta = apply_filters( 'wpf_user_update', $user_meta, $user_id );

		// Allows for cancelling via filter.

		if ( null == $user_meta ) {
			wpf_log( 'notice', $user_id, 'Push user meta aborted: no metadata found for user.' );
			return false;
		}

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $user_meta ) || empty( $contact_id ) ) {
			return;
		}

		wpf_log( 'info', $user_id, 'Pushing meta data to ' . wp_fusion()->crm->name . ': ', array( 'meta_array' => $user_meta ) );

		$result = wp_fusion()->crm->update_contact( $contact_id, $user_meta );

		if ( is_wp_error( $result ) ) {

			wpf_log( $result->get_error_code(), $user_id, 'Error while updating meta data: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;

		} elseif ( false === $result ) {

			// If nothing was updated.
			return false;

		}

		do_action( 'wpf_pushed_user_meta', $user_id, $contact_id, $user_meta );

		return true;

	}

	/**
	 * Imports a user
	 *
	 * @access public
	 * @return int / WP_Error User ID of newly created user
	 */

	public function import_user( $contact_id, $send_notification = false, $role = false ) {

		// First see if user already exists
		$users = get_users(
			array(
				'meta_key'   => wp_fusion()->crm->slug . '_contact_id',
				'meta_value' => $contact_id,
				'fields'     => array( 'ID' ),
			)
		);

		if ( ! empty( $users ) ) {

			$this->pull_user_meta( $users[0]->ID );
			$this->get_tags( $users[0]->ID, true, false );

			// Maybe change role (but not for admins).
			if ( ! empty( $role ) && ! user_can( $users[0]->ID, 'manage_options' ) && wp_roles()->is_role( $role ) ) {

				$user = new WP_User( $users[0]->ID );
				$user->set_role( $role );
			}

			return $users[0]->ID;

		}

		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		if ( is_wp_error( $user_meta ) ) {

			wpf_log( 'error', 0, 'Error importing contact ID ' . $contact_id . ': ' . $user_meta->get_error_message() );
			return $user_meta;

		} elseif ( empty( $user_meta['user_email'] ) ) {

			wpf_log( 'error', 0, 'No email found for imported contact ID ' . $contact_id . '.', array( 'meta_array_nofilter' => $user_meta ) );
			return new WP_Error( 'error', 'No email provided for imported user' );

		}

		$user_meta = wpf_clean( $user_meta ); // make it safe.

		// See if user with matching email exists.
		$user = get_user_by( 'email', $user_meta['user_email'] );

		if ( is_wp_error( $user ) ) {

			wpf_log( 'error', 0, 'Error importing contact ID ' . $contact_id . ' with error: ' . $user->get_error_message() );
			return false;

		} elseif ( is_object( $user ) ) {

			$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user->ID );

			// Don't push updates back to CRM.
			remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );

			update_user_meta( $user->ID, wp_fusion()->crm->slug . '_contact_id', $contact_id );
			$this->set_user_meta( $user->ID, $user_meta );
			$this->get_tags( $user->ID, true, false );

			// Maybe change role (but not for admins).
			if ( ! empty( $role ) && ! user_can( $user->ID, 'manage_options' ) && wp_roles()->is_role( $role ) ) {

				$user = new WP_User( $user->ID );
				$user->set_role( $role );

			}

			do_action( 'wpf_user_updated', $user->ID, $user_meta );

			return $user->ID;

		}

		if ( empty( $user_meta['user_pass'] ) ) {

			// Generate a password if one hasn't been supplied.
			$user_meta['user_pass']           = wp_generate_password( 12, false );
			$user_meta['generated_user_pass'] = 'true';

			// If the action got removed by another user, add it back.
			if ( ! has_action( 'wpf_user_imported', array( $this, 'return_password' ) ) ) {
				add_action( 'wpf_user_imported', array( $this, 'return_password' ), 10, 2 );
			}
		} else {

			// If we're not generating a password, no need to send it back.
			remove_action( 'wpf_user_imported', array( $this, 'return_password' ), 10, 2 );

		}

		// If user name is set.
		if ( empty( $user_meta['user_login'] ) ) {

			// Get the default from the settings.

			$format = wpf_get_option( 'username_format', 'email' );

			if ( 'email' === $format ) {
				$user_meta['user_login'] = $user_meta['user_email'];
			} elseif ( 'flname' === $format ) {
				$user_meta['user_login'] = $user_meta['first_name'] . $user_meta['last_name'];
			} elseif ( 'fnamenum' === $format ) {
				$user_meta['user_login'] = $user_meta['first_name'] . wp_rand( 1, 99999 );
			}

			// Randomize it further if needed.

			while ( username_exists( $user_meta['user_login'] ) ) {
				$user_meta['user_login'] .= wp_rand( 1, 999 );
			}
		}

		// Roles!

		if ( empty( $user_meta['role'] ) && ! empty( $role ) ) {
			$user_meta['role'] = $role;
		}

		// Maybe convert a role title to slug.

		$slug = array_search( $role, wp_roles()->get_names() );

		if ( false !== $slug ) {
			$user_meta['role'] = $slug;
		}

		if ( empty( $user_meta['role'] ) ) {

			$user_meta['role'] = get_option( 'default_role' );

		} elseif ( 'administrator' == $user_meta['role'] ) {

			// Not allowed.
			$user_meta['role'] = get_option( 'default_role' );
			wpf_log( 'notice', 0, 'For security reasons you cannot import a contact as an administrator.' );

		} elseif ( ! wp_roles()->is_role( $user_meta['role'] ) ) {

			wpf_log( 'notice', 0, 'Provided role <strong>' . $user_meta['role'] . '</strong> is not a valid role on your site. The default role <strong>' . get_option( 'default_role' ) . '</strong> will be used instead.' );
			$user_meta['role'] = get_option( 'default_role' );

		}

		// Set contact ID.
		$user_meta[ wp_fusion()->crm->slug . '_contact_id' ] = $contact_id;

		// Apply filters.
		$user_meta = apply_filters( 'wpf_import_user', $user_meta, $contact_id );

		// Allows for cancelling via filter
		if ( null === $user_meta ) {
			wpf_log( 'notice', 0, 'Import of contact ID ' . $contact_id . ' aborted: no metadata found for user.' );
			return false;
		}

		// Prevent the default registration hook from running.
		remove_action( 'user_register', array( $this, 'user_register' ), 20 );

		// Don't push updates back to CRM.
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'set_user_role', array( $this, 'update_user_role' ), 10, 3 );

		// Prevent mail from being sent.
		if ( false === $send_notification && ! wpf_get_option( 'send_welcome_email' ) ) {
			add_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ), 100 );
		}

		// We don't want to set a user ID here.

		if ( isset( $user_meta['user_id'] ) ) {
			unset( $user_meta['user_id'] );
		}

		// We don't want to set user_registered either.

		if ( isset( $user_meta['user_registered'] ) ) {
			unset( $user_meta['user_registered'] );
		}

		// Insert user and store meta.
		$user_id = wp_insert_user( $user_meta );

		if ( is_wp_error( $user_id ) ) {

			wpf_log( 'error', 0, 'Error importing contact ID ' . $contact_id . ' with error: ' . $user_id->get_error_message() );
			return false;

		}

		// Logger.
		wpf_log( 'info', $user_id, 'Imported contact ID <strong>' . $contact_id . '</strong>, with meta data: ', array( 'meta_array_nofilter' => $user_meta ) );

		if ( isset( $user_meta['generated_user_pass'] ) ) {

			// Set nag to change the password on next login.
			update_user_option( $user_id, 'default_password_nag', true );

			// Remove log data for generated pass.
			unset( $user_meta['generated_user_pass'] );

		}

		// Save any custom fields (wp insert user ignores them).
		$this->set_user_meta( $user_id, $user_meta );

		// Get tags.
		$this->get_tags( $user_id, true, false );

		// Send notification. This is after loading tags and meta in case any other plugins have modified the password reset key.
		if ( $send_notification || wpf_get_option( 'send_welcome_email' ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		// Allow wp_mail to work again now that the user has been imported.
		remove_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ), 100 );

		// Denote user was imported.
		do_action( 'wpf_user_imported', $user_id, $user_meta );

		return $user_id;

	}

	/**
	 * Suppresses the new user welcome email from going out during an import
	 *
	 * @access public
	 * @return array Mail args
	 */

	public function suppress_wp_mail() {

		return array(
			'to'      => '',
			'subject' => 'This email has been suppressed by WP Fusion during a user import, because send_notification was set to false.',
			'message' => '',
		);

	}


	/**
	 * Set the HTTP timeout to just 5 seconds to avoid hanging up a login.
	 *
	 * @since  3.36.10
	 *
	 * @param  float $timeout The timeout in seconds.
	 * @return float The timeout value.
	 */
	public function http_request_timeout( $timeout ) {

		return 5;

	}

}
