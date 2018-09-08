<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Admin_Notices {

	public function __construct() {

		add_action( 'admin_notices', array( $this, 'plugin_activation' ) );
		add_action( 'wp_ajax_wpf_dismiss_notice', array( $this, 'dismiss_notice' ) );

	}

	/**
	 * Shows a notice on first activation
	 *
	 * @access public
	 * @return mixed
	 */

	public function plugin_activation() {

		if ( wp_fusion()->settings->get( 'connection_configured' ) == false ) {

			$out = '<div id="wpf-needs-setup" class="updated">';
			$out .= '<p>';
			$out .= 'To finish setting up WP Fusion, please go to the <a href="' . get_admin_url() . '/options-general.php?page=wpf-settings#setup">WP Fusion settings page</a>.';
			$out .= '</p>';
			$out .= '</div>';

			echo $out;

		}

	}

	/**
	 * Saves option that notice has been dismissed
	 *
	 * @access public
	 * @return void
	 */

	public function dismiss_notice() {

		if( isset( $_POST['notice'] ) ) {
			update_option( 'noticed_dismissed_' . $_POST['notice'], true );
		}

		die();

	}

}

new WPF_Admin_Notices;