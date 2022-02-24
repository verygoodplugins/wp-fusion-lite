<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * This class handles upgrades between WP Fusion versions.
 *
 * When you add a new release that needs to run an upgrade, just add a new
 *
 * public static v_X_X_X() {}
 *
 * where X_X_X is the version number that requires an upgrade. The function will
 * run one time when the plugin is updated to that version.
 *
 * @since 3.38.22
 */
class WPF_Upgrades {

	/**
	 * Constructs a new instance.
	 *
	 * @since 3.38.22
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'maybe_updated_plugin' ), 5 ); // 5 so it runs before staging site detection.
		add_action( 'wpf_plugin_updated', array( $this, 'run_upgrade_scripts' ), 10, 2 );

	}

	/**
	 * Track the current version of the plugin, and log updates to the logs.
	 *
	 * @since 3.35.9
	 */
	public function maybe_updated_plugin() {

		$version = wpf_get_option( 'wp_fusion_version' );

		if ( empty( $version ) ) {

			// Prior to 3.38.22 the version was stored in wp_options.
			$version = get_option( 'wp_fusion_version' );

			if ( ! empty( $version ) ) {
				delete_option( 'wp_fusion_version' ); // we don't need it anymore.
			}
		}

		if ( ! empty( $version ) && WP_FUSION_VERSION !== $version ) {

			wpf_log( 'notice', get_current_user_id(), 'WP Fusion updated from <strong>v' . $version . '</strong> to <strong>v' . WP_FUSION_VERSION . '</strong>.', array( 'source' => 'plugin-updater' ) );

			wp_fusion()->settings->set( 'wp_fusion_version', WP_FUSION_VERSION );

			do_action( 'wpf_plugin_updated', $version, WP_FUSION_VERSION );

		} elseif ( empty( $version ) ) {

			// First install.
			wp_fusion()->settings->set( 'wp_fusion_version', WP_FUSION_VERSION );

		}

	}

	/**
	 * See if we need to run an upgrade function for the current version.
	 *
	 * @since 3.38.22
	 *
	 * @param string $old_version The old version number.
	 * @param string $new_version The new version number.
	 */
	public function run_upgrade_scripts( $old_version, $new_version ) {

		foreach ( get_class_methods( $this ) as $function ) {

			if ( 0 === strpos( $function, 'v_' ) ) {

				$version = str_replace( 'v_', '', $function );

				if ( version_compare( $new_version, $version ) >= 0 && version_compare( $old_version, $version ) < 0 ) {
					call_user_func( 'WPF_Upgrades::' . $function );
				}
			}
		}

	}

	/**
	 * Taxonomy rules are now set to autoload to avoid a database hit when
	 * checking access.
	 *
	 * @since 3.38.22
	 * @since 3.38.23 We'll run on .23 as well just in case it got missed with .22.
	 */
	public static function v_3_38_23() {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules' );

		if ( ! empty( $taxonomy_rules ) ) {
			update_option( 'wpf_taxonomy_rules', $taxonomy_rules, true );
		}

	}

	/**
	 * Set the site_url so staging site detection works.
	 *
	 * @since 3.38.39
	 */
	public static function v_3_38_39() {

		wp_fusion()->settings->set( 'site_url', get_site_url() );

	}

}

new WPF_Upgrades();
