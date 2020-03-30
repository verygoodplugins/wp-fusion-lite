<?php

class WPF_Groundhogg_Admin {

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
		add_action( 'show_field_groundhogg_header_begin', array( $this, 'show_field_groundhogg_header_begin' ), 10, 2 );
		add_action( 'show_field_groundhogg_connect_end', array( $this, 'show_field_groundhogg_connect_end' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ), 10, 2 );

	}

	/**
	 * Loads groundhogg connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['groundhogg_header'] = array(
			'title'   => __( 'Groundhogg Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['groundhogg_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'groundhogg_connect' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Set up GH specific settings (Logins Tags Sync and Login Meta Sync aren't necessary with GH since changes are communicated in real time)
	 *
	 * @access  public
	 * @return  array Settings
	 */

	public function configure_settings( $settings, $options ) {

		unset( $settings['login_sync'] );
		unset( $settings['login_meta_sync'] );

		$new_settings = array(
			'gh_default_status' => array(
				'title'       => __( 'Default Status', 'wp-fusion' ),
				'desc'        => __( 'Select a default optin status for new contacts.', 'wp-fusion' ),
				'type'        => 'select',
				'std'         => 2,
				'section'     => 'main',
				'choices'     => array(
					2 => 'Confirmed',
					1 => 'Unconfimed',
					3 => 'Unsubscribed',
					4 => 'Weekly',
					5 => 'Monthly',
				),
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard Groundhogg field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/groundhogg-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $groundhogg_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $groundhogg_fields[ $field ] );
				}

			}

		}

		return $options;

	}

	/**
	 * Puts a div around the groundhogg configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_groundhogg_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';
		echo '<style>#groundhogg_connect {display: none;}</style>';

	}

	/**
	 * Close out Groundhogg section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_groundhogg_connect_end( $id, $field ) {

		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #nationbuilder div
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