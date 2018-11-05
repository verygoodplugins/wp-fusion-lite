<?php

class WPF_Platformly_Admin {

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
		add_action( 'show_field_platformly_header_begin', array( $this, 'show_field_platformly_header_begin' ), 10, 2 );
		add_action( 'show_field_platformly_key_end', array( $this, 'show_field_platformly_key_end' ), 10, 2 );

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

	}


	/**
	 * Loads platformly connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['platformly_header'] = array(
			'title'   => __( 'Platformly Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['platformly_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'You can create an API key from the API Docs &amp; Keys section of your Platform.ly account.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'platformly_key' )
		);

		if( $settings['connection_configured'] == true && wp_fusion()->settings->get('crm') == 'platformly' ) {

			$new_settings['platformly_project'] = array(
				'title'   => __( 'Project', 'wp-fusion' ),
				'std'     => false,
				'type'    => 'select',
				'section' => 'setup',
				'choices' => wp_fusion()->settings->get( 'available_projects' ),
				'desc'	  => 'After changing projects you will need to Resynchronize (above) to load the tags and fields for that project.'
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}



	/**
	 * Loads standard platformly field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/platformly-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $platformly_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $platformly_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Puts a div around the platformly configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_platformly_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out mailerlight section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_platformly_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';

		if( wp_fusion()->crm->slug == 'platformly' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}

		echo '</div>'; // close #platformly div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_key = sanitize_text_field( $_POST['platformly_key'] );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['platformly_key']        = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}