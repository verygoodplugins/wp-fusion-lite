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
		add_action( 'show_user_profile', array( $this, 'user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'user_profile' ) );

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
	 * Updates the contact record in the CRM when a profile is edited in the backend
	 *
	 * @access public
	 * @return void
	 */

	public function user_profile_update( $user_id ) {

		global $pagenow;

		// See if tags have manually been modified on the user edit screen

		if ( ($pagenow == 'profile.php' || $pagenow == 'user-edit.php') && isset( $_POST[ 'wpf_tags_field_edited' ] ) && $_POST[ 'wpf_tags_field_edited' ] == 'true' ) {

			// Prevent it from running more than once on a profile update
			unset( $_POST[ 'wpf_tags_field_edited'] );

			if(isset( $_POST[ wp_fusion()->crm->slug . '_tags' ] )) {
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

	}


	/**
	 * Resynchronize local user ID with IS contact record
	 *
	 * @access public
	 * @return mixed
	 */

	public function resync_contact() {

		$user_id = intval($_POST['user_id']);

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
			'user_tags'  => $user_tags
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

			if ( isset( $post_data['email'] ) ) {

				$post_data['user_email'] = $post_data['email'];
				unset( $post_data['email'] );

				if( isset( $post_data['url'] ) ) {
					$post_data['user_url'] = $post_data['url'];
					unset( $post_data['url'] );
				}

				if ( isset( $post_data['pass1-text'] ) ) {
					$post_data['user_pass'] = $post_data['pass1-text'];
					unset( $post_data['pass1-text'] );
				}

			}

			if ( isset( $post_data['user_password'] ) ) {

				$post_data['user_pass'] = $post_data['user_password'];
				unset( $post_data['user_password'] );

			}

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

		if( ! current_user_can('manage_options') ) {
			return;
		}

		// For debugging purposes

		if ( isset( $_GET['wpf_register'] ) ) {

			wp_fusion()->user->user_register( $user->ID, null, true );

		} elseif ( isset( $_GET['wpf_pull'] ) ) {

			wp_fusion()->user->pull_user_meta( $user->ID );

		} elseif ( isset( $_GET['wpf_push'] ) ) {

			wp_fusion()->user->push_user_meta( $user->ID );

		} elseif ( isset( $_GET['wpf_show_meta'] ) ) {

			$user_meta = get_user_meta( $user->ID );

			echo '<pre>';
			echo print_r( $user_meta, true );
			echo '</pre>';

		}

		?>
		<h3>WP Fusion</h3>

		<table class="form-table">

			<?php do_action( 'wpf_user_profile_before_table_rows', $user ); ?>

			<tr>
				<th><label for="contact_id"><?php echo wp_fusion()->crm->name ?> Contact ID</label></th>
				<td id="contact-id">
					<?php if ( $contact_id = wp_fusion()->user->get_contact_id( $user->ID ) ) : ?>

						<?php if( is_wp_error( $contact_id ) ) : ?>

							<strong>Error:</strong> <?php var_dump($contact_id); ?>

						<?php else : ?>

							<?php echo $contact_id; ?>

						<?php endif; ?>

					<?php else : ?>

						No <?php echo wp_fusion()->crm->name ?> contact record found.

					<?php endif; ?>
				</td>
			</tr>
			<?php if ( wp_fusion()->user->get_contact_id( $user->ID ) ) : ?>
				
				<tr id="wpf-tags-row">
					<th><label for="wpf_tags"><?php echo sprintf( __('%s Tags', 'wp-fusion'), wp_fusion()->crm->name ); ?></label></th>
					<td id="wpf-tags-td">
					
						<?php 

						$args = array(
							'setting' 		=> wp_fusion()->user->get_tags( $user->ID ),
							'meta_name'		=> wp_fusion()->crm->slug . '_tags',
							'disabled'		=> true,
						);

						wpf_render_tag_multiselect( $args );

						?>

						<input type="hidden" id="wpf-tags-field-edited" name="wpf_tags_field_edited" value="false" />
						<p class="description"><?php _e( 'These tags are currently applied to the user in', 'wp-fusion' ) ?> <?php echo wp_fusion()->crm->name ?> <a id="wpf-profile-edit-tags" href="#"><?php _e('Edit Tags', 'wp-fusion'); ?></a></p>

					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="resync_contact"><?php _e( 'Resync Tags', 'wp-fusion' ) ?></label></th>
				<td>

					<a id="resync-contact" href="#" class="button button-default" data-user_id="<?php echo $user->ID ?>"><?php _e( 'Resync Tags', 'wp-fusion' ) ?></a>
					<p class="description"><?php echo sprintf( __( 'If the contact ID or tags aren\'t in sync, click here to reset the local data and load from the %s contact record.', 'wp-fusion' ), wp_fusion()->crm->name ); ?></p>

				</td>
			</tr>

			<?php do_action( 'wpf_user_profile_after_table_rows', $user ); ?>

		</table>
		<?php
	}
}