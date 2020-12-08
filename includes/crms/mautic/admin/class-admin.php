<?php

class WPF_Mautic_Admin {

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
		add_action( 'show_field_mautic_header_begin', array( $this, 'show_field_mautic_header_begin' ), 10, 2 );
		add_action( 'show_field_mautic_password_end', array( $this, 'show_field_mautic_password_end' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}


	/**
	 * Loads mautic connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mautic_header'] = array(
			'title'   => __( 'Mautic Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
			'desc'	  => __( 'Before attempting to connect to Mautic, you\'ll first need to enable API access. You can do this by going to the configuration screen, and selecting API Settings. Turn both <strong>API Enabled</strong> and <strong>Enable Basic HTTP Auth</strong> to On.', 'wp-fusion-lite' )
		);

		$new_settings['mautic_header']['desc'] .= '<br /><br />' . __( '<strong>Note</strong> that if you\'ve just enabled the API for the first time you\'ll probably need to <a href="https://docs.mautic.org/en/troubleshooting#1-clear-the-cache" target="_blank">clear your Mautic caches</a>.', 'wp-fusion-lite' );

		$new_settings['mautic_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the URL for your Mautic account (like http://app.mautic.net/).', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);

		$new_settings['mautic_username'] = array(
			'title'   => __( 'Username', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the Username for your Mautic account.', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);

		$new_settings['mautic_password'] = array(
			'title'       => __( 'Password', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'password'	  => true,
			'post_fields' => array( 'mautic_url', 'mautic_username', 'mautic_password' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard mautic field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['user_email']['crm_field'] ) ) {
			$options['contact_fields']['user_email']['crm_field'] = 'email';
		}

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['first_name']['crm_field'] ) ) {
			$options['contact_fields']['first_name']['crm_field'] = 'firstname';
		}

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['last_name']['crm_field'] ) ) {
			$options['contact_fields']['last_name']['crm_field'] = 'lastname';
		}

		return $options;

	}

	/**
	 * Loads Mautic specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Mautic Site Tracking', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'For more information on these settings, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#mautic" target="_blank">', '</a>' ),
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main'
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://www.mautic.org/docs/en/contacts/contact_monitoring.html">Mautic site tracking</a>.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'advanced_site_tracking' ),
		);

		if ( true == $options['site_tracking'] ) {
			$std = true;
		} else {
			$std = false;
		}

		$site_tracking['advanced_site_tracking'] = array(
			'title'   => __( 'Advanced Site Tracking', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Identify logged in users to Mautic, and merge anonymous visitors with contacts after signup. %1$sSee here for more information%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/tutorials/site-tracking-scripts/#mautic" target="_blank">', '</a>' ),
			'std'     => $std,
			'type'    => 'checkbox',
			'section' => 'main',
			'tooltip' => __( 'Enabling this option improves tracking page views against identified contacts, but may have problems when caching is used that cause contact records to become merged. For optimal results make sure logged in users are excluded from all page caching.', 'wp-fusion-lite' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}


	/**
	 * Puts a div around the Mautic configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mautic_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out Mautic section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mautic_password_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #mautic div

		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$mautic_url       = esc_url_raw( $_POST['mautic_url'] );
		$mautic_username  = sanitize_text_field( $_POST['mautic_username'] );
		$mautic_password  = stripslashes( sanitize_text_field( $_POST['mautic_password'] ) ); // stripslashes to deal with special characters in passwords

		$connection = $this->crm->connect( $mautic_url, $mautic_username, $mautic_password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                               = wp_fusion()->settings->get_all();
			$options['mautic_url']				   = $mautic_url;
			$options['mautic_username']            = $mautic_username;
			$options['mautic_password']            = $mautic_password;
			$options['crm']                        = $this->slug;
			$options['connection_configured']      = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}