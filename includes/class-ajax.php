<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_AJAX {

	public function __construct() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_link_click_script' ) );

		// AJAX handlers
		add_action( 'wp_ajax_apply_tags', array( $this, 'apply_tags' ) );
		add_action( 'wp_ajax_nopriv_apply_tags', array( $this, 'apply_tags' ) );

		add_action( 'wp_ajax_remove_tags', array( $this, 'remove_tags' ) );
		add_action( 'wp_ajax_nopriv_remove_tags', array( $this, 'remove_tags' ) );

		add_action( 'wp_ajax_update_user', array( $this, 'update_user' ) );
		//add_action( 'wp_ajax_nopriv_update_user', array( $this, 'update_user' ) );

	}

	/**
	 * Applies tags to a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags() {

		$tags = $_POST['tags'];

		if( ! is_array( $tags ) ) {
			$tags = explode(',', $tags);
		}

		$tags = array_map('sanitize_text_field', $tags);

		$tags_to_apply = array();

		foreach ( $tags as $tag ) {

			$tag_id = wp_fusion()->user->get_tag_id( $tag );

			if ( false === $tag_id ) {

				wpf_log( 'notice', $user_id, 'Unable to determine tag ID from tag with name <strong>' . $tag . '</strong>. Tag will not be applied.' );
				continue;

			}

			$tags_to_apply[] = $tag_id;
		}

		if ( ! empty( $tags_to_apply ) ) {

			wp_fusion()->user->apply_tags( $tags_to_apply );

		}

		die();

	}

	/**
	 * Removes tags from a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function remove_tags() {

		$tags = $_POST['tags'];

		if( ! is_array( $tags ) ) {
			$tags = explode(',', $tags);
		}

		$tags = array_map('sanitize_text_field', $tags);

		$tags_to_remove = array();

		foreach ( $tags as $tag ) {

			$tag_id = wp_fusion()->user->get_tag_id( $tag );

			if ( false === $tag_id ) {

				wpf_log( 'notice', $user_id, 'Unable to determine tag ID from tag with name <strong>' . $tag . '</strong>. Tag will not be applied.' );
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

		$update_data = json_decode( stripslashes( $_POST['data'] ), true );

		$user_id = wpf_get_current_user_id();

		if ( is_array( $update_data ) ) {

			$update_data = array_map( 'sanitize_text_field', $update_data );

			// Figure out how we're doing the update

			$contact_fields = wp_fusion()->settings->get( 'contact_fields', array() );
			$crm_fields     = wp_fusion()->settings->get( 'crm_fields', array() );

			// CRMs with field groupings

			if( is_array( reset( $crm_fields ) ) ) {

				$crm_fields_tmp = array();

				foreach( $crm_fields as $field_group ) {

					foreach( $field_group as $field => $label ) {

						$crm_fields_tmp[ $field ] = $label;

					}

				}

				$crm_fields = $crm_fields_tmp;

			}

			$user_meta_data = array();
			$crm_data = array();

			foreach( $update_data as $key => $value ) {

				if( isset( $contact_fields[ $key ] ) && $contact_fields[ $key ]['active'] == true ) {

					$user_meta_data[ $key ] = $value;

				} else {

					foreach( $crm_fields as $field => $label ) {

						if( $key == $field || $key == $label ) {

							$crm_data[ $field ] = $value;

						}

					}

				}

			}

			if( ! empty( $user_meta_data ) ) {
				wp_fusion()->user->push_user_meta( $user_id, $user_meta_data );
			}

			if( ! empty( $crm_data ) ) {
				$contact_id = wp_fusion()->user->get_contact_id( $user_id );
				wp_fusion()->crm->update_contact( $contact_id, $crm_data, false );
			}

		}

		die();

	}

	/**
	 * Enqueues link click tracking scripts if option is enabled
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_link_click_script() {

		if ( ! wp_script_is( 'wpf-apply-tags' ) && wp_fusion()->settings->get( 'link_click_tracking' ) == true ) {

			wp_enqueue_script( 'wpf-apply-tags', WPF_DIR_URL . 'assets/js/wpf-apply-tags.js', array( 'jquery' ), WP_FUSION_VERSION, true );
			wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

		}

	}

}