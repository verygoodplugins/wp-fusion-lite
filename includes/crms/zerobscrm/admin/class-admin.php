<?php

class WPF_ZeroBSCRM_Admin {

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
		add_action( 'show_field_zerobscrm_header_begin', array( $this, 'show_field_zerobscrm_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ), 10, 2 );

	}

	/**
	 * Loads zerobscrm connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['zerobscrm_header'] = array(
			'title'   => __( 'ZeroBSCRM Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['zerobscrm_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'zerobscrm_connect' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard ZeroBSCRM field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/zerobscrm-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $zerobscrm_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $zerobscrm_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Remove some settings that don't apply when connected to Jetpack on the
	 * same site.
	 *
	 * @since  3.37.31
	 *
	 * @param  array $settings The settings.
	 * @param  array $options  The options in the database.
	 * @return array The settings.
	 */
	public function configure_settings( $settings, $options ) {

		unset( $settings['login_sync'] );
		unset( $settings['login_meta_sync'] );
		unset( $settings['access_key_header'] );
		unset( $settings['access_key_desc'] );
		unset( $settings['access_key'] );
		unset( $settings['webhook_url'] );
		unset( $settings['test_webhooks'] );
		unset( $settings['login_meta_sync'] );

		$new_settings = array();

		$new_settings['automatic_imports_header'] = array(
			'title'   => __( 'Bidirectional Sync with Jetpack CRM', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
			'desc'    => __( 'Changes to contacts and contact tags in Jetpack CRM are automatically synced back to the contact\'s corresponding WordPress user record. To automatically import Jetpack contacts as WordPress users, select an <strong>Import Trigger</strong> below.', 'wp-fusion-lite' ),
		);

		$new_settings['jetpack_import_tag'] = array(
			'title'   => __( 'Import Trigger', 'wp-fusion-lite' ),
			'desc'    => __( 'When any of these tags are applied to a contact in Jetpack CRM, they will be imported as a new WordPres user.', 'wp-fusion-lite' ),
			'type'    => 'assign_tags',
			'section' => 'main',
		);

		if ( zeroBSCRM_getSetting( 'portalusers' ) ) {
			$new_settings['jetpack_import_tag']['desc'] .= '<br /><br /><div class="alert alert-info">' . sprintf( __( 'Heads up: You currently have <strong>Generate WordPress Users for new contacts</strong> enabled in the <a href="%s">Jetpack CRM Client Portal</a>.<br />The Import Trigger will not work if Jetpack is also automatically importing WordPress users.', 'wp-fusion-lite' ), esc_url( admin_url( 'admin.php?page=zerobscrm-plugin-settings&tab=clients' ) ) ) . '</div>';
		}

		$settings = wp_fusion()->settings->insert_setting_before( 'return_password_header', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Puts a div around the zerobscrm configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_zerobscrm_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
		echo '<style>#zerobscrm_connect {display: none;}</style>';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$connection = $this->crm->connect( true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}

}
