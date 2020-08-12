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

	}


	/**
	 * Tries to detect a leadsource for new visitors and makes the data available to integrations
	 *
	 * @access  public
	 * @return  void
	 */

	function set_lead_source() {

		if ( headers_sent() ) {
			return;
		}

		$leadsource_vars = array(
			'leadsource',
			'utm_campaign',
			'utm_medium',
			'utm_source',
			'utm_term',
			'utm_content',
			'gclid',
		);

		$leadsource_vars = apply_filters( 'wpf_leadsource_vars', $leadsource_vars );

		$alt_vars = array(
			'original_ref',
			'landing_page',
		);

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		foreach ( $leadsource_vars as $var ) {

			if ( isset( $_GET[ $var ] ) && isset( $contact_fields[ $var ] ) && $contact_fields[ $var ]['active'] == true ) {
				setcookie( 'wpf_leadsource[' . $var . ']', $_GET[ $var ], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

		if ( ! is_admin() && empty( $_COOKIE['wpf_ref'] ) ) {

			if ( isset( $contact_fields['original_ref'] ) && $contact_fields['original_ref']['active'] == true && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				setcookie( 'wpf_ref[original_ref]', $_SERVER['HTTP_REFERER'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}

			if ( isset( $contact_fields['landing_page'] ) && $contact_fields['landing_page']['active'] == true ) {
				setcookie( 'wpf_ref[landing_page]', $_SERVER['REQUEST_URI'], time() + DAY_IN_SECONDS * 90, COOKIEPATH, COOKIE_DOMAIN );
			}
		}

	}

	/**
	 * Merges lead source variables when a user registers
	 *
	 * @access  public
	 * @return  array User Meta
	 */

	function merge_lead_source( $user_meta, $user_id ) {

		// No need to run this when a user registers
		remove_filter( 'wpf_api_add_contact_args', array( $this, 'merge_lead_source_guest' ) );

		if ( ! empty( $_COOKIE['wpf_leadsource'] ) ) {
			$user_meta = array_merge( $user_meta, $_COOKIE['wpf_leadsource'] );
		}

		if ( ! empty( $_COOKIE['wpf_ref'] ) ) {
			$user_meta = array_merge( $user_meta, $_COOKIE['wpf_ref'] );
		}

		return $user_meta;

	}

	/**
	 * Merges lead source variables on contact add, at the API layer for guest signups
	 *
	 * @access  public
	 * @return  array Args
	 */

	function merge_lead_source_guest( $args ) {

		if ( ! isset( $_COOKIE['wpf_leadsource'] ) && ! isset( $_COOKIE['wpf_ref'] ) ) {
			return $args;
		}

		// Only do this on guests (checking $map_meta_fields == true)
		if ( count( $args ) == 3 && true == $args[2] ) {
			return $args;
		} elseif ( count( $args ) == 2 && true == $args[1] ) {
			return $args;
		}

		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

		$merged_data = array();

		// Possibly set lead sources from cookie
		if ( isset( $_COOKIE['wpf_leadsource'] ) && is_array( $_COOKIE['wpf_leadsource'] ) ) {

			foreach ( $_COOKIE['wpf_leadsource'] as $key => $value ) {

				if ( isset( $contact_fields[ $key ] ) && $contact_fields[ $key ]['active'] == true ) {

					$merged_data[ $key ] = $value;

					if ( is_array( $args[0] ) ) {

						// Add contact
						$args[0][ $contact_fields[ $key ]['crm_field'] ] = $value;

					} elseif ( is_array( $args[1] ) ) {

						// Update contact
						$args[1][ $contact_fields[ $key ]['crm_field'] ] = $value;

					}
				}
			}
		}

		if ( isset( $_COOKIE['wpf_ref'] ) && is_array( $_COOKIE['wpf_ref'] ) ) {

			foreach ( $_COOKIE['wpf_ref'] as $key => $value ) {

				if ( isset( $contact_fields[ $key ] ) && $contact_fields[ $key ]['active'] == true ) {

					$merged_data[ $key ] = $value;

					if ( is_array( $args[0] ) ) {

						// Add contact
						$args[0][ $contact_fields[ $key ]['crm_field'] ] = $value;

					} elseif ( is_array( $args[1] ) ) {

						// Update contact
						$args[1][ $contact_fields[ $key ]['crm_field'] ] = $value;

					}
				}
			}
		}

		if ( ! empty( $merged_data ) ) {

			wpf_log(
				'info', 0, 'Syncing lead source data for guest:', array(
					'meta_array' => $merged_data,
					'source'     => 'lead-source-tracking',
				)
			);

		}

		return $args;

	}

	/**
	 * Allow the leadsource cookies in the async process
	 *
	 * @access  public
	 * @return  array Cookies
	 */

	function allowed_cookies( $cookies ) {

		$cookies[] = 'wpf_leadsource';
		$cookies[] = 'wpf_ref';

		return $cookies;

	}

}
