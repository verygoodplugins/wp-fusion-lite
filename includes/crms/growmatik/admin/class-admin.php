<?php

class WPF_Growmatik_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @since 3.36
	 *
	 * @param string $slug The CRM's slug
	 * @param string $name The name of the CRM
	 * @param object $crm  The CRM object
	 * @return void
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_growmatik_header_begin', array( $this, 'show_field_growmatik_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.36
	 *
	 * @return void
	 */

	public function init() {
		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
	}


	/**
	 * Registers Growmatik API settings
	 *
	 * @since 3.36
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['growmatik_header'] = array(
			'title'   => __( 'Growmatik CRM Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['growmatik_api_key'] = array(
			'title'   => __( 'API Key', 'wp-fusion' ),
			'desc'    => __( 'Enter your Growmatik API key. You can generate one in the <em>Site settings > Integrations > API</em>.', 'wp-fusion' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['growmatik_api_secret'] = array(
			'title'       => __( 'API Secret', 'wp-fusion' ),
			'desc'        => __( 'Enter your Growmatik API Secret. You can generate one in the <em>Site settings > Integrations > API</em>.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'growmatik_api_secret', 'growmatik_api_key' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard field names and attempts to match them up with standard local ones
	 *
	 * @since 3.36
	 *
	 * @param array $options The options saved in the database
	 * @return array $options The options saved in the database
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/growmatik-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $growmatik_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $growmatik_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.36
	 *
	 * @param string $id    The ID of the field
	 * @param array  $field The field properties
	 * @return mixed HTML output
	 */

	public function show_field_growmatik_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @since 3.36
	 *
	 * @return mixed JSON response
	 */
	public function test_connection() {

		$api_secret = sanitize_text_field( $_POST['growmatik_api_secret'] );
		$api_key    = sanitize_text_field( $_POST['growmatik_api_key'] );

		$connection = $this->crm->connect( $api_secret, $api_key );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		}

		$options                          = wp_fusion()->settings->get_all();
		$options['growmatik_api_secret']  = $api_secret;
		$options['growmatik_api_key']     = $api_key;
		$options['crm']                   = $this->slug;
		$options['connection_configured'] = true;

		wp_fusion()->settings->set_all( $options );

		wp_send_json_success();
	}
}
