<?php

class WPF_EngageBay_Admin {

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
		add_action( 'show_field_engagebay_header_begin', array( $this, 'show_field_engagebay_header_begin' ), 10, 2 );

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

	}


	/**
	 * Loads engagebay connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['engagebay_header'] = array(
			'title'   => __( 'EngageBay Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['engagebay_domain'] = array(
			'title'   => __( 'Subdomain', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter your EngageBay CRM account subdomain (not the full URL) needed for tracking.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['engagebay_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can create a new API key in the API Info settings of your EngageBay account.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'engagebay_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads EngageBay specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'EngageBay Site Tracking', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable EngageBay site tracking scripts.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_acct'] = array(
			'title'   => __( 'JavaScript API Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Your JavaScript API Key ID can be found in the Tracking Code in your EngageBay account, under Admin Settings &raquo; API and Tracking Code. For example: <code>8g8fejferfqbi4g4mradq09373</code>', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}

	/**
	 * Loads standard engagebay field names and attempts to match them up with standard local ones
	 * remove any subtype settings
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] ) {

			require_once dirname( __FILE__ ) . '/engagebay-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				$keys  = explode( '+', $field );  // remove the SUBTYPE
				$field = $keys[0];

				if ( isset( $engagebay_fields[ $field ] ) &&
				empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $engagebay_fields[ $field ] );

				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the engagebay configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_engagebay_header_begin( $id, $field ) {

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

		$api_key = isset( $_POST['engagebay_key'] ) ? sanitize_text_field( wp_unslash( $_POST['engagebay_key'] ) ) : false;

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['engagebay_key']         = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}

}
