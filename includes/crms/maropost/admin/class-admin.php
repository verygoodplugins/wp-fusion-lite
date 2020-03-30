<?php

class WPF_Maropost_Admin {

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
		add_action( 'show_field_maropost_header_begin', array( $this, 'show_field_maropost_header_begin' ), 10, 2 );
		add_action( 'show_field_maropost_key_end', array( $this, 'show_field_maropost_key_end' ), 10, 2 );

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
		//add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}


	/**
	 * Loads Maropost connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$is_config = array();

		$is_config['maropost_header'] = array(
			'title'   => __( 'Maropost Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$is_config['account_id'] = array(
			'title'   => __( 'Account ID', 'wp-fusion' ),
			'desc'    => __( 'Enter your Maropost account ID (i.e. "1234").', 'wp-fusion' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);


		$is_config['maropost_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'Find your API key under connections in your account settings on Maropost.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'account_id', 'maropost_key', 'mp_list'  )
		);


		if( $settings['connection_configured'] == true && wp_fusion()->settings->get('crm') == 'maropost') {

			$is_config['mp_list'] = array(
				'title'   => __( 'Maropost Default List', 'wp-fusion' ),
				'std'     => 'Personal',
				'type'    => 'select',
				'section' => 'setup',
				'choices' => wp_fusion()->settings->get( 'maropost_lists' )

			);
		}
		

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $is_config );

		return $settings;

	}


	/**
	 * Loads standard Maropost field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/maropost-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $maropost_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $maropost_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Puts a div around the Maropost configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_maropost_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out maropost section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_maropost_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		if( wp_fusion()->crm->slug == 'maropost' ) {
			echo '<style type="text/css">#tab-import { display: none; }</style>';
		}
		echo '</div>'; // close #Maropost div
		echo '<table class="form-table">';



	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$account_id = sanitize_text_field( $_POST['account_id'] );
		$api_key 	= sanitize_text_field( $_POST['maropost_key'] );
		$mp_list    = sanitize_text_field( $_POST['mp_list'] );


		$connection = $this->crm->connect( $account_id, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['account_id']            = $account_id;
			$options['mp_list']               = $mp_list;
			$options['maropost_key']          = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

}