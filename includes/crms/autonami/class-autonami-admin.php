<?php
/**
 * WP Fusion - FunnelKit Automations admin.
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2026, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.48.0
 */

/**
 * FunnelKit Automations settings and application-password callback.
 *
 * @since 3.48.0
 */
class WPF_Autonami_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.37.14
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.37.14
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.37.14
	 */

	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.37.14
	 *
	 * @param string $slug CRM slug.
	 * @param string $name CRM name.
	 * @param object $crm  CRM instance.
	 */
	public function __construct( $slug, $name, $crm ) {

		require_once dirname( __DIR__ ) . '/class-rest-auth.php';
		WPF_CRM_REST_Auth::register();

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings.
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_autonami_header_begin', array( $this, 'show_field_autonami_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
			$this->init();
		}

		// The callback must run before this CRM is the saved crm value.
		// admin-post.php is ignored because it is not the settings screen.
		add_action( 'admin_init', array( $this, 'handle_rest_authentication' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.37.14
	 */
	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
	}


	/**
	 * Handle REST API authentication.
	 *
	 * @since 3.37.14
	 */
	public function handle_rest_authentication() {

		$credentials = WPF_CRM_REST_Auth::authorize( $this->slug, 'esc_url' );

		if ( false === $credentials ) {
			return;
		}

		wp_fusion()->settings->set( 'autonami_url', $credentials['site_url'] );
		wp_fusion()->settings->set( 'autonami_username', $credentials['user_login'] );
		wp_fusion()->settings->set( 'autonami_password', $credentials['password'] );
		wp_fusion()->settings->set( 'crm', $this->slug );

		wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
		exit;
	}


	/**
	 * Registers FunnelKit Automations API settings.
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options The options saved in the database.
	 *
	 * @return array $settings The settings.
	 * @since  3.37.14
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['autonami_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['autonami_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'class'   => 'wp-rest-url',
			'desc'    => __( 'Enter the URL to your website where FunnelKit Automations is installed (must be https://).', 'wp-fusion-lite' ),
		);

		if ( class_exists( 'BWFAN_Core' ) ) {
			$same_site_url = '<code>' . esc_html( home_url() ) . '</code>';

			$new_settings['autonami_url']['desc'] .= '<br /><br /><strong>';
			$new_settings['autonami_url']['desc'] .= sprintf(
				/* translators: %s: Home URL wrapped in a code tag. */
				__( 'If you are trying to connect to FunnelKit Automations on this site, enter %s for the URL.', 'wp-fusion-lite' ),
				$same_site_url
			);
			$new_settings['autonami_url']['desc'] .= '</strong>';
		}

		$success_url = WPF_CRM_REST_Auth::get_success_url( $this->slug );

		if ( empty( $options['autonami_url'] ) ) {
			$href  = '#';
			$class = 'button button-disabled rest-auth-btn';
		} else {
			$href  = WPF_CRM_REST_Auth::get_authorize_url( $this->slug, $options['autonami_url'] );
			$class = 'button rest-auth-btn';
		}

		$link  = '<a id="autonami-auth-btn" class="' . esc_attr( $class ) . '"';
		$link .= ' href="' . esc_url( $href ) . '"';
		$link .= ' data-success-url="' . esc_attr( $success_url ) . '">';
		$link .= __( 'Authorize with FunnelKit Automations', 'wp-fusion-lite' );
		$link .= '</a>';

		$new_settings['autonami_url']['desc'] .= '<br /><br />' . $link;
		$new_settings['autonami_url']['desc'] .= '<span class="description">' . __( 'You can click the Authorize button to be taken to the FunnelKit Automations site and generate an application password automatically.', 'wp-fusion-lite' ) . '</span>';

		$new_settings['autonami_username'] = array(
			'title'   => __( 'Application Username', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['autonami_password'] = array(
			'title'       => __( 'Application Password', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'autonami_url', 'autonami_username', 'autonami_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard FunnelKit Automations_REST field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @param array $options The options.
	 *
	 * @return array The options.
	 * @since  3.37.14
	 */
	public function add_default_fields( $options ) {

		if ( ! empty( $options['connection_configured'] ) ) {

			$standard_fields = $this->get_default_fields();

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
				}
			}
		}

		return $options;
	}

	/**
	 * Gets the default fields.
	 *
	 * @return array The default fields.
	 * @since  3.37.14
	 */
	public static function get_default_fields() {

		return array(
			'first_name'     => array(
				'crm_label' => 'First Name',
				'crm_field' => 'f_name',
			),
			'last_name'      => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'l_name',
			),
			'user_email'     => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
			),
			'billing_phone'  => array(
				'crm_label' => 'Phone',
				'crm_field' => 'contact_no',
			),
			'billing_state'  => array(
				'crm_label' => 'State',
				'crm_field' => 'state',
			),
			'billing_counry' => array(
				'crm_label' => 'Country',
				'crm_field' => 'country',
			),
		);
	}

	/**
	 * Puts a div around the CRM configuration section so it can be toggled.
	 *
	 * @param string $id The ID of the field.
	 * @param array  $field The field properties.
	 *
	 * @return mixed HTML output.
	 * @since 3.37.14
	 */
	public function show_field_autonami_header_begin( $id, $field ) {

		// The show_field hook requires the field ID and config.
		unset( $id, $field );

		echo '</table>';
		$crm = wpf_get_option( 'crm' );

		if ( empty( $crm ) || $this->slug !== $crm ) {
			$config_class = 'hidden';
		} else {
			$config_class = 'crm-active';
		}

		printf(
			'<div id="%1$s" class="crm-config %2$s" data-name="%3$s" data-crm="%1$s">',
			esc_attr( $this->slug ),
			esc_attr( $config_class ),
			esc_attr( $this->name )
		);
	}


	/**
	 * Verify connection credentials.
	 *
	 * @return mixed JSON response.
	 * @since 3.37.14
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$url      = isset( $_POST['autonami_url'] ) ? esc_url_raw( wp_unslash( $_POST['autonami_url'] ) ) : false;
		$username = isset( $_POST['autonami_username'] ) ? sanitize_text_field( wp_unslash( $_POST['autonami_username'] ) ) : false;
		$password = isset( $_POST['autonami_password'] ) ? sanitize_text_field( wp_unslash( $_POST['autonami_password'] ) ) : false;

		$connection = $this->crm->connect( $url, $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['autonami_url']          = $url;
			$options['autonami_username']     = $username;
			$options['autonami_password']     = $password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
