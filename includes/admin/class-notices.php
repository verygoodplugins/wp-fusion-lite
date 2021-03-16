<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Admin_Notices {

	public function __construct() {

		add_action( 'admin_notices', array( $this, 'plugin_activation' ) );

		add_action( 'wpf_settings_notices', array( $this, 'show_compatibility_notices' ) );
		add_action( 'wp_ajax_dismiss_wpf_notice', array( $this, 'dismiss_notice' ) );

	}

	/**
	 * Shows a notice on first activation
	 *
	 * @access public
	 * @return mixed
	 */

	public function plugin_activation() {

		if ( ! wp_fusion()->settings->get( 'connection_configured' ) && 'settings_page_wpf-settings' !== get_current_screen()->id ) {

			$out  = '<div id="wpf-needs-setup" class="updated">';
			$out .= '<p>';
			$out .= sprintf( __( 'To finish setting up WP Fusion, please go to the %1$sWP Fusion settings page%2$s</a>.', 'wp-fusion-lite' ), '<a href="' . get_admin_url() . '/options-general.php?page=wpf-settings#setup">', '</a>' );
			$out .= '</p>';
			$out .= '</div>';

			echo $out;

		}

	}

	/**
	 * Shows compatibility notices with other plugins, on the WPF settings page
	 *
	 * @since 3.33.4
	 * @return mixed HTML output
	 */

	public function show_compatibility_notices() {

		$notices = apply_filters( 'wpf_compatibility_notices', array() );

		foreach ( $notices as $id => $message ) {

			if ( wp_fusion()->settings->get( "dismissed_{$id}" ) ) {
				continue;
			}

			echo '<div id="' . $id . '-notice" data-notice="' . $id . '" class="notice notice-warning wpf-notice is-dismissible"><p>' . $message . '</p></div>';

		}

		if ( wp_fusion()->settings->get( 'staging_mode' ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			printf( __( '<strong>Heads up:</strong> WP Fusion is currently in Staging Mode. No data will be sent to or loaded from %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name );

			echo '</p></div>';

		}

	}

	/**
	 * Saves option that notice has been dismissed
	 *
	 * @access public
	 * @return void
	 */

	public function dismiss_notice() {

		wp_fusion()->settings->set( "dismissed_{$_POST['id']}", true );

		wp_die();

	}

}

new WPF_Admin_Notices;