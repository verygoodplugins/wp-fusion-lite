<?php

class WPF_FluentCRM_REST_Admin {

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
	 * Get things started
	 *
	 * @since 3.37.14
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_fluentcrm_rest_header_begin', array( $this, 'show_field_fluentcrm_rest_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
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

		if ( isset( $_GET['site_url'] ) && isset( $_GET['crm'] ) && 'fluentcrm' == $_GET['crm'] ) {

			$url      = esc_url( urldecode( $_GET['site_url'] ) );
			$username = sanitize_text_field( urldecode( $_GET['user_login'] ) );
			$password = sanitize_text_field( urldecode( $_GET['password'] ) );

			wp_fusion()->settings->set( 'fluentcrm_rest_url', $url );
			wp_fusion()->settings->set( 'fluentcrm_rest_username', $username );
			wp_fusion()->settings->set( 'fluentcrm_rest_password', $password );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}

	}



	/**
	 * Registers FluentCRM API settings.
	 *
	 * @since  3.37.14
	 *
	 * @param  array $settings The registered settings on the options page.
	 * @param  array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['fluentcrm_rest_header'] = array(
			'title'   => __( 'FluentCRM Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['fluentcrm_rest_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'desc'    => __( 'Enter the URL to your website where FluentCRM is installed (must be https://)', 'wp-fusion-lite' ),
		);

		if ( empty( $options['fluentcrm_rest_url'] ) ) {
			$href  = '#';
			$class = 'button button-disabled';
		} else {
			$href  = trailingslashit( $options['fluentcrm_rest_url'] ) . 'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' . urlencode( get_bloginfo( 'name' ) ) . '&success_url=' . admin_url( 'options-general.php?page=wpf-settings' ) . '%26crm=fluentcrm';
			$class = 'button';
		}

		$new_settings['fluentcrm_rest_url']['desc'] .= '<br /><br /><a id="fluentcrm_rest-auth-btn" class="' . $class . '" href="' . $href . '">' . __( 'Authorize with FluentCRM', 'wp-fusion-lite' ) . '</a>';
		$new_settings['fluentcrm_rest_url']['desc'] .= '<span class="description">' . __( 'You can click the Authorize button to be taken to the FluentCRM site and generate an application password automatically.', 'wp-fusion-lite' ) . '</span>';

		$new_settings['fluentcrm_rest_username'] = array(
			'title'   => __( 'Application Username', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['fluentcrm_rest_password'] = array(
			'title'       => __( 'Application Password', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'fluentcrm_rest_url', 'fluentcrm_rest_username', 'fluentcrm_rest_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.37.14
	 *
	 * @return array The default fields.
	 */
	public static function get_default_fields() {

		$fields = [
			'first_name'        => [
				'crm_label' => 'First Name',
				'crm_field' => 'first_name',
			],
			'last_name'         => [
				'crm_label' => 'Last Name',
				'crm_field' => 'last_name',
			],
			'user_email'        => [
				'crm_label' => 'Email',
				'crm_field' => 'email',
			],
			'phone_number'      => [
				'crm_label' => 'Phone',
				'crm_field' => 'phone',
			],
			'billing_address_1' => [
				'crm_label' => 'Address 1',
				'crm_field' => 'address_line_1',
			],
			'billing_address_2' => [
				'crm_label' => 'Address 2',
				'crm_field' => 'address_line_2',
			],
			'billing_city'      => [
				'crm_label' => 'City',
				'crm_field' => 'city',
			],
			'billing_state'     => [
				'crm_label' => 'State',
				'crm_field' => 'state',
			],
			'billing_postcode'  => [
				'crm_label' => 'Zip',
				'crm_field' => 'postal_code',
			],
			'billing_country'   => [
				'crm_label' => 'Country',
				'crm_field' => 'country',
			],
		];

		$fields[] = array(
			'crm_label' => 'Name Prefix',
			'crm_field' => 'prefix',
		);

		$fields[] = array(
			'crm_label' => 'Contact Type',
			'crm_field' => 'contact_type',
		);

		$fields[] = array(
			'crm_label' => 'Date of Birth',
			'crm_field' => 'date_of_birth',
		);

		$fields[] = array(
			'crm_label' => 'Status',
			'crm_field' => 'status',
		);

		$fields[] = array(
			'crm_label' => 'Photo',
			'crm_field' => 'photo',
		);

		return $fields;

	}



	/**
	 * Loads standard FluentCRM_REST field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @since  3.37.14
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */

	public function add_default_fields( $options ) {

		if ( true == $options['connection_configured'] ) {

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
	 * Puts a div around the CRM configuration section so it can be toggled.
	 *
	 * @since 3.37.14
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_fluentcrm_rest_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.37.14
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		$url      = esc_url( $_POST['fluentcrm_rest_url'] );
		$username = sanitize_text_field( $_POST['fluentcrm_rest_username'] );
		$password = sanitize_text_field( $_POST['fluentcrm_rest_password'] );

		$connection = $this->crm->connect( $url, $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                            = wp_fusion()->settings->get_all();
			$options['fluentcrm_rest_url']      = $url;
			$options['fluentcrm_rest_username'] = $username;
			$options['fluentcrm_rest_password'] = $password;
			$options['crm']                     = $this->slug;
			$options['connection_configured']   = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
