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

		add_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source' ) );

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );

		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ), 5 );
		add_filter( 'wpf_meta_fields', array( $this, 'add_meta_fields' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_scripts' ) );
	}

	/**
	 * Checks if lead source parameters are set and enabled for sync.
	 *
	 * @since 3.42.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_tracking_leadsource() {

		if ( ! empty( $this->merge_lead_source() ) ) {
			return true;
		} else {
			return false;
		}
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
			'fbclid',
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

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		if ( ! empty( $_COOKIE[ $leadsource_cookie_name ] ) && ! is_array( $_COOKIE[ $leadsource_cookie_name ] ) ) {
			$cookie_data = (array) json_decode( wp_unslash( $_COOKIE[ $leadsource_cookie_name ] ), true );
		} else {
			$cookie_data = array();
		}

		foreach ( $this->get_leadsource_vars() as $var ) {

			if ( isset( $_GET[ $var ] ) && wpf_is_field_active( $var ) ) {
				$cookie_data[ $var ] = sanitize_text_field( wp_unslash( $_GET[ $var ] ) );
			}
		}

		if ( ! empty( $cookie_data ) ) {
			setcookie( $leadsource_cookie_name, wp_json_encode( $cookie_data ), time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
		}

		if ( ! is_admin() && empty( $_COOKIE[ $ref_cookie_name ] ) ) {

			$cookie_data = array();

			if ( wpf_is_field_active( 'original_ref' ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$cookie_data['original_ref'] = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			}

			if ( wpf_is_field_active( 'landing_page' ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
				$cookie_data['landing_page'] = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			}

			if ( ! empty( $cookie_data ) ) {
				setcookie( $ref_cookie_name, wp_json_encode( $cookie_data ), time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}
	}

	/**
	 * Merge Lead Source
	 * Merges lead source variables when a user registers.
	 *
	 * @since  unknown
	 * @since  3.43.2  Removed redundant UTM query parameters from the current page and landing page URLs.
	 *
	 * @param  array $args args.
	 *
	 * @return array User Meta
	 */
	public function merge_lead_source( array $args = array() ): array {

		$lead_source_data = array();

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		$cookies = $_COOKIE;

		if ( ! empty( $cookies[ $leadsource_cookie_name ] ) ) {

			if ( ! is_array( $cookies[ $leadsource_cookie_name ] ) ) {
				// New 3.40.43 format.
				$cookies[ $leadsource_cookie_name ] = json_decode( wp_unslash( $cookies[ $leadsource_cookie_name ] ), true );
			}

			if ( ! empty( $cookies[ $leadsource_cookie_name ] ) ) {
				$data             = array_map( 'sanitize_text_field', wp_unslash( $cookies[ $leadsource_cookie_name ] ) );
				$lead_source_data = array_merge( $lead_source_data, $data );
			}
		}

		if ( ! empty( $cookies[ $ref_cookie_name ] ) ) {

			if ( ! is_array( $cookies[ $ref_cookie_name ] ) ) {
				// New 3.40.43 format.
				$cookies[ $ref_cookie_name ] = json_decode( wp_unslash( $cookies[ $ref_cookie_name ] ), true );
			}

			if ( ! empty( $cookies[ $ref_cookie_name ] ) ) {
				$data             = array_map( 'sanitize_text_field', wp_unslash( $cookies[ $ref_cookie_name ] ) );
				$lead_source_data = array_merge( $lead_source_data, $data );
			}
		}

		if ( wpf_is_field_active( 'current_page' ) ) {
			if ( isset( $_REQUEST['referrer'] ) ) {
				$lead_source_data['current_page'] = esc_url_raw( wp_unslash( $_REQUEST['referrer'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$lead_source_data['current_page'] = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			} else {
				$lead_source_data['current_page'] = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			}
		}

		if ( ! empty( $lead_source_data ) ) {

			wpf_log(
				'info',
				wpf_get_current_user_id(),
				'Syncing lead source data:',
				array(
					'meta_array' => $lead_source_data,
					'source'     => 'lead-source-tracking',
				)
			);

			if ( empty( $args[0] ) ) {
				$args[0] = array(); // in case it came in empty.
			}

			// Remove any UTM query strings from the current page and landing page URL.
			if ( ! empty( $lead_source_data['current_page'] ) ) {
				$lead_source_data['current_page'] = $this->remove_query_parameters( $lead_source_data['current_page'] );
			}
			if ( ! empty( $lead_source_data['landing_page'] ) ) {
				$lead_source_data['landing_page'] = $this->remove_query_parameters( $lead_source_data['landing_page'] );
			}

			if ( is_array( $args[0] ) ) {
				// Add contact.
				$args[0] = $args[0] + wp_fusion()->crm->map_meta_fields( $lead_source_data ); // dont overwrite anything we might have gotten from the database.
			} elseif ( is_array( $args[1] ) ) {
				// Update contact.
				$args[1] = $args[1] + wp_fusion()->crm->map_meta_fields( $lead_source_data ); // dont overwrite anything we might have gotten from the database.
			}
		}

		return $args;
	}

	/**
	 * Remove Query Parameters
	 * Removes UTM query parameters from a URL.
	 *
	 * @since  3.43.2
	 *
	 * @param  string $url The URL.
	 * @return string $url The URL without query parameters.
	 */
	public function remove_query_parameters( string $url ): string {

		$postion = strpos( $url, '/?' );

		if ( false !== $postion ) {
			$url = substr( $url, 0, $postion + 1 );
		}

		return $url;
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

		$meta_fields['fbclid'] = array(
			'type'   => 'text',
			'label'  => __( 'Facebook Click Identifier', 'wp-fusion-lite' ),
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
			wp_enqueue_script( 'wpf-leadsource-tracking', WPF_DIR_URL . 'assets/js/wpf-leadsource-tracking.js', array(), WP_FUSION_VERSION, true );
		}
	}
}
