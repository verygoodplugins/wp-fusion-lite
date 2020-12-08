<?php

class WPF_MailEngine_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */

	static $docs = array(
		'hu' => 'https://docs.google.com/document/d/1lKJSEMT-731bWRIQsVnHL8sosQkqrx6rOI_VR6bWB5k/edit#heading=h.tnjtjhbffgks',
		'en' => 'https://docs.google.com/document/d/1vPCd8_DrPGC1GYHEy6zyNFKy7ymYVjmj5wzUqYd30ds/edit#heading=h.xhfywkl8jbby',
	);


	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_mailengine_header_begin', array( $this, 'show_field_mailengine_header_begin' ), 10, 2 );
		add_action( 'show_field_mailengine_client_id_end', array( $this, 'show_field_mailengine_client_id_end' ), 10, 2 );

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
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 30 );
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['mailengine_header'] = array(
			'title'   => __( 'MailEngine Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
			'desc'    => __( 'Before attempting to connect to MailEngine, you\'ll first need to enable Soap access. You can do this by requesting a <strong>client_id</strong> and get the <strong>subscribe_id</strong> from the group configuration screen. The <strong>wsdl url</strong> can be found in the developers guide (<a href="' . static::$docs['hu'] . '" target="_blank">hu</a> / <a href="' . static::$docs['en'] . '" target="_blank">en</a>)', 'wp-fusion-lite' ),
		);

		$new_settings['mailengine_developers_guide'] = array(
			'title'   => __( 'Developers guide', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
			'desc'    => __( '<ul><li><a href="' . static::$docs['hu'] . '" target="_blank">Hungarian</a></li><li><a href="' . static::$docs['en'] . '" target="_blank">English</a></li></ul>', 'wp-fusion-lite' ),
		);

		$new_settings['mailengine_wsdl_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'desc'    => __( 'URL of your MailEngine WSDL', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['mailengine_subscribe_id'] = array(
			'title'   => __( 'Subscribe id', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the Subscribe id for your MailEngine group.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'class'   => 'api_key',
		);

		$new_settings['mailengine_client_id'] = array(
			'title'       => __( 'Client id', 'wp-fusion-lite' ),
			'desc'        => __( 'Enter the Client id for your MailEngine account.', 'wp-fusion-lite' ),
			'std'         => '',
			'type'        => 'api_validate',
			'class'       => 'api_key',
			'password'    => true,
			'section'     => 'setup',
			'post_fields' => array( 'mailengine_wsdl_url', 'mailengine_client_id', 'mailengine_subscribe_id' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailengine_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out settings section
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_mailengine_client_id_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #custom div
		echo '<table class="form-table">';

	}

	/**
	 * Loads standard mailengine field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true && empty( $options['contact_fields']['user_email']['crm_field'] ) ) {
			$options['contact_fields']['user_email']['crm_field'] = 'email';
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
		$mailengine_main_settings = array();

		$mailengine_main_settings['mailengine_configuration'] = array(
			'title'   => __( 'MailEngine Configuration', 'wp-fusion-lite' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main',
		);

		$mailengine_main_settings['mailengine_affiliate'] = array(
			'title'   => __( 'Affiliate', 'wp-fusion-lite' ),
			'desc'    => __( 'Affiliate ID determines whether users\'s data can be overwritten in MailEngine. Only <strong>trusted affiliates</strong> can overwrite data. Read further details in the docs (<a href="' . static::$docs['hu'] . '" target="_blank">hu</a> / <a href="' . static::$docs['en'] . '" target="_blank">en</a>).', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'number',
			'section' => 'main',
		);

		$mailengine_main_settings['mailengine_hidden_subscribe'] = array(
			'title'   => __( 'Hidden subscribe', 'wp-fusion-lite' ),
			'desc'    => __( 'Hidden subscription is a simple opt-in subscription (<i>recommended</i>). <br />If hidden subscribe is not checked, the subscription behaves as double opt-in. Read further details in the docs (<a href="' . static::$docs['hu'] . '" target="_blank">hu</a> / <a href="' . static::$docs['en'] . '" target="_blank">en</a>).', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$mailengine_main_settings['mailengine_activate_unsubscribed'] = array(
			'title'   => __( 'Activate Unsubscribed users', 'wp-fusion-lite' ),
			'desc'    => __( 'Reactivate newly registered users in the MailEngine who previously unsubscribed. Read further details in the docs (<a href="' . static::$docs['hu'] . '" target="_blank">hu</a> / <a href="' . static::$docs['en'] . '" target="_blank">en</a>)', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $mailengine_main_settings );

		return $settings;

	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$wsdl_url     = $_POST['mailengine_wsdl_url'];
		$client_id    = $_POST['mailengine_client_id'];
		$subscribe_id = $_POST['mailengine_subscribe_id'];

		$connection = $this->crm->connect( $wsdl_url, $client_id, $subscribe_id, true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		} else {

			$options                            = wp_fusion()->settings->get_all();
			$options['mailengine_wsdl_url']     = $wsdl_url;
			$options['mailengine_client_id']    = $client_id;
			$options['mailengine_subscribe_id'] = $subscribe_id;
			$options['crm']                     = $this->slug;
			$options['connection_configured']   = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();
		}

		die();

	}


}
