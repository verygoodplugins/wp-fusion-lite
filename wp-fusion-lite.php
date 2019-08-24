<?php

/*
Plugin Name: WP Fusion Lite
Description: WP Fusion connects your website to your CRM or marketing automation system.
Plugin URI: https://wpfusion.com/
Version: 3.25
Author: Very Good Plugins
Author URI: https://verygoodplugins.com/
Text Domain: wp-fusion
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

define( 'WP_FUSION_VERSION', '3.25' );

// deny direct access
if ( ! function_exists( 'add_action' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}


final class WP_Fusion_Lite {

	/** Singleton *************************************************************/

	/**
	 * @var WP_Fusion_Lite The one true WP_Fusion_Lite
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
	 * @var WPF_CRM_Base
	 * @since 2.0
	 */
	public $crm_base;


	/**
	 * Access to the currently selected CRM
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
	 * Handles resticted content and redirects
	 *
	 * @var WPF_Access_Control
	 * @since 3.12
	 */
	public $access;


	/**
	 * Handles auto login sessions
	 *
	 * @var WPF_Admin_Interfaces
	 * @since 3.12
	 */
	public $auto_login;


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
	 * Insures that only one instance of WP_Fusion_Lite exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 1.0
	 * @static
	 * @static var array $instance
	 * @return WP_Fusion_Lite The one true WP_Fusion_Lite
	 */

	public static function instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof WP_Fusion_Lite ) ) {

			self::$instance = new WP_Fusion_Lite();
			self::$instance->setup_constants();

			// If PHP version not met
			if ( ! self::$instance->check_install() ) {
				add_action( 'admin_notices', array( self::$instance, 'php_version_notice' ) );
			}

			self::$instance->init_includes();

			// Create settings
			self::$instance->settings = new WPF_Settings();

			// Load active CRM
			self::$instance->crm_base = new WPF_CRM_Base();
			self::$instance->crm      = self::$instance->crm_base->crm;

			// Only useful if a CRM is selected and valid
			if ( ! empty( self::$instance->crm ) ) {

				self::$instance->setup_crm_constants();
				self::$instance->includes();

				self::$instance->logger     = new WPF_Log_Handler();
				self::$instance->user       = new WPF_User();
				self::$instance->access     = new WPF_Access_Control();
				self::$instance->auto_login = new WPF_Auto_Login();
				self::$instance->ajax       = new WPF_AJAX();
				self::$instance->batch      = new WPF_Batch();

				add_action( 'plugins_loaded', array( self::$instance, 'integrations_includes' ), 10 ); // This has to be 10 for Elementor
				add_action( 'after_setup_theme', array( self::$instance, 'integrations_includes_theme' ) );

				add_action( 'plugins_loaded', array( self::$instance, 'load_textdomain' ) );

				if ( self::$instance->is_full_version() ) {
					add_action( 'after_setup_theme', array( self::$instance, 'updater' ), 20 );
				}
			}
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
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'wp-fusion' ), WP_FUSION_VERSION );
	}

	/**
	 * Disable unserializing of the class
	 *
	 * @access protected
	 * @return void
	 */

	public function __wakeup() {
		// Unserializing instances of the class is forbidden
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'wp-fusion' ), WP_FUSION_VERSION );
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

	}

	/**
	 * Check min PHP version
	 *
	 * @access private
	 * @return bool
	 */

	private function check_install() {

		if ( version_compare( phpversion(), WPF_MIN_PHP_VERSION, '>=' ) ) {
			return true;
		} else {
			return false;
		}

	}


	/**
	 * Setup CRM related constants
	 *
	 * @access private
	 * @return void
	 */

	private function setup_crm_constants() {

		if ( ! defined( 'WPF_CRM_NAME' ) ) {
			define( 'WPF_CRM_NAME', self::$instance->crm->name );
		}

	}


	/**
	 * Defines default supported plugin integrations
	 *
	 * @access public
	 * @return array Integrations
	 */

	public function get_integrations() {

		return apply_filters( 'wpf_integrations', array() );

	}

	/**
	 * Defines default supported theme integrations
	 *
	 * @access public
	 * @return array Integrations
	 */

	public function get_integrations_theme() {

		return apply_filters( 'wpf_integrations_theme', array() );

	}

	/**
	 * Defines supported CRMs
	 *
	 * @access private
	 * @return array CRMS
	 */

	public function get_crms() {

		return apply_filters(
			'wpf_crms', array(
				'infusionsoft'   => 'WPF_Infusionsoft_iSDK',
				'activecampaign' => 'WPF_ActiveCampaign',
				'ontraport'      => 'WPF_Ontraport',
				'drip'           => 'WPF_Drip',
				'convertkit'     => 'WPF_ConvertKit',
				'agilecrm'       => 'WPF_AgileCRM',
				'salesforce'     => 'WPF_Salesforce',
				'mautic'         => 'WPF_Mautic',
				'intercom'       => 'WPF_Intercom',
				'aweber'         => 'WPF_AWeber',
				'mailerlite'     => 'WPF_MailerLite',
				'capsule'        => 'WPF_Capsule',
				'zoho'           => 'WPF_Zoho',
				'kartra'         => 'WPF_Kartra',
				'userengage'     => 'WPF_UserEngage',
				'convertfox'     => 'WPF_ConvertFox',
				'salesflare'     => 'WPF_Salesflare',
				'vtiger'         => 'WPF_Vtiger',
				'flexie'         => 'WPF_Flexie',
				'tubular'        => 'WPF_Tubular',
				'maropost'       => 'WPF_Maropost',
				'mailchimp'      => 'WPF_MailChimp',
				'sendinblue'     => 'WPF_SendinBlue',
				'hubspot'        => 'WPF_HubSpot',
				'platformly'     => 'WPF_Platformly',
				'drift'          => 'WPF_Drift',
				'staging'        => 'WPF_Staging',
				'autopilot'      => 'WPF_Autopilot',
				'customerly'     => 'WPF_Customerly',
				'copper'         => 'WPF_Copper',
				'nationbuilder'  => 'WPF_NationBuilder',
				'groundhogg'     => 'WPF_Groundhogg',
				'mailjet'        => 'WPF_Mailjet',
				'sendlane'       => 'WPF_Sendlane',
				'getresponse'    => 'WPF_GetResponse',
				'mailpoet'       => 'WPF_MailPoet',
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

		// Settings
		require_once WPF_DIR_PATH . 'includes/admin/class-settings.php';

		// CRM base class
		require_once WPF_DIR_PATH . 'includes/crms/class-base.php';

		if ( is_admin() ) {

			require_once WPF_DIR_PATH . 'includes/admin/class-notices.php';
			require_once WPF_DIR_PATH . 'includes/admin/admin-functions.php';
			require_once WPF_DIR_PATH . 'includes/admin/class-admin-interfaces.php';

			self::$instance->admin_interfaces = new WPF_Admin_Interfaces();

		}

	}

	/**
	 * Includes classes applicable for after the connection is configured
	 *
	 * @access private
	 * @return void
	 */

	private function includes() {

		require_once WPF_DIR_PATH . 'includes/admin/logging/class-log-handler.php';
		require_once WPF_DIR_PATH . 'includes/class-user.php';
		require_once WPF_DIR_PATH . 'includes/class-ajax.php';
		require_once WPF_DIR_PATH . 'includes/class-access-control.php';
		require_once WPF_DIR_PATH . 'includes/class-auto-login.php';
		require_once WPF_DIR_PATH . 'includes/admin/class-batch.php';
		require_once WPF_DIR_PATH . 'includes/admin/gutenberg/class-gutenberg.php';

		if ( is_admin() && $this->is_full_version() ) {

			require_once WPF_DIR_PATH . 'includes/admin/class-updater.php';

		} else {

			require_once WPF_DIR_PATH . 'includes/class-shortcodes.php';

		}

		// Admin bar tools
		if ( ! is_admin() && self::$instance->settings->get( 'enable_admin_bar', true ) == true ) {
			require_once WPF_DIR_PATH . 'includes/admin/class-admin-bar.php';
		}

		if ( $this->is_full_version() ) {

			require_once WPF_DIR_PATH . 'includes/class-api.php';

		}

	}

	/**
	 * Includes plugin integrations after all plugins have loaded
	 *
	 * @access private
	 * @return void
	 */

	public function integrations_includes() {

		// Integrations base
		require_once WPF_DIR_PATH . 'includes/integrations/class-base.php';

		// Store integrations for public access
		self::$instance->integrations = new stdClass();

		// Integrations autoloader
		foreach ( wp_fusion()->get_integrations() as $filename => $dependency_class ) {

			if ( class_exists( $dependency_class ) || function_exists( $dependency_class ) ) {

				if ( file_exists( WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php';
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

		// Integrations base
		require_once WPF_DIR_PATH . 'includes/integrations/class-base.php';

		// Integrations autoloader
		foreach ( wp_fusion()->get_integrations_theme() as $filename => $dependency_class ) {

			if ( class_exists( $dependency_class ) || function_exists( $dependency_class ) ) {

				if ( file_exists( WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php' ) ) {
					require_once WPF_DIR_PATH . 'includes/integrations/class-' . $filename . '.php';
				}
			}
		}

	}

	/**
	 * Load internationalization files
	 *
	 * @access public
	 * @return void
	 */

	public function load_textdomain() {

		load_plugin_textdomain( 'wp-fusion', false, 'wp-fusion/languages' );

	}


	/**
	 * Check to see if this is WPF Lite or regular
	 *
	 * @access public
	 * @return bool
	 */

	public function is_full_version() {

		$integrations = $this->get_integrations();

		if ( ! empty( $integrations ) ) {
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
		echo '<p><strong>Warning:</strong> WP Fusion requires at least PHP version ' . WPF_MIN_PHP_VERSION . ' in order to function properly. You are currently using PHP version ' . phpversion() . '. Please update your version of PHP, or contact your web host for assistance.</p>';
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

	// Get WP Fusion Running
	wp_fusion();

}
