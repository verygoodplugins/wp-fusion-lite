<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Lead_Source_Tracking {

	/**
	 * WPF_Lead_Source_Tracking constructor.
	 */

	public function __construct() {

		// Lead source tracking

		add_action( 'init', array( $this, 'set_lead_source' ) );

		add_filter( 'wpf_user_register', array( $this, 'merge_lead_source' ) );
		add_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source_guest' ) );

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );

		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ), 5 );
		add_filter( 'wpf_meta_fields', array( $this, 'add_meta_fields' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_scripts' ) );

	}

	/**
	 * Gets the leadsource cookie name.
	 *
	 * @since  3.37.3
	 *
	 * @return string The leadsource cookie name.
	 */
	private function get_leadsource_cookie_name() {

		return apply_filters( 'wpf_leadsource_cookie_name', 'wpf_leadsource' );

	}

	/**
	 * Gets the referral cookie name.
	 *
	 * @since  3.37.3
	 *
	 * @return string The leadsource cookie name.
	 */
	private function get_referral_cookie_name() {

		return apply_filters( 'wpf_referral_cookie_name', 'wpf_ref' );

	}

	/**
	 * Gets the leadsource variables for tracking and syncing.
	 *
	 * @since  3.37.25
	 *
	 * @return array The leadsource variables.
	 */
	public function get_leadsource_vars() {

		$leadsource_vars = array(
			'leadsource',
			'utm_campaign',
			'utm_medium',
			'utm_source',
			'utm_term',
			'utm_content',
			'gclid',
		);

		return apply_filters( 'wpf_leadsource_vars', $leadsource_vars );

	}

	/**
	 * Tries to detect a leadsource for new visitors and makes the data available to integrations
	 *
	 * @access  public
	 * @return  void
	 */

	public function set_lead_source() {

		if ( headers_sent() ) {
			return;
		}

		$leadsource_vars = $this->get_leadsource_vars();

		$alt_vars = array(
			'original_ref',
			'landing_page',
		);

		$contact_fields = wpf_get_option( 'contact_fields' );

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		foreach ( $leadsource_vars as $var ) {

			if ( isset( $_GET[ $var ] ) && wpf_is_field_active( $var ) ) {
				setcookie( "{$leadsource_cookie_name}[{$var}]", sanitize_text_field( wp_unslash( $_GET[ $var ] ) ), time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

		if ( ! is_admin() && empty( $_COOKIE[ $ref_cookie_name ] ) ) {

			if ( wpf_is_field_active( 'original_ref' ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				setcookie( "{$ref_cookie_name}[original_ref]", esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

			if ( wpf_is_field_active( 'landing_page' ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
				setcookie( "{$ref_cookie_name}[landing_page]", esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

	}

	/**
	 * Merges lead source variables when a user registers
	 *
	 * @access  public
	 * @return  array User Meta
	 */

	public function merge_lead_source( $user_meta = array() ) {

		// No need to run this when a user registers.
		remove_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source_guest' ) );

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		$cookies = $_COOKIE;

		// Maybe URL-decode the components (some hosts do this with JS tracking).
		foreach ( $cookies as $key => $val ) {

			if ( ! is_array( $val ) && 0 === strpos( $key, 'wpf_leadsource' ) ) {
				$newkey = str_replace( 'wpf_leadsource%5B', '', $key );
				$newkey = str_replace( '%5D', '', $newkey );

				if ( ! isset( $cookies['wpf_leadsource'] ) ) {
					$cookies['wpf_leadsource'] = array();
				}

				$cookies['wpf_leadsource'][ $newkey ] = $val;
			}

		}

		if ( ! empty( $cookies[ $leadsource_cookie_name ] ) ) {

			$data      = array_map( 'sanitize_text_field', wp_unslash( $cookies[ $leadsource_cookie_name ] ) );
			$user_meta = array_merge( $user_meta, $data );
		}

		if ( ! empty( $cookies[ $ref_cookie_name ] ) ) {

			$data      = array_map( 'sanitize_text_field', wp_unslash( $cookies[ $ref_cookie_name ] ) );
			$user_meta = array_merge( $user_meta, $data );

		}

		if ( isset( $_REQUEST['referrer'] ) ) {
			$user_meta['current_page'] = esc_url_raw( wp_unslash( $_REQUEST['referrer'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$user_meta['current_page'] = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		} else {
			$user_meta['current_page'] = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return $user_meta;

	}

	/**
	 * Merges lead source variables on contact add, at the API layer for guest signups
	 *
	 * @access  public
	 * @return  array Args
	 */

	public function merge_lead_source_guest( $args ) {

		// Only do this on guests.
		if ( doing_action( 'user_register' ) ) {
			return $args;
		}

		$data = $this->merge_lead_source();

		if ( ! empty( $data ) ) {

			wpf_log(
				'info',
				0,
				'Syncing lead source data for guest:',
				array(
					'meta_array' => $data,
					'source'     => 'lead-source-tracking',
				)
			);

			$args[0] = $args[0] + wp_fusion()->crm->map_meta_fields( $data ); // dont overwrite anything we might have gotten from the database.

		}

		return $args;

	}

	/**
	 * Allow the leadsource cookies in the async process
	 *
	 * @access  public
	 * @return  array Cookies
	 */

	public function allowed_cookies( $cookies ) {

		$cookies[] = $this->get_leadsource_cookie_name();
		$cookies[] = $this->get_referral_cookie_name();

		return $cookies;

	}

	/**
	 * Add Lead Source Tracking field group to Contact Fields list
	 *
	 * @since  3.36.16
	 *
	 * @param  array $field_groups The field groups.
	 * @return array  The field groups
	 */
	public function add_meta_field_group( $field_groups ) {

		$field_groups['leadsource'] = array(
			'title'  => __( 'Google Analytics and Lead Source Tracking', 'wp-fusion-lite' ),
			'url'    => 'https://wpfusion.com/documentation/tutorials/lead-source-tracking/',
			'fields' => array(),
		);

		return $field_groups;

	}

	/**
	 * Add Lead Source Tracking field group to Contact Fields list
	 *
	 * @since 3.37.25
	 *
	 * @param array $meta_fields The meta fields.
	 * @return array The meta fields.
	 */

	public function add_meta_fields( $meta_fields ) {

		$meta_fields['leadsource'] = array(
			'type'   => 'text',
			'label'  => __( 'Lead Source', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['utm_campaign'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Analytics Campaign', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['utm_source'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Analytics Source', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['utm_medium'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Analytics Medium', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['utm_term'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Analytics Term', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['utm_content'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Analytics Content', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['gclid'] = array(
			'type'   => 'text',
			'label'  => __( 'Google Click Identifier', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['original_ref'] = array(
			'type'   => 'text',
			'label'  => __( 'Original Referrer', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['landing_page'] = array(
			'type'   => 'text',
			'label'  => __( 'Landing Page', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		$meta_fields['current_page'] = array(
			'type'   => 'text',
			'label'  => __( 'Current Page', 'wp-fusion-lite' ),
			'group'  => 'leadsource',
			'pseudo' => true,
		);

		foreach ( $this->get_leadsource_vars() as $var ) {

			if ( ! isset( $meta_fields[ $var ] ) ) {
				$meta_fields[ $var ] = array(
					'type'   => 'text',
					'label'  => $var . ' (custom)',
					'group'  => 'leadsource',
					'pseudo' => true,
				);
			}
		}

		return $meta_fields;

	}

	/**
	 * Enqueues the lead source tracking script, if enabled.
	 *
	 * @since 3.40.10
	 */
	public function enqueue_tracking_scripts() {

		if ( wpf_get_option( 'js_leadsource_tracking' ) ) {
			wp_enqueue_script( 'wpf-leadsource-tracking', WPF_DIR_URL . 'assets/js/wpf-leadsource-tracking.js', array( 'jquery' ), WP_FUSION_VERSION, true );
		}

	}

}
