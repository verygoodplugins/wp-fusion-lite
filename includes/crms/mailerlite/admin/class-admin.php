<?php

class WPF_MailerLite_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @since   1.0
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ) );
		add_action( 'show_field_mailerlite_header_begin', array( $this, 'show_field_mailerlite_header_begin' ), 10, 2 );

		// AJAX.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) === $this->slug ) {
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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

		add_filter( 'validate_field_mailerlite_update_trigger', array( $this, 'validate_update_trigger' ) );
		add_filter( 'validate_field_mailerlite_add_tag', array( $this, 'validate_import_trigger' ) );

		add_action( 'wpf_resetting_options', array( $this, 'delete_webhooks' ) );

	}


	/**
	 * Loads mailerlite connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings ) {

		$new_settings = array(
			'mailerlite_header' => array(
				'title'   => __( 'MailerLite Configuration', 'wp-fusion-lite' ),
				'type'    => 'heading',
				'section' => 'setup',
			),
			'mailerlite_key' => array(
				'title'       => __( 'API Token', 'wp-fusion-lite' ),
				'desc'        => __( 'You can find your API token in the <a href="https://dashboard.mailerlite.com/integrations/api" target="_blank">Developer API</a> settings of your MailerLite account.', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'post_fields' => array( 'mailerlite_key' ),
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads MailerLite specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$new_settings['contact_copy_header'] = array(
			'title'   => __( 'MailerLite Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced'
		);

		$new_settings['email_changes'] = array(
			'title'   => __( 'Email Address Changes', 'wp-fusion-lite' ),
			'type'    => 'select',
			'section' => 'advanced',
			'std'	  => 'ignore',
			'choices' => array(
				'ignore'	=> 'Ignore',
				'duplicate' => 'Duplicate and Delete'
			),
			'desc'    => __( 'MailerLite doesn\'t allow for changing the email address of an existing subscriber. Choose <strong>Ignore</strong> and WP Fusion will continue updating a single subscriber, ignoring email address changes. Choose <strong>Duplicate and Delete</strong> and WP Fusion will attempt to create a new subscriber with the same details when an email address has been changed, and remove the original subscriber.', 'wp-fusion-lite' ),
		);

		$new_settings['email_changes']['desc'] .= '<br /><br />';
		$new_settings['email_changes']['desc'] .= sprintf( __( 'For more information, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/crm-specific-docs/email-address-changes-with-mailerlite/" target="_blank">', '</a>' );

		$settings = wp_fusion()->settings->insert_setting_before( 'advanced_header', $settings, $new_settings );

		if ( wp_fusion()->is_full_version() ) {

			$settings['access_key_desc'] = array(
				'type'    => 'paragraph',
				'section' => 'main',
				'desc'    => __( 'Configuring the fields below allows you to add new users to your site and update existing users based on changes in MailerLite. Read our <a href="https://wpfusion.com/documentation/webhooks/mailerlite-webhooks/" target="_blank">documentation</a> for more information.', 'wp-fusion-lite' ),
			);

			$settings['access_key_desc']['desc'] .= ' ' . sprintf( __( 'To list all registered webhooks (for debugging purposes), %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings&ml_debug=true' ) ) . '">', '</a>' );

			if ( isset( $_GET['ml_debug'] ) ) {

				$settings['access_key_desc']['desc'] .= ' ' . sprintf( __( 'To <strong>delete</strong> all registered webhooks, %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings&ml_debug=true&ml_destroy_all_webhooks=true' ) ) . '">', '</a>' );

				$webhooks = $this->crm->get_webhooks();

				if ( isset( $_GET['ml_destroy_all_webhooks'] ) ) {

					foreach ( $webhooks as $webhook ) {
						$this->crm->destroy_webhook( $webhook->id );
					}

					$webhooks = 'Destroyed ' . count( $webhooks ) . ' webhooks.';

				}

				$settings['access_key_desc']['desc'] .= '<pre>' . wpf_print_r( $webhooks, true ) . '</pre>';

			}

			$new_settings['mailerlite_update_trigger'] = array(
				'title'   => __( 'Update Trigger', 'wp-fusion-lite' ),
				'desc'    => __( 'When a subscriber is updated in MailerLite, send their data back to WordPress.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$new_settings['mailerlite_update_trigger_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['mailerlite_update_trigger_group_add_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['mailerlite_update_trigger_group_remove_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['mailerlite_add_tag'] = array(
				'title'       => __( 'Import Group', 'wp-fusion-lite' ),
				'desc'        => __( 'When a contact is added to this group in MailerLite, they will be imported as a new WordPress user.', 'wp-fusion-lite' ),
				'type'        => 'assign_tags',
				'section'     => 'main',
				'placeholder' => __( 'Select a group', 'wp-fusion-lite' ),
				'limit'       => 1,
			);

			$new_settings['mailerlite_add_tag_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['mailerlite_import_notification'] = array(
				'title'   => __( 'Enable Notifications', 'wp-fusion-lite' ),
				'desc'    => __( 'Send a welcome email to new users containing their username and a password reset link.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$settings = wp_fusion()->settings->insert_setting_before( 'access_key', $settings, $new_settings );

		}

		// add a settings field to let the user select a default optin status for new contacts.
		$new_settings = array(
			'mailerlite_optin' => array(
				'title'   => __( 'Default Optin Status', 'wp-fusion-lite' ),
				'desc'    => __( 'Select the default optin status for new contacts.', 'wp-fusion-lite' ),
				'tooltip' => __( '"Default" will respect the opt-in settings configured in MailerLite. Set "Active" to mark the subscriber confirmed, or "Unconfirmed" to trigger a double-opt-in email.', 'wp-fusion-lite' ),
				'type'    => 'radio',
				'section' => 'main',
				'choices' => array(
					''             => __( 'Default', 'wp-fusion-lite' ),
					'active'       => __( 'Active', 'wp-fusion-lite' ),
					'unconfirmed'  => __( 'Unconfirmed', 'wp-fusion-lite' ),
					'unsubscribed' => __( 'Unsubscribed', 'wp-fusion-lite' ),
				),
			),
		);

		$new_settings['mailerlite_optin']['desc'] .= ' ' . sprintf( __( 'For more information, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/crm-specific-docs/mailerlite-double-opt-ins/" target="_blank">', '</a>' );

		$new_settings['mailerlite_resubscribe'] = array(
			'title'   => __( 'Resubscribe', 'wp-fusion-lite' ),
			'desc'    => __( 'When adding a subscriber to a new group, resubscribe them in case they have unsubscribed.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		$new_settings = array();

		$new_settings['site_tracking_header'] = array(
			'title'   => __( 'MailerLite Site Tracking', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$new_settings['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#mailerlite">MailerLite site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Creates or destroys webhooks when the Import Group setting is changed.
	 *
	 * @since 3.10.0
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @param array $input The settings input.
	 * @return array|WP_Error The settings input or a WP_Error object.
	 */
	public function validate_import_trigger( $input ) {

		// See if we need to destroy an existing webhook before creating a new one.
		$rule_id = wpf_get_option( 'mailerlite_add_tag_rule_id' );

		if ( ! empty( $rule_id ) ) {
			$this->crm->destroy_webhook( $rule_id );
			add_filter( 'validate_field_mailerlite_add_tag_rule_id', '__return_false' );
		}

		// Abort if tag has been removed and no new one provided.
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_ids = $this->crm->register_webhooks( 'add' );

		// If there was an error, make the user select the tag again.
		if ( is_wp_error( $rule_ids ) || empty( $rule_ids ) ) {
			return $rule_ids;
		}

		// Save it.

		add_filter(
			'wpf_initialize_options',
			function( $options ) use ( &$rule_ids ) {

				$options['mailerlite_add_tag_rule_id'] = $rule_ids[0];
				return $options;

			}
		);

		return $input;

	}

	/**
	 * Creates or destroys webhooks when the Update Trigger setting is changed.
	 *
	 * @since 3.10.0
	 * @since 3.40.55 Updated and refactored to support v2 API webhooks.
	 *
	 * @param bool $input The settings input.
	 * @return bool|WP_Error The settings input or a WP_Error object.
	 */
	public function validate_update_trigger( $input ) {

		// See if we need to destroy existing webhooks before creating a new one.
		$rule_ids = array_filter(
			array(
				wpf_get_option( 'mailerlite_update_trigger_rule_id' ),
				wpf_get_option( 'mailerlite_update_trigger_group_add_rule_id' ),
				wpf_get_option( 'mailerlite_update_trigger_group_remove_rule_id' ),
			)
		);

		if ( ! empty( $rule_ids ) ) {

			foreach ( $rule_ids as $rule_id ) {
				$this->crm->destroy_webhook( $rule_id );
			}

			add_filter( 'validate_field_mailerlite_update_trigger_rule_id', '__return_false' );
			add_filter( 'validate_field_mailerlite_update_trigger_group_add_rule_id', '__return_false' );
			add_filter( 'validate_field_mailerlite_update_trigger_group_remove_rule_id', '__return_false' );

		}

		// Abort if tag has been removed and no new one provided.
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_ids = $this->crm->register_webhooks( 'update' );

		// If there was an error, make the user select the tag again.
		if ( is_wp_error( $rule_ids ) || empty( $rule_ids ) ) {
			return $rule_ids;
		}

		// Save it.

		add_filter(
			'wpf_initialize_options',
			function( $options ) use ( &$rule_ids ) {

				$options['mailerlite_update_trigger_rule_id'] = $rule_ids[0];

				if ( ! $this->crm->is_v2() ) {

					// The v1 API registers 3 webhooks for update.
					$options['mailerlite_update_trigger_group_add_rule_id']    = $rule_ids[1];
					$options['mailerlite_update_trigger_group_remove_rule_id'] = $rule_ids[2];

				}

				return $options;

			}
		);

		return $input;

	}

	/**
	 * Delete webhooks when settings are reset.
	 *
	 * @since 3.10.0
	 *
	 * @param array $options The options.
	 */
	public function delete_webhooks( $options ) {

		if ( ! empty( $options['mailerlite_add_tag_rule_id'] ) ) {

			// The add webhook.
			$this->crm->destroy_webhook( $options['mailerlite_add_tag_rule_id'] );

		}

		if ( ! empty( $options['mailerlite_update_trigger_rule_id'] ) ) {

			// The three update webhooks.

			$this->crm->destroy_webhook( $options['mailerlite_update_trigger_rule_id'] );
			$this->crm->destroy_webhook( $options['mailerlite_update_trigger_group_add_rule_id'] );
			$this->crm->destroy_webhook( $options['mailerlite_update_trigger_group_remove_rule_id'] );

		}

	}

	/**
	 * Loads standard mailerlite field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/mailerlite-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $mailerlite_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $mailerlite_fields[ $field ] );
				}

			}

		}

		return $options;

	}


	/**
	 * Puts a div around the mailerlite configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailerlite_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$api_key = sanitize_text_field( wp_unslash( $_POST['mailerlite_key'] ) );

		$connection = $this->crm->connect( $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['mailerlite_key']        = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();

	}

}