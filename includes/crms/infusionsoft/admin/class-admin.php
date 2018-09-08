<?php

class WPF_Infusionsoft_iSDK_Admin {

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

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 10, 2 );
		add_action( 'show_field_infusionsoft_header_begin', array( $this, 'show_field_infusionsoft_header_begin' ), 10, 2 );
		add_action( 'show_field_api_key_end', array( $this, 'show_field_api_key_end' ), 10, 2 );

		// AJAX test connection
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

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 20 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}


	/**
	 * Loads Infusionsoft connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$is_config = array();

		$is_config['infusionsoft_header'] = array(
			'title'   => __( 'Infusionsoft Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$is_config['app_name'] = array(
			'title'   => __( 'Application Name', 'wp-fusion' ),
			'desc'    => __( 'Enter the name of your Infusionsoft application (i.e. "ab123").', 'wp-fusion' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);

		$is_config['api_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'For help generating an API key, please read <a target="_blank" href="http://help.infusionsoft.com/userguides/get-started/tips-and-tricks/api-key">this knowledgebase article</a>.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'app_name', 'api_key' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $is_config );

		return $settings;

	}

	/**
	 * Loads Infusionsoft specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$new_settings['api_call'] = array(
			'title'   => __( 'API Call', 'wp-fusion' ),
			'desc'    => __( 'Check this box to make an API call when a profile is updated. See <a target="_blank" href="https://wpfusion.com/documentation/tutorials/infusionsoft-api-goals/">the documentation</a> for more info.', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'api_call_integration', 'api_call_name' )
		);

		$new_settings['api_call_integration'] = array(
			'title'   => __( 'Integration', 'wp-fusion' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'text',
			'section' => 'main'
		);

		$new_settings['api_call_name'] = array(
			'title'   => __( 'Call Name', 'wp-fusion' ),
			'desc'    => '',
			'std'     => 'contactUpdated',
			'type'    => 'text',
			'section' => 'main'
		);

		$new_settings['site_tracking_header'] = array(
			'title'   => __( 'Infusionsoft Site Tracking', 'wp-fusion' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main'
		);

		$new_settings['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://help.infusionsoft.com/userguides/campaigns-and-broadcasts/lead-sources-and-visitor-traffic/embed-the-infusionsoft-tracking-code-into-your-website">Infusionsoft site tracking</a>.', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main'
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'profile_update_tags', $settings, $new_settings );

		$settings['api_call_name']['disabled']        = ( wp_fusion()->settings->get( 'api_call' ) == 0 ? true : false );
		$settings['api_call_integration']['std']      = wp_fusion()->settings->get( 'app_name' );
		$settings['api_call_integration']['disabled'] = ( wp_fusion()->settings->get( 'api_call' ) == 0 ? true : false );

		return $settings;

	}

	/**
	 * Loads standard Infusionsoft field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/infusionsoft-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $infusionsoft_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ]['crm_field'] = $infusionsoft_fields[ $field ]['crm_field'];
				}

			}

		}


		return $options;

	}


	/**
	 * Validates a custom field to make sure it exists in IS
	 *
	 * @access public
	 * @return string IS field name
	 */

	public function validate_custom_field( $field_label ) {

		if ( is_wp_error( $this->crm->connect() ) ) {
			return false;
		}

		$fields = array( 'Id', 'Name', 'GroupId' );
		$query  = array( 'Label' => $field_label );

		$result = $this->crm->app->dsQuery( 'DataFormField', 10, 0, $query, $fields );

		if ( empty( $result ) ) {

			$fields = array( 'Id', 'Name', 'GroupId' );
			$query  = array( 'Name' => $field_label );

			$result = $this->crm->app->dsQuery( 'DataFormField', 10, 0, $query, $fields );

			if ( empty( $result ) ) {

				return false;

			} else {

				// Add underscore to custom fields
				$result[0]['Name'] = '_' . $result[0]['Name'];

				return $result[0];

			}

		} else {

			// Add underscore to custom fields
			$result[0]['Name'] = '_' . $result[0]['Name'];

			return $result[0];

		}

	}


	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_infusionsoft_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Closes API key field and allows for AJAX injected error reporting
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_api_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #infusionsoft-isdk div
		echo '<table class="form-table">';


	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$app_name = $_POST['app_name'];
		$api_key  = $_POST['api_key'];

		$connection = $this->crm->connect( $app_name, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['app_name']              = $app_name;
			$options['api_key']               = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

	}


}
