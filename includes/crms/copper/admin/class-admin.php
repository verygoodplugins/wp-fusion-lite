<?php

class WPF_Copper_Admin {

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

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_copper_header_begin', array( $this, 'show_field_copper_header_begin' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_filter( 'validate_field_copper_update_trigger', array( $this, 'validate_update_trigger' ), 10, 2 );
		add_filter( 'validate_field_copper_add_tag', array( $this, 'validate_import_trigger' ), 10, 2 );
	}

	/**
	 * Loads Copper connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['copper_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['copper_user_email'] = array(
			'title'   => __( 'User Email', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the email address for your Copper account.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['copper_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion-lite' ),
			'desc'        => __( 'You can generate an API key in your Copper account, under Settings &raquo; API Keys.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'copper_key', 'copper_user_email' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads Copper specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_settings( $settings, $options ) {

		if ( wp_fusion()->is_full_version() ) {

			$new_settings['contact_copy_header'] = array(
				'title'   => __( 'Copper Settings', 'wp-fusion-lite' ),
				'type'    => 'heading',
				'section' => 'general',
			);

			$settings = wp_fusion()->settings->insert_setting_before( 'advanced_header', $settings, $new_settings );

			$settings['access_key_desc'] = array(
				'std'     => 0,
				'type'    => 'paragraph',
				'section' => 'main',
				'desc'    => __( 'Configuring the fields below allows you to add new users to your site and update existing users based on changes in Copper. Read our <a href="https://wpfusion.com/documentation/webhooks/copper-webhooks/" target="_blank">documentation</a> for more information.', 'wp-fusion-lite' ),
			);

			$settings['access_key']['type'] = 'hidden';

			$new_settings['copper_update_trigger'] = array(
				'title'   => __( 'Update Trigger', 'wp-fusion-lite' ),
				'desc'    => __( 'When a subscriber is updated in Copper, send their data back to WordPress.', 'wp-fusion-lite' ),
				'std'     => 0,
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$new_settings['copper_update_trigger_rule_id'] = array(
				'std'     => false,
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['copper_add_tag'] = array(
				'title'       => __( 'Import Tag', 'wp-fusion-lite' ),
				'desc'        => __( 'When a person is added to this tag in Copper, they will be imported as a new WordPres user.', 'wp-fusion-lite' ),
				'type'        => 'assign_tags',
				'section'     => 'main',
				'placeholder' => 'Select a tag',
				'limit'       => 1,
			);

			$new_settings['copper_add_tag_rule_id'] = array(
				'std'     => false,
				'type'    => 'hidden',
				'section' => 'main',
			);

			$settings = wp_fusion()->settings->insert_setting_after( 'access_key', $settings, $new_settings );

		}

		return $settings;
	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */
	public function validate_import_trigger( $input, $setting ) {

		$prev_value = wpf_get_option( 'copper_add_tag' );

		// If no changes have been made, quit early
		if ( $input == $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wpf_get_option( 'copper_add_tag_rule_id' );

		if ( ! empty( $rule_id ) ) {
			wp_fusion()->crm->destroy_webhook( $rule_id );
			add_filter(
				'validate_field_copper_add_tag_rule_id',
				function () {
					return false;
				}
			);
		}

		// Abort if tag has been removed and no new one provided
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook( 'add' );

		// If there was an error, make the user select the tag again
		if ( $rule_id == false ) {
			return false;
		}

		add_filter(
			'validate_field_copper_add_tag_rule_id',
			function () use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;
	}

	/**
	 * Creates / destroys / updates webhooks on field changes
	 *
	 * @access public
	 * @return mixed
	 */
	public function validate_update_trigger( $input, $setting ) {

		$prev_value = wpf_get_option( 'copper_update_trigger' );

		// If no changes have been made, quit early
		if ( $input == $prev_value ) {
			return $input;
		}

		// See if we need to destroy an existing webhook before creating a new one
		$rule_id = wpf_get_option( 'copper_update_trigger_rule_id' );

		if ( ! empty( $rule_id ) ) {
			wp_fusion()->crm->destroy_webhook( $rule_id );
			add_filter(
				'validate_field_copper_update_trigger_rule_id',
				function () {
					return false;
				}
			);
		}

		// Abort if tag has been removed and no new one provided
		if ( $input == false ) {
			return $input;
		}

		// Add new rule and save
		$rule_id = wp_fusion()->crm->register_webhook( 'update' );

		// If there was an error, make the user select the tag again
		if ( $rule_id == false ) {
			return false;
		}

		add_filter(
			'validate_field_copper_update_trigger_rule_id',
			function () use ( &$rule_id ) {
				return $rule_id;
			}
		);

		return $input;
	}

	/**
	 * Loads standard Copper field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/copper-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $copper_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $copper_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Copper configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_copper_header_begin( $id, $field ) {

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

		$access_key = isset( $_POST['copper_key'] ) ? sanitize_text_field( wp_unslash( $_POST['copper_key'] ) ) : false;
		$user_email = isset( $_POST['copper_user_email'] ) ? sanitize_email( wp_unslash( $_POST['copper_user_email'] ) ) : false;

		$connection = $this->crm->connect( $user_email, $access_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['copper_key']            = $access_key;
			$options['copper_user_email']     = $user_email;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}
}
