<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_User_Profile {

	/**
	 * Get things started
	 *
	 * @since 1.0
	 * @return void
	 */

	public function __construct() {

		// User profile display / edit
		add_action( 'show_user_profile', array( $this, 'user_profile' ), 5 );
		add_action( 'edit_user_profile', array( $this, 'user_profile' ), 5 );

		add_action( 'admin_notices', array( $this, 'profile_notices' ) );

		// AJAX
		add_action( 'wp_ajax_resync_contact', array( $this, 'resync_contact' ) );

		// Updates
		add_action( 'user_register', array( $this, 'user_profile_update' ), 5 );
		add_action( 'profile_update', array( $this, 'user_profile_update' ), 5 );

		// Filters for posted data from internal forms
		add_filter( 'wpf_user_register', array( $this, 'filter_form_fields' ), 10, 2 );
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

		if ( ! isset( $_GET['wpf_profile_action'] ) ) {
			return;
		}

		$user_id = intval( $_GET['user_id'] );

		// For debugging purposes
		if ( 'register' == $_GET['wpf_profile_action'] ) {

			$contact_id = wp_fusion()->user->user_register( $user_id, null, true );

			if ( $contact_id ) {

				$edit_url = wp_fusion()->user->get_contact_edit_url( $user_id );

				if ( false !== $edit_url ) {
					$contact_id = '<a href="' . $edit_url . '" target="_blank">' . $contact_id . '</a>';
				}

				$message = sprintf( __( '<strong>Success:</strong> User was added to %1$s with contact ID %2$s.' ), wp_fusion()->crm->name, $contact_id );

			} else {

				$message = sprintf( __( '<strong>Error:</strong> Unable to create contact in %1$s, see the %2$sactivity logs%3$s for more information.' ), wp_fusion()->crm->name, '<a href="' . admin_url( 'tools.php?page=wpf-settings-logs' ) . '">', '</a>' );

			}
		} elseif ( 'pull' == $_GET['wpf_profile_action'] ) {

			$user_meta = wp_fusion()->user->pull_user_meta( $user_id );

			$message = sprintf( __( '<strong>Success:</strong> Loaded metadata from %1$s:' ), wp_fusion()->crm->name );

			$message .= '<br /><pre>' . print_r( $user_meta, true ) . '</pre>';

		} elseif ( 'push' == $_GET['wpf_profile_action'] ) {

			wp_fusion()->user->push_user_meta( $user_id );

			$message = sprintf( __( '<strong>Success:</strong> Synced user meta to %1$s.' ), wp_fusion()->crm->name );

		} elseif ( 'show_meta' == $_GET['wpf_profile_action'] ) {

			$user_meta = wp_fusion()->user->get_user_meta( $user_id );

			$message = '<pre>' . print_r( $user_meta, true ) . '</pre>';

		}

		echo '<div class="notice notice-success">';
		echo '<p>' . $message . '</p>';
		echo '</div>';

	}

	/**
	 * Updates the contact record in the CRM when a profile is edited in the backend
	 *
	 * @access public
	 * @return void
	 */

	public function user_profile_update( $user_id ) {

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		global $pagenow;

		if ( $pagenow == 'profile.php' || $pagenow == 'user-edit.php' ) {

			// See if tags have manually been modified on the user edit screen
			if ( isset( $_POST['wpf_tags_field_edited'] ) && $_POST['wpf_tags_field_edited'] == 'true' ) {

				do_action( 'wpf_admin_profile_tags_edited', $user_id );

				// Prevent it from running more than once on a profile update
				unset( $_POST['wpf_tags_field_edited'] );

				if ( isset( $_POST[ wp_fusion()->crm->slug . '_tags' ] ) ) {
					$posted_tags = $_POST[ wp_fusion()->crm->slug . '_tags' ];
					unset( $_POST[ wp_fusion()->crm->slug . '_tags' ] );
				} else {
					$posted_tags = array();
				}

				$posted_tags = stripslashes_deep( $posted_tags );

				$posted_tags = array_map( 'sanitize_text_field', $posted_tags );

				$user_tags = wp_fusion()->user->get_tags( $user_id );

				// Apply new tags
				$apply_tags = array();
				foreach ( $posted_tags as $tag ) {

					if ( ! in_array( $tag, $user_tags ) ) {
						$apply_tags[] = $tag;
					}
				}

				if ( ! empty( $apply_tags ) ) {

					wp_fusion()->user->apply_tags( $apply_tags, $user_id );
				}

				// Remove removed tags
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

			// Email changes that have just been confirmed
			if ( isset( $_GET['newuseremail'] ) ) {

				$user = get_userdata( $user_id );

				wp_fusion()->user->push_user_meta( $user_id, array( 'user_email' => $user->user_email ) );

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

		$user_id = intval( $_POST['user_id'] );

		// Force reset contact ID and search for new match
		$contact_id = wp_fusion()->user->get_contact_id( $user_id, true );

		// If no contact found
		if ( empty( $contact_id ) ) {
			wp_send_json_error();
		}

		// Force reset tags and search for new tags
		$user_tags = wp_fusion()->user->get_tags( $user_id, true, false );

		$response = array(
			'contact_id' => $contact_id,
			'user_tags'  => $user_tags,
		);

		do_action( 'wpf_resync_contact', $user_id );

		// Return the result to the script and die
		echo json_encode( $response );

		wp_die();

	}


	/**
	 * Filters registration data before sending to the CRM (internal add / edit fields)
	 *
	 * @access public
	 * @return array Registration data
	 */

	public function filter_form_fields( $post_data, $user_id ) {

		global $pagenow;

		if ( $pagenow == 'profile.php' || $pagenow == 'user-edit.php' || $pagenow == 'user-new.php' ) {

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

			// Merge in some wp_users stuff
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
		<h3><?php _e( 'WP Fusion', 'wp-fusion-lite' ); ?></h3>

		<table class="form-table">

			<?php do_action( 'wpf_user_profile_before_table_rows', $user ); ?>

			<tr>
				<th><label for="contact_id"><?php printf( __( '%s Contact ID' ), wp_fusion()->crm->name ); ?></label></th>
				<td id="contact-id">
					<?php if ( $contact_id = wp_fusion()->user->get_contact_id( $user->ID ) ) : ?>

						<?php if ( is_wp_error( $contact_id ) ) : ?>

							<strong>Error:</strong> <?php var_dump( $contact_id ); ?>

						<?php else : ?>

							<?php echo $contact_id; ?>

							<?php $edit_url = wp_fusion()->user->get_contact_edit_url( $user->ID ); ?>

							<?php if ( false !== $edit_url ) : ?>

								- <a href="<?php echo $edit_url ?>" target="_blank"><?php printf( __( 'View in %s', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?> &rarr;</a>

							<?php endif; ?>

							<?php do_action( 'wpf_user_profile_after_contact_id', $user->ID ); ?>

						<?php endif; ?>

					<?php else : ?>

						<?php printf( __( 'No %s contact record found.', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?>

						<a href="<?php echo admin_url( 'user-edit.php' ); ?>?user_id=<?php echo $user->ID; ?>&wpf_profile_action=register"><?php _e( 'Create new contact', 'wp-fusion-lite' ); ?>.</a>

					<?php endif; ?>
				</td>
			</tr>
			<?php if ( wp_fusion()->user->get_contact_id( $user->ID ) ) : ?>

				<tr id="wpf-tags-row">
					<th><label for="wpf_tags"><?php echo sprintf( __( '%s Tags', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?></label></th>
					<td id="wpf-tags-td">

						<?php

						$args = array(
							'setting'   => wp_fusion()->user->get_tags( $user->ID ),
							'meta_name' => wp_fusion()->crm->slug . '_tags',
							'disabled'  => true,
						);

						wpf_render_tag_multiselect( $args );

						?>

						<input type="hidden" id="wpf-tags-field-edited" name="wpf_tags_field_edited" value="false" />
						<p class="description"><?php _e( 'These tags are currently applied to the user in', 'wp-fusion-lite' ); ?> <?php echo wp_fusion()->crm->name; ?> <a id="wpf-profile-edit-tags" href="#"><?php _e( 'Edit Tags', 'wp-fusion-lite' ); ?></a></p>

					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="resync_contact"><?php _e( 'Resync Tags', 'wp-fusion-lite' ); ?></label></th>
				<td>

					<a id="resync-contact" href="#" class="button button-default" data-user_id="<?php echo $user->ID; ?>"><?php _e( 'Resync Tags', 'wp-fusion-lite' ); ?></a>
					<p class="description"><?php echo sprintf( __( 'If the contact ID or tags aren\'t in sync, click here to reset the local data and load from the %s contact record.', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?></p>

				</td>
			</tr>

			<tr>
				<th><label for="resync_contact"><?php _e( 'Additional Actions', 'wp-fusion-lite' ); ?></label></th>
				<td>

					<a href="<?php echo admin_url( 'user-edit.php' ); ?>?user_id=<?php echo $user->ID; ?>&wpf_profile_action=push"><?php _e( 'Push User Meta', 'wp-fusion-lite' ); ?></a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php printf( __( 'Extracts any enabled meta fields from the database and syncs them to %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?>"></span> | 

					<a href="<?php echo admin_url( 'user-edit.php' ); ?>?user_id=<?php echo $user->ID; ?>&wpf_profile_action=pull"><?php _e( 'Pull User Meta', 'wp-fusion-lite' ); ?></a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php printf( __( 'Loads any enabled meta fields from %s and saves them to the user record.', 'wp-fusion-lite' ), wp_fusion()->crm->name ); ?>"></span> | 

					<a href="<?php echo admin_url( 'user-edit.php' ); ?>?user_id=<?php echo $user->ID; ?>&wpf_profile_action=show_meta"><?php _e( 'Show User Meta', 'wp-fusion-lite' ); ?></a>

					<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php _e( 'Displays all metadata found in the database for this user.', 'wp-fusion-lite' ); ?>"></span> 

				</td>
			</tr>

			<?php do_action( 'wpf_user_profile_after_table_rows', $user ); ?>

		</table>
		<?php
	}
}
