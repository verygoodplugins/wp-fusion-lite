<?php
/**
 * WP Fusion Lite - Elementor Forms Integration Handler.
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.45.2
 */

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Integration_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the integration with Elementor Forms.
 *
 * @since 3.45.2
 */
class WPF_Lite_Elementor_Forms_Integration extends Integration_Base {

	/**
	 * Get action ID.
	 *
	 * @since 3.45.2
	 * @return string ID
	 */
	public function get_name() {
		return 'wpfusion-lite';
	}

	/**
	 * Get action label.
	 *
	 * @since 3.45.2
	 * @return string Label
	 */
	public function get_label() {
		return __( 'WP Fusion', 'wp-fusion-lite' );
	}

	/**
	 * Registers settings.
	 *
	 * @since 3.45.2
	 *
	 * @param object $widget The widget instance.
	 */
	public function register_settings_section( $widget ) {

		$content = '<div class="wpf-upgrade-nag-container"><div class="innercontent">';
		$content .= sprintf(
			'<p>%s</p>',
			sprintf(
				esc_html__( 'With the full version of WP Fusion you can apply tags in %s based on form submissions, as well as set up field mapping between fields on your form and fields in %s.', 'wp-fusion-lite' ),
				esc_html( wp_fusion()->crm->name ),
				esc_html( wp_fusion()->crm->name )
			)
		);
		$content .= sprintf(
			'<p>%s</p>',
			sprintf(
				esc_html__( '%1$sUpgrade WP Fusion today%2$s and automate your marketing with %3$s.', 'wp-fusion-lite' ),
				'<strong>',
				'</strong>',
				esc_html( wp_fusion()->crm->name )
			)
		);
		$content .= '<div class="buttonwrapper">';
		$content .= '<a class="button-primary" href="https://wpfusion.com/pricing/?utm_source=free-plugin&utm_medium=elementor-forms&utm_campaign=free-plugin" target="_blank">View Pricing</a>';
		$content .= '</div></div></div>';

		$widget->start_controls_section(
			'section_wpfusion',
			array(
				'label'     => 'WP Fusion',
				'condition' => array(
					'submit_actions' => $this->get_name(),
				),
			)
		);

		// Upgrade nag.
		$widget->add_control(
			'wpf_nag',
			array(
				'type' => Controls_Manager::ALERT,
				'alert_type' => 'info',
				'content' => $content,
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Unsets WPF settings on export.
	 *
	 * @since 3.45.2
	 *
	 * @param array $element The element settings.
	 * @return array The element settings.
	 */
	public function on_export( $element ) {
        // This method is required by the parent class but not used.
		return $element;
	}

	/**
	 * Run
	 * Process form submission.
	 *
	 * @since 3.45.2
	 *
	 * @param object      $record       Elementor form record.
	 * @param object|bool $ajax_handler Ajax handler or false.
	 */
	public function run( $record, $ajax_handler = false ) {
		// This method is required by the parent class but not used.
	}

	/**
	 * Handle panel request.
	 *
	 * @since 3.45.2
	 *
	 * @param array $data The request data.
	 */
	public function handle_panel_request( array $data ) {
		// This method is required by the parent class but not used.
	}
}
