<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Auto_Login {

	/**
	 * Auto login user
	 */

	public $auto_login_user = array();

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.12
	 */

	public function __construct() {

		// Track URL session logins
		add_action( 'init', array( $this, 'start_auto_login' ), 1 );
		add_filter( 'wpf_end_auto_login', array( $this, 'maybe_end' ), 10, 2 );
		add_filter( 'wpf_skip_auto_login', array( $this, 'maybe_skip' ), 10, 2 );

		// add_action( 'wp_head', array( $this, 'hide_auto_login_parameter' ) );

		// Session cleanup cron
		add_action( 'clear_auto_login_metadata', array( $this, 'clear_auto_login_metadata' ) );

		// End the session when someone logs in

		add_action( 'wp_logout', array( $this, 'end_auto_login' ), 1 );
		//add_action( 'wp_login', array( $this, 'end_auto_login' ), 1 ); // We don't want to run on wp_login because if someone has just logged in this will end the session
		add_action( 'wp_authenticate', array( $this, 'end_auto_login' ), 1 );

		add_action( 'wpf_get_tags_start', array( $this, 'unhook_tags_modified' ), 1 );

		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

		add_action( 'wp_head', array( $this, 'debug_mode' ), 1 );

		add_action( 'wp_head', array( $this, 'maybe_doing_it_wrong' ), 100 );

	}

	/**
	 * Gets contact ID from URL
	 *
	 * @access public
	 * @return string Contact ID
	 */

	public function get_contact_id_from_url() {

		$contact_id = false;

		$alt_query_var = apply_filters( 'wpf_auto_login_query_var', false );

		if ( isset( $_GET['cid'] ) ) {

			$contact_id = sanitize_text_field( $_GET['cid'] );

		} elseif ( $contact_id == false && $alt_query_var != false && isset( $_GET[ $alt_query_var ] ) ) {

			$contact_id = sanitize_text_field( $_GET[ $alt_query_var ] );

		}

		$contact_id = apply_filters( 'wpf_auto_login_contact_id', $contact_id );

		return $contact_id;

	}


	/**
	 * Hides the ?cid= login parameter from the URL.
	 *
	 * @since 3.36.5
	 *
	 * @return string Javascript
	 */

	public function hide_auto_login_parameter() {

		if ( doing_wpf_auto_login() && isset( $_GET['cid'] ) ) {
			echo "
			<!-- WP Fusion auto login -->
			<script>
			if( typeof window.history.replaceState == 'function') {
				const url = new URL(location);
				url.searchParams.delete('cid');
				history.replaceState(null, null, url)
			}
			</script>
			<!-- END WP Fusion auto login -->";
		}

	}

	/**
	 * Starts a session if contact ID is passed in URL
	 *
	 * @access public
	 * @return void
	 */

	public function start_auto_login( $contact_id = false ) {

		if ( wpf_is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		if ( false == $contact_id && false == wp_fusion()->settings->get( 'auto_login' ) && false == wp_fusion()->settings->get( 'auto_login_forms' ) ) {
			return;
		}

		$contact_data = array();

		// Try finding a contact ID in the URL
		if ( false == $contact_id ) {
			$contact_id = $this->get_contact_id_from_url();
		}

		if ( empty( $contact_id ) && empty( $_COOKIE['wpf_contact'] ) ) {
			return;
		}

		if ( ! empty( $_COOKIE['wpf_contact'] ) ) {
			$contact_data = json_decode( stripslashes( $_COOKIE['wpf_contact'] ), true );
		}

		// Allow permanently ending the session
		if ( true === apply_filters( 'wpf_end_auto_login', false, $contact_data ) ) {
			$this->end_auto_login();
			return;
		}

		// If CID has changed, start a new session
		if ( ! empty( $contact_data ) && ! empty( $contact_id ) && $contact_id != $contact_data['contact_id'] ) {
			$this->end_auto_login();
			$contact_data = array();
		}

		if ( empty( $contact_data ) && isset( $contact_id ) ) {

			// Do first time autologin

			$user_id = $this->create_temp_user( $contact_id );

			if ( is_wp_error( $user_id ) ) {
				return false;
			}

			$contact_data = array(
				'contact_id' => $contact_id,
				'user_id'    => $user_id,
			);

		} elseif ( isset( $contact_data['user_id'] ) ) {

			// If data already exists, make sure the user hasn't expired

			$contact_id_from_db = get_user_meta( $contact_data['user_id'], wp_fusion()->crm->slug . '_contact_id', true );

			if ( empty( $contact_id_from_db ) || $contact_id_from_db != $contact_data['contact_id'] ) {

				$user_id = $this->create_temp_user( $contact_data['contact_id'] );

				if ( is_wp_error( $user_id ) ) {
					return false;
				}

				$contact_data['user_id'] = $user_id;

			} elseif ( false !== $contact_id ) {

				// If the temp user already exists but ?cid= is in the URL, update their tags anyway

				wp_fusion()->user->get_tags( $contact_data['user_id'], true, false );

			}
		}

		$this->auto_login_user = $contact_data;

		// Allow temporarily skipping the session on a single page
		if ( false !== $contact_data && true === apply_filters( 'wpf_skip_auto_login', false, $contact_data ) ) {
			return;
		}

		// Get the temporary user object

		$user = wpf_get_current_user();

		// Maybe set the $current_user global

		if ( true == wp_fusion()->settings->get( 'auto_login_current_user' ) ) {
			global $current_user;
			$current_user = $user;
		}

		// Set the user in the cache

		wp_cache_set( $contact_data['user_id'], $user, 'users', DAY_IN_SECONDS );

		// Hide admin bar
		add_filter( 'show_admin_bar', '__return_false' );

		// Disable comments (removed in v3.37.27)
		// add_filter( 'comments_open', array( wp_fusion()->access, 'turn_off_comments' ), 10, 2 );

		add_filter( 'wp_get_current_commenter', array( $this, 'get_current_commenter' ) );

		do_action( 'wpf_started_auto_login', $contact_data['user_id'], $contact_id );

		return $contact_data['user_id'];

	}

	/**
	 * Permanently ends the auto login session in certain scenarios
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_end( $end, $contact_data ) {

		if ( isset( $_GET['wpf-end-auto-login'] ) ) {
			return true;
		}

		$request_uris = array(
			'login',
			'register',
			'order-received',
			'purchase-confirmation',
		);

		$request_uris = apply_filters( 'wpf_end_auto_login_request_uris', $request_uris );

		foreach ( $request_uris as $uri ) {

			if ( strpos( $_SERVER['REQUEST_URI'], $uri ) !== false ) {
				$end = true;
			}
		}

		// Check transient

		if ( isset( $contact_data['contact_id'] ) ) {

			$transient = get_option( 'wpf_end_auto_login_' . $contact_data['contact_id'] );

			if ( $transient ) {

				$end = true;
				delete_option( 'wpf_end_auto_login_' . $contact_data['contact_id'] );

			}
		}

		return $end;

	}

	/**
	 * Skips the auto login session in certain scenarios
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_skip( $skip, $contact_data ) {

		$request_uris = apply_filters( 'wpf_skip_auto_login_request_uris', array() );

		foreach ( $request_uris as $uri ) {

			if ( strpos( $_SERVER['REQUEST_URI'], $uri ) !== false ) {
				$skip = true;
			}
		}

		return $skip;

	}

	/**
	 * Creates a temporary user for auto login sessions
	 *
	 * @access public
	 * @return int Temporary user ID
	 */

	public function create_temp_user( $contact_id ) {

		$user_tags = wp_fusion()->crm->get_tags( $contact_id );

		if ( is_wp_error( $user_tags ) ) {
			return $user_tags;
		}

		// Set the random number based on the CID
		$user_id = rand( 100000000, 1000000000 );

		update_user_meta( $user_id, wp_fusion()->crm->slug . '_tags', $user_tags );
		update_user_meta( $user_id, wp_fusion()->crm->slug . '_contact_id', $contact_id );

		wpf_log( 'info', $user_id, 'Starting auto-login session for contact ID ' . $contact_id . ' with tags:', array( 'tag_array' => $user_tags ) );

		// Allow other integrations to quickly access the auto login user ID

		$this->auto_login_user['user_id'] = $user_id;

		// Load meta data
		$user = wp_fusion()->user->pull_user_meta( $user_id );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		update_user_meta( $user_id, 'user_email', $user['user_email'] );

		$contact_data = array(
			'contact_id' => $contact_id,
			'user_id'    => $user_id,
		);

		$cookie_expiration = apply_filters( 'wpf_auto_login_cookie_expiration', DAY_IN_SECONDS * 180 );

		setcookie( 'wpf_contact', json_encode( $contact_data ), time() + $cookie_expiration, COOKIEPATH, COOKIE_DOMAIN );

		// Schedule cleanup after one day
		wp_schedule_single_event( time() + 86400, 'clear_auto_login_metadata', array( $user_id ) );

		return $user_id;

	}

	/**
	 * Sets the current commenter based on the auto-login user data.
	 *
	 * @since  3.37.27
	 *
	 * @param  array $comment_author_data The comment author data.
	 * @return array The comment author data.
	 */
	public function get_current_commenter( $comment_author_data ) {

		if ( empty( array_filter( $comment_author_data ) ) ) {
			$comment_author_data = array(
				'comment_author'       => get_user_meta( $this->auto_login_user['user_id'], 'first_name', true ),
				'comment_author_email' => get_user_meta( $this->auto_login_user['user_id'], 'user_email', true ),
				'comment_author_url'   => get_user_meta( $this->auto_login_user['user_id'], 'user_url', true ),
			);
		}

		return $comment_author_data;

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

			if ( ! headers_sent() ) {

				// Clear the cookie if headers haven't been sent yet
				setcookie( 'wpf_contact', false, time() - ( 15 * 60 ), COOKIEPATH, COOKIE_DOMAIN );

				wp_destroy_current_session();
				wp_clear_auth_cookie();

			} elseif ( wpf_is_user_logged_in() ) {

				// If headers have been sent, set a transient to clear the cookie on next load (since 3.36.1 we'll use update_option instead of set_transient)
				update_option( 'wpf_end_auto_login_' . $contact_data['contact_id'], true );

			}

		}

	}

	/**
	 * If we're in an auto-login session, let's un-hook any automated enrollments that might be tied to tags being modified
	 *
	 * @access public
	 * @return void
	 */

	public function unhook_tags_modified( $user_id ) {

		if ( doing_wpf_auto_login() || $this->get_contact_id_from_url() ) {

			remove_all_actions( 'wpf_tags_modified', 10 );
			remove_all_actions( 'wpf_tags_applied', 10 );
			remove_all_actions( 'wpf_tags_removed', 10 );
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

		wp_cache_delete( $user_id, 'users' );

	}

	/**
	 * Adds auto-login settings to the WPF settings page.
	 *
	 * @since  3.37.12
	 *
	 * @param  array $settings The settings.
	 * @param  array $options  The options.
	 * @return array The settings.
	 */
	public function register_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['auto_login_header'] = array(
			'title'   => __( 'Auto Login / Tracking Links', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$new_settings['auto_login'] = array(
			'title'   => __( 'Allow URL Login', 'wp-fusion-lite' ),
			'desc'    => __( 'Track user activity and unlock content by passing a Contact ID in a URL. See <a href="https://wpfusion.com/documentation/tutorials/auto-login-links/" target="_blank">this tutorial</a> for more info.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$new_settings['auto_login_forms'] = array(
			'title'   => __( 'Form Auto Login', 'wp-fusion-lite' ),
			'desc'    => __( 'Start an auto-login session whenever a visitor submits a form configured with WP Fusion.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$new_settings['auto_login_thrivecart'] = array(
			'title'   => __( 'ThriveCart Auto Login', 'wp-fusion-lite' ),
			'desc'    => __( 'Automatically log in new users with a ThriveCart success URL. See <a href="https://wpfusion.com/documentation/tutorials/thrivecart/" target="_blank">this tutorial</a> for more info.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$new_settings['auto_login_current_user'] = array(
			'title'   => __( 'Set Current User', 'wp-fusion-lite' ),
			'desc'    => __( 'Sets the <code>$current_user</code> global for the auto-login user. Makes auto-login work better with form plugins, but may cause other plugins to crash.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$new_settings['auto_login_debug_mode'] = array(
			'title'   => __( 'Debug Mode', 'wp-fusion-lite' ),
			'desc'    => __( 'Output information about the current auto-login session to the HTML comments in the header of your site.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings = wp_fusion()->settings->insert_setting_before( 'system_header', $settings, $new_settings );

		return $settings;

	}



	/**
	 * Debug mode output.
	 *
	 * @since 3.37.12
	 */
	public function debug_mode() {

		if ( ! wp_fusion()->settings->get( 'auto_login_debug_mode' ) ) {
			return;
		}

		echo '<!-- WP FUSION - AUTO LOGIN DEBUG INFO:' . PHP_EOL . PHP_EOL;

		// URL login enabled?

		echo '* ' . __( 'Auto login enabled?' ) . ' ';

		if ( ! wp_fusion()->settings->get( 'auto_login' ) ) {
			echo __( 'No' ) . ' ❌';
		} else {
			echo __( 'Yes' ) . ' ✅';
		}

		echo PHP_EOL;

		// Form login enabled?

		echo '* ' . __( 'Form auto login enabled?' ) . ' ';

		if ( ! wp_fusion()->settings->get( 'auto_login_forms' ) ) {
			echo __( 'No' ) . ' ❌';
		} else {
			echo __( 'Yes' ) . ' ✅';
		}

		echo PHP_EOL;

		// Set current user enabled?

		echo '* ' . __( 'Set Current User enabled?' ) . ' ';

		if ( ! wp_fusion()->settings->get( 'auto_login_current_user' ) ) {
			echo __( 'No' ) . ' ❌';
		} else {
			echo __( 'Yes' ) . ' ✅';
		}

		echo PHP_EOL;

		// URL parameter

		echo '* ' . __( 'URL parameter set?' ) . ' ';

		$contact_id = $this->get_contact_id_from_url();

		if ( false === $contact_id ) {
			echo __( 'No' );
		} else {
			echo __( 'Yes' ) . ' - Contact ID ' . $contact_id . ' ✅';
		}

		echo PHP_EOL;

		// Auto-login cookie

		echo '* ' . __( 'wpf_contact cookie set?' ) . ' ';

		if ( empty( $_COOKIE['wpf_contact'] ) ) {
			echo __( 'No' ) . ' ❌';
		} else {
			echo __( 'Yes' ) . ' ✅';
		}

		echo PHP_EOL;

		// Auto-login user

		echo '* ' . __( 'Auto login user ID set?' ) . ' ';

		if ( empty( $this->auto_login_user['user_id'] ) ) {
			echo __( 'No' ) . ' ❌';
		} else {
			echo __( 'Yes' ) . ' - User ID ' . $this->auto_login_user['user_id'] . ' ✅';
		}

		echo PHP_EOL;

		if ( doing_wpf_auto_login() ) {

			// Tags

			echo '* ' . __( 'Auto login tags: ' ) . PHP_EOL;

			echo print_r( wp_fusion()->user->get_tags(), true );

			echo PHP_EOL;

			// Fields

			echo '* ' . __( 'Auto login usermeta: ' ) . PHP_EOL;

			$meta = wp_fusion()->user->get_user_meta( wpf_get_current_user_id() );

			unset( $meta[ wp_fusion()->crm->slug . '_tags' ] ); // we just displayed this above

			echo print_r( $meta, true );

			echo PHP_EOL;

		}

		echo PHP_EOL;

		echo ' END WP FUSION AUTO LOGIN DEBUG INFO -->';

	}


	/**
	 * Display a warning if auto login links are used by a logged in admin
	 *
	 * @access public
	 * @return mixed HTML message
	 */

	public function maybe_doing_it_wrong() {

		if ( is_admin() ) {
			return;
		}

		if ( false == wp_fusion()->settings->get( 'auto_login' ) && false == wp_fusion()->settings->get( 'auto_login_forms' ) ) {
			return;
		}

		if ( ! empty( $this->get_contact_id_from_url() ) && current_user_can( 'manage_options' ) ) {

			echo '<div style="padding: 20px; border: 4px solid #ff0000; text-align: center;">';

			echo '<strong>' . __( 'Heads up: It looks like you\'re using a WP Fusion auto-login link, but you\'re already logged into the site, so nothing will happen. Always test auto-login links in a private browser tab.', 'wp-fusion-lite' ) . '</strong><br /><br />';

			echo '<em>(' . __( 'This message is only shown to admins and won\'t be visible to regular users.', 'wp-fusion-lite' ) . ')</em>';

			echo '</div>';

		}

	}

}
;