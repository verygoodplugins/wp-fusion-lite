<?php

class WPF_AWeber_Admin {

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
		add_action( 'show_field_aweber_header_begin', array( $this, 'show_field_aweber_header_begin' ), 10, 2 );
		add_action( 'show_field_aweber_secret_end', array( $this, 'show_field_aweber_secret_end' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );

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
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_oauth_complete() {

		$settings = get_option( 'wpf_options', array() );

		if( isset( $_GET['oauth_token'] ) && empty( $settings['aweber_token'] ) && isset($_COOKIE['request_token_secret']) )  {

			$this->crm->connect();

			$this->crm->app->user->tokenSecret = $_COOKIE['request_token_secret'];
			$this->crm->app->user->requestToken = $_GET['oauth_token'];
			$this->crm->app->user->verifier = $_GET['oauth_verifier'];

			list($access_token, $access_token_secret) = $this->crm->app->getAccessToken();

			$settings['crm'] = 'aweber';
			$settings['aweber_token'] = $access_token;
			$settings['aweber_secret'] = $access_token_secret;
			update_option( 'wpf_options', $settings );

			// Clear cookie
			setcookie('request_token_secret', '', time() - 3600);

			wp_redirect( get_admin_url() . 'options-general.php?page=wpf-settings#setup' );
			exit;

		}

	}


	/**
	 * Loads AWeber connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['aweber_header'] = array(
			'title'   => __( 'AWeber Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup',
		);

		if( empty( $options['aweber_token'] ) && ! isset( $_GET['oauth_token'] ) ) {

			try {
			
				$this->crm->connect();
				list($request_token, $request_token_secret) = $this->crm->app->getRequestToken(get_admin_url() . 'options-general.php?page=wpf-settings#setup');

				setcookie('request_token_secret', $request_token_secret );

				$new_settings['aweber_header']['desc'] = '<table class="form-table"><tr>';
				$new_settings['aweber_header']['desc'] .= '<th scope="row"><label>Authorize</label></th>';
				$new_settings['aweber_header']['desc'] .= '<td><a class="button button-primary" href="' . $this->crm->app->getAuthorizeUrl() . '">Authorize with AWeber</a><br /><span class="description">You\'ll be taken to AWeber to authorize WP Fusion and generate access keys for this site.</td>';
				$new_settings['aweber_header']['desc'] .= '</tr></table></div><table class="form-table">';
				
			} catch (Exception $e) {

				$new_settings['aweber_header']['desc'] = '<div class="alert alert-danger">Error getting AWeber authorization URL. Please contact support. <em>' . $e->getMessage() . '<em></div>';

				// Prevent failed connection from breaking layout
				$new_settings['aweber_secret'] = array(
					'type'        => 'hidden',
					'section' 	  => 'setup'
				);

			}

		} else {

			$new_settings['aweber_token'] = array(
				'title'   => __( 'Access Token', 'wp-fusion' ),
				'std'     => '',
				'type'    => 'text',
				'section' => 'setup'
			);

			$new_settings['aweber_secret'] = array(
				'title'       => __( 'Access Secret', 'wp-fusion' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'aweber_token', 'aweber_secret' )
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads AWeber specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		$new_settings['aweber_list'] = array(
			'title'       => __( 'List', 'wp-fusion' ),
			'desc'        => __( 'Select an AWeber list to use with WP Fusion.', 'wp-fusion' ),
			'type'        => 'select',
			'placeholder' => 'Select list',
			'section'     => 'main',
			'choices'     => $options['available_lists']
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		if ( ! isset( $settings['create_users']['unlock']['aweber_lists'] ) ) {
			$settings['create_users']['unlock'][] = 'aweber_lists';
		}

		$settings['aweber_lists']['disabled'] = ( wp_fusion()->settings->get( 'create_users' ) == 0 ? true : false );

		return $settings;

	}


	/**
	 * Loads standard AWeber field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/aweber-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $aweber_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $aweber_fields[ $field ] );
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

	public function show_field_aweber_header_begin( $id, $field ) {

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


	public function show_field_aweber_secret_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #aweber div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_url = $_POST['ac_url'];
		$api_key = $_POST['ac_key'];

		$connection = $this->crm->connect( $api_url, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['ac_url']                = $api_url;
			$options['ac_key']                = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}