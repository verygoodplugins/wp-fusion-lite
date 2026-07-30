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

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run when this CRM is selected as active.
	 *
	 * @since 3.47.14
	 */
	public function init() {
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_filter( 'validate_field_kit_update_tag', array( $this, 'validate_webhooks' ), 10, 2 );
		add_filter( 'validate_field_kit_add_tag', array( $this, 'validate_webhooks' ), 10, 2 );
		add_filter( 'validate_field_kit_notify_unsubscribe', array( $this, 'validate_unsubscribe_webhook' ), 10, 2 );
	}

	/**
	 * Loads standard Kit field names and matches them to local fields.
	 *
	 * @since 3.47.14
	 *
	 * @param  array $options The options array.
	 * @return array The modified options array.
	 */
	public function add_default_fields( $options ) {

		if ( true === $options['connection_configured'] && ! empty( $options['contact_fields'] ) && is_array( $options['contact_fields'] ) ) {

			require_once __DIR__ . '/admin/kit-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $kit_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $kit_fields[ $field ] );
				}
			}
		}

		return $options;
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
	 * Loads Kit-specific settings fields for webhooks.
	 *
	 * @since 3.47.14
	 *
	 * @param  array $settings The settings array.
	 * @param  array $options  The options array.
	 * @return array The modified settings array.
	 */
	public function register_settings( $settings, $options ) {

		$settings['access_key_desc'] = array(
			'type'    => 'paragraph',
			'section' => 'main',
			'desc'    => __( 'Configuring the fields below allows Kit to add new users to your site and update existing users when specific tags are applied from within Kit. Read our <a href="https://wpfusion.com/documentation/webhooks/convertkit-webhooks/" target="_blank">documentation</a> for more information.', 'wp-fusion-lite' ),
		);

		$new_settings = array();

		$new_settings['kit_update_tag'] = array(
			'title'       => __( 'Update Trigger', 'wp-fusion-lite' ),
			'desc'        => __( 'When this tag is applied to a contact in Kit, their tags and meta data will be updated in WordPress.', 'wp-fusion-lite' ),
			'type'        => 'assign_tags',
			'section'     => 'main',
			'placeholder' => 'Select a tag',
			'action'      => 'update',
			'limit'       => 1,
		);

		$new_settings['kit_update_tag_rule_id'] = array(
			'std'     => false,
			'type'    => 'hidden',
			'section' => 'main',
		);

		$new_settings['kit_add_tag'] = array(
			'title'       => __( 'Import Trigger', 'wp-fusion-lite' ),
			'desc'        => __( 'When this tag is applied to a contact in Kit, they will be imported as a new WordPress user.', 'wp-fusion-lite' ),
			'type'        => 'assign_tags',
			'section'     => 'main',
			'placeholder' => 'Select a tag',
			'action'      => 'add',
			'limit'       => 1,
		);

		$new_settings['kit_add_tag_rule_id'] = array(
			'std'     => false,
			'type'    => 'hidden',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'access_key', $settings, $new_settings );

		// Webhook URL is not needed when using tag-based triggers.
		unset( $settings['webhook_url'] );

		$new_settings = array();

		$new_settings['kit_settings_header'] = array(
			'title'   => __( 'Kit Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$new_settings['kit_notify_unsubscribe'] = array(
			'title'   => __( 'Notify on Unsubscribe', 'wp-fusion-lite' ),
			'desc'    => __( 'Send a notification email when a subscriber with a WordPress user account unsubscribes. See <a href="https://wpfusion.com/documentation/crm-specific-docs/convertkit-unsubscribe-notifications/" target="_blank">the documentation</a> for more info.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
			'std'     => 0,
			'unlock'  => array( 'kit_notify_email' ),
			'action'  => 'unsubscribe',
		);

		$new_settings['kit_unsubscribe_rule_id'] = array(
			'std'     => false,
			'type'    => 'hidden',
			'section' => 'advanced',
		);

		$new_settings['kit_notify_email'] = array(
			'title'    => __( 'Notification Email', 'wp-fusion-lite' ),
			'desc'     => __( 'The notification will be sent to this email.', 'wp-fusion-lite' ),
			'type'     => 'text',
			'section'  => 'advanced',
			'std'      => get_option( 'admin_email' ),
			'disabled' => ( isset( $options['kit_notify_unsubscribe'] ) && true === (bool) $options['kit_notify_unsubscribe'] ? false : true ),
		);

		$settings = wp_fusion()->settings->insert_setting_before( 'advanced_header', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Creates / destroys / updates webhooks on field changes.
	 *
	 * @since 3.47.14
	 *
	 * @param  mixed $input   The field input.
	 * @param  array $setting The setting configuration.
	 * @return mixed The validated input.
	 */
	public function validate_webhooks( $input, $setting ) {

		$type     = $setting['action'];
		$tag      = 0;
		$prev_tag = 0;

		if ( is_array( $input ) ) {
			$tag = $input[0];
		}

		$prev_value = wpf_get_option( 'kit_' . $type . '_tag' );

		if ( is_array( $prev_value ) ) {
			$prev_tag = $prev_value[0];
		}

		// If no changes have been made, quit early.
		if ( $tag === $prev_tag ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one.
		$rule_id = wpf_get_option( 'kit_' . $type . '_tag_rule_id' );

		if ( ! empty( $rule_id ) ) {
			wp_fusion()->crm->destroy_webhook( $rule_id );
			add_filter(
				'validate_field_kit_' . $type . '_tag_rule_id',
				function () {
					return false;
				}
			);
		}

		// Abort if tag has been removed and no new one provided.
		if ( empty( $tag ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_id = wp_fusion()->crm->register_webhook( $type, $tag );

		// If there was an error, make the user select the tag again.
		if ( false === $rule_id || is_wp_error( $rule_id ) ) {
			return false;
		}

		add_filter(
			'validate_field_kit_' . $type . '_tag_rule_id',
			function () use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;
	}

	/**
	 * Creates / destroys / updates the unsubscribe webhook on field changes.
	 *
	 * @since 3.47.14
	 *
	 * @param  mixed $input   The field input.
	 * @param  array $setting The setting configuration.
	 * @return mixed The validated input.
	 */
	public function validate_unsubscribe_webhook( $input, $setting ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		$prev_value = wpf_get_option( 'kit_notify_unsubscribe' );

		// If no changes have been made, quit early.
		if ( $input === $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one.
		$rule_id = wpf_get_option( 'kit_unsubscribe_rule_id' );

		if ( ! empty( $rule_id ) ) {

			wp_fusion()->crm->destroy_webhook( $rule_id );

			add_filter(
				'validate_field_kit_unsubscribe_rule_id',
				function () {
					return false;
				}
			);

		}

		// Abort if setting is switched off.
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_id = wp_fusion()->crm->register_webhook( 'unsubscribe', false );

		// If there was an error, make the user select the option again.
		if ( false === $rule_id || is_wp_error( $rule_id ) ) {
			return false;
		}

		add_filter(
			'validate_field_kit_unsubscribe_rule_id',
			function () use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;
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
