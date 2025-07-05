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
	 * Helps prevent the user_register hook from running twice.
	 *
	 * @since 3.44.3
	 * @var bool
	 */
	public $inserting_user = false;

	/**
	 * WPF_User constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Set the constants for the usermeta keys.
		$this->set_constants();

		// Register and profile updates.
		add_action( 'user_register', array( $this, 'user_register' ), 20 ); // 20 so usermeta added by other plugins is saved.
		add_action( 'user_register', array( $this, 'clear_inserting_user_flag' ), 21 );
		add_action( 'profile_update', array( $this, 'profile_update' ), 10, 3 );
		add_action( 'rest_after_insert_user', array( $this, 'rest_after_insert_user' ), 10, 3 ); // Add REST API support.
		add_action( 'add_user_to_blog', array( $this, 'add_user_to_blog' ) );
		add_filter( 'wpf_user_register', array( $this, 'maybe_set_first_last_name' ), 100, 2 ); // 100 so it runs after everything else

		// Deleted users.
		add_action( 'delete_user', array( $this, 'user_delete' ) );
		add_action( 'remove_user_from_blog', array( $this, 'user_delete' ) );

		add_action( 'password_reset', array( $this, 'password_reset' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );

		// Roles.
		add_filter( 'wp_pre_insert_user_data', array( $this, 'set_inserting_user_flag' ), 10, 2 );
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
	 * Sets the constants for the usermeta keys.
	 *
	 * @since 3.38.25
	 * @since 3.41.35 Moved from wpf_crm_init hook to constructor.
	 */
	public function set_constants() {

		$slug = wpf_get_option( 'crm' );

		if ( wpf_get_option( 'multisite_prefix_keys' ) ) {

			global $wpdb;
			$slug = $wpdb->get_blog_prefix() . $slug;

		}

		if ( ! defined( 'WPF_CONTACT_ID_META_KEY' ) ) {
			define( 'WPF_CONTACT_ID_META_KEY', $slug . '_contact_id' );
		}

		if ( ! defined( 'WPF_TAGS_META_KEY' ) ) {
			define( 'WPF_TAGS_META_KEY', $slug . '_tags' );
		}
	}

	/**
	 * Gets the current user ID, with support for auto-logged-in users
	 *
	 * @access public
	 * @return int User ID
	 */
	public function get_current_user_id() {

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		} elseif ( doing_wpf_auto_login() ) {
			$user_id = wp_fusion()->auto_login->auto_login_user['user_id'];
		} else {
			$user_id = 0;
		}

		// This lets the HTTP API logging know which user ID we're currently operating on.
		wp_fusion()->logger->user_id = $user_id;

		return $user_id;
	}

	/**
	 * Gets the current user's email address, with support for auto-logged-in
	 * users, and guests that are being tracked via cookie.
	 *
	 * @since  3.38.23
	 *
	 * @return string|bool Email address or false.
	 */
	public function get_current_user_email() {

		$user = $this->get_current_user();

		if ( $user ) {
			$email = $user->user_email;
		} elseif ( isset( $_COOKIE['wpf_guest'] ) ) {
			$email = sanitize_email( wp_unslash( $_COOKIE['wpf_guest'] ) );
		} elseif ( ! empty( wp_fusion()->crm->guest_email ) ) {
			$email = wp_fusion()->crm->guest_email;
		} else {
			$email = false;
		}

		return $email;
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

		if ( empty( $post_data ) ) {
			return $post_data;
		}

		if ( ! isset( $post_data['first_name'] ) || ( empty( $post_data['first_name'] ) && ! is_null( $post_data['first_name'] ) ) ) {

			$try = array( 'first_name', 'fname', 'first_' );

			foreach ( $try as $partial ) {

				foreach ( $post_data as $key => $value ) {

					if ( false !== strpos( $key, $partial ) && ! empty( $value ) && ! is_numeric( $value ) ) {
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

					if ( false !== strpos( $key, $partial ) && ! empty( $value ) && ! is_numeric( $value ) ) {
						$post_data['last_name'] = $value;
						break 2;
					}
				}
			}
		}

		if ( empty( $post_data['first_name'] ) && empty( $post_data['last_name'] ) && ( ! empty( $post_data['name'] ) || ! empty( $post_data['display_name'] ) ) ) {

			// Set the first / last name from the "name" or "display_name" parameters.

			if ( ! empty( $post_data['name'] ) ) {
				$name = $post_data['name'];
			} elseif ( $post_data['display_name'] !== $post_data['user_login'] ) {
				$name = $post_data['display_name'];
			} else {
				$name = false;
			}

			if ( false !== $name ) {
				$names                   = explode( ' ', $name, 2 );
				$post_data['first_name'] = $names[0];

				if ( isset( $names[1] ) ) {
					$post_data['last_name'] = $names[1];
				}
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
	 * @param int   $user_id   The user ID.
	 * @param array $post_data The registration data.
	 * @param bool  $force     Whether or not to override role limitations.
	 * @return string|bool|WP_Error The contact ID of the new contact, false if it was bypassed, or a WP_Error object if there was an error.
	 */
	public function user_register( $user_id, $post_data = array(), $force = false ) {

		remove_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );

		// Don't load tags or meta when someone registers.
		remove_action( 'wp_login', array( $this, 'login' ), 10, 2 );

		do_action( 'wpf_user_register_start', $user_id, $post_data );

		// Get posted data from the registration form.
		if ( empty( $post_data ) && ! empty( $_POST ) ) {
			$post_data = (array) wpf_clean( wp_unslash( $_POST ) );
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
		 * @param array|null $post_data The registration data.
		 * @param int        $user_id   The user ID.
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
				sprintf( __( 'User registration not synced to %s because email address wasn\'t detected in the submitted data.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
				array(
					'source'              => 'user-register',
					'meta_array_nofilter' => $post_data,
				)
			);

			return new WP_Error( 'error', 'Email address not detected in the submitted data.' );
		}

		// Check if contact already exists in CRM.
		$contact_id = $this->get_contact_id( $user_id, true );

		if ( ! wpf_get_option( 'create_users' ) && false === $force && empty( $contact_id ) ) {

			wpf_log(
				'notice',
				$user_id,
				/* translators: %s: CRM Name */
				sprintf( __( 'User registration not synced to %s because "Create Contacts" is disabled in the WP Fusion settings. You will not be able to apply tags to this user.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			);

			return new WP_Error( 'error', 'Create Contacts is disabled in the WP Fusion settings.' );

		}

		// Get any lists to add.
		$assign_lists = apply_filters( 'wpf_add_contact_lists', wpf_get_option( 'assign_lists', array() ) );

		if ( ! empty( $assign_lists ) ) {
			$post_data['lists'] = $assign_lists;
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
					sprintf( __( 'User not added to %1$s because role %2$s isn\'t enabled for contact creation.', 'wp-fusion-lite' ), wp_fusion()->crm->name, '<strong>' . $post_data['role'] . '</strong>' ),
					array(
						'source' => 'limit-user-roles',
					)
				);

				return new WP_Error( 'error', 'Role not enabled for contact creation.' );

			}

			// Log what's about to happen.

			wpf_log(
				'info',
				$user_id,
				/* translators: %s: CRM Name */
				sprintf( __( 'New user registration. Adding contact to %s:', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
				array(
					'source'     => 'user-register',
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
						'source' => 'user-register',
					)
				);

				return $contact_id;

			}

			$contact_id = sanitize_text_field( $contact_id );

			update_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, $contact_id );

		} else {

			// Contact already exists in the CRM, update them.

			wpf_log(
				'info',
				$user_id,
				/* translators: %1$s: Existing contact ID, %2$s CRM name */
				sprintf( __( 'New user registration. Updating contact #%1$s in %2$s:', 'wp-fusion-lite' ), $contact_id, wp_fusion()->crm->name ),
				array(
					'source'     => 'user-register',
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
						'source' => 'user-register',
					)
				);

				return $result;

			}

			// Load the tags from the existing contact record.

			wp_fusion()->logger->add_source( 'user-register' );

			$this->get_tags( $user_id, true, false );

		}

		// Assign any tags specified in the WPF settings page.
		$assign_tags = wpf_get_option( 'assign_tags' );

		if ( ! empty( $assign_tags ) ) {
			wp_fusion()->logger->add_source( 'general-settings' );
			$this->apply_tags( $assign_tags, $user_id );
		}

		do_action( 'wpf_user_created', $user_id, $contact_id, $post_data );

		return $contact_id;
	}

	/**
	 * Enables syncing additional role changes after a user has been synced to the CRM.
	 *
	 * @since 3.44.6
	 */
	public function clear_inserting_user_flag() {
		$this->inserting_user = false;
	}

	/**
	 * Triggered when profile updated.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data Object containing user's data prior to update.
	 * @param array   $userdata      The raw array of data passed to wp_insert_user().
	 */
	public function profile_update( $user_id, $old_user_data = false, $userdata = array() ) {

		$bypass = apply_filters( 'wpf_bypass_profile_update', false, wpf_clean( wp_unslash( $_REQUEST ) ) );

		// This doesn't need to run twice on a page load.
		remove_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );

		if ( did_action( 'retrieve_password' ) ) {
			return; // don't do this when a password reset is requested.
		}

		if ( ! empty( $_POST ) && false === $bypass ) {

			$post_data = wpf_clean( wp_unslash( $_POST ) );

			// Maybe detect email address changes.

			if ( isset( $userdata['user_email'] ) && is_a( $old_user_data, 'WP_User' ) && strtolower( $userdata['user_email'] ) !== strtolower( $old_user_data->user_email ) ) {
				$post_data['user_email']          = $userdata['user_email'];
				$post_data['previous_user_email'] = $old_user_data->user_email;
			}

			$this->push_user_meta( $user_id, $post_data );

		}
	}

	/**
	 * Handles user updates via the REST API
	 *
	 * @since  3.45.2
	 *
	 * @param  WP_User         $user     The user object.
	 * @param  WP_REST_Request $request  The request object.
	 * @param  bool            $creating Whether this is a new user.
	 */
	public function rest_after_insert_user( $user, $request, $creating ) {

		if ( $creating ) {
			return; // user_register already handled this.
		}

		wp_fusion()->logger->add_source( 'rest-api' );

		$user_data = $request->get_params();

		if ( isset( $user_data['email'] ) ) {
			$user_data['user_email'] = $user_data['email'];
		}

		if ( ! empty( $user_data['meta'] ) ) {
			$user_data = array_merge( $user_data['meta'], $user_data );
			unset( $user_data['meta'] );
		}

		$this->push_user_meta( $user->ID, $user_data );
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

		$contact_id = get_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, true );

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
	 * @param int $user_id The user ID.
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

		return wp_fusion()->crm->get_contact_edit_url( $contact_id );
	}


	/**
	 * Gets contact ID from user ID.
	 *
	 * @since  1.0.0
	 *
	 * @param  int|bool $user_id      The user ID or false to use current user.
	 * @param  bool     $force_update Whether or not to force-check the contact
	 *                                ID by making an API call to the CRM.
	 * @return bool|string Contact ID or false if not found.
	 */
	public function get_contact_id( $user_id = false, $force_update = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		if ( empty( $user_id ) ) {
			return false;
		}

		do_action( 'wpf_get_contact_id_start', $user_id );

		$contact_id = get_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, true );

		if ( empty( $contact_id ) ) {
			$contact_id = false;
		}

		// If the contact was created in staging mode and we're no longer in staging mode.
		if ( 0 === strpos( $contact_id, 'staging_' ) && ! wpf_is_staging_mode() && 'staging' !== wp_fusion()->crm->slug ) {
			$contact_id = false;
		}

		// We need the email address for the wpf_get_contact_id_email filter.

		$user = get_user_by( 'id', $user_id );

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
		if ( false === $force_update ) {
			return apply_filters( 'wpf_contact_id', $contact_id, $email_address );
		}

		// If no user email set, don't bother with an API call.
		if ( ! is_email( $email_address ) ) {
			wpf_log( 'error', $user_id, 'Contact ID lookup failed. Invalid email address: ' . $email_address );
			return false;
		}

		$loaded_contact_id = wp_fusion()->crm->get_contact_id( $email_address );

		if ( is_wp_error( $loaded_contact_id ) ) {

			wpf_log( $loaded_contact_id->get_error_code(), $user_id, 'Error getting contact ID for <strong>' . $email_address . '</strong>: ' . $loaded_contact_id->get_error_message() );
			return false; // This will allow integrations to try to create a new one.

		}

		$contact_id = apply_filters( 'wpf_contact_id', $loaded_contact_id, $email_address );

		if ( empty( $contact_id ) ) {

			// Error logging.
			wpf_log( 'info', $user_id, 'No contact found in ' . wp_fusion()->crm->name . ' for <strong>' . $email_address . '</strong>' );
			delete_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, $contact_id );
			delete_user_meta( $user_id, WPF_TAGS_META_KEY, $contact_id );

		} else {

			$contact_id = sanitize_text_field( $contact_id );

			// Save it for later.
			update_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, $contact_id );
		}

		do_action( 'wpf_got_contact_id', $user_id, $contact_id );

		return $contact_id;
	}

	/**
	 * Gets and saves updated user meta from the CRM
	 *
	 * @access public
	 * @return array|WP_Error User Meta or WP_Error if there was an error.
	 */
	public function pull_user_meta( $user_id = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $contact_id ) ) {
			wpf_log( 'notice', $user_id, __( 'Error loading user meta: no contact record found.', 'wp-fusion-lite' ) );
			return new WP_Error( 'error', 'No contact record found.' );
		}

		do_action( 'wpf_pre_pull_user_meta', $user_id );

		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		// Error logging.
		if ( is_wp_error( $user_meta ) ) {

			wpf_log( $user_meta->get_error_code(), $user_id, 'Error loading contact user meta: ' . $user_meta->get_error_message() );
			return $user_meta;

		} elseif ( empty( $user_meta ) ) {

			wpf_log( 'notice', $user_id, 'No elligible user meta loaded.' );
			return new WP_Error( 'error', 'No elligible user meta loaded.' );

		}

		/**
		 * Allow modification of the loaded data.
		 *
		 * There are two filters that run on this data, this, and the newer
		 * wpf_set_user_meta in WPF_User::set_user_meta(). Since this filter
		 * runs before the logs, we'll use this one for re-formatting any data.
		 *
		 * For example in WPF_MemberPress::pulled_user_meta(), we convert radios
		 * and checkboxes to their proper database values.
		 *
		 * wpf_set_user_meta will then be used whenever data has to be pulled
		 * out of the $user_meta array to be set elsewhere (i.e. a custom
		 * table), for example WPF_BuddyPress::set_user_meta().
		 *
		 * @since 1.0.0
		 *
		 * @see   WPF_User::set_user_meta()
		 * @link  https://wpfusion.com/documentation/filters/wpf_pulled_user_meta/
		 *
		 * @param array $user_meta The meta data.
		 * @param int   $user_id   The user ID.
		 */

		$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user_id );

		// Allows for cancelling via filter.
		if ( null === $user_meta ) {
			return;
		}

		wpf_log(
			'info',
			$user_id,
			'Loaded meta data from ' . wp_fusion()->crm->name . ':',
			array(
				'meta_array' => $user_meta,
			)
		);

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
	public function get_user_meta( $user_id = false ) {

		if ( false === $user_id ) {
			$user_id = $this->get_current_user_id();
		}

		if ( empty( $user_id ) ) {
			return apply_filters( 'wpf_get_user_meta', array(), $user_id );
		}

		// Start by getting everything in the database.

		$user_meta = get_user_meta( $user_id );

		if ( ! $user_meta ) {
			return apply_filters( 'wpf_get_user_meta', array(), $user_id );
		}

		$user_meta = array_map(
			function ( $a ) {
				return maybe_unserialize( $a[0] );
			},
			$user_meta
		);

		// get_userdata() doesn't work properly during an auto login session.

		if ( doing_wpf_auto_login() && wpf_get_current_user_id() === $user_id ) {
			return apply_filters( 'wpf_get_user_meta', $user_meta, $user_id );
		}

		$userdata = get_userdata( $user_id );

		if ( false === $userdata ) {
			return array();
		}

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
			$user_meta[ $userdata->cap_key ] = array_keys( $userdata->caps );
		}

		$user_meta['ip'] = $this->get_ip();

		$user_meta = apply_filters( 'wpf_get_user_meta', $user_meta, $user_id );

		return $user_meta;
	}

	/**
	 * Gets the IP of the user.
	 *
	 * @since 3.38.40
	 *
	 * @return string The IP.
	 */
	public function get_ip() {

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			$ip = '';
		}

		/**
		 * Allows the IP address of the client to be modified.
		 *
		 * Use this filter if the server is behind a proxy.
		 *
		 * @since 3.38.40
		 *
		 * @param string $ip The IP being used.
		 */
		$ip = apply_filters( 'wpf_ip_address', $ip );

		// HTTP_X_FORWARDED_FOR can return a comma separated list of IPs; use the first one.
		$ips = explode( ',', $ip );

		return $ips[0];
	}

	/**
	 * Sets user meta data.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $user_id   The ID of the user to update.
	 * @param array $user_meta An associative array of user meta data to set.
	 */
	public function set_user_meta( $user_id, $user_meta ) {

		/**
		 * Allow modification of the data.
		 *
		 * There are two filters that run on this data, this, and the older
		 * wpf_pulled_user_meta in WPF_User::pull_user_meta(). Since this filter
		 * runs after the logs, we'll use this one for data that has to be
		 * pulled out of the $user_meta array to be set elsewhere (i.e. a custom
		 * table), for example WPF_BuddyPress::set_user_meta().
		 *
		 * @since 3.35.13
		 *
		 * @see   WPF_User::pull_user_meta()
		 * @link  https://wpfusion.com/documentation/advanced-developer-tutorials/detecting-and-syncing-additional-fields/
		 *
		 * @param array $user_meta The meta data.
		 * @param int   $user_id   The user ID.
		 */

		$user_meta = apply_filters( 'wpf_set_user_meta', $user_meta, $user_id );

		$user_meta = wpf_clean( $user_meta ); // sanitize and clean.

		// Don't send updates back.
		remove_action( 'profile_update', array( $this, 'profile_update' ) );
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ) );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ) );

		// Save all of it to usermeta table if doing auto login
		if ( doing_wpf_auto_login() ) {

			foreach ( $user_meta as $key => $value ) {

				update_user_meta( $user_id, $key, $value );

			}
		} else {

			$user = get_userdata( $user_id );

			foreach ( $user_meta as $key => $value ) {

				if ( empty( $value ) && ! is_null( $value ) && '0' !== $value && 'raw' !== wpf_get_field_type( $key ) ) {

					// We only set empty values if:
					// 1. The field value is 0 or is null.
					// 2. The field type is set to "raw".

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

				} elseif ( $key == 'role' && ! empty( $value ) ) {

					if ( user_can( $user_id, 'manage_options' ) ) {
						continue; // Don't run on admins.
					}

					if ( is_array( $value ) ) {
						$value = $value[0]; // fix roles loaded as arrays.
					}

					$slug = array_search( $value, wp_roles()->get_names() );

					if ( false !== $slug ) {
						$value = $slug;
					}

					if ( wp_roles()->is_role( $value ) && ! in_array( $value, (array) $user->roles ) ) {

						// Don't send it back again
						remove_action( 'set_user_role', array( $this, 'add_remove_user_role' ), 10, 3 );
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
				} elseif ( $key === $user->cap_key ) {

					if ( user_can( $user_id, 'manage_options' ) ) { // Don't run on admins.
						continue;
					}

					if ( ! is_array( $value ) ) {
						$value = explode( ',', $value );
					}

					if ( is_array( $value ) ) {

						$capabilities = array();

						foreach ( $value as $i => $role ) {

							$role = trim( $role );

							if ( ! wp_roles()->is_role( $role ) ) {
								wpf_log( 'notice', $user_id, 'Role <strong>' . $role . '</strong> was loaded, but it is not a valid user role for this site.' );
								continue;
							}

							$capabilities[ $role ] = true;

						}

						if ( ! empty( $capabilities ) ) {

							if ( $capabilities !== $user->caps ) {

								wpf_log( 'notice', $user_id, 'User capabilities changed to:', array( 'meta_array_nofilter' => $capabilities ) );
								update_user_meta( $user_id, $user->cap_key, $capabilities );

							}
						}
					}
				} else {

					update_user_meta( $user_id, $key, $value );

				}

				do_action( 'wpf_user_meta_updated', $user_id, $key, $value );

			}
		}

		add_action( 'profile_update', array( $this, 'profile_update' ) );
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

		if ( 0 === $user_id ) {
			return array(); // not logged in.
		}

		do_action( 'wpf_get_tags_start', $user_id );

		$user_tags = get_user_meta( $user_id, WPF_TAGS_META_KEY, true );

		// In case the tag names got HTML encoded, decode them here so access checks don't fail.
		if ( is_array( $user_tags ) ) {
			$user_tags = array_map( 'htmlspecialchars_decode', $user_tags );
		}

		if ( ! empty( $user_tags ) && ! is_array( $user_tags ) ) {
			$user_tags = array(); // fix corrupted or incomplete tags.
		}

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

		$tags = apply_filters( 'wpf_loaded_tags', $tags, $user_id, $contact_id );

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

		// Clean and sanitize.

		$tags = wpf_clean_tags( $tags );

		wpf_log( 'info', $user_id, __( 'Loaded tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		// Compare new tags to current tags to see what's changed.
		$user_tags = (array) get_user_meta( $user_id, WPF_TAGS_META_KEY, true );

		if ( ! empty( $tags ) && $tags == $user_tags ) {

			if ( doing_action( 'wpf_tags_modified' ) ) {
				// Don't fire a tags modified inside a tags modified if they haven't changed.
				return;
			}

			// Doing the action here so that automated enrollments are triggered.
			do_action( 'wpf_tags_modified', $user_id, $user_tags );

			// If nothing changed.
			return;

		}

		// Check and see if new tags have been pulled, and if so, resync the available tags list.

		if ( is_admin() ) {

			$sync_needed    = false;
			$available_tags = wpf_get_option( 'available_tags' );

			foreach ( (array) $tags as $tag ) {

				if ( ! isset( $available_tags[ $tag ] ) ) {

					if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {
						$available_tags[ $tag ] = $tag;
					}

					$sync_needed = true;
				}
			}

			if ( true === $sync_needed ) {

				if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {
					wp_fusion()->settings->set( 'available_tags', $available_tags );
				} else {
					wp_fusion()->crm->sync_tags();
				}
			}
		}

		// Save it to the DB.

		if ( ! empty( $tags ) ) {
			update_user_meta( $user_id, WPF_TAGS_META_KEY, $tags );
		} else {
			delete_user_meta( $user_id, WPF_TAGS_META_KEY );
		}

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
	 * @return bool|WP_Error True if successful, WP_Error if not.
	 */
	public function apply_tags( $tags, $user_id = false ) {

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return false;
		}

		// Sanitize!
		$cleaned_tags = wpf_clean_tags( $tags );

		// Check for unknown tags.
		if ( count( $cleaned_tags ) !== count( $tags ) ) {

			$unknown_tags = array();

			foreach ( $tags as $tag ) {
				if ( ! $this->get_tag_id( $tag ) ) {
					$unknown_tags[] = $tag;
				}
			}

			wpf_log( 'notice', $user_id, 'Some tags were not applied because they were invalid or unknown.', array( 'tag_array' => $unknown_tags ) );
		}

		if ( empty( $cleaned_tags ) ) {
			return false;
		}

		$tags = $cleaned_tags;

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

		// If no contact ID, double check in the CRM.

		if ( empty( $contact_id ) ) {

			$contact_id = $this->get_contact_id( $user_id, true );

			if ( empty( $contact_id ) ) {

				wpf_log( 'notice', $user_id, __( 'No contact ID for user. Failed to apply tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
				return false;

			}
		}

		$user_tags = $this->get_tags( $user_id );

		// Maybe quit early if user already has the tag.
		$diff = array_diff( (array) $tags, $user_tags );

		// @TODO can we indicate in the logs when *some* tags aren't being applied because the user already has them?

		/**
		 * By default WP Fusion will not send an API call to apply tags that a user already has. This can be overridden here.
		 *
		 * @param bool $prevent_reapply_tags Whether to prevent re-applying tags
		 */

		if ( apply_filters( 'wpf_prevent_reapply_tags', wpf_get_option( 'prevent_reapply', true ) ) ) {

			if ( empty( $diff ) ) {

				wpf_log( 'info', $user_id, __( 'Applying Tag(s). No API call will be sent since the user already has all of the following tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );
				return true;

			} else {

				// If we're only applying tags the user doesn't have already.
				$tags = $diff;

			}
		}

		if ( empty( array_filter( $tags ) ) ) {
			return true;
		}

		// Check for chaining.

		if ( doing_action( 'wpf_tags_modified' ) && ! doing_wpf_webhook() ) {
			wpf_log( 'notice', $user_id, __( '<strong>Chaining situation detected</strong>. WP Fusion is about to apply tags as the result of an automated enrollment, which was triggered by other tags being applied. This kind of setup should be avoided and may result in unexpected behavior or site instability.', 'wp-fusion-lite' ) );
		}

		// Logging.

		wpf_log( 'info', $user_id, __( 'Applying tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		$result = wp_fusion()->crm->apply_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wpf_log( $result->get_error_code(), $user_id, 'Error while applying tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return $result;
		}

		// Save to the database.

		$user_tags = array_values( array_unique( array_merge( $user_tags, $tags ) ) );

		update_user_meta( $user_id, WPF_TAGS_META_KEY, $user_tags );

		// If a new tag was just applied, update the available list.

		if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			$available_tags = wpf_get_option( 'available_tags', array() );

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
	 * @return bool|WP_Error True if successful, false if user doesn't have the tag, WP_Error if API call failed.
	 */
	public function remove_tags( $tags, $user_id = false ) {

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		// Sanitize!

		$tags = wpf_clean_tags( $tags );

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

		if ( empty( array_filter( $tags ) ) ) {
			return true;
		}

		// Check for chaining.

		if ( doing_action( 'wpf_tags_modified' ) && ! doing_wpf_webhook() ) {
			wpf_log( 'notice', $user_id, __( '<strong>Chaining situation detected</strong>. WP Fusion is about to remove tags as the result of an automated enrollment, which was triggered by other tags being applied. This kind of setup should be avoided and may result in unexpected behavior or site instability.', 'wp-fusion-lite' ) );
		}

		// Logging.

		wpf_log( 'info', $user_id, __( 'Removing tag(s)', 'wp-fusion-lite' ) . ': ', array( 'tag_array' => $tags ) );

		$result = wp_fusion()->crm->remove_tags( $tags, $contact_id );

		if ( is_wp_error( $result ) ) {
			wpf_log( $result->get_error_code(), $user_id, 'Error while removing tags: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return $result;
		}

		// Save to the database.

		$user_tags = array_unique( array_diff( $user_tags, $tags ) );

		update_user_meta( $user_id, WPF_TAGS_META_KEY, $user_tags );

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

		$password_field = wpf_get_option( 'return_password_field' );

		if ( wpf_get_option( 'return_password' ) && ! empty( $password_field ) ) {

			wpf_log( 'info', $user_id, 'Returning generated password <strong>' . $user_meta['user_pass'] . '</strong> to ' . wp_fusion()->crm->name );

			$contact_id = $this->get_contact_id( $user_id );
			$result     = wp_fusion()->crm->update_contact( $contact_id, array( $password_field => $user_meta['user_pass'] ), false );

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

				$crm_field = wp_fusion()->crm->get_crm_field( $key );

				if ( false !== strpos( $crm_field, 'add_tag_' ) && wp_fusion()->crm->is_field_active( $key ) ) {

					if ( is_array( $value ) ) {
						$apply_tags = array_merge( $apply_tags, $value );
					} else {
						$apply_tags[] = $value;
					}
				}
			}

			if ( ! empty( $apply_tags ) ) {

				$contact_id = get_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, true );

				if ( ! empty( $contact_id ) ) {

					// User update for existing contact ID, easy.
					$this->apply_tags( $apply_tags, $user_id );

				} else {

					// New user registration, harder.
					add_action(
						'wpf_user_created',
						function ( $user_id, $contact_id, $post_data ) use ( &$apply_tags ) {

							$this->apply_tags( $apply_tags, $user_id );
						},
						10,
						3
					);

				}
			}
		}

		return $user_meta;
	}

	/**
	 * Prevents the user_register hook from running twice.
	 *
	 * @since 3.44.3
	 *
	 * @param array $user_data The user data.
	 * @param bool  $update    Whether the user is being updated.
	 * @return array The user data.
	 */
	public function set_inserting_user_flag( $user_data, $update ) {

		if ( false === $update ) {
			$this->inserting_user = true;
		}

		return $user_data;
	}

	/**
	 * Triggered when user role added or removed
	 *
	 * @access public
	 * @return void
	 */
	public function add_remove_user_role( $user_id, $role ) {

		if ( $this->inserting_user || empty( wp_fusion()->crm ) || doing_wpf_webhook() ) {
			// User register will kick in later, and set_current_user sometimes causes
			// errors because the CRM isn't set up yet. We also don't need to sync the role
			// back if it was changed by a webhook.
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$caps = get_user_meta( $user_id, $user->cap_key, true ); // for some reason $user->caps isn't always updated by the time this function runs.

		if ( ! empty( $caps ) && is_array( $caps ) ) {

			$roles = array_keys( $caps );

			if ( ! $this->get_contact_id( $user_id ) ) {

				if ( ! empty( array_intersect( wpf_get_option( 'user_roles', array() ), $roles ) ) ) {

					// If we're limiting user roles and the user's role was just changed to a valid one.

					wp_fusion()->logger->add_source( 'limit-user-roles' );

					$this->user_register( $user_id );

					remove_action( 'set_user_role', array( $this, 'add_remove_user_role' ), 10, 2 ); // Don't do it twice.
					remove_action( 'add_user_role', array( $this, 'add_remove_user_role' ), 10, 2 );

				}
			} else {

				// Maybe sync the updated roles.

				if ( doing_action( 'remove_user_role' ) ) {
					$role = $roles[0]; // If we're removing a role, $role will be the role that was just removed, so let's grab the fist capability instead.
				}

				if ( wpf_is_field_active( array( $user->cap_key, 'wp_capabilities', 'role' ) ) ) {

					$update_data = array(
						$user->cap_key    => $roles,
						'wp_capabilities' => $roles,
						'role'            => $role,
					);

					$this->push_user_meta( $user_id, $update_data );
				}
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

		if ( 2 <= did_action( 'wp_login' ) ) {
			return;
		}

		if ( ! wp_fusion()->crm ) {
			return; // in case the CRM isn't loaded yet.
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
	 * @return int|bool User ID or false.
	 */
	public function get_user_id( $contact_id ) {

		do_action( 'wpf_get_user_id_start', $contact_id );

		$user_id = apply_filters( 'wpf_get_user_id', false, $contact_id ); // Allow bypassing the database query, for performance.

		if ( false !== $user_id ) {

			// If the query was bypassed.
			return $user_id;

		}

		// We're using $wpdb here rather then WP_User_Query to get around some
		// performance issues resulting from using JOINs on large usermeta tables.

		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT user_id
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s
				AND meta_value = %s
				AND user_id < 100000000
				ORDER BY user_id ASC",
			WPF_CONTACT_ID_META_KEY,
			$contact_id
		);

		// ^ If the user ID is greater than 100 million, it's an auto-login user ID, not a real user.

		$user_id = $wpdb->get_var( $query );

		if ( is_null( $user_id ) ) {
			$user_id = false;
		}

		return $user_id;
	}

	/**
	 * Gets all users that have saved contact IDs.
	 *
	 * @since 3.37.21
	 *
	 * @return array User IDs.
	 */
	public static function get_users_with_contact_ids() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'     => WPF_CONTACT_ID_META_KEY,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => WPF_CONTACT_ID_META_KEY,
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
					'key'     => WPF_TAGS_META_KEY,
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

		// Allow overrides by admin bar.
		if ( wpf_is_user_logged_in() && current_user_can( 'manage_options' ) && get_query_var( 'wpf_tag' ) ) {

			if ( 'unlock-all' === get_query_var( 'wpf_tag' ) ) {
				return true;
			}

			if ( 'lock-all' === get_query_var( 'wpf_tag' ) ) {
				return false;
			}
		}

		if ( ! wpf_is_user_logged_in() && false === $user_id ) {
			return false;
		}

		$user_tags = $this->get_tags( $user_id );

		if ( empty( $user_tags ) ) {
			return false;
		}

		if ( ! is_array( $tags ) ) {
			$tags = array( $tags );
		}

		$tags = apply_filters( 'wpf_user_has_tag', $tags, $user_id );

		// Make sure we're only checking against valid tags.
		$tags = array_filter( array_map( array( $this, 'get_tag_id' ), $tags ) );

		if ( ! empty( array_intersect( $tags, $user_tags ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Gets tag ID from tag name
	 *
	 * @access public
	 * @return string|bool The tag ID or false if not found.
	 */
	public function get_tag_id( $tag_name ) {

		if ( is_array( $tag_name ) ) {

			// Sometimes this comes through as an array and we're not sure why.

			wpf_log( 'notice', 0, '(Debug) An array was passed to get_tag_id():', array( 'tag_array' => $tag_name ) );
			$tag_name = reset( $tag_name );

		}

		$tag_name = trim( $tag_name );

		$tag_name = htmlspecialchars_decode( $tag_name ); // in case it's HTML encoded.

		// If it's already an ID.

		if ( is_numeric( $tag_name ) ) {
			return $tag_name;
		}

		$available_tags = wp_fusion()->settings->get_available_tags_flat( true, false );

		// If it's already an ID and exists.

		if ( isset( $available_tags[ $tag_name ] ) ) {
			return $tag_name;
		}

		// Search for a tag with the ID.
		$tag_name = strval( $tag_name );

		$tag_id = array_search( $tag_name, $available_tags, true );

		if ( false !== $tag_id ) {
			return $tag_id;
		}

		// If no match found, and CRM supports add_tags, return the label.
		if ( ! empty( wp_fusion()->crm ) && wp_fusion()->crm->supports( 'add_tags' ) ) {
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

		if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

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

		wp_fusion()->logger->add_source( 'push-all' );

		$this->push_user_meta( $object_id, array( $meta_key => $_meta_value ) );
	}


	/**
	 * Sends updated user meta to CRM
	 *
	 * @access public
	 * @return bool|WP_Error True if successful, false or WP_Error if not.
	 */
	public function push_user_meta( $user_id, $user_meta = array() ) {

		if ( ! wpf_get_option( 'push' ) || ! wp_fusion()->crm ) {
			return false;
		}

		do_action( 'wpf_push_user_meta_start', $user_id, $user_meta );

		// If nothing's been supplied, get the latest from the DB.

		if ( empty( $user_meta ) ) {
			$user_meta = $this->get_user_meta( $user_id );
		}

		$user_meta = apply_filters( 'wpf_user_update', $user_meta, $user_id );

		// Allows for cancelling via filter.

		if ( null === $user_meta ) {
			wpf_log( 'notice', $user_id, 'Push user meta aborted: no metadata found for user.' );
			return false;
		}

		$contact_id = $this->get_contact_id( $user_id );

		if ( empty( $user_meta ) || empty( $contact_id ) ) {
			return false;
		}

		wpf_log( 'info', $user_id, 'Pushing meta data to ' . wp_fusion()->crm->name . ': ', array( 'meta_array' => $user_meta ) );

		$result = wp_fusion()->crm->update_contact( $contact_id, $user_meta );

		if ( is_wp_error( $result ) ) {

			wpf_log( $result->get_error_code(), $user_id, 'Error while updating meta data: ' . $result->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return $result;

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
	 * @return int|WP_Error User ID of newly created user.
	 */
	public function import_user( $contact_id, $send_notification = false, $role = false ) {

		// First see if user already exists.
		$user_id = wpf_get_user_id( $contact_id );

		if ( $user_id ) {

			$this->pull_user_meta( $user_id );
			$this->get_tags( $user_id, true, false );

			// Maybe change role (but not for admins).
			if ( ! empty( $role ) && ! user_can( $user_id, 'manage_options' ) && wp_roles()->is_role( $role ) ) {

				$user = new WP_User( $user_id );
				$user->set_role( $role );
			}

			return $user_id;

		}

		$user_meta = wp_fusion()->crm->load_contact( $contact_id );

		if ( is_wp_error( $user_meta ) ) {

			wpf_log( 'error', 0, 'Error importing contact #' . $contact_id . ': ' . $user_meta->get_error_message() );
			return $user_meta;

		} elseif ( empty( $user_meta['user_email'] ) ) {

			wpf_log( 'error', 0, 'No email found for imported contact #' . $contact_id . '.', array( 'meta_array_nofilter' => $user_meta ) );
			return new WP_Error( 'error', 'No email provided for imported user' );

		}

		$user_meta = wpf_clean( $user_meta ); // make it safe.

		// See if user with matching email exists.
		$user = get_user_by( 'email', $user_meta['user_email'] );

		if ( is_wp_error( $user ) ) {

			wpf_log( 'error', 0, 'Error importing contact #' . $contact_id . ' with error: ' . $user->get_error_message() );
			return $user;

		} elseif ( is_object( $user ) ) {

			/**
			 * Allow modification of the loaded data.
			 *
			 * There are two filters that run on this data, this, and the newer
			 * wpf_set_user_meta in WPF_User::set_user_meta(). Since this filter
			 * runs before the logs, we'll use this one for re-formatting any data.
			 *
			 * For example in WPF_MemberPress::pulled_user_meta(), we convert radios
			 * and checkboxes to their proper database values.
			 *
			 * wpf_set_user_meta will then be used whenever data has to be pulled
			 * out of the $user_meta array to be set elsewhere (i.e. a custom
			 * table), for example WPF_BuddyPress::set_user_meta().
			 *
			 * @since 1.0.0
			 *
			 * @see   WPF_User::set_user_meta()
			 * @link  https://wpfusion.com/documentation/filters/wpf_pulled_user_meta/
			 *
			 * @param array $user_meta The meta data.
			 * @param int   $user_id   The user ID.
			 */

			$user_meta = apply_filters( 'wpf_pulled_user_meta', $user_meta, $user->ID );

			update_user_meta( $user->ID, WPF_CONTACT_ID_META_KEY, $contact_id );
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

		if ( isset( $user_meta['role'] ) && is_array( $user_meta['role'] ) ) {
			$user_meta['role'] = $user_meta['role'][0]; // fix roles loaded as arrays.
		}

		if ( empty( $user_meta['role'] ) && ! empty( $role ) ) {
			$user_meta['role'] = $role; // if it was set in the URL or import tool params.
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
		$user_meta[ WPF_CONTACT_ID_META_KEY ] = $contact_id;

		// Apply filters.
		$user_meta = apply_filters( 'wpf_import_user', $user_meta, $contact_id );

		// Allows for cancelling via filter
		if ( null === $user_meta ) {
			wpf_log( 'notice', 0, 'Import of contact #' . $contact_id . ' aborted: no metadata found for user.' );
			return 0;
		}

		// Prevent the default registration hook from running.
		remove_action( 'user_register', array( $this, 'user_register' ), 20 );

		// Don't push updates back to CRM.
		remove_action( 'updated_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'added_user_meta', array( $this, 'push_user_meta_single' ), 10, 4 );
		remove_action( 'set_user_role', array( $this, 'add_remove_user_role' ), 10, 3 );

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

			wpf_log( 'error', 0, 'Error importing contact #' . $contact_id . ' with error: ' . $user_id->get_error_message() );
			return $user_id;

		}

		// Logger.
		wpf_log( 'info', $user_id, 'Imported contact #' . $contact_id . ', with meta data: ', array( 'meta_array_nofilter' => $user_meta ) );

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
