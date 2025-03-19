<?php

class WPF_Groundhogg_REST_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.38.10
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.38.10
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.38.10
	 */

	private $crm;

	/**
	 * Get things started
	 *
	 * @since 3.38.10
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_groundhogg_rest_header_begin', array( $this, 'show_field_groundhogg_rest_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'handle_rest_authentication' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.38.10
	 */

	public function init() {

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
	}


	/**
	 * Handle REST API authentication.
	 *
	 * @since 3.38.10
	 */
	public function handle_rest_authentication() {

		if ( isset( $_GET['site_url'] ) && isset( $_GET['crm'] ) && $this->slug == $_GET['crm'] ) {

			$url      = esc_url_raw( urldecode( $_GET['site_url'] ) );
			$username = sanitize_text_field( urldecode( $_GET['user_login'] ) );
			$password = sanitize_text_field( urldecode( $_GET['password'] ) );

			wp_fusion()->settings->set( 'groundhogg_rest_url', $url );
			wp_fusion()->settings->set( 'groundhogg_rest_username', $username );
			wp_fusion()->settings->set( 'groundhogg_rest_password', $password );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}



	/**
	 * Registers Groundhogg API settings.
	 *
	 * @since  3.38.10
	 *
	 * @param  array $settings The registered settings on the options page.
	 * @param  array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['groundhogg_rest_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['groundhogg_rest_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'class'   => 'wp-rest-url',
			'desc'    => __( 'Enter the URL to your website where Groundhogg is installed (must be https://)', 'wp-fusion-lite' ),
		);

		if ( empty( $options['groundhogg_rest_url'] ) ) {
			$href  = '#';
			$class = 'button button-disabled rest-auth-btn';
		} else {
			$href  = trailingslashit( $options['groundhogg_rest_url'] ) . 'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' . urlencode( get_bloginfo( 'name' ) ) . '&success_url=' . admin_url( 'options-general.php?page=wpf-settings' ) . '%26crm=' . $this->slug;
			$class = 'button rest-auth-btn';
		}

		$new_settings['groundhogg_rest_url']['desc'] .= '<br /><br /><a id="groundhogg_rest-auth-btn" class="' . esc_attr( $class ) . '" href="' . esc_url( $href ) . '">' . __( 'Authorize with Groundhogg', 'wp-fusion-lite' ) . '</a>';
		$new_settings['groundhogg_rest_url']['desc'] .= '<span class="description">' . __( 'You can click the Authorize button to be taken to the Groundhogg site and generate an application password automatically, or enter your application credentials manually below.', 'wp-fusion-lite' ) . '</span>';

		$new_settings['groundhogg_rest_username'] = array(
			'title'   => __( 'Application Username', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['groundhogg_rest_password'] = array(
			'title'       => __( 'Application Password', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'groundhogg_rest_url', 'groundhogg_rest_username', 'groundhogg_rest_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Gets the default fields.
	 *
	 * @since  3.38.10
	 *
	 * @return array The default fields.
	 */
	public static function get_default_fields() {

		$groundhogg_fields = array();

		$groundhogg_fields['first_name'] = array(
			'crm_label' => 'First Name',
			'crm_field' => 'first_name',
		);

		$groundhogg_fields['last_name'] = array(
			'crm_label' => 'Last Name',
			'crm_field' => 'last_name',
		);

		$groundhogg_fields['user_email'] = array(
			'crm_label' => 'Email',
			'crm_field' => 'email',
		);

		$groundhogg_fields['phone_number'] = array(
			'crm_label' => 'Phone',
			'crm_field' => 'primary_phone',
		);

		$groundhogg_fields['billing_address_1'] = array(
			'crm_label' => 'Address 1',
			'crm_field' => 'street_address_1',
		);

		$groundhogg_fields['billing_address_2'] = array(
			'crm_label' => 'Address 2',
			'crm_field' => 'street_address_2',
		);

		$groundhogg_fields['billing_city'] = array(
			'crm_label' => 'City',
			'crm_field' => 'city',
		);

		$groundhogg_fields['billing_state'] = array(
			'crm_label' => 'State',
			'crm_field' => 'region',
		);

		$groundhogg_fields['billing_postcode'] = array(
			'crm_label' => 'Zip',
			'crm_field' => 'postal_zip',
		);

		$groundhogg_fields['billing_country'] = array(
			'crm_label' => 'Country',
			'crm_field' => 'country',
		);

		$groundhogg_fields['company'] = array(
			'crm_label' => 'Company Name',
			'crm_field' => 'company_name',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Job Title',
			'crm_field' => 'job_title',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Full Company Address',
			'crm_field' => 'company_address',
		);

		$groundhogg_fields['website'] = array(
			'crm_label' => 'Website',
			'crm_field' => 'website',
		);

		$groundhogg_fields['user_url'] = array(
			'crm_label' => 'Website',
			'crm_field' => 'website',
		);

		$groundhogg_fields['billing_country'] = array(
			'crm_label' => 'Country',
			'crm_field' => 'country',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Birthday',
			'crm_field' => 'birthday',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'GDPR Consent',
			'crm_field' => 'gdpr_consent',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Agreed to Terms',
			'crm_field' => 'terms_agreement',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Consented to Marketing',
			'crm_field' => 'marketing_consent',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Optin Status',
			'crm_field' => 'optin_status',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Profile Picture',
			'crm_field' => 'profile_picture',
		);

		$groundhogg_fields[] = array(
			'crm_label' => 'Owner ID',
			'crm_field' => 'owner_id',
		);

		return $groundhogg_fields;
	}



	/**
	 * Loads standard Groundhogg_REST field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @since  3.38.10
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
	 * Adds optin settings.
	 *
	 * @since  3.42.5
	 *
	 * @param  array $settings The settings.
	 * @param  array $options  The options.
	 * @return array The settings.
	 */
	public function register_settings( $settings, $options ) {

		$new_settings = array(
			'gh_default_status' => array(
				'title'   => __( 'Default Status', 'wp-fusion-lite' ),
				'desc'    => __( 'Select a default optin status for new contacts.', 'wp-fusion-lite' ),
				'type'    => 'select',
				'std'     => 2,
				'section' => 'main',
				'choices' => array(
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
	 * Puts a div around the CRM configuration section so it can be toggled.
	 *
	 * @since 3.38.10
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_groundhogg_rest_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );

		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.38.10
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$url      = esc_url_raw( wp_unslash( $_POST['groundhogg_rest_url'] ) );
		$username = sanitize_text_field( wp_unslash( $_POST['groundhogg_rest_username'] ) );
		$password = sanitize_text_field( wp_unslash( $_POST['groundhogg_rest_password'] ) );

		$connection = $this->crm->connect( $url, $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                             = array();
			$options['groundhogg_rest_url']      = $url;
			$options['groundhogg_rest_username'] = $username;
			$options['groundhogg_rest_password'] = $password;
			$options['crm']                      = $this->slug;
			$options['connection_configured']    = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
