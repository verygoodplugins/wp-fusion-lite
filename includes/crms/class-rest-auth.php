<?php
/**
 * WP Fusion - REST application-password callback guard.
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2026, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.48.0
 */

/**
 * Shared gate for FluentCRM, FunnelKit Automations, and Groundhogg REST.
 *
 * @since 3.48.0
 */
class WPF_CRM_REST_Auth {

	/**
	 * Recognize an application-password callback and return sanitized credentials.
	 *
	 * Callers keep this on admin_init outside the active-CRM init() guard. The
	 * callback must run before this CRM is the saved crm value, so a
	 * not-yet-connected site can select it. admin-post.php is ignored because
	 * it is not the settings screen.
	 *
	 * Query values are unslashed before sanitizing. The no-JS callback is
	 * urldecoded once more. Nothing is written here.
	 *
	 * @since 3.48.0
	 *
	 * @param string $slug         CRM slug.
	 * @param string $sanitize_url esc_url_raw or esc_url.
	 * @return array|false {
	 *     Sanitized credentials, or false when nothing may be saved.
	 *
	 *     @type string $site_url   Site URL.
	 *     @type string $user_login Application username.
	 *     @type string $password   Application password.
	 * }
	 */
	public static function authorize( $slug, $sanitize_url = 'esc_url_raw' ) {

		if ( 'esc_url' !== $sanitize_url && 'esc_url_raw' !== $sanitize_url ) {
			return false;
		}

		if ( ! isset( $_GET['crm'], $_GET['site_url'], $_GET['user_login'], $_GET['password'] ) ) {
			return false;
		}

		if ( is_array( $_GET['crm'] ) || is_array( $_GET['site_url'] ) ) {
			return false;
		}

		if ( is_array( $_GET['user_login'] ) || is_array( $_GET['password'] ) ) {
			return false;
		}

		// The sniff cannot see sanitizing through the decode helper below.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$crm_raw   = (string) wp_unslash( $_GET['crm'] );
		$login_raw = (string) wp_unslash( $_GET['user_login'] );
		$pass_raw  = (string) wp_unslash( $_GET['password'] );
		$site_raw  = (string) wp_unslash( $_GET['site_url'] );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// The no-JS form urlencodes these, then add_query_arg() encodes them
		// again, so PHP leaves "%3A" and "+" in the query. The normal
		// auth-app.js path is already decoded, and a "+" there is literal.
		if ( self::is_double_encoded_site_url( $site_raw ) ) {
			$crm_raw   = urldecode( $crm_raw );
			$login_raw = urldecode( $login_raw );
			$pass_raw  = urldecode( $pass_raw );
			$site_raw  = urldecode( $site_raw );
		}

		$crm        = sanitize_text_field( $crm_raw );
		$user_login = sanitize_text_field( $login_raw );
		$password   = sanitize_text_field( $pass_raw );
		$raw_url    = sanitize_text_field( $site_raw );

		// FunnelKit Automations historically stored the URL with esc_url().
		if ( 'esc_url' === $sanitize_url ) {
			$site_url = esc_url( $raw_url );
		} else {
			$site_url = esc_url_raw( $raw_url );
		}

		if ( '' === $crm || '' === $site_url || '' === $user_login || '' === $password ) {
			return false;
		}

		$page = '';

		if ( isset( $_GET['page'] ) && ! is_array( $_GET['page'] ) ) {
			$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) );
		}

		// admin-post.php is ignored because it is not the settings screen.
		if ( $slug !== $crm || ! self::is_settings_screen( $page ) ) {
			return false;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$nonce = '';

		if ( isset( $_GET['_wpnonce'] ) && ! is_array( $_GET['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) );
		}

		if ( ! wp_verify_nonce( $nonce, 'wpf_rest_auth_' . $slug ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings&wpf-auth=failed' ) );
			exit;
		}

		return array(
			'site_url'   => $site_url,
			'user_login' => $user_login,
			'password'   => $password,
		);
	}

	/**
	 * Whether the CRM URL is still the no-JS callback's leftover encoding.
	 *
	 * A normal browser return already contains "://". The no-JS form still
	 * has percent-encoding and no scheme separator.
	 *
	 * @since 3.48.0
	 *
	 * @param string $site_url Unslashed site_url query value.
	 * @return bool
	 */
	private static function is_double_encoded_site_url( $site_url ) {

		if ( false !== strpos( $site_url, '://' ) ) {
			return false;
		}

		return 1 === preg_match( '/%[0-9A-Fa-f]{2}/', $site_url );
	}

	/**
	 * Allow the Authorize button to keep its success URL through kses.
	 *
	 * The link is printed with wp_kses_post(). WordPress before 5.0 strips
	 * data attributes, which disables the button on first connect.
	 *
	 * @since 3.48.0
	 */
	public static function register() {

		if ( false !== has_filter( 'wp_kses_allowed_html', array( __CLASS__, 'allow_success_url_attr' ) ) ) {
			return;
		}

		add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'allow_success_url_attr' ), 10, 2 );
	}

	/**
	 * Keep data-success-url on links in post kses.
	 *
	 * @since 3.48.0
	 *
	 * @param array        $allowed Allowed tags.
	 * @param string|array $context Kses context.
	 * @return array
	 */
	public static function allow_success_url_attr( $allowed, $context ) {

		if ( 'post' === $context && isset( $allowed['a'] ) && is_array( $allowed['a'] ) ) {
			$allowed['a']['data-success-url'] = true;
		}

		return $allowed;
	}

	/**
	 * Settings URL the remote site should redirect back to.
	 *
	 * @since 3.48.0
	 *
	 * @param string $slug CRM slug.
	 * @return string
	 */
	public static function get_success_url( $slug ) {

		$target = admin_url( 'options-general.php?page=wpf-settings&crm=' . rawurlencode( $slug ) );

		// wp_nonce_url() HTML-escapes ampersands. Decode them before this URL
		// is rawurlencoded into the remote success_url parameter.
		$nonce_url = wp_nonce_url( $target, 'wpf_rest_auth_' . $slug );

		return html_entity_decode( $nonce_url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Remote authorize-application.php URL for the Authorize button.
	 *
	 * @since 3.48.0
	 *
	 * @param string $slug            CRM slug.
	 * @param string $remote_site_url CRM site URL.
	 * @return string
	 */
	public static function get_authorize_url( $slug, $remote_site_url ) {

		$app_name = rawurlencode( 'WP Fusion - ' . get_bloginfo( 'name' ) );
		$endpoint = trailingslashit( $remote_site_url ) . 'wp-admin/authorize-application.php';

		return $endpoint . '?app_name=' . $app_name . '&success_url=' . rawurlencode( self::get_success_url( $slug ) );
	}

	/**
	 * Whether the request is the WP Fusion settings screen.
	 *
	 * Requests to admin-post.php and admin-ajax.php are not the settings screen,
	 * so they return without writing and without exiting.
	 *
	 * @since 3.48.0
	 *
	 * @param string $page Sanitized page query arg.
	 * @return bool
	 */
	private static function is_settings_screen( $page ) {

		global $pagenow;

		if ( ! isset( $pagenow ) || 'options-general.php' !== $pagenow ) {
			return false;
		}

		return 'wpf-settings' === $page;
	}
}
