<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_AJAX {

	public function __construct() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_async_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_link_click_script' ) );

		// AJAX handlers
		add_action( 'wp_ajax_apply_tags', array( $this, 'apply_tags' ) );
		add_action( 'wp_ajax_nopriv_apply_tags', array( $this, 'apply_tags' ) );

		add_action( 'wp_ajax_update_user', array( $this, 'update_user' ) );
		add_action( 'wp_ajax_nopriv_update_user', array( $this, 'update_user' ) );

		add_action( 'wp_ajax_wpf_async', array( $this, 'process_async' ) );
		add_action( 'wp_ajax_nopriv_wpf_async', array( $this, 'process_async' ) );

	}

	/**
	 * Applies tags to a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags() {

		$tags = $_POST['tags'];

		if( isset( $_POST['user_id'] ) ) {
			$user_id = $_POST['user_id'];
		} else {
			$user_id = get_current_user_id();
		}

		if( ! is_array( $tags ) ) {
			$tags = explode(',', $tags);
		}

		wp_fusion()->user->apply_tags( $tags, $user_id );

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

		if( isset( $_POST['user_id'] ) ) {
			$user_id = $_POST['user_id'];
		} else {
			$user_id = get_current_user_id();
		}

		if( is_array( $update_data ) ) {

			// Figure out how we're doing the update

			$contact_fields = wp_fusion()->settings->get( 'contact_fields', array() );
			$crm_fields = wp_fusion()->settings->get( 'crm_fields', array() );

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

			wp_enqueue_script( 'wpf-apply-tags', WPF_DIR_URL . '/assets/js/wpf-apply-tags.js', array( 'jquery' ), WP_FUSION_VERSION, true );
			wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

		}

	}

	/**
	 * Adds an asynchronous callback to the async queue
	 *
	 * @access public
	 * @return void
	 */

	public function async_add( $id, $args ) {

		$wpf_async = get_option( 'wpf_async', array() );

		$wpf_async[ $id ] = $args;

		update_option( 'wpf_async', $wpf_async );

	}

	/**
	 * Enqueues async scripts if async actions detected
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_async_script() {

		if ( ! get_option( 'wpf_async' ) ) {
			return;
		}

		$wpf_async = get_option( 'wpf_async' );

		wp_register_script( 'wpf-async', WPF_DIR_URL . '/assets/js/wpf-async.js', array( 'jquery' ), WP_FUSION_VERSION, false );

		wp_localize_script( 'wpf-async', 'wpf_async', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'hooks'   => $wpf_async
		) );

		wp_enqueue_script( 'wpf-async' );

	}

	/**
	 * Processes async actions
	 *
	 * @access public
	 * @return void
	 */

	public function process_async() {

		$callback = $_POST['data'];
		$index = $_POST['index'];

		// Add "doing async" override
		$callback['args'][] = true;

		$class = new $callback['class'];
		call_user_func_array( array( $class, $callback['function'] ), $callback['args'] );

		// Clean up task if it's been processed
		$wpf_async = get_option( 'wpf_async' );
		unset( $wpf_async[$index] );

		if( empty( $wpf_async ) ) {
			delete_option( 'wpf_async' );
		} else {
			update_option( 'wpf_async', $wpf_async );
		}

		die();

	}

}