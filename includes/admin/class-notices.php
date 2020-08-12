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

		$screen = get_current_screen();

		if ( wp_fusion()->settings->get( 'connection_configured' ) == false && $screen->id != 'settings_page_wpf-settings' ) {

			$out = '<div id="wpf-needs-setup" class="updated">';
			$out .= '<p>';
			$out .=  sprintf( __( 'To finish setting up WP Fusion, please go to the %1$sWP Fusion settings page%2$s</a>.', 'wp-fusion-lite' ), '<a href="' . get_admin_url() . '/options-general.php?page=wpf-settings#setup">', '</a>' );
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
			update_option( 'noticed_dismissed_' . sanitize_key($_POST['notice']), true );
		}

		die();

	}

}

new WPF_Admin_Notices;