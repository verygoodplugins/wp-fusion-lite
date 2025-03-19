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

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
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
		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ), 20 ); // 20 so it runs after WooCommerce.

		// Enable the status field if we're collecting email optins.
		add_action( 'validate_field_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
		add_action( 'validate_field_give_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
		add_action( 'validate_field_edd_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
	}


	/**
	 * Handle REST API authentication.
	 *
	 * @since 3.37.14
	 */
	public function handle_rest_authentication() {

		if ( isset( $_GET['site_url'] ) && isset( $_GET['crm'] ) && $this->slug == $_GET['crm'] ) {

			$url      = esc_url_raw( urldecode( $_GET['site_url'] ) );
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
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['fluentcrm_rest_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'class'   => 'wp-rest-url',
			'desc'    => __( 'Enter the URL to your website where FluentCRM is installed (must be https://)', 'wp-fusion-lite' ),
		);

		if ( empty( $options['fluentcrm_rest_url'] ) ) {
			$href  = '#';
			$class = 'button button-disabled rest-auth-btn';
		} else {
			$href  = trailingslashit( $options['fluentcrm_rest_url'] ) . 'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' . urlencode( get_bloginfo( 'name' ) ) . '&success_url=' . admin_url( 'options-general.php?page=wpf-settings' ) . '%26crm=' . $this->slug;
			$class = 'button rest-auth-btn';
		}

		$new_settings['fluentcrm_rest_url']['desc'] .= '<br /><br /><a id="fluentcrm_rest-auth-btn" class="' . $class . '" href="' . $href . '">' . __( 'Authorize with FluentCRM', 'wp-fusion-lite' ) . '</a>';
		$new_settings['fluentcrm_rest_url']['desc'] .= '<span class="description">' . __( 'You can click the Authorize button to be taken to the FluentCRM site and generate an application password automatically.', 'wp-fusion-lite' ) . '</span>';

		$new_settings['fluentcrm_tag_format'] = array(
			'title'   => __( 'Tag Format', 'wp-fusion-lite' ),
			'type'    => 'select',
			'section' => 'setup',
			'desc'    => sprintf( __( 'Select how FluentCRM tags should be %1$sreferenced by WP Fusion%2$s. For new installs we recommend using IDs, slugs are provided as an option for backwards compatibility.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-fluentcrm-rest-api-to-wordpress/#tag-format" target="_blank">', '</a>' ),
			'std'     => ! empty( $options['connection_configured'] ) ? 'slug' : 'id',
			'choices' => array(
				'id'   => 'IDs',
				'slug' => 'Slugs',
			),
			'tooltip' => __( 'After changing the tag format, you will need to Resync Tags for Every User from the Advanced settings tab to load the new tags.', 'wp-fusion-lite' ),
		);

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
			'post_fields' => array( 'fluentcrm_rest_url', 'fluentcrm_rest_username', 'fluentcrm_rest_password', 'fluentcrm_tag_format' ),
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

		$fields = array(
			'first_name'        => array(
				'crm_label' => 'First Name',
				'crm_field' => 'first_name',
			),
			'last_name'         => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'last_name',
			),
			'user_email'        => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
			),
			'phone_number'      => array(
				'crm_label' => 'Phone',
				'crm_field' => 'phone',
			),
			'billing_address_1' => array(
				'crm_label' => 'Address 1',
				'crm_field' => 'address_line_1',
			),
			'billing_address_2' => array(
				'crm_label' => 'Address 2',
				'crm_field' => 'address_line_2',
			),
			'billing_city'      => array(
				'crm_label' => 'City',
				'crm_field' => 'city',
			),
			'billing_state'     => array(
				'crm_label' => 'State',
				'crm_field' => 'state',
			),
			'billing_postcode'  => array(
				'crm_label' => 'Zip',
				'crm_field' => 'postal_code',
			),
			'billing_country'   => array(
				'crm_label' => 'Country',
				'crm_field' => 'country',
			),
		);

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

		$fields[] = array(
			'crm_label' => 'Avatar URL',
			'crm_field' => 'avatar',
		);

		$fields[] = array(
			'crm_label' => 'Lists',
			'crm_field' => 'lists',
		);

		return $fields;
	}

	/**
	 * Add option to set default subscriber status.
	 *
	 * @since 3.40.40
	 *
	 * @param array $settings The settings.
	 * @return array The settings.
	 */
	public function configure_settings( $settings ) {

		$new_settings = array(
			'default_status' => array(
				'title'   => __( 'Default Status', 'wp-fusion-lite' ),
				'desc'    => __( 'Select a default optin status for new contacts.', 'wp-fusion-lite' ),
				'tooltip' => __( 'If Pending is selected, a double opt-in email will be sent to confirm the subscriber\'s email address. This can be overridden on a per-form basis by syncing a value of "subscribed" to the Status field.', 'wp-fusion-lite' ),
				'type'    => 'select',
				'std'     => 'subscribed',
				'section' => 'main',
				'choices' => array(
					'subscribed'   => 'Subscribed',
					'pending'      => 'Pending',
					'unsubscribed' => 'Unsubscribed',
				),
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_lists', $settings, $new_settings );

		if ( isset( $settings['email_optin_tags'] ) ) {

			$new_settings = array(
				'woo_optin_status' => array(
					'title'   => __( 'Optin Status', 'wp-fusion-lite' ),
					'desc'    => __( 'Select an opt-in status for customers who check the optin box.', 'wp-fusion-lite' ),
					'tooltip' => __( 'If Pending is selected, a double opt-in email will be sent to confirm the subscriber\'s email address.', 'wp-fusion-lite' ),
					'type'    => 'select',
					'std'     => 'subscribed',
					'section' => 'integrations',
					'choices' => array(
						'subscribed' => 'Subscribed',
						'pending'    => 'Pending',
					),
				),
			);

			$settings = wp_fusion()->settings->insert_setting_after( 'email_optin_tags', $settings, $new_settings );
		}

		return $settings;
	}

	/**
	 * Enables the email optin field for sync if we're collecting it at checkout.
	 *
	 * @since 3.44.22
	 *
	 * @param bool              $input           The input value.
	 * @param array             $setting         The setting array.
	 * @param WP_Fusion_Options $options_class   The options class.
	 * @return mixed The validated input.
	 */
	public function validate_field_email_optin( $input, $setting, $options_class ) {

		if ( true === boolval( $input ) ) {

			$target_field = str_replace( 'validate_field_', '', current_filter() );

			if ( ! isset( $options_class->post_data['contact_fields'][ $target_field ] ) || empty( $options_class->post_data['contact_fields'][ $target_field ]['active'] ) ) {

				$options_class->post_data['contact_fields'][ $target_field ] = array(
					'active'    => true,
					'crm_field' => 'status',
					'type'      => 'checkbox',
				);
			}
		}

		return $input;
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
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.37.14
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$url        = esc_url_raw( wp_unslash( $_POST['fluentcrm_rest_url'] ) );
		$username   = sanitize_text_field( wp_unslash( $_POST['fluentcrm_rest_username'] ) );
		$password   = sanitize_text_field( wp_unslash( $_POST['fluentcrm_rest_password'] ) );
		$tag_format = sanitize_text_field( wp_unslash( $_POST['fluentcrm_tag_format'] ) );
		$connection = $this->crm->connect( $url, $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                            = array();
			$options['fluentcrm_rest_url']      = $url;
			$options['fluentcrm_rest_username'] = $username;
			$options['fluentcrm_rest_password'] = $password;
			$options['fluentcrm_tag_format']    = $tag_format;
			$options['crm']                     = $this->slug;
			$options['connection_configured']   = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
