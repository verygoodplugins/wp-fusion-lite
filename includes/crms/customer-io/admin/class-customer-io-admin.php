<?php

class WPF_Customer_IO_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.42.2
	 */
	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.42.2
	 */
	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.42.2
	 */
	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.42.2
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_customer_io_header_begin', array( $this, 'show_field_customer_io_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.42.2
	 */
	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ) );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.42.2
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['customer_io_header'] = array(
			'title'   => __( 'Customer.io Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings[ "{$this->slug}_region" ] = array(
			'title'   => __( 'Account Region', 'wp-fusion-lite' ),
			'desc'    => __( 'You can find your region by going to <a target="_blank" href="https://fly.customer.io/settings/privacy">this link</a> and checking your data center on the right sidebar.', 'wp-fusion-lite' ),
			'type'    => 'select',
			'choices' => array(
				'us' => 'United States',
				'eu' => 'Europe',
			),
			'std'     => 'us',
			'section' => 'setup',
		);

		$new_settings[ "{$this->slug}_api_key" ] = array(
			'title'   => __( 'API Key', 'wp-fusion-lite' ),
			'desc'    => __( 'You can create your API key for WP Fusion by going to <a target="_blank" href="https://fly.customer.io/settings/api_credentials?keyType=app">this link</a>.', 'wp-fusion-lite' ),
			'section' => 'setup',
			'type'    => 'text',
		);

		$new_settings[ "{$this->slug}_site_id" ] = array(
			'title'   => __( 'Tracking Site ID', 'wp-fusion-lite' ),
			'desc'    => __( 'You can get your site ID for WP Fusion by going to <a target="_blank" href="https://fly.customer.io/settings/api_credentials?keyType=tracking">this link</a>.', 'wp-fusion-lite' ),
			'section' => 'setup',
			'type'    => 'text',
		);

		$new_settings[ "{$this->slug}_tracking_api_key" ] = array(
			'title'       => __( 'Tracking API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can get your tracking API key for WP Fusion by going to <a target="_blank" href="https://fly.customer.io/settings/api_credentials?keyType=tracking">this link</a>.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( "{$this->slug}_region", "{$this->slug}_api_key", "{$this->slug}_site_id", "{$this->slug}_tracking_api_key" ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}


	/**
	 * Loads Bento specific settings fields
	 *
	 * @access  public
	 * @since 3.42.2
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Customer.io Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#customer-io">Customer.io site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}

	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since  3.42.2
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] ) {

			$customer_io_fields = $this->crm::get_default_fields();

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $customer_io_fields[ $field ] ) && empty( $data['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $data, $customer_io_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.42.2
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_customer_io_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.42.2
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );
		$region = isset( $_POST[ "{$this->slug}_region" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "{$this->slug}_region" ] ) ) : false;

		$api_key = isset( $_POST[ "{$this->slug}_api_key" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "{$this->slug}_api_key" ] ) ) : false;

		$site_id          = isset( $_POST[ "{$this->slug}_site_id" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "{$this->slug}_site_id" ] ) ) : false;
		$tracking_api_key = isset( $_POST[ "{$this->slug}_tracking_api_key" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "{$this->slug}_tracking_api_key" ] ) ) : false;

		$connection = $this->crm->connect( $region, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options = array(
				"{$this->slug}_region"           => $region,
				"{$this->slug}_api_key"          => $api_key,
				"{$this->slug}_site_id"          => $site_id,
				"{$this->slug}_tracking_api_key" => $tracking_api_key,
				'crm'                            => $this->slug,
				'connection_configured'          => true,
			);

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}
	}
}
