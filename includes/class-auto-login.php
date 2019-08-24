<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Auto_Login {

	/**
	 * Auto login user
	 */

	public $auto_login_user;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.12
	 */

	public function __construct() {

		// Track URL session logins
		add_action( 'init', array( $this, 'start_auto_login' ), 1 );
		add_filter( 'wpf_end_auto_login', array( $this, 'maybe_end' ) );
		add_action( 'wp_logout', array( $this, 'end_auto_login' ) );
		add_action( 'wp_login', array( $this, 'end_auto_login' ) );
		add_action( 'set_logged_in_cookie', array( $this, 'end_auto_login' ) );

		// Session cleanup cron
		add_action( 'clear_auto_login_metadata', array( $this, 'clear_auto_login_metadata' ) );

	}


	/**
	 * Starts a session if contact ID is passed in URL
	 *
	 * @access public
	 * @return void
	 */

	public function start_auto_login( $contact_id = false ) {

		$alt_query_var = apply_filters( 'wpf_auto_login_query_var', false );

		if( $contact_id == false && isset( $_GET[ 'cid' ] ) ) {

			$contact_id = sanitize_text_field( $_GET[ 'cid' ] );

		} elseif( $contact_id == false && $alt_query_var != false && isset( $_GET[ $alt_query_var ] ) ) {

			$contact_id = sanitize_text_field( $_GET[ $alt_query_var ] );

		}

		$contact_id = apply_filters( 'wpf_auto_login_contact_id', $contact_id );

		if( empty( $contact_id ) && empty( $_COOKIE['wpf_contact'] ) ) {
			return;
		}

		if ( is_user_logged_in() || apply_filters( 'wpf_skip_auto_login', false ) ) {
			return;
		}

		// If regular auto login is disabled and a contact ID is present in the URL, then quit
		if( isset( $_GET['cid'] ) && wp_fusion()->settings->get( 'auto_login' ) == false ) {
			return;
		}

		if( ! empty( $_COOKIE['wpf_contact'] ) && apply_filters( 'wpf_end_auto_login', false ) ) {
			$this->end_auto_login();
			return;
		}

		if( !empty( $_COOKIE['wpf_contact'] ) ) {
			$contact_data = json_decode( stripslashes( $_COOKIE['wpf_contact'] ), true );
		}

		// If CID has changed, start a new session
		if( ! empty( $contact_data ) && !empty( $contact_id ) && $contact_id != $contact_data['contact_id'] ) {
			$this->end_auto_login();
			$contact_data = array();
		}

		// Do first time autologin
		if ( empty( $contact_data ) && isset( $contact_id ) ) {

			define('DOING_WPF_AUTO_LOGIN', true);

			$user_id = $this->create_temp_user( $contact_id );

			 if( is_wp_error( $user_id ) ) {
			 	return false;
			 }

			$this->auto_login_user = array(
				'contact_id'	=> $contact_id,
				'user_id'		=> $user_id
			);

			global $current_user;
			$current_user->ID = $user_id;

			// Hide admin bar
			add_filter( 'show_admin_bar', '__return_false' );

			do_action( 'wpf_started_auto_login', $user_id, $contact_id );

			return;

		}

		// Returning autologin
		if ( is_array( $contact_data ) ) {

			define('DOING_WPF_AUTO_LOGIN', true);

			// See if temp user still exists
			$contact_id = get_user_meta( $contact_data['user_id'], wp_fusion()->crm->slug . '_contact_id', true );

			if( empty( $contact_id ) || $contact_id != $contact_data['contact_id'] ) {
				 $user_id = $this->create_temp_user( $contact_data['contact_id'] );

				 if( is_wp_error( $user_id ) ) {
				 	return false;
				 }

				 $contact_data['user_id'] = $user_id;
			}

			$this->auto_login_user = $contact_data;

			global $current_user;
			$current_user->ID = $contact_data['user_id'];

			// Hide admin bar
			add_filter( 'show_admin_bar', '__return_false' );

			do_action( 'wpf_started_auto_login', $contact_data['user_id'], $contact_id );

		}

	}

	/**
	 * Allows temporarily bypassing auto login on certain pages
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_end( $end ) {

		if( isset( $_GET['wpf-end-auto-login'] ) ) {
			$end = true;
		}

		$request_uris = array(
			'login',
			'register',
			'checkout'
		);

		$request_uris = apply_filters( 'wpf_end_auto_login_request_uris', $request_uris );

		foreach(  $request_uris as $uri ) {

			if( strpos( $_SERVER['REQUEST_URI'], $uri ) !== false ) {
				$end = true;
			}

		}

		return $end;

	}

	/**
	 * Creates a temporary user for auto login sessions
	 *
	 * @access public
	 * @return int Temporary user ID
	 */

	public function create_temp_user( $contact_id ) {

		$user_tags = wp_fusion()->crm->get_tags( $contact_id );

		if( is_wp_error( $user_tags ) ) {
			return $user_tags;
		}

		// Set the random number based on the CID
		$user_id = rand( 100000000, 1000000000 );

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );
		update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		$contact_data = array(
			'contact_id'	=> $contact_id,
			'user_id'		=> $user_id
		);

		setcookie( 'wpf_contact', json_encode( $contact_data ), time() + DAY_IN_SECONDS * 180, COOKIEPATH, COOKIE_DOMAIN );

		// Load meta data
		wp_fusion()->user->pull_user_meta( $user_id );

		// Schedule cleanup after one day
		wp_schedule_single_event( time() + 86400, 'clear_auto_login_metadata', array( $user_id ) );

		return $user_id;

	}

	/**
	 * Ends session on user login or logout
	 *
	 * @access public
	 * @return void
	 */

	public function end_auto_login() {

		if ( ! empty( $_COOKIE['wpf_contact'] ) ) {

			$contact_data = json_decode( stripslashes( $_COOKIE['wpf_contact'] ), true );

			$this->clear_auto_login_metadata( $contact_data['user_id'] );
			$this->auto_login_user = false;

			setcookie( 'wpf_contact', false, time() - ( 15 * 60 ), COOKIEPATH, COOKIE_DOMAIN );

		}

	}

	/**
	 * Clear orphaned metadata for auto-login users
	 *
	 * @access public
	 * @return void
	 */

	public function clear_auto_login_metadata( $user_id ) {

		global $wpdb;
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d", $user_id ) );

		foreach ( $meta as $mid ) {
			delete_metadata_by_mid( 'user', $mid );
		}

	}

}