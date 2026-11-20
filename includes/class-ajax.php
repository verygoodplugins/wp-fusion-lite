<?php
/**
 * WP Fusion - Public tag AJAX API.
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since 3.48.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AJAX handlers for applying and removing tags on the current user.
 *
 * The nopriv actions stay registered so auto-login sessions, which have no
 * WordPress auth cookie, can still reach these handlers.
 *
 * @since 3.48.1
 */
class WPF_AJAX {

	/**
	 * Get things started.
	 *
	 * @since 3.48.1
	 */
	public function __construct() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_link_click_script' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_apply_tags', array( $this, 'apply_tags' ) );
		add_action( 'wp_ajax_nopriv_apply_tags', array( $this, 'apply_tags' ) ); // needed for applying tags via auto-login.
		add_action( 'wp_ajax_remove_tags', array( $this, 'remove_tags' ) );
		add_action( 'wp_ajax_nopriv_remove_tags', array( $this, 'remove_tags' ) ); // needed for applying tags via auto-login.
	}

	/**
	 * Applies tags to a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */
	public function apply_tags() {

		// nopriv stays registered because auto-login has no WordPress auth cookie.
		if ( ! $this->has_tag_session() ) {
			$this->send_unauthorized();
		}

		if ( ! $this->is_same_site_request() ) {
			$this->send_forbidden();
		}

		$tags = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Unsigned so existing JavaScript snippets keep working.
		if ( isset( $_POST['tags'] ) && is_array( $_POST['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) );
		} elseif ( isset( $_POST['tags'] ) ) {
			$tags = explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) );
		} else {
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$data = json_decode( file_get_contents( 'php://input' ), true ); // check JSON payload.

			if ( ! empty( $data ) && isset( $data['tags'] ) ) {
				$tags = explode( ',', sanitize_text_field( wp_unslash( $data['tags'] ) ) );
			}
		}

		$tags = array_filter( $tags );

		if ( empty( $tags ) ) {
			wp_send_json_success();
		}

		$tags_to_apply = array();

		foreach ( $tags as $tag ) {

			$tag_id = $this->get_existing_tag_id( $tag );

			if ( false === $tag_id ) {

				wpf_log( 'notice', wpf_get_current_user_id(), 'Unable to determine tag ID from tag with name <strong>' . $tag . '</strong>. Tag will not be applied. Please make sure this tag exists in ' . wp_fusion()->crm->name . '. If you\'ve just added a new tag, click Resync Available Tags in the WP Fusion settings.' );
				continue;

			}

			$tags_to_apply[] = $tag_id;
		}

		if ( ! empty( $tags_to_apply ) ) {

			wp_fusion()->user->apply_tags( $tags_to_apply );

		}

		wp_send_json_success();
	}

	/**
	 * Removes tags from a given user via AJAX call
	 *
	 * @access public
	 * @return void
	 */
	public function remove_tags() {

		// nopriv stays registered because auto-login has no WordPress auth cookie.
		if ( ! $this->has_tag_session() ) {
			$this->send_unauthorized();
		}

		if ( ! $this->is_same_site_request() ) {
			$this->send_forbidden();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Unsigned so existing JavaScript snippets keep working.
		if ( ! isset( $_POST['tags'] ) ) {
			wp_die();
		}

		if ( is_array( $_POST['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) );
		} else {
			$tags = explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$tags_to_remove = array();

		foreach ( $tags as $tag ) {

			$tag_id = $this->get_existing_tag_id( $tag );

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
	 * Whether the request has a real login or a validated auto-login session.
	 *
	 * A wpf_contact cookie with only a user_id still populates auto-login
	 * state. With auto_login_current_user on, that also makes
	 * is_user_logged_in() true, so the cookie is checked before the
	 * WordPress login. The contact ID in the cookie must match the one
	 * stored for that user.
	 *
	 * @since 3.48.1
	 * @access private
	 *
	 * @return bool
	 */
	private function has_tag_session() {

		// Checked first. auto_login_current_user copies the cookie user into
		// $current_user, which makes is_user_logged_in() true for a bad cookie.
		if ( doing_wpf_auto_login() ) {

			if ( ! defined( 'WPF_CONTACT_ID_META_KEY' ) ) {
				return false;
			}

			$session = wp_fusion()->auto_login->auto_login_user;

			if ( empty( $session['user_id'] ) || empty( $session['contact_id'] ) ) {
				return false;
			}

			$stored = get_user_meta( $session['user_id'], WPF_CONTACT_ID_META_KEY, true );

			return (string) $stored === (string) $session['contact_id'];
		}

		return is_user_logged_in();
	}

	/**
	 * Reject a tag request that has no WordPress or auto-login session.
	 *
	 * WordPress 4.6 does not accept a status code on wp_send_json_error(),
	 * so the header is set directly.
	 *
	 * @since 3.48.1
	 * @access private
	 */
	private function send_unauthorized() {

		status_header( 401 );
		wp_send_json_error();
	}

	/**
	 * Whether a tag request came from this site.
	 *
	 * Blocks cross-site request forgery without breaking the documented
	 * JavaScript API. A valid wpf_ajax_nonce passes. Requests without one,
	 * like custom snippets or cached pages with an expired nonce, pass only
	 * when the browser's Origin header, or failing that the Referer header, is
	 * this site. A posted _wp_http_referer field is ignored. Extra origins can
	 * be allowed with the core allowed_http_origins filter.
	 *
	 * @since 3.48.3
	 * @access private
	 *
	 * @return bool
	 */
	private function is_same_site_request() {

		$allowed = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the nonce check.
		$nonce = isset( $_REQUEST['_ajax_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) ) : '';

		if ( '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wpf_ajax_nonce' ) ) {

			$allowed = true;

		} else {

			$origin = get_http_origin();

			if ( '' !== $origin ) {

				// Browsers send Origin on cross-site POSTs, including "null" from sandboxed frames.
				$allowed = (bool) is_allowed_http_origin( $origin );

			} else {

				// The header only. wp_get_raw_referer() prefers a posted field.
				$referer = $this->get_referer_header();

				if ( '' !== $referer ) {
					$allowed = $this->is_allowed_referer( $referer );
				}
			}
		}

		/**
		 * Filters whether a tag AJAX request is treated as coming from this site.
		 *
		 * @since 3.48.3
		 *
		 * @param bool $allowed Whether the request passed the nonce or same-origin check.
		 */
		return (bool) apply_filters( 'wpf_tags_ajax_request_allowed', $allowed );
	}

	/**
	 * Referer header for this request.
	 *
	 * Not wp_get_raw_referer(). That helper returns a posted _wp_http_referer
	 * value first, and a cross-site form can set that field.
	 *
	 * @since 3.48.3
	 * @access private
	 *
	 * @return string
	 */
	private function get_referer_header() {

		if ( empty( $_SERVER['HTTP_REFERER'] ) || ! is_string( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed for scheme and host, not output.
		return wp_unslash( $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * Whether a Referer URL is this site.
	 *
	 * Scheme and host are checked against the core origin allow-list. The
	 * port is not part of that list.
	 *
	 * @since 3.48.3
	 * @access private
	 *
	 * @param string $referer Referer URL.
	 * @return bool
	 */
	private function is_allowed_referer( $referer ) {

		$parts = wp_parse_url( $referer );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		return (bool) is_allowed_http_origin( $scheme . '://' . $parts['host'] );
	}

	/**
	 * Reject a tag request that did not come from this site.
	 *
	 * @since 3.48.3
	 * @access private
	 */
	private function send_forbidden() {

		status_header( 403 );
		wp_send_json_error();
	}

	/**
	 * Resolve a request tag to an ID that already exists locally.
	 *
	 * CRMs that support add_tags make get_tag_id() return an unknown label,
	 * and sending that label to apply_tags() creates the tag. This endpoint
	 * only accepts tags already present in the available tags list.
	 *
	 * @since 3.48.1
	 * @access private
	 *
	 * @param string $tag Tag name or ID from the request.
	 * @return mixed Existing tag ID, or false.
	 */
	private function get_existing_tag_id( $tag ) {

		$tag_id = wp_fusion()->user->get_tag_id( $tag );

		if ( false === $tag_id || '' === $tag_id ) {
			return false;
		}

		$available_tags = wp_fusion()->settings->get_available_tags_flat();

		if ( ! array_key_exists( $tag_id, $available_tags ) ) {
			return false;
		}

		return $tag_id;
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
				'nonce'   => wp_create_nonce( 'wpf_ajax_nonce' ),
			);

			wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', $localize_data );

		}
	}
}
