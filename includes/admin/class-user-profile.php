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

		add_action( 'admin_notices', array( $this, 'profile_notices' ) );

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

	}

	/**
	 * Does manual actions on user profiles and displays the results
	 *
	 * @since 3.35.14
	 *
	 * @return mixed Notice Content
	 */
	public function profile_notices() {

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wpf_profile_action' ) ) {
			return;
		}

		if ( ! isset( $_GET['user_id'] ) || ! isset( $_GET['wpf_profile_action'] ) ) {
			return;
		}

		$user_id = absint( $_GET['user_id'] );
		$action  = sanitize_key( $_GET['wpf_profile_action'] );

		// For debugging purposes.
		if ( 'register' === $action ) {

			$contact_id = wp_fusion()->user->user_register( $user_id, null, true );

			if ( $contact_id ) {

				$edit_url = wp_fusion()->user->get_contact_edit_url( $user_id );

				if ( false !== $edit_url ) {
					$contact_id = '<a href="' . $edit_url . '" target="_blank">#' . $contact_id . '</a>';
				}

				$message = sprintf( __( '<strong>Success:</strong> User was added to %1$s with contact ID %2$s.' ), wp_fusion()->crm->name, $contact_id );

			} else {

				$message = sprintf( __( '<strong>Error:</strong> Unable to create contact in %1$s, see the %2$sactivity logs%3$s for more information.' ), wp_fusion()->crm->name, '<a href="' . admin_url( 'tools.php?page=wpf-settings-logs' ) . '">', '</a>' );

			}
		} elseif ( 'pull' === $action ) {

			$user_meta = wp_fusion()->user->pull_user_meta( $user_id );

			$message = sprintf( __( '<strong>Success:</strong> Loaded metadata from %1$s:' ), esc_html( wp_fusion()->crm->name ) );

			$message .= '<br /><pre>' . wpf_print_r( $user_meta, true ) . '</pre>';

		} elseif ( 'push' === $action ) {

			wp_fusion()->user->push_user_meta( $user_id );

			$contact_id = wpf_get_contact_id( $user_id );

			$edit_url = wp_fusion()->user->get_contact_edit_url( $user_id );

			if ( false !== $edit_url ) {
				$contact_id = '<a href="' . $edit_url . '" target="_blank">#' . $contact_id . '</a>';
			}

			$message = sprintf( __( '<strong>Success:</strong> Synced user meta to %1$s contact ID %2$s.' ), esc_html( wp_fusion()->crm->name ), $contact_id );

		} elseif ( 'show_meta' === $action ) {

			$user_meta = wp_fusion()->user->get_user_meta( $user_id );

			$message = '<pre>' . wpf_print_r( $user_meta, true ) . '</pre>';

		}

		echo '<div class="notice notice-success">';
		echo '<p>' . wp_kses_post( $message ) . '</p>';
		echo '</div>';

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
					<input type="checkbox" name="wpf_add_contact" id="wpf_add_contact" value="1" checked />
					<label for="wpf_add_contact"><?php printf( esc_html__( 'Add the user as a contact in %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?></label>
				</td>
			</tr>

		</table>

		<?php


	}

	/**
	 * Updates the contact record in the CRM when a profile is edited in the backend
	 *
	 * @access public
	 * @return void
	 */
	public function user_profile_update( $user_id ) {

		check_admin_referer( 'update-user_' . $user_id );

		// See if tags have manually been modified on the user edit screen.
		if ( ! empty( $_POST['wpf_tags_field_edited'] ) ) {

			do_action( 'wpf_admin_profile_tags_edited', $user_id );

			// Prevent it from running more than once on a profile update.
			unset( $_POST['wpf_tags_field_edited'] );

			if ( isset( $_POST[ WPF_TAGS_META_KEY ] ) ) {
				$posted_tags = array_map( 'sanitize_text_field', wp_unslash( $_POST[ WPF_TAGS_META_KEY ] ) );
			} else {
				$posted_tags = array();
			}

			$user_tags = wp_fusion()->user->get_tags( $user_id );

			// Apply new tags.
			$apply_tags = array();

			foreach ( $posted_tags as $tag ) {

				if ( ! in_array( $tag, $user_tags ) ) {
					$apply_tags[] = $tag;
				}
			}

			if ( ! empty( $apply_tags ) ) {

				wp_fusion()->user->apply_tags( $apply_tags, $user_id );
			}

			// Remove removed tags.
			$remove_tags = array();

			foreach ( $user_tags as $tag ) {

				if ( ! in_array( $tag, $posted_tags ) ) {
					$remove_tags[] = $tag;
				}
			}

			if ( ! empty( $remove_tags ) ) {
				wp_fusion()->user->remove_tags( $remove_tags, $user_id );
			}
		}

		// Email changes that have just been confirmed.
		if ( isset( $_GET['newuseremail'] ) ) {

			$user = get_userdata( $user_id );

			wp_fusion()->user->push_user_meta( $user_id, array( 'user_email' => $user->user_email ) );

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
	 * @access public
	 * @return array Registration data
	 */

	public function filter_form_fields( $post_data, $user_id ) {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return $post_data;
		}

		$screen = get_current_screen();

		if ( ! is_null( $screen ) && in_array( $screen->id, array( 'profile', 'user-edit', 'user-new', 'user' ) ) ) {

			if ( 'user' === $screen->id && ! isset( $post_data['wpf_add_contact'] ) ) {
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

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>

		<div id="wp-fusion-user-profile-settings">

		<h2><?php echo wpf_logo_svg(); ?> <?php esc_html_e( 'WP Fusion', 'wp-fusion-lite' ); ?></h2>

			<table class="form-table">

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
						<p class="description"><?php echo sprintf( __( 'If the contact ID or tags aren\'t in sync, click here to reset the local data and look up the contact again by email address in %s.', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ); ?></p>

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

		</div>

		<?php
	}
}
