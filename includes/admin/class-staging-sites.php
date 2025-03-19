<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * This class handles detecting when the site URL has changed, and activating staging mode.
 *
 * @since 3.38.35
 */
class WPF_Staging_Sites {

	/**
	 * Constructs a new instance.
	 *
	 * @since 3.38.35
	 */
	public function __construct() {

		add_filter( 'wpf_get_setting_staging_mode', array( $this, 'maybe_activate_staging_mode' ) );

		add_action( 'admin_init', array( $this, 'process_actions' ) );

		add_action( 'admin_notices', array( $this, 'show_staging_notice' ) );
		add_action( 'wpf_settings_notices', array( $this, 'show_staging_notice_wpf' ) );
	}

	/**
	 * Enables staging mode if this is a duplicate site.
	 *
	 * @since  3.38.35
	 *
	 * @param  bool $value  The value.
	 * @return bool  Whether or not to activate staging mode.
	 */
	public function maybe_activate_staging_mode( $value ) {

		if ( defined( 'WPF_STAGING_MODE' ) && false === WPF_STAGING_MODE ) {
			return false; // allow force-disabling the staging site detection.
		} elseif ( $this->is_duplicate_site() || ( defined( 'WPF_STAGING_MODE' ) && true === WPF_STAGING_MODE ) ) {
			return true;
		}

		return $value;
	}


	/**
	 * Remove the prefix used to prevent the site URL being updated on WP Engine
	 * and Cloudways.
	 *
	 * @since  3.40.16
	 *
	 * @param  string $value  The value.
	 * @return string The site URL.
	 */
	public static function get_site_url() {

		return str_replace( '_[wpf_siteurl]_', '', wpf_get_option( 'site_url', get_site_url() ) );
	}

	/**
	 * Handles deactivating / ignoring staging mode via the links in the admin
	 * notices.
	 *
	 * @since 3.38.40
	 */
	public function process_actions() {

		if ( ! empty( $_REQUEST['_wpfnonce'] ) && wp_verify_nonce( $_REQUEST['_wpfnonce'], 'wpf_duplicate_site' ) && isset( $_GET['wpf_duplicate_site'] ) ) {

			if ( 'update' === $_GET['wpf_duplicate_site'] ) {

				wpf_log( 'notice', get_current_user_id(), 'Site URL changed from <strong>' . self::get_site_url() . '</strong> to <strong>' . get_site_url() . '</strong>. Staging mode deactivated.', array( 'source' => 'staging-mode' ) );

				wp_fusion()->settings->set( 'dismissed_wpf-staging-notice', false ); // so it can be shown again.
				wp_fusion()->settings->set( 'site_url', self::get_duplicate_site_lock_key() );
				wp_fusion()->settings->set( 'staging_mode', false );

				wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings' ) );

			} elseif ( 'ignore' === $_GET['wpf_duplicate_site'] ) {

				wp_fusion()->settings->set( 'dismissed_wpf-staging-notice', true );

			}
		}
	}

	/**
	 * Checks if the WordPress site URL is the same as the URL for the site WP
	 * Fusion normally runs on.
	 *
	 * @since  3.38.39
	 *
	 * @return bool  True if duplicate site, False otherwise.
	 */
	public static function is_duplicate_site() {

		$wp_site_url_parts  = wp_parse_url( get_site_url() );
		$wpf_site_url_parts = wp_parse_url( self::get_site_url() );

		if ( ! isset( $wp_site_url_parts['path'] ) && ! isset( $wpf_site_url_parts['path'] ) ) {
			$paths_match = true;
		} elseif ( isset( $wp_site_url_parts['path'] ) && isset( $wpf_site_url_parts['path'] ) && $wp_site_url_parts['path'] === $wpf_site_url_parts['path'] ) {
			$paths_match = true;
		} else {
			$paths_match = false;
		}

		if ( isset( $wp_site_url_parts['host'] ) && isset( $wpf_site_url_parts['host'] ) && $wp_site_url_parts['host'] === $wpf_site_url_parts['host'] ) {
			$hosts_match = true;
		} else {
			$hosts_match = false;
		}

		// Check the host and path, do not check the protocol/scheme to avoid
		// issues with WP Engine and other occasions where the WP_SITEURL
		// constant may be set, but being overridden (e.g. by FORCE_SSL_ADMIN).

		if ( $paths_match && $hosts_match ) {
			$is_duplicate = false;
		} else {
			$is_duplicate = true;
		}

		if ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
			$is_duplicate = false; // if we've switched to another blog.
		}

		return apply_filters( 'wpf_is_duplicate_site', $is_duplicate );
	}

	/**
	 * Generates a unique key based on the sites URL used to determine duplicate/staging sites.
	 *
	 * The key can not simply be the site URL, e.g. http://example.com, because some hosts (WP Engine) replaces all
	 * instances of the site URL in the database when creating a staging site. As a result, we obfuscate
	 * the URL by inserting '_[wpf_siteurl]_' into the middle of it.
	 *
	 * We don't use a hash because keeping the URL in the value allows for viewing and editing the URL
	 * directly in the database.
	 *
	 * @since 3.40.16
	 * @return string The duplicate lock key.
	 */
	public static function get_duplicate_site_lock_key() {

		$site_url = get_site_url();
		$scheme   = wp_parse_url( $site_url, PHP_URL_SCHEME ) . '://';
		$site_url = str_replace( $scheme, '', $site_url );

		return $scheme . substr_replace( $site_url, '_[wpf_siteurl]_', round( strlen( $site_url ) / 2 ), 0 );
	}

	/**
	 * Shows a notice when WPF is in staging mode.
	 *
	 * @since 3.38.40
	 * @return mixed HTML output
	 */
	public function show_staging_notice() {

		if ( wpf_get_option( 'dismissed_wpf-staging-notice' ) && ! doing_action( 'wpf_settings_notices' ) ) {
			return; // if the notice has been dismissed across the admin, we'll only show it on the WPF settings page.
		}

		if ( $this->is_duplicate_site() && current_user_can( 'manage_options' ) && ! defined( 'WPF_STAGING_MODE' ) ) {

			echo '<div id="wpf-staging-notice" data-notice="wpf-staging-notice" class="notice notice-warning wpf-notice is-dismissible"><p>';

			printf(
				// translators: 1$-2$: opening and closing <strong> tags. $3 the CRM name. 4$-5$: Opening and closing link to production URL. 6$: Production URL. 7$-8$ Opening and closing link to staging docs.
				esc_html__( 'It looks like this site has moved or is a duplicate site. %1$sWP Fusion%2$s has enabled %7$sstaging mode%8$s to prevent unwanted data from being synced with %3$s. %1$sWP Fusion%2$s considers %4$s%6$s%5$s to be the site\'s URL.', 'wp-fusion-lite' ),
				'<strong>',
				'</strong>',
				esc_html( wp_fusion()->crm->name ),
				'<a href="' . esc_url( self::get_site_url() ) . '" target="_blank">',
				'</a>',
				esc_url( self::get_site_url() ),
				'<a href="https://wpfusion.com/documentation/faq/staging-sites/" target="_blank">',
				'</a>'
			);

			echo '</p><p>';

			if ( ! doing_action( 'wpf_settings_notices' ) ) {

				echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'wpf_duplicate_site', 'ignore' ), 'wpf_duplicate_site', '_wpfnonce' ) ) . '" class="button button-primary">';

				esc_html_e( 'Quit nagging me (but keep staging mode enabled)', 'wp-fusion-lite' );

				echo '</a> ';

			}

			echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'wpf_duplicate_site', 'update' ), 'wpf_duplicate_site', '_wpfnonce' ) ) . '" class="button">';

			esc_html_e( 'Disable staging mode and make this the production site', 'wp-fusion-lite' );

			echo '</a>';

			echo '</p></div>';

		}
	}

	/**
	 * Shows a notice on the WPF settings page when WPF is in staging mode.
	 *
	 * @since 3.38.35
	 * @return mixed HTML output
	 */
	public function show_staging_notice_wpf() {

		if ( ( wpf_get_option( 'staging_mode' ) && ! $this->is_duplicate_site() ) || ( defined( 'WPF_STAGING_MODE' ) && true === WPF_STAGING_MODE ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			if ( defined( 'WPF_STAGING_MODE' ) && true === WPF_STAGING_MODE ) {

				printf(
					// translators: 1$-2$: opening and closing <strong> tags. $3-$4 opening and closing link to documentation on staging sites. $5 the CRM name.
					esc_html__( '%1$sHeads up:%2$s WP Fusion is currently in %3$sstaging mode%4$s due to %5$sWPF_STAGING_MODE%6$s being defined in wp-config.php. No data will be sent to or loaded from %7$s.', 'wp-fusion-lite' ),
					'<strong>',
					'</strong>',
					'<a href="https://wpfusion.com/documentation/faq/staging-sites/" target="_blank">',
					'</a>',
					'<code>',
					'</code>',
					esc_html( wp_fusion()->crm->name )
				);
			} else {

				printf(
					// translators: 1$-2$: opening and closing <strong> tags. $3-$4 opening and closing link to documentation on staging sites. $5 the CRM name.
					esc_html__( '%1$sHeads up:%2$s WP Fusion is currently in %3$sstaging mode%4$s. No data will be sent to or loaded from %5$s.', 'wp-fusion-lite' ),
					'<strong>',
					'</strong>',
					'<a href="https://wpfusion.com/documentation/faq/staging-sites/" target="_blank">',
					'</a>',
					esc_html( wp_fusion()->crm->name )
				);

			}

			echo '</p></div>';

		} elseif ( $this->is_duplicate_site() ) {

			$this->show_staging_notice();

		}
	}
}

new WPF_Staging_Sites();
