<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_AJAX {

	public function __construct() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_link_click_script' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_apply_tags', array( $this, 'apply_tags' ) );
		add_action( 'wp_ajax_nopriv_apply_tags', array( $this, 'apply_tags' ) );

		add_action( 'wp_ajax_remove_tags', array( $this, 'remove_tags' ) );
		add_action( 'wp_ajax_nopriv_remove_tags', array( $this, 'remove_tags' ) );

		add_action( 'wp_ajax_update_user', array( $this, 'update_user' ) );

	}

	/**
	 * Applies tags to a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags() {

		if ( ! isset( $_POST['tags'] ) ) {
			wp_die();
		}

		if ( is_array( $_POST['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) );
		} else {
			$tags = explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) );
		}

		$tags_to_apply = array();

		foreach ( $tags as $tag ) {

			$tag_id = wp_fusion()->user->get_tag_id( $tag );

			if ( false === $tag_id ) {

				wpf_log( 'notice', wpf_get_current_user_id(), 'Unable to determine tag ID from tag with name <strong>' . $tag . '</strong>. Tag will not be applied. Please make sure this tag exists in ' . wp_fusion()->crm->name . '. If you\'ve just added a new tag, click Resync Available Tags in the WP Fusion settings.' );
				continue;

			}

			$tags_to_apply[] = $tag_id;
		}

		if ( ! empty( $tags_to_apply ) ) {

			wp_fusion()->user->apply_tags( $tags_to_apply );

		}

		wp_die();

	}

	/**
	 * Removes tags from a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function remove_tags() {

		if ( ! isset( $_POST['tags'] ) ) {
			wp_die();
		}

		if ( is_array( $_POST['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) );
		} else {
			$tags = explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) );
		}

		$tags_to_remove = array();

		foreach ( $tags as $tag ) {

			$tag_id = wp_fusion()->user->get_tag_id( $tag );

			if ( false === $tag_id ) {

				wpf_log( 'notice', wpf_get_current_user_id(), 'Unable to determine tag ID from tag with name <strong>' . $tag . '</strong>. Tag will not be applied.' );
				continue;

			}

			$tags_to_remove[] = $tag_id;
		}

		if ( ! empty( $tags_to_remove ) ) {

			wp_fusion()->user->remove_tags( $tags_to_remove );

		}

		die();

	}

	/**
	 * Updates contact via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function update_user() {

		if ( ! isset( $_POST['data'] ) ) {
			wp_die();
		}

		$update_data = json_decode( wp_unslash( $_POST['data'] ), true );
		$user_id     = wpf_get_current_user_id();

		if ( is_array( $update_data ) ) {

			$update_data = array_map( 'sanitize_text_field', $update_data );

			// Figure out how we're doing the update.

			$contact_fields = wpf_get_option( 'contact_fields', array() );
			$crm_fields     = wp_fusion()->settings->get_crm_fields_flat();

			$user_meta_data = array();
			$crm_data       = array();

			foreach ( $update_data as $key => $value ) {

				if ( isset( $contact_fields[ $key ] ) && ! empty( $contact_fields[ $key ]['active'] ) ) {

					$user_meta_data[ $key ] = $value;

				} else {

					foreach ( $crm_fields as $field => $label ) {

						if ( $key === $field || $key === $label ) {

							$crm_data[ $field ] = $value;

						}
					}
				}
			}

			if ( ! empty( $user_meta_data ) ) {
				wp_fusion()->user->push_user_meta( $user_id, $user_meta_data );
			}

			if ( ! empty( $crm_data ) ) {
				$contact_id = wp_fusion()->user->get_contact_id( $user_id );
				wp_fusion()->crm->update_contact( $contact_id, $crm_data, false );
			}
		}

		wp_die();

	}

	/**
	 * Enqueues link click tracking scripts if option is enabled
	 *
	 * @access public
	 * @return void
	 */
	public function enqueue_link_click_script() {

		if ( ! wp_script_is( 'wpf-apply-tags' ) && wpf_get_option( 'link_click_tracking' ) ) {

			wp_enqueue_script( 'wpf-apply-tags', WPF_DIR_URL . 'assets/js/wpf-apply-tags.js', array( 'jquery' ), WP_FUSION_VERSION, true );

			$localize_data = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			);

			wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', $localize_data );

		}

	}

}
