<?php
/**
 * WP Fusion - Kit CRM Admin Integration
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.47.2
 */

/**
 * The Kit CRM admin integration class.
 *
 * @since 3.47.2
 */
class WPF_Kit_Admin {

	/**
	 * The CRM slug.
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	private $slug;

	/**
	 * The CRM name.
	 *
	 * @since 3.47.2
	 * @var   string
	 */
	private $name;

	/**
	 * The CRM instance.
	 *
	 * @since 3.47.2
	 * @var   WPF_Kit
	 */
	private $crm;

	/**
	 * Initialize the admin integration.
	 *
	 * @since 3.47.2
	 *
	 * @param string  $slug The CRM slug.
	 * @param string  $name The CRM name.
	 * @param WPF_Kit $crm  The CRM instance.
	 */
	public function __construct( $slug, $name, $crm ) {
		$this->slug = $slug; // kit.
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_kit_header_begin', array( $this, 'show_field_kit_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		// OAuth.
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );
	}

	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com.
	 *
	 * @since  3.47.2
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings' ),
			'action'   => 'wpf_get_kit_token',
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );
	}

	/**
	 * Completes the OAuth process in the admin.
	 *
	 * @since   3.47.2
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) && 'wpfkit' === $_GET['state'] ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$this->crm->authorize( $code );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;
		}
	}

	/**
	 * Register connection settings for Kit CRM.
	 *
	 * @since 3.47.2
	 *
	 * @param  array $settings The settings array.
	 * @param  array $options  The options array.
	 * @return array The modified settings array.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new = array();

		$new['kit_header'] = array(
			// translators: %s is the CRM name.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options['kit_token'] ) && ! isset( $_GET['code'] ) ) {

			$new['kit_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
				// translators: %1$s is the CRM name.
				'desc'    => sprintf( __( 'You\'ll be taken to %1$s to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ), $this->name ),
			);

		} else {

			$new['kit_oauth_status'] = array(
				'title'       => __( 'Connection Status', 'wp-fusion-lite' ),
				'type'        => 'oauth_connection_status',
				'section'     => 'setup',
				'name'        => $this->name,
				'url'         => $this->get_oauth_url(),
				'post_fields' => array( 'kit_token', 'kit_refresh_token' ),
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new );

		return $settings;
	}

	/**
	 * Wrap the config section so it can be toggled per CRM.
	 *
	 * @since 3.47.2
	 *
	 * @param string $id    The field ID.
	 * @param array  $field The field configuration.
	 */
	public function show_field_kit_header_begin( $id, $field ) {
		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( false === $crm || $this->slug !== $crm ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * AJAX handler to test the API connection.
	 *
	 * @since 3.47.2
	 */
	public function test_connection() {
		check_ajax_referer( 'wpf_settings_nonce' );

		$token      = isset( $_POST['kit_token'] ) ? sanitize_text_field( wp_unslash( $_POST['kit_token'] ) ) : false;
		$connection = $this->crm->connect( $token, true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		} else {
			$options                          = array();
			$options['kit_token']             = $token;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );
			wp_send_json_success();
		}
		die();
	}
}
