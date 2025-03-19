<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the admin user profile functionality.
 *
 * @since 1.0.0
 */
class WPF_User_Profile {

	/**
	 * Get things started
	 *
	 * @since 1.0
	 * @return void
	 */
	public function __construct() {

		// User profile display / edit.
		add_action( 'show_user_profile', array( $this, 'user_profile' ), 5 );
		add_action( 'edit_user_profile', array( $this, 'user_profile' ), 5 );

		add_action( 'load-profile.php', array( $this, 'process_profile_actions' ) );
		add_action( 'load-user-edit.php', array( $this, 'process_profile_actions' ) );

		// New users.
		add_action( 'user_new_form', array( $this, 'user_new_form' ) );

		// AJAX.
		add_action( 'wp_ajax_resync_contact', array( $this, 'resync_contact' ) );

		// Updates.
		add_action( 'edit_user_profile_update', array( $this, 'user_profile_update' ), 5 );
		add_action( 'personal_options_update', array( $this, 'user_profile_update' ), 5 );

		// Filters for posted data from internal forms.
		add_filter( 'wpf_user_register', array( $this, 'filter_form_fields' ), 30, 2 ); // 30 so all other plugins have run.
		add_filter( 'wpf_user_update', array( $this, 'filter_form_fields' ), 10, 2 );

		// Bulk actions.
		add_filter( 'bulk_actions-users', array( $this, 'add_bulk_actions' ) );
	}



	/**
	 * Add bulk actions to the users page.
	 *
	 * @since 3.44.6
	 *
	 * @param array $bulk_actions The bulk actions array.
	 * @return array The modified bulk actions array.
	 */
	public function add_bulk_actions( $bulk_actions ) {
		$bulk_actions['users_sync']      = __( 'Resync contact IDs and tags', 'wp-fusion-lite' );
		$bulk_actions['users_meta']      = __( 'Push user meta', 'wp-fusion-lite' );
		$bulk_actions['pull_users_meta'] = __( 'Pull user meta', 'wp-fusion-lite' );
		return $bulk_actions;
	}


	/**
	 * Does manual actions on user profiles and displays the results
	 *
	 * @since 3.35.14
	 * @since 3.44.15 Moved to the load-profile.php and load-user-edit.php actions for
	 *                better compatibility with plugins that remove admin notices like
	 *                "MemberPress Courses".
	 *
	 * @return mixed Notice Content
	 */
	public function process_profile_actions() {

		if ( ! isset( $_GET['wpf_profile_action'] ) || ! isset( $_GET['user_id'] ) ) {
			return;
		}

		check_admin_referer( 'wpf_profile_action' );

		$user_id = absint( $_GET['user_id'] );
		$action  = sanitize_key( $_GET['wpf_profile_action'] );

		wpf_disable_api_queue(); // get API responses immediately.

		// For debugging purposes.
		if ( 'register' === $action ) {

			$result = wp_fusion()->user->user_register( $user_id, null, true );

			if ( ! is_wp_error( $result ) ) {

				$edit_url = wp_fusion()->user->get_contact_edit_url( $user_id );

				if ( false !== $edit_url ) {
					$result = '<a href="' . $edit_url . '" target="_blank">#' . $result . '</a>';
				}

				$message = sprintf(
					/* translators: %1$s CRM name, %2$s contact ID */
					__( '<strong>Success:</strong> User was added to %1$s with contact ID %2$s.', 'wp-fusion-lite' ),
					wp_fusion()->crm->name,
					$result
				);

			} else {

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( '<strong>Error:</strong> Unable to create contact in %1$s: %2$s.', 'wp-fusion-lite' ),
					wp_fusion()->crm->name,
					$result->get_error_message()
				);

			}
		} elseif ( 'pull' === $action ) {

			$result = wp_fusion()->user->pull_user_meta( $user_id );

			if ( ! is_wp_error( $result ) ) {

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( '<strong>Success:</strong> Loaded metadata from %1$s:', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name )
				);

				$message .= '<br /><pre>' . wpf_print_r( $result, true ) . '</pre>';

			} else {

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( '<strong>Error:</strong> Unable to pull metadata from %1$s: %2$s.', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name ),
					$result->get_error_message()
				);

			}
		} elseif ( 'push' === $action ) {

			$result = wp_fusion()->user->push_user_meta( $user_id );

			if ( ! is_wp_error( $result ) ) {

				$contact_id = wpf_get_contact_id( $user_id );

				$edit_url = wp_fusion()->user->get_contact_edit_url( $user_id );

				if ( false !== $edit_url ) {
					$contact_id = '<a href="' . $edit_url . '" target="_blank">#' . $contact_id . '</a>';
				}

				$message = sprintf(
					/* translators: %1$s CRM name, %2$s contact ID */
					__( '<strong>Success:</strong> Synced user meta to %1$s contact ID %2$s.', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name ),
					$contact_id
				);
			} else {

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( '<strong>Error:</strong> Unable to push user meta to %1$s: %2$s.', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name ),
					$result->get_error_message()
				);

			}
		} elseif ( 'show_meta' === $action ) {

			$result = wp_fusion()->user->get_user_meta( $user_id );

			$message = '<pre>' . wpf_print_r( $result, true ) . '</pre>';

		}

		wp_admin_notice(
			$message,
			array(
				'id'                 => 'wpf_profile_action_message',
				'dismissible'        => true,
				'additional_classes' => ! is_wp_error( $result ) ? array( 'updated' ) : array( 'error' ),
			)
		);
	}

	/**
	 * Adds "Add to CRM" checkbox to the New User form.
	 *
	 * @since 3.40.28
	 *
	 * @return mixed HTML content.
	 */
	public function user_new_form() {

		?>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><?php printf( esc_html__( 'Add to %s', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?></th>
				<td>
					<input type="checkbox" name="wpf_add_contact" id="wpf_add_contact" value="1" <?php checked( wpf_get_option( 'create_users' ) ); ?> />
					<label for="wpf_add_contact"><?php printf( esc_html__( 'Add the user as a contact in %s', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?></label>
				</td>
			</tr>

		</table>

		<?php
	}

	/**
	 * Updates the contact record in the CRM when a profile is edited in the backend.
	 *
	 * @since 3.44.15
	 */
	public function user_profile_update( $user_id ) {

		check_admin_referer( 'update-user_' . $user_id );

		// This sets the log source for the WPF_User::profile_update() function.
		wp_fusion()->logger->add_source( 'user-profile' );

		wpf_disable_api_queue(); // get API responses immediately.

		// See if tags have manually been modified on the user edit screen.
		if ( ! empty( $_POST['wpf_tags_field_edited'] ) ) {

			// Prevent it from running more than once on a profile update.
			unset( $_POST['wpf_tags_field_edited'] );

			if ( isset( $_POST[ WPF_TAGS_META_KEY ] ) ) {
				$posted_tags = wpf_clean_tags( $_POST[ WPF_TAGS_META_KEY ] );
			} else {
				$posted_tags = array();
			}

			$user_tags = wpf_get_tags( $user_id );

			// Apply new tags.
			$result = wp_fusion()->user->apply_tags( array_diff( $posted_tags, $user_tags ), $user_id );

			// Remove removed tags.

			if ( ! is_wp_error( $result ) ) {
				$result = wp_fusion()->user->remove_tags( array_diff( $user_tags, $posted_tags ), $user_id );
			}

			if ( is_wp_error( $result ) ) {

				// Copied from user-edit.php in core.

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( 'Error: Unable to update tags in %1$s: %2$s.', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name ),
					$result->get_error_message()
				);

				add_action(
					'user_profile_update_errors',
					function ( $errors ) use ( $message ) {
						$errors->add( 'wpf_error', $message );
					}
				);

			} else {
				do_action( 'wpf_admin_profile_tags_edited', $user_id );
			}
		}

		// Email changes that have just been confirmed.
		if ( isset( $_GET['newuseremail'] ) ) {

			$user = get_userdata( $user_id );

			$result = wp_fusion()->user->push_user_meta( $user_id, array( 'user_email' => $user->user_email ) );

			if ( is_wp_error( $result ) ) {

				$message = sprintf(
					/* translators: %1$s CRM name */
					__( '<strong>Error:</strong> Unable to update user email in %1$s: %2$s.', 'wp-fusion-lite' ),
					esc_html( wp_fusion()->crm->name ),
					$result->get_error_message()
				);

				add_action(
					'user_profile_update_errors',
					function ( $errors ) use ( $message ) {
						$errors->add( 'wpf_error', $message );
					}
				);
			}
		}
	}


	/**
	 * Resynchronize local user ID with IS contact record
	 *
	 * @access public
	 * @return mixed
	 */
	public function resync_contact() {

		check_ajax_referer( 'wpf_admin_nonce' );

		if ( ! isset( $_POST['user_id'] ) ) {
			wp_die( -1 );
		}

		$user_id = absint( $_POST['user_id'] );

		// Force reset contact ID and search for new match.
		$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

		// If no contact found.
		if ( empty( $contact_id ) ) {
			wp_send_json_error();
		}

		// Force reset tags and search for new tags.
		$user_tags = wp_fusion()->user->get_tags( $user_id, true, false );

		$response = array(
			'contact_id' => $contact_id,
			'user_tags'  => $user_tags,
		);

		do_action( 'wpf_resync_contact', $user_id );

		// Return the result to the script and die.
		wp_send_json( $response );

		wp_die();
	}


	/**
	 * Filters registration data before sending to the CRM (internal add / edit fields)
	 *
	 * @param array|null $post_data The registration data.
	 * @param int        $user_id   The user ID.
	 * @return array|null Registration data or null if we're bypassing the registration process.
	 */

	public function filter_form_fields( $post_data, $user_id ) {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return $post_data;
		}

		$screen = get_current_screen();

		if ( ! is_null( $screen ) && in_array( $screen->id, array( 'profile', 'user-edit', 'user-new', 'user' ) ) ) {

			if ( 'user' === $screen->id && doing_filter( 'wpf_user_register' ) && ! isset( $_POST['wpf_add_contact'] ) ) {
				// translators: %s is the name of the CRM
				wpf_log( 'notice', $user_id, sprintf( __( 'Add to %1$s was not checked, the user will not be synced to %1$s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ) );
				return null; // cancel the signup process if the Add to CRM box isn't checked.
			}

			$field_map = array(
				'email'         => 'user_email',
				'url'           => 'user_url',
				'pass1-text'    => 'user_pass',
				'user_password' => 'user_pass',
				'pass1'         => 'user_pass',
			);

			foreach ( $field_map as $key => $field ) {

				if ( ! empty( $post_data[ $key ] ) ) {
					$post_data[ $field ] = $post_data[ $key ];
				}
			}

			// Filter out any objects before stripslashes_deep
			$post_data = array_filter(
				$post_data,
				function ( $value ) {
					return ! is_object( $value );
				}
			);

			$post_data = stripslashes_deep( $post_data );

			// Merge in some wp_users stuff.
			$userdata = get_userdata( $user_id );

			$post_data['user_login']      = $userdata->user_login;
			$post_data['user_registered'] = $userdata->user_registered;
			$post_data['user_nicename']   = $userdata->user_nicename;

		}

		return $post_data;
	}


	/**
	 * Adds fields to user profile
	 *
	 * @access public
	 * @return void
	 */

	public function user_profile( $user ) {

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		?>

		<h2 class="wp-fusion-user-profile-settings"><?php echo wpf_logo_svg(); ?> <?php esc_html_e( 'WP Fusion', 'wp-fusion-lite' ); ?></h2>

		<table class="form-table wp-fusion-user-profile-settings">

			<?php do_action( 'wpf_user_profile_before_table_rows', $user ); ?>

			<tr>
				<th><label for="contact_id"><?php printf( esc_html__( '%s Contact ID', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?></label></th>
				<td id="contact-id">
					<?php $contact_id = wp_fusion()->user->get_contact_id( $user->ID ); ?>

					<?php if ( false !== $contact_id ) : ?>

						<?php if ( is_wp_error( $contact_id ) ) : ?>

							<strong>Error:</strong> <?php echo wp_kses_post( wpf_print_r( $contact_id ) ); ?>

						<?php else : ?>

							<?php echo esc_html( $contact_id ); ?>

							<?php $edit_url = wp_fusion()->user->get_contact_edit_url( $user->ID ); ?>

							<?php if ( false !== $edit_url ) : ?>

								- <a href="<?php echo esc_url( $edit_url ); ?>" target="_blank"><?php printf( esc_html__( 'View in %s', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?> &rarr;</a>

							<?php endif; ?>

							<?php do_action( 'wpf_user_profile_after_contact_id', $user->ID ); ?>

						<?php endif; ?>

					<?php else : ?>

						<?php printf( esc_html__( 'No %s contact record found.', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?>

						<a href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wpf_profile_action' ), admin_url( 'user-edit.php?user_id=' . $user->ID . '&wpf_profile_action=register' ) ) ); ?>">
							<?php esc_html_e( 'Create new contact', 'wp-fusion-lite' ); ?>.
						</a>

					<?php endif; ?>
				</td>
			</tr>
			<?php if ( wp_fusion()->user->get_contact_id( $user->ID ) ) : ?>

				<tr id="wpf-tags-row">
					<th><label for="wpf_tags"><?php printf( esc_html__( '%s Tags', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?></label></th>
					<td id="wpf-tags-td">

						<?php

						$args = array(
							'setting'   => wp_fusion()->user->get_tags( $user->ID ),
							'meta_name' => WPF_TAGS_META_KEY,
							'disabled'  => true,
							'read_only' => true,
						);

						wpf_render_tag_multiselect( $args );

						?>

						<input type="hidden" id="wpf-tags-field-edited" name="wpf_tags_field_edited" value="0" />
						<p class="description"><?php esc_html_e( 'These tags are currently applied to the user in', 'wp-fusion-lite' ); ?> <?php echo esc_html( wp_fusion()->crm->name ); ?> <a id="wpf-profile-edit-tags" href="#"><?php esc_html_e( 'Edit Tags', 'wp-fusion-lite' ); ?></a></p>

					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="resync_contact"><?php esc_html_e( 'Resync Tags', 'wp-fusion-lite' ); ?></label></th>
				<td>

					<a id="resync-contact" href="#" class="button button-default" data-user_id="<?php echo $user->ID; ?>"><?php esc_html_e( 'Resync Tags', 'wp-fusion-lite' ); ?></a>
					<p class="description"><?php printf( __( 'If the contact ID or tags aren\'t in sync, click here to reset the local data and look up the contact again by email address in %s.', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?></p>

				</td>
			</tr>

			<tr>
				<th><label for="resync_contact"><?php esc_html_e( 'Additional Actions', 'wp-fusion-lite' ); ?></label></th>
				<td>

					<a href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wpf_profile_action' ), admin_url( 'user-edit.php?user_id=' . $user->ID . '&wpf_profile_action=push' ) ) ); ?>">
						<?php esc_html_e( 'Push User Meta', 'wp-fusion-lite' ); ?>
					</a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php printf( esc_attr__( 'Extracts any enabled meta fields from the database and syncs them to %s.', 'wp-fusion-lite' ), esc_attr( wp_fusion()->crm->name ) ); ?>"></span> | 

					<a href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wpf_profile_action' ), admin_url( 'user-edit.php?user_id=' . $user->ID . '&wpf_profile_action=pull' ) ) ); ?>">
						<?php esc_html_e( 'Pull User Meta', 'wp-fusion-lite' ); ?>
					</a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php printf( esc_attr__( 'Loads any enabled meta fields from %s and saves them to the user record.', 'wp-fusion-lite' ), esc_attr( wp_fusion()->crm->name ) ); ?>"></span> | 

					<a href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wpf_profile_action' ), admin_url( 'user-edit.php?user_id=' . $user->ID . '&wpf_profile_action=show_meta' ) ) ); ?>">
						<?php esc_html_e( 'Show User Meta', 'wp-fusion-lite' ); ?>
					</a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php esc_html_e( 'Displays all metadata found in the database for this user.', 'wp-fusion-lite' ); ?>"></span> |

					<a href="<?php echo esc_url( admin_url( 'tools.php?page=wpf-settings-logs&user=' . $user->ID ) ); ?>">
						<?php esc_html_e( 'View Logs', 'wp-fusion-lite' ); ?> &rarr;
					</a>

				</td>
			</tr>

			<?php do_action( 'wpf_user_profile_after_table_rows', $user ); ?>

		</table>

		<?php
	}
}


