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

		// Settings.
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_staging_header_begin', array( $this, 'show_field_staging_header_begin' ) );
		add_action( 'show_field_staging_header_end', array( $this, 'show_field_staging_header_end' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ) );
		}
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
			'title'   => __( 'Staging', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
			'desc'    => __( 'In "Staging Mode" WP Fusion will function as normal, but no API calls will be sent. </p><p>Tags and other actions will be applied to a local buffer, and will be erased when staging mode is disabled.', 'wp-fusion-lite' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_staging_header_begin() {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_staging_header_end() {

		echo '</div>';
	}

	/**
	 * Make sure email is enabled and mapped.
	 *
	 * @since 3.41.26
	 * @param array $options The options
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] ) {

			$options['contact_fields']['user_email'] = array(
				'crm_field' => 'email',
			);

		}

		return $options;
	}
}
