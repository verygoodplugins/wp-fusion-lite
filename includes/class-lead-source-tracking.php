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

		add_filter( 'wpf_user_register', array( $this, 'merge_lead_source' ), 10, 2 );
		add_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source_guest' ) );

		add_filter( 'wpf_async_allowed_cookies', array( $this, 'allowed_cookies' ) );

		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ), 5 );
		add_filter( 'wpf_meta_fields', array( $this, 'add_meta_fields' ) );

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

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		foreach ( $leadsource_vars as $var ) {

			if ( isset( $_GET[ $var ] ) && wpf_is_field_active( $var ) ) {
				setcookie( "{$leadsource_cookie_name}[{$var}]", $_GET[ $var ], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

		if ( ! is_admin() && empty( $_COOKIE[ $ref_cookie_name ] ) ) {

			if ( wpf_is_field_active( 'original_ref' ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				setcookie( "{$ref_cookie_name}[original_ref]", $_SERVER['HTTP_REFERER'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

			if ( wpf_is_field_active( 'landing_page' ) ) {
				setcookie( "{$ref_cookie_name}[landing_page]", $_SERVER['REQUEST_URI'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

	}

	/**
	 * Merges lead source variables when a user registers
	 *
	 * @access  public
	 * @return  array User Meta
	 */

	public function merge_lead_source( $user_meta, $user_id ) {

		// No need to run this when a user registers
		remove_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source_guest' ) );

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		if ( ! empty( $_COOKIE[ $leadsource_cookie_name ] ) ) {
			$user_meta = array_merge( $user_meta, $_COOKIE[ $leadsource_cookie_name ] );
		}

		if ( ! empty( $_COOKIE[ $ref_cookie_name ] ) ) {
			$user_meta = array_merge( $user_meta, $_COOKIE[ $ref_cookie_name ] );
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

		$leadsource_cookie_name = $this->get_leadsource_cookie_name();
		$ref_cookie_name        = $this->get_referral_cookie_name();

		if ( ! isset( $_COOKIE[ $leadsource_cookie_name ] ) && ! isset( $_COOKIE[ $ref_cookie_name ] ) ) {
			return $args;
		}

		// Only do this on guests (checking $map_meta_fields == true)
		if ( count( $args ) == 3 && true == $args[2] ) {
			return $args;
		} elseif ( count( $args ) == 2 && true == $args[1] ) {
			return $args;
		}

		$merged_data = array();

		if ( isset( $_COOKIE[ $leadsource_cookie_name ] ) && is_array( $_COOKIE[ $leadsource_cookie_name ] ) ) {
			$merged_data = array_merge( $merged_data, $_COOKIE[ $leadsource_cookie_name ] );
		}

		if ( isset( $_COOKIE[ $ref_cookie_name ] ) && is_array( $_COOKIE[ $ref_cookie_name ] ) ) {
			$merged_data = array_merge( $merged_data, $_COOKIE[ $ref_cookie_name ] );
		}

		if ( ! empty( $merged_data ) ) {

			wpf_log(
				'info', 0, 'Syncing lead source data for guest:', array(
					'meta_array' => $merged_data,
					'source'     => 'lead-source-tracking',
				)
			);

			$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

			foreach ( $merged_data as $key => $value ) {

				if ( isset( $contact_fields[ $key ] ) && $contact_fields[ $key ]['active'] == true ) {

					$merged_data[ $key ] = $value;

					if ( is_array( $args[0] ) ) {

						// Add contact

						if ( isset( $args[1] ) && false == $args[1] ) {

							// Map meta fields off
							$args[0][ $contact_fields[ $key ]['crm_field'] ] = $value;

						} else {

							// Map meta fields on

							$args[0][ $key ] = $value;

						}
					} elseif ( is_array( $args[1] ) ) {

						// Update contact (not currently in use)

						$args[1][ $contact_fields[ $key ]['crm_field'] ] = $value;

						if ( isset( $args[2] ) && false == $args[2] ) {

							// Map meta fields off
							$args[1][ $contact_fields[ $key ]['crm_field'] ] = $value;

						} else {

							// Map meta fields on

							$args[1][ $key ] = $value;

						}
					}
				}
			}
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

}
