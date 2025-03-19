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

	/**
	 * Set the access control system on by default.
	 *
	 * @since 3.39.0
	 */
	public static function v_3_39_0() {

		wp_fusion()->settings->set( 'restrict_content', true );
	}

	/**
	 * Update the duplicate site key to include a hash to prevent it from
	 * getting replaced on WP Engine / CloudWays.
	 *
	 * @see WPF_StagingSites::get_duplicate_site_lock_key().
	 *
	 * @since 3.40.16
	 */
	public static function v_3_40_16() {

		wp_fusion()->settings->set( 'site_url', WPF_Staging_Sites::get_duplicate_site_lock_key() );
	}

	/**
	 * Copies the AffiliateWP "Apply Tags - Approved" setting to the Active setting instead.
	 *
	 * @since 3.41.42
	 */
	public static function v_3_41_42() {

		$setting = wpf_get_option( 'awp_apply_tags_approved' );

		if ( ! empty( $setting ) ) {
			wp_fusion()->settings->set( 'awp_apply_tags_active', $setting );
		}
	}

	/**
	 * Moves forum restrictions to the Forums page when BuddyPress / BuddyBoss is active.
	 *
	 * @since 3.42.3
	 */
	public static function v_3_42_3() {

		if ( wpf_get_option( 'bbp_lock' ) && function_exists( 'bp_get_option' ) ) {

			$forums_page_id = bp_get_option( '_bbp_root_slug_custom_slug', '' );

			if ( ! empty( $forums_page_id ) ) {

				$settings = array(
					'lock_content' => true,
					'allow_tags'   => wpf_get_option( 'bbp_allow_tags', array() ),
					'redirect_url' => wpf_get_option( 'bbp_redirect', home_url() ),
				);

				update_post_meta( $forums_page_id, 'wpf-settings', $settings );

				wp_fusion()->settings->set( 'bbp_lock', false );

			}
		}
	}

	/**
	 * Moves lists from ac_lists to assign_lists.
	 *
	 * @since 3.42.3
	 */
	public static function v_3_42_6() {

		if ( wpf_get_option( 'ac_lists' ) ) {
			wp_fusion()->settings->set( 'assign_lists', wpf_get_option( 'ac_lists' ) );
		}
	}

	/**
	 * Moves lists from cc_lists to assign_lists in Constant Contact.
	 *
	 * @since 3.43.14
	 */
	public static function v_3_43_14() {

		if ( wpf_get_option( 'cc_lists' ) ) {
			wp_fusion()->settings->set( 'assign_lists', wpf_get_option( 'cc_lists' ) );
		}
	}

	/**
	 * Deletes any orphaned batch operations from 3.44.8.
	 *
	 * @since 3.44.11
	 */
	public static function v_3_44_11() {

		global $wpdb;

		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wpfb_status_wpf_background_process_%';" );
	}
}

new WPF_Upgrades();
