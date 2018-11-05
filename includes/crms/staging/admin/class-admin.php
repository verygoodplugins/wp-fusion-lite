<?php

class WPF_Staging_Admin {

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
		add_action( 'show_field_staging_header_begin', array( $this, 'show_field_staging_header_begin' ), 10, 2 );
		add_action( 'show_field_staging_header_end', array( $this, 'show_field_staging_header_end' ), 10, 2 );

	}


	/**
	 * Loads Staging connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['staging_header'] = array(
			'title'   => __( 'Staging', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
			'desc'	  => 'This is equivalent to activating "Staging Mode" from the Advanced settings tab. WP Fusion will function as normal, but no API calls will be sent. </p><p>Tags and other actions will be applied to a local buffer, and will be erased when staging mode is disabled.'
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Puts a div around the configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_staging_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out Active Campaign section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_staging_header_end( $id, $field ) {

		echo '</div>'; // close #staging div
		echo '<table class="form-table">';

	}

}