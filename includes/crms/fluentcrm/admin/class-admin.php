<?php

class WPF_FluentCRM_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   3.35
	 */
	public function __construct( $slug, $name, $crm ) {
		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_fluentcrm_header_begin', array( $this, 'show_field_fluentcrm_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 20, 2 ); // 20 so it runs after WooCommerce.
		add_action( 'wpf_resync_contact', array( $this, 'resync_contact' ) );

		// Enable the status field if we're collecting email optins.
		add_action( 'validate_field_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
		add_action( 'validate_field_give_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
		add_action( 'validate_field_edd_email_optin', array( $this, 'validate_field_email_optin' ), 10, 3 );
	}


	/**
	 * Loads ActiveCampaign connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['fluentcrm_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['fluentcrm_connect'] = array(
			'title'       => __( 'Connect', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'fluentcrm_connect' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads ActiveCampaign specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_settings( $settings, $options ) {

		unset( $settings['login_sync'] );
		unset( $settings['login_meta_sync'] );

		if ( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['fluentcrm_lists'] = array(
			'title'       => __( 'Default Lists', 'wp-fusion-lite' ),
			'desc'        => __( 'All contacts synced to FluentCRM by WP Fusion will be added to the selected lists.', 'wp-fusion-lite' ),
			'type'        => 'multi_select',
			'placeholder' => 'Select lists',
			'section'     => 'main',
			'choices'     => $options['available_lists'],
		);

		$new_settings['default_status'] = array(
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
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_lists', $settings, $new_settings );

		if ( ! isset( $settings['create_users']['unlock']['fluentcrm_lists'] ) ) {
			$settings['create_users']['unlock'][] = 'available_lists';
		}

		$settings['fluentcrm_lists']['disabled'] = ( wpf_get_option( 'create_users' ) == 0 ? true : false );

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
	 * Loads standard FluentCRM field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			$default_fields = $this->default_field_maps();

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $default_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $default_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_fluentcrm_header_begin( $id, $field ) {
		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

		echo '<style>#fluentcrm_connect {display: none;}</style>';
	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );
		if ( ! defined( 'FLUENTCRM' ) ) {
			wp_send_json_error( 'FluentCRM does not exist' );
		}
		$options                          = array();
		$options['connection_configured'] = true;
		$options['crm']                   = $this->slug;
		wp_fusion()->settings->set_multiple( $options );
		wp_send_json_success();
	}

	/**
	 * Triggered by Resync Contact button, loads lists for contact and saves to user meta
	 *
	 * @access public
	 * @return void
	 */
	public function resync_contact( $user_id ) {
		$contact = FluentCrmApi( 'contacts' )->getContactByUserId( $user_id );
		if ( ! $contact ) {
			return;
		}

		// Lets resync the lists
		$lists = array();
		foreach ( $contact->lists as $list_object ) {
			$lists[] = $list_object->id;
		}
		update_user_meta( $user_id, 'fluentcrm_lists', $lists );
	}


	private function default_field_maps() {
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

		return $fields;
	}
}
