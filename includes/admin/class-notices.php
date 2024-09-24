<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles registering, displaying, and dismissing notices in the admin.
 *
 * @since 2.0.0
 */
class WPF_Admin_Notices {

	/**
	 * Array of notices to be displayed.
	 *
	 * @since 3.42.12
	 * @var array
	 */
	public $settings_notices = array();

	/**
	 * Constructs a new instance.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		add_action( 'admin_notices', array( $this, 'plugin_activation' ) );

		add_action( 'wpf_settings_notices', array( $this, 'show_compatibility_notices' ) );
		add_action( 'wpf_settings_notices', array( $this, 'show_session_notices' ) );
		add_action( 'wp_ajax_dismiss_wpf_notice', array( $this, 'dismiss_notice' ) );

		add_action( 'admin_notices', array( $this, 'display_batch_notices' ) );
	}


	/**
	 * Display batch operations notices.
	 *
	 * @since 3.44.6
	 */
	public function display_batch_notices() {
		$screen = get_current_screen();
		if ( $screen && ( 'settings_page_wpf-settings' === $screen->id || 'users' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id ) ) {
			do_action( 'wpf_settings_notices' );
		}
	}

	/**
	 * Shows a notice on first activation
	 *
	 * @access public
	 * @return mixed
	 */
	public function plugin_activation() {

		if ( ! wpf_get_option( 'connection_configured' ) && 'settings_page_wpf-settings' !== get_current_screen()->id ) {

			echo '<div id="wpf-needs-setup" data-notice="wpf-needs-setup" class="notice notice-warning wpf-notice is-dismissible">';
			echo '<p>';
			printf( esc_html__( 'To finish setting up WP Fusion, please go to the %1$sWP Fusion settings page%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( get_admin_url() . 'options-general.php?page=wpf-settings#setup' ) . '">', '</a>' );
			echo '</p>';
			echo '</div>';

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

			if ( wpf_get_option( "dismissed_{$id}" ) ) {
				continue;
			}

			echo '<div id="' . esc_attr( $id ) . '-notice" data-notice="' . esc_attr( $id ) . '" class="notice notice-warning wpf-notice is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';

		}
	}

	/**
	 * Shows notices that were queued up on init (connection issues, etc).
	 *
	 * @since 3.42.12
	 * @return mixed HTML output
	 */
	public function show_session_notices() {

		foreach ( $this->settings_notices as $message ) {

			echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';

		}
	}

	/**
	 * Adds a notice to be displayed
	 *
	 * @since 3.42.2
	 * @param string $message The message.
	 */
	public function add_notice( $message ) {

		$this->settings_notices[] = $message;
	}

	/**
	 * Saves option that notice has been dismissed
	 *
	 * @access public
	 * @return void
	 */
	public function dismiss_notice() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( isset( $_POST['id'] ) ) {

			$id = sanitize_text_field( wp_unslash( $_POST['id'] ) );
			wp_fusion()->settings->set( "dismissed_{$id}", true );

		}

		wp_die();
	}
}
