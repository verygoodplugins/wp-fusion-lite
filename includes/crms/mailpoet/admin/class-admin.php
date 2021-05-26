<?php

class WPF_MailPoet_Admin {

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
		add_action( 'show_field_mailpoet_header_begin', array( $this, 'show_field_mailpoet_header_begin' ), 10, 2 );
		add_action( 'show_field_mailpoet_connect_end', array( $this, 'show_field_mailpoet_connect_end' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
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

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}

	/**
	 * Loads mailpoet connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mailpoet_header'] = array(
			'title'   => __( 'MailPoet Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['mailpoet_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'mailpoet_connect' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads MailPoet specific settings fields
	 *
	 * @access  public
	 * @since   3.31.1
	 */

	public function register_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mailpoet_header_2'] = array(
			'title'   => __( 'MailPoet Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$new_settings['mailpoet_send_confirmation'] = array(
			'title'   => __( 'Confirmation Emails', 'wp-fusion-lite' ),
			'desc'    => __( 'Send confirmation emails via MailPoet when a subscriber is added to any list.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 0,
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard MailPoet field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/mailpoet-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $mailpoet_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $mailpoet_fields[ $field ] );
				}
			}
		}

		return $options;

	}

	/**
	 * Puts a div around the mailpoet configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailpoet_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';
		echo '<style>#mailpoet_connect {display: none;} #tab-import { display: none; }</style>';

	}

	/**
	 * Close out MailPoet section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailpoet_connect_end( $id, $field ) {

		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #mailpoet div
		echo '<table class="form-table">';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$connection = $this->crm->connect( true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}
