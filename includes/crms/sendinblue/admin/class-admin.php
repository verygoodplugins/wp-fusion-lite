<?php

class WPF_SendinBlue_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_sendinblue_header_begin', array( $this, 'show_field_sendinblue_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_filter( 'validate_field_double_optin_template', array( $this, 'validate_double_optin' ), 10, 3 );
	}


	/**
	 * Loads sendinblue connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['sendinblue_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['sendinblue_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can find your API v3 key in the <a href="https://account.brevo.com/advanced/api/" target="_blank">API settings</a> of your Brevo account.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'sendinblue_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads standard Brevo field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/sendinblue-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $sendinblue_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $sendinblue_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Add Sendinblue specific settings.
	 *
	 * @since  3.40.5
	 *
	 * @param  array $settings The settings.
	 * @param  array $options  The options in the database.
	 * @return array The settings.
	 */
	public function register_settings( $settings, $options ) {

		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Brevo Site Tracking', 'wp-fusion-lite' ),
			'url'     => 'https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#brevo',
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable Brevo site tracking scripts.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_key'] = array(
			'title'   => __( 'Tracking Client Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Your tracking <code>client_key</code> can be found in the Tracking Code <a href="https://automation.brevo.com/parameters" target="_blank">in the Automation settings of your Brevo account</a>. For example: <code>l7u0448l6oipghl8v7k92</code>', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$site_tracking['double_optin_header'] = array(
			'title'   => __( 'Double Opt-in Settings', 'wp-fusion-lite' ),
			'url'     => 'https://wpfusion.com/documentation/tutorials/double-opt-ins/#brevo',
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['double_optin_template'] = array(
			'title'       => __( 'Double Opt-in Template', 'wp-fusion-lite' ),
			'desc'        => __( 'Select a template to use for double opt-in. For more information see the <a href="https://help.brevo.com/hc/en-us/articles/360019540880-Create-a-double-opt-in-DOI-confirmation-template-for-Brevo-form" target="_blank">Brevo documentation</a>.', 'wp-fusion-lite' ),
			'type'        => 'select',
			'placeholder' => __( 'None', 'wp-fusion-lite' ),
			'choices'     => isset( $options['optin_templates'] ) ? $options['optin_templates'] : (array) wp_fusion()->crm->sync_optin_templates(),
			'section'     => 'main',
		);

		$site_tracking['double_optin_redirect_url'] = array(
			'title'   => __( 'Opt-in Redirect URL', 'wp-fusion-lite' ),
			'desc'    => __( 'URL that user will be redirected to after clicking on the double opt-in URL. When editing your DOI template you can reference this URL by using <code>{{ params.DOIurl }}</code>.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'std'     => home_url(),
			'section' => 'main',
		);

		$site_tracking['double_optin_lists'] = array(
			'title'   => __( 'Opt-in List(s)', 'wp-fusion-lite' ),
			'desc'    => __( 'You must select at least one list to add subscribers to once they have confirmed their subscription.', 'wp-fusion-lite' ),
			'type'    => 'assign_tags',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}

	/**
	 * Ensures a redirect URI and lists are set for double optin.
	 *
	 * @since 3.42.5
	 *
	 * @param string            $value         The selected opt-in template.
	 * @param array             $field         The field properties.
	 * @param WP_Fusion_Options $options_class The options class.
	 * @return string|WP_Error The value or error.
	 */
	public function validate_double_optin( $value, $field, $options_class ) {

		if ( ! empty( $value ) ) {

			if ( false === filter_var( $options_class->post_data['double_optin_redirect_url'], FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_redirect_url', __( 'You must enter a valid redirect URL.', 'wp-fusion-lite' ) );
			} elseif ( empty( $options_class->post_data['double_optin_lists'] ) ) {
				return new WP_Error( 'no_optin_lists', __( 'You must select at least one list to add subscribers to once they have confirmed their subscription.', 'wp-fusion-lite' ) );
			}
		}

		return $value;
	}

	/**
	 * Puts a div around the sendinblue configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_sendinblue_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$api_key = sanitize_text_field( $_POST['sendinblue_key'] );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['sendinblue_key']        = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
