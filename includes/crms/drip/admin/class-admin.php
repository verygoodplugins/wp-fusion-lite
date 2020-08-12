<?php

class WPF_Drip_Admin {

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
		add_action( 'show_field_drip_header_begin', array( $this, 'show_field_drip_header_begin' ), 10, 2 );
		add_action( 'show_field_drip_token_end', array( $this, 'show_field_drip_token_end' ), 10, 2 );

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

		add_action( 'wpf_user_profile_after_contact_id', array( $this, 'show_inactive_badge' ) );
		add_filter( 'wpf_users_list_filter_options', array( $this, 'filter_options' ) );
		add_filter( 'wpf_users_list_meta_query', array( $this, 'users_list_meta_query' ), 10, 2 );

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

	}


	/**
	 * Show badge next to users who are inactive
	 *
	 * @access  public
	 * @since   3.33.12
	 */

	public function show_inactive_badge( $user_id ) {

		if ( ! empty( get_user_meta( $user_id, 'drip_inactive', true ) ) ) {
			echo '<span class="label label-default"><strong><em>(Inactive - <a href="https://wpfusion.com/documentation/crm-specific-docs/inactive-people-in-drip/" target="_blank">May not be updatable over the API</a>)</em></strong></span>';
		}

	}

	/**
	 * Add Inactive to filter options on All Users list
	 *
	 * @access  public
	 * @since   3.33.12
	 */

	public function filter_options( $options ) {

		$options['inactive'] = __( '(Inactive in Drip)', 'wp-fusion-lite' );

		return $options;

	}

	/**
	 * Custom meta query for filtering by Inactives in All Users list
	 *
	 * @access  public
	 * @since   3.33.12
	 */

	public function users_list_meta_query( $meta_query, $filter ) {

		if ( 'inactive' == $filter ) {
			$meta_query = array(
				array(
					'key'     => 'drip_inactive',
					'compare' => 'EXISTS',
				),
			);
		}

		return $meta_query;

	}


	/**
	 * Loads Drip connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['drip_header'] = array(
			'title'   => __( 'Drip Configuration', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['drip_account'] = array(
			'title'   => __( 'Account ID', 'wp-fusion-lite' ),
			'desc'    => __( 'Enter the Account ID for your Drip account (find it under Settings >> Site Setup >> 3rd Party Integrations).', 'wp-fusion-lite' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);

		$new_settings['drip_token'] = array(
			'title'       => __( 'API Token', 'wp-fusion-lite' ),
			'desc'        => __( 'Enter your Drip API token. You can find it in your Drip account <a href="https://www.getdrip.com/user/edit" target="_blank">here</a>.', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'drip_account', 'drip_token' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}


	/**
	 * Loads standard Drip field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/drip-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $drip_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $drip_fields[ $field ] );
				}
			}
		}

		return $options;

	}


	/**
	 * Loads Drip specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'Drip Settings', 'wp-fusion-lite' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main'
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="http://kb.getdrip.com/general/installing-your-javascript-snippet/">Drip site tracking</a>.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main'
		);

		$site_tracking['email_change_event'] = array(
			'title'   => __( 'Email Change Event', 'wp-fusion-lite' ),
			'desc'    => __( 'Send an <code>Email Changed</code> event when a user changes their email address.', 'wp-fusion-lite' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main'
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;

	}



	/**
	 * Puts a div around the Drip configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_drip_header_begin( $id, $field ) {

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


	public function show_field_drip_token_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #drip div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_token  = sanitize_text_field( $_POST['drip_token'] );
		$account_id = intval( $_POST['drip_account'] );

		$connection = $this->crm->connect( $api_token, $account_id, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['drip_token']            = $api_token;
			$options['drip_account']          = $account_id;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}