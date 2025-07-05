<?php

/**
 * Plugin Name: WP Fusion Lite
 * Description: WP Fusion Lite synchronizes your WordPress users with your CRM or marketing automation system.
 * Plugin URI: https://wpfusion.com/
 * Version: 3.46.3
 * Author: Very Good Plugins
 * Author URI: https://verygoodplugins.com/
 * Text Domain: wp-fusion-lite
 */

/**
 * @copyright Copyright (c) 2018. All rights reserved.
 *
 * @license   Released under the GPL license http://www.opensource.org/licenses/gpl-license.php
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * **********************************************************************
 */

define( 'WP_FUSION_VERSION', '3.46.3' );

// deny direct access.
if ( ! function_exists( 'add_action' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

/**
 * Main WP_Fusion class.
 *
 * @since 1.0.0
 */
final class WP_Fusion_Lite {

	/** Singleton *************************************************************/

	/**
	 * @var WP_Fusion The one true WP_Fusion
	 * @since 1.0
	 */
	private static $instance;


	/**
	 * Contains all active integrations classes
	 *
	 * @since 3.0
	 */
	public $integrations;


	/**
	 * Manages configured CRMs
	 *
	 * @since 2.0
	 * @since 3.40 No longer in use, maintained for backwards compatibility.
	 *
	 * @var   WPF_CRM_Base
	 */
	public $crm_base;


	/**
	 * Access to the currently selected CRM.
	 *
	 * @var crm
	 * @since 2.0
	 */
	public $crm;


	/**
	 * Handler for AJAX and and asynchronous functions
	 *
	 * @var crm
	 * @since 2.0
	 */
	public $ajax;


	/**
	 * Handler for batch processing
	 *
	 * @var batch
	 * @since 3.0
	 */
	public $batch;


	/**
	 * Logging and diagnostics class
	 *
	 * @var logger
	 * @since 3.0
	 */
	public $logger;


	/**
	 * User handler - registration, sync, and updates
	 *
	 * @var WPF_User
	 * @since 2.0
	 */
	public $user;


	/**
	 * Stores configured admin meta boxes and other admin interfaces
	 *
	 * @var WPF_Admin_Interfaces
	 * @since 2.0
	 */
	public $admin_interfaces;

	/**
	 * Handles admin notices.
	 *
	 * @var WPF_Admin_Notices
	 * @since 3.42.12
	 */
	public $admin_notices;


	/**
	 * Handles restricted content and redirects
	 *
	 * @var WPF_Access_Control
	 * @since 3.12
	 */
	public $access;


	/**
	 * Handles auto login sessions
	 *
	 * @var WPF_Auto_Login
	 * @since 3.12
	 */
	public $auto_login;


	/**
	 * Handles lead source tracking
	 *
	 * @var WPF_Lead_Sources
	 * @since 3.30.4
	 */
	public $lead_source_tracking;

	/**
	 * Handles ISO 3166-1 alpha-3 and alpha-2 codes
	 *
	 * @var WPF_ISO_Regions
	 * @since 3.44.3
	 */
	public $iso_regions;


	/**
	 * The settings instance variable
	 *
	 * @var WPF_Settings
	 * @since 1.0
	 */
	public $settings;


	/**
	 * Main WP_Fusion_Lite Instance
	 *
	 * Ensures that only one instance of WP_Fusion_Lite exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 1.0
	 *
	 * @static var array $instance
	 * @return WP_Fusion_Lite The one true WP_Fusion_Lite
	 */
	public static function instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof WP_Fusion_Lite ) ) {

			self::$instance = new WP_Fusion_Lite();

			self::$instance->setup_constants();
			self::$instance->check_install();
			self::$instance->init_includes();

			// Create settings.
			self::$instance->settings = new WPF_Settings();
			self::$instance->logger   = new WPF_Log_Handler();
			self::$instance->batch    = new WPF_Batch();

			if ( is_admin() ) {
				self::$instance->admin_notices = new WPF_Admin_Notices();
			}

			// Integration modules are stored here for easy access, for
			// example wp_fusion()->integrations->{'woocommerce'}->process_order( $order_id );.

			self::$instance->integrations = new stdClass();

			// Load the CRM modules.
			add_action( 'plugins_loaded', array( self::$instance, 'init_crm' ) );

			// Only useful if a CRM is selected.
			if ( self::$instance->settings->get( 'connection_configured' ) ) {

				self::$instance->includes();

				if ( is_admin() ) {
					self::$instance->admin_interfaces = new WPF_Admin_Interfaces();
				}

				self::$instance->user                 = new WPF_User();
				self::$instance->lead_source_tracking = new WPF_Lead_Source_Tracking();
				self::$instance->access               = new WPF_Access_Control();
				self::$instance->auto_login           = new WPF_Auto_Login();
				self::$instance->ajax                 = new WPF_AJAX();
				self::$instance->iso_regions          = new WPF_ISO_Regions();

				if ( self::$instance->is_full_version() ) {
					add_action( 'plugins_loaded', array( self::$instance, 'integrations_includes' ), 10 ); // This has to be 10 for Elementor.
					add_action( 'after_setup_theme', array( self::$instance, 'integrations_includes_theme' ) );
				}

				add_action( 'init', array( self::$instance, 'init' ), 6 ); // 6 so it's after WPF_CRM_Base::init().

			}

			if ( self::$instance->is_full_version() ) {
				add_action( 'init', array( self::$instance, 'updater' ) );
				add_filter( 'init', array( self::$instance, 'load_textdomain' ), 5 ); // 5 so it's before WPF_Optionss::init().
			}

			register_deactivation_hook( __FILE__, array( self::$instance, 'deactivate' ) );

		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @access protected
	 * @return void
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'wp-fusion-lite' ), esc_html( WP_FUSION_VERSION ) );
	}

	/**
	 * Disable unserializing of the class
	 *
	 * @access protected
	 * @return void
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'wp-fusion-lite' ), esc_html( WP_FUSION_VERSION ) );
	}

	/**
	 * Setup plugin constants
	 *
	 * @access private
	 * @return void
	 */
	private function setup_constants() {

		if ( ! defined( 'WPF_MIN_WP_VERSION' ) ) {
			define( 'WPF_MIN_WP_VERSION', '4.0' );
		}

		if ( ! defined( 'WPF_MIN_PHP_VERSION' ) ) {
			define( 'WPF_MIN_PHP_VERSION', '5.6' );
		}

		if ( ! defined( 'WPF_DIR_PATH' ) ) {
			define( 'WPF_DIR_PATH', plugin_dir_path( __FILE__ ) );
		}

		if ( ! defined( 'WPF_PLUGIN_PATH' ) ) {
			define( 'WPF_PLUGIN_PATH', plugin_basename( __FILE__ ) );
		}

		if ( ! defined( 'WPF_DIR_URL' ) ) {
			define( 'WPF_DIR_URL', plugin_dir_url( __FILE__ ) );
		}

		if ( ! defined( 'WPF_STORE_URL' ) ) {
			define( 'WPF_STORE_URL', 'https://wpfusion.com' );
		}

		if ( ! defined( 'WPF_EDD_ITEM_ID' ) ) {
			define( 'WPF_EDD_ITEM_ID', 'XXXX' );
		}
	}

	/**
	 * Fires when WP Fusion is deactivated.
	 *
	 * @since 3.38.31
	 */
	public function deactivate() {

		$timestamp = wp_next_scheduled( 'wpf_background_process_cron' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpf_background_process_cron' );
		}
	}

	/**
	 * Check min PHP version
	 *
	 * @access private
	 * @return bool
	 */
	private function check_install() {

		if ( ! version_compare( phpversion(), WPF_MIN_PHP_VERSION, '>=' ) ) {
			add_action( 'admin_notices', array( self::$instance, 'php_version_notice' ) );
		}

		if ( ! $this->is_full_version() ) {

			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . '/wp-admin/includes/plugin.php';
			}

			// If the full version has been installed, deactivate this one.
			if ( is_plugin_active( 'wp-fusion/wp-fusion.php' ) ) {
				add_action( 'admin_notices', array( self::$instance, 'full_version_notice' ) );
				deactivate_plugins( plugin_basename( __FILE__ ) );
			}
		}
	}


	/**
	 * Defines default supported plugin integrations
	 *
	 * @access public
	 * @return array Integrations
	 */
	public function get_integrations() {

		return apply_filters(
			'wpf_integrations',
			array()
		);
	}

	/**
	 * Defines default supported theme integrations
	 *
	 * @access public
	 * @return array Integrations
	 */
	public function get_integrations_theme() {

		return apply_filters(
			'wpf_integrations_theme',
			array()
		);
	}

	/**
	 * Defines supported CRMs
	 *
	 * @access private
	 * @return array CRMS
	 */
	public function get_crms() {

		return apply_filters(
			'wpf_crms',
			array(
				'infusionsoft'     => 'WPF_Infusionsoft_iSDK',
				'activecampaign'   => 'WPF_ActiveCampaign',
				'ontraport'        => 'WPF_Ontraport',
				'drip'             => 'WPF_Drip',
				'convertkit'       => 'WPF_ConvertKit',
				'agilecrm'         => 'WPF_AgileCRM',
				'salesforce'       => 'WPF_Salesforce',
				'mautic'           => 'WPF_Mautic',
				'intercom'         => 'WPF_Intercom',
				// 'aweber'         => 'WPF_AWeber',
				'mailerlite'       => 'WPF_MailerLite',
				'capsule'          => 'WPF_Capsule',
				'zoho'             => 'WPF_Zoho',
				'kartra'           => 'WPF_Kartra',
				'userengage'       => 'WPF_UserEngage',
				'convertfox'       => 'WPF_ConvertFox',
				'salesflare'       => 'WPF_Salesflare',
				// 'vtiger'         => 'WPF_Vtiger',
				'flexie'           => 'WPF_Flexie',
				'tubular'          => 'WPF_Tubular',
				'maropost'         => 'WPF_Maropost',
				'mailchimp'        => 'WPF_MailChimp',
				'sendinblue'       => 'WPF_SendinBlue',
				'hubspot'          => 'WPF_HubSpot',
				'platformly'       => 'WPF_Platformly',
				'drift'            => 'WPF_Drift',
				'staging'          => 'WPF_Staging',
				'autopilot'        => 'WPF_Autopilot',
				'customerly'       => 'WPF_Customerly',
				'copper'           => 'WPF_Copper',
				'nationbuilder'    => 'WPF_NationBuilder',
				'groundhogg'       => 'WPF_Groundhogg',
				'mailjet'          => 'WPF_Mailjet',
				'sendlane'         => 'WPF_Sendlane',
				'getresponse'      => 'WPF_GetResponse',
				'mailpoet'         => 'WPF_MailPoet',
				'klaviyo'          => 'WPF_Klaviyo',
				'birdsend'         => 'WPF_BirdSend',
				'zerobscrm'        => 'WPF_ZeroBSCRM',
				'mailengine'       => 'WPF_MailEngine',
				'klick-tipp'       => 'WPF_KlickTipp',
				'sendfox'          => 'WPF_SendFox',
				'quentn'           => 'WPF_Quentn',
				// 'loopify'        => 'WPF_Loopify',
				'wp-erp'           => 'WPF_WP_ERP',
				'engagebay'        => 'WPF_EngageBay',
				'fluentcrm'        => 'WPF_FluentCRM',
				'growmatik'        => 'WPF_Growmatik',
				'highlevel'        => 'WPF_HighLevel',
				'emercury'         => 'WPF_Emercury',
				'fluentcrm-rest'   => 'WPF_FluentCRM_REST',
				'pulsetech'        => 'WPF_PulseTechnologyCRM',
				'autonami'         => 'WPF_Autonami',
				'bento'            => 'WPF_Bento',
				'dynamics-365'     => 'WPF_Dynamics_365',
				'groundhogg-rest'  => 'WPF_Groundhogg_REST',
				'moosend'          => 'WPF_MooSend',
				'constant-contact' => 'WPF_Constant_Contact',
				'pipedrive'        => 'WPF_Pipedrive',
				'engage'           => 'WPF_Engage',
				'ortto'            => 'WPF_Ortto',
				'emailoctopus'     => 'WPF_EmailOctopus',
				'customer-io'      => 'WPF_Customer_IO',
				'omnisend'         => 'WPF_Omnisend',
				'encharge'         => 'WPF_Encharge',
				'sender'           => 'WPF_Sender',
			)
		);
	}

	/**
	 * Include required files
	 *
	 * @access private
	 * @return void
	 */
	private function init_includes() {

		// Functions.
		require_once WPF_DIR_PATH . 'includes/functions.php';

		// Settings.
		require_once WPF_DIR_PATH . 'includes/admin/class-staging-sites.php';
		require_once WPF_DIR_PATH . 'includes/admin/class-settings.php';
		require_once WPF_DIR_PATH . 'includes/admin/logging/class-log-handler.php';
		require_once WPF_DIR_PATH . 'includes/admin/class-batch.php';

		// CRM base class.
		require_once WPF_DIR_PATH . 'includes/crms/class-base.php';

		if ( is_admin() ) {
			require_once WPF_DIR_PATH . 'includes/admin/class-notices.php';
			require_once WPF_DIR_PATH . 'includes/admin/admin-functions.php';
			require_once WPF_DIR_PATH . 'includes/admin/class-upgrades.php';
		}

		if ( $this->is_full_version() ) {

			// Plugin updater.
			include WPF_DIR_PATH . 'includes/admin/class-updater.php';

			// Woo HPOS compatibility must be declared early.
			require_once WPF_DIR_PATH . 'includes/integrations/class-woocommerce-compatibility.php';
		} else {
			require_once WPF_DIR_PATH . 'includes/admin/class-lite-helper.php';
		}
	}

	/**
	 * Includes classes applicable for after the connection is configured
	 *
	 * @access private
	 * @return void
	 */
	private function includes() {

		require_once WPF_DIR_PATH . 'includes/class-user.php';
		require_once WPF_DIR_PATH . 'includes/class-lead-source-tracking.php';
		require_once WPF_DIR_PATH . 'includes/class-ajax.php';
		require_once WPF_DIR_PATH . 'includes/class-access-control.php';
		require_once WPF_DIR_PATH . 'includes/class-auto-login.php';
		require_once WPF_DIR_PATH . 'includes/class-iso-regions.php';
		require_once WPF_DIR_PATH . 'includes/admin/gutenberg/class-gutenberg.php';
		require_once WPF_DIR_PATH . 'includes/admin/class-admin-interfaces.php';
		require_once WPF_DIR_PATH . 'includes/admin/class-tags-select-api.php';

		// Shortcodes.
		if ( ! is_admin() && self::$instance->settings->get( 'connection_configured' ) ) {
			require_once WPF_DIR_PATH . 'includes/class-shortcodes.php';
		}

		// Admin bar tools.
		if ( ! is_admin() && self::$instance->settings->get( 'enable_admin_bar' ) ) {
			require_once WPF_DIR_PATH . 'includes/admin/class-admin-bar.php';
		}

		// Incoming webhooks handler + WooCommerce HPOS compatibility.
		if ( $this->is_full_version() ) {
			require_once WPF_DIR_PATH . 'includes/integrations/class-forms-helper.php';
			require_once WPF_DIR_PATH . 'includes/class-api.php';
		}
	}



	/**
	 * Initialize the CRM object based on the currently configured options
	 *
	 * @return object CRM Interface
	 */
	public function init_crm() {

		self::$instance->crm      = new WPF_CRM_Base();
		self::$instance->crm_base = self::$instance->crm; // backwards compatibility with pre 3.40 integrations.

		do_action( 'wpf_crm_loaded', self::$instance->crm );

		return self::$instance->crm;
	}

	/**
	 * Fires when WP Fusion has loaded.
	 *
	 * When developing addons, use this hook to initialize any functionality
	 * that depends on WP Fusion.
	 *
	 * @since 3.37.14
	 *
	 * @link  https://wpfusion.com/documentation/actions/wp_fusion_init/
	 */
	public function init() {

		/**
		 * Init CRM.
		 *
		 * Indicates that the CRM has been set up and allows accessing the CRM
		 * or modifying it by reference.
		 *
		 * @since 3.37.14
		 * @since 3.38.24 CRM is now passed by reference because it's cooler.
		 *
		 * @param WPF_* object  The CRM class.
		 */

		do_action_ref_array( 'wp_fusion_init_crm', array( &self::$instance->crm ) );

		/**
		 * Init.
		 *
		 * WP Fusion is ready.
		 *
		 * @since 3.37.14
		 *
		 * @link  https://wpfusion.com/documentation/actions/wp_fusion_init/
		 */

		do_action( 'wp_fusion_init' );
	}

	/**
	 * Includes plugin integrations after all plugins have loaded
	 *
	 * @access private
	 * @return void
	 */
	public function integrations_includes() {

		// Integrations base.
		require_once WPF_DIR_PATH . 'includes/integrations/class-base.php';

		// Integrations autoloader.

		foreach ( wp_fusion()->get_integrations() as $filename => $dependency_class ) {

			$filename = sanitize_file_name( $filename );

			if ( class_exists( $dependency_class ) || function_exists( $dependency_class ) ) {

				if ( file_exists( WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php';
				} elseif ( file_exists( WPF_DIR_PATH . 'includes/integrations/' . $filename . '/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/integrations/' . $filename . '/class-' . $filename . '.php';
				}
			}
		}
	}

	/**
	 * Includes theme integrations after all theme has loaded
	 *
	 * @access private
	 * @return void
	 */
	public function integrations_includes_theme() {

		// Integrations base.
		require_once WPF_DIR_PATH . 'includes/integrations/class-base.php';

		// Integrations autoloader.
		foreach ( wp_fusion()->get_integrations_theme() as $filename => $dependency_class ) {

			$filename = sanitize_file_name( $filename );

			if ( class_exists( $dependency_class ) || function_exists( $dependency_class ) ) {

				if ( file_exists( WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php';
				}
			}
		}
	}

	/**
	 * Check to see if this is WPF Lite or regular
	 *
	 * @access public
	 * @return bool
	 */
	public function is_full_version() {

		if ( class_exists( 'WP_Fusion' ) ) {
			return true;
		} else {
			return false;
		}
	}


	/**
	 * Returns error message and deactivates plugin when error returned.
	 *
	 * @access public
	 * @return mixed error message.
	 */
	public function php_version_notice() {

		echo '<div class="notice notice-error">';
		echo '<p>';
		printf( esc_html__( 'Heads up! WP Fusion requires at least PHP version %1$s in order to function properly. You are currently using PHP version %2$s. Please update your version of PHP, or contact your web host for assistance.', 'wp-fusion-lite' ), esc_html( WPF_MIN_PHP_VERSION ), esc_html( phpversion() ) );
		echo '</p>';
		echo '</div>';
	}


	/**
	 * Display a warning when the full version of WPF is active
	 *
	 * @access public
	 * @return mixed error message.
	 */
	public function full_version_notice() {

		echo '<div class="notice notice-error">';
		echo '<p>';
		esc_html_e( 'Heads up: It looks like you\'ve installed the full version of WP Fusion. We have deactivated WP Fusion Lite for you, and copied over all your settings. You can go ahead and delete the WP Fusion Lite plugin 🙂', 'wp-fusion-lite' );
		echo '</p>';
		echo '</div>';
	}
}


/**
 * The main function responsible for returning the one true WP Fusion
 * Instance to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * Example: <?php $wpf = wp_fusion(); ?>
 *
 * @return object The one true WP Fusion Instance
 */

if ( ! function_exists( 'wp_fusion' ) ) {

	function wp_fusion() {
		return WP_Fusion_Lite::instance();
	}

	// Get WP Fusion running.
	wp_fusion();

}
