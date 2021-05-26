<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Shortcodes {

	public function __construct() {

		add_shortcode( 'wpf', array( $this, 'shortcodes' ) );
		add_shortcode( 'wpf_update_tags', array( $this, 'shortcode_update_tags' ) );
		add_shortcode( 'wpf_update_meta', array( $this, 'shortcode_update_meta' ) );

		add_shortcode( 'wpf_loggedin', array( $this, 'shortcode_loggedin' ) );
		add_shortcode( 'wpf_loggedout', array( $this, 'shortcode_loggedout' ) );

		add_shortcode( 'wpf_user_can_access', array( $this, 'shortcode_user_can_access' ) );

		if ( ! shortcode_exists( 'user_meta' ) ) {
			add_shortcode( 'user_meta', array( $this, 'shortcode_user_meta' ), 10, 2 );
		}

		if ( ! shortcode_exists( 'user_meta_if' ) ) {
			add_shortcode( 'user_meta_if', array( $this, 'shortcode_user_meta_if' ), 10, 2 );
		}

	}


	/**
	 * Handles content restriction shortcodes
	 *
	 * @access public
	 * @return mixed
	 */

	public function shortcodes( $atts, $content = '' ) {

		if ( ( is_array( $atts ) && in_array( 'logged_out', $atts ) ) || $atts == 'logged_out' ) {
			$atts['logged_out'] = true;
		}

		$atts = shortcode_atts(
			array(
				'tag'        => '',
				'not'        => '',
				'method'     => '',
				'logged_out' => false,
			), $atts, 'wpf'
		);

		if ( false !== strpos( $atts['tag'], '“' ) || false !== strpos( $atts['not'], '“' ) ) {
			return '<pre>' . __( '<strong>Oops!</strong> Curly quotes were found in a shortcode parameter of the [wpf] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
		}

		// Hide content for non-logged in users
		if ( ! wpf_is_user_logged_in() && $atts['logged_out'] == false ) {
			return false;
		}

		$user_tags = wp_fusion()->user->get_tags();

		$user_tags = str_replace( '[', '(', $user_tags );
		$user_tags = str_replace( ']', ')', $user_tags );

		$proceed_tag = false;
		$proceed_not = false;

		if ( ! empty( $atts['tag'] ) ) {

			$tags       = array();
			$tags_split = explode( ',', $atts['tag'] );

			// Get tag IDs where needed
			foreach ( $tags_split as $tag ) {
				if ( is_numeric( $tag ) ) {
					$tags[] = $tag;
				} else {
					$tags[] = wp_fusion()->user->get_tag_id( $tag );
				}
			}

			foreach ( $tags as $tag ) {

				if ( in_array( $tag, $user_tags ) ) {
					$proceed_tag = true;

					if ( $atts['method'] == 'any' ) {
						break;
					}
				} else {
					$proceed_tag = false;

					if ( $atts['method'] != 'any' ) {
						break;
					}
				}
			}

			// If we're overriding
			if ( $current_filter = get_query_var( 'wpf_tag' ) ) {
				if ( in_array( $current_filter, $tags ) ) {
					$proceed_tag = true;
				}
			}
		} else {
			$proceed_tag = true;
		}

		if ( ! empty( $atts['not'] ) ) {

			$tags       = array();
			$tags_split = explode( ',', $atts['not'] );

			// Get tag IDs where needed
			foreach ( $tags_split as $tag ) {
				if ( is_numeric( $tag ) ) {
					$tags[] = $tag;
				} else {
					$tags[] = wp_fusion()->user->get_tag_id( trim( $tag ) );
				}
			}

			foreach ( $tags as $tag ) {
				if ( in_array( $tag, $user_tags ) ) {
					$proceed_not = false;
					break;
				} else {
					$proceed_not = true;
				}
			}

			// If we're overriding
			if ( $current_filter = get_query_var( 'wpf_tag' ) ) {
				if ( in_array( $current_filter, $tags ) ) {
					return false;
				}
			}
		} else {
			$proceed_not = true;
		}

		// Check for else condition

		if ( false !== strpos( $content, '[else]' ) ) {

			// Clean up old [/else] from pre 3.33.19
			$content = str_replace( '[/else]', '', $content );

			$else_content = explode( '[else]', $content );

			// Remove the else content from the main content
			$content      = $else_content[0];
			$else_content = $else_content[1];

		}

		if ( $proceed_tag == true && $proceed_not == true ) {
			$can_access = true;
		} else {
			$can_access = false;
		}

		global $post;

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			$can_access = true;
		}

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), false );

		if ( $can_access == true ) {

			return do_shortcode( shortcode_unautop( $content ) );

		} elseif ( ! empty( $else_content ) ) {

			return do_shortcode( shortcode_unautop( $else_content ) );

		}

	}


	/**
	 * Update tags shortcode
	 *
	 * @access public
	 * @return null
	 */

	public function shortcode_update_tags( $atts ) {

		if ( wpf_is_user_logged_in() && ! is_admin() ) {
			wp_fusion()->user->get_tags( wpf_get_current_user_id(), true );
		}

	}

	/**
	 * Update meta data shortcode
	 *
	 * @access public
	 * @return null
	 */

	public function shortcode_update_meta( $atts ) {

		if ( wpf_is_user_logged_in() && ! is_admin() ) {
			wp_fusion()->user->pull_user_meta( wpf_get_current_user_id() );
		}

	}

	/**
	 * Show a piece of user meta
	 *
	 * @access public
	 * @return string
	 */

	public function shortcode_user_meta( $atts, $content = null ) {

		$atts = shortcode_atts(
			array(
				'field'       => '',
				'date-format' => '',
				'format'      => '',
			), $atts
		);

		if ( false !== strpos( $atts['field'], '“' ) || false !== strpos( $atts['format'], '“' ) ) {
			return '<pre>' . __( '<strong>Oops!</strong> Curly quotes were found in a shortcode parameter of the [user_meta] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
		}

		if ( empty( $atts['field'] ) ) {
			return;
		}

		if ( ! wpf_is_user_logged_in() ) {
			return do_shortcode( $content );
		}

		if ( $atts['field'] == 'user_id' ) {
			$atts['field'] = 'ID';
		}

		$user_id = wpf_get_current_user_id();

		$user_data = get_userdata( $user_id );

		if ( is_object( $user_data ) && property_exists( $user_data->data, $atts['field'] ) ) {

			$value = $user_data->data->{$atts['field']};

		} else {

			$value = get_user_meta( $user_id, $atts['field'], true );

		}

		// Maybe refresh the data once from the CRM if the key doesn't exist at all

		if ( empty( $value ) && wp_fusion()->crm_base->is_field_active( $atts['field'] ) ) {

			if ( ! metadata_exists( 'user', $user_id, $atts['field'] ) ) {

				if ( ! empty( wp_fusion()->user->get_contact_id() ) ) {

					$user_meta = wp_fusion()->user->pull_user_meta();

					if ( isset( $user_meta[ $atts['field'] ] ) ) {
						$value = $user_meta[ $atts['field'] ];
					}
				}
			}
		}

		$value = apply_filters( 'wpf_user_meta_shortcode_value', $value, $atts['field'] );

		// Prevent array-to-string conversion warnings when this value is an array

		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		if ( ! empty( $atts['date-format'] ) && ! empty( $value ) ) {

			if ( is_numeric( $value ) ) {

				$value = date( $atts['date-format'], $value );

			} else {

				$value = date( $atts['date-format'], strtotime( $value ) );

			}
		}

		if ( $atts['format'] == 'ucwords' ) {
			$value = ucwords( $value );
		}

		if ( empty( $value ) && ! is_numeric( $value ) ) {
			return do_shortcode( $content );
		} else {
			return $value;
		}

	}

	/**
	 * User can access shortcode
	 *
	 * @access public
	 * @return mixed
	 */

	public function shortcode_user_can_access( $atts, $content = '' ) {

		$defaults = array(
			'id' => false,
		);

		$atts = shortcode_atts( $defaults, $atts, 'wpf_user_can_access' );

		if ( false == $atts['id'] ) {
			$atts['id'] = get_the_ID();
		}

		// Check for else condition

		if ( false !== strpos( $content, '[else]' ) ) {

			$else_content = explode( '[else]', $content );

			// Remove the else content from the main content
			$content      = $else_content[0];
			$else_content = $else_content[1];

		}

		$can_access = wp_fusion()->access->user_can_access( $atts['id'] );

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			$can_access = true;
		}

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), $atts['id'] );

		if ( true == $can_access ) {

			return do_shortcode( shortcode_unautop( $content ) );

		} elseif ( ! empty( $else_content ) ) {

			return do_shortcode( shortcode_unautop( $else_content ) );

		}

	}

	/**
	 * Show content only for logged in users
	 *
	 * @access public
	 * @return string Content
	 */

	public function shortcode_loggedin( $atts, $content = null ) {

		if ( ( wpf_is_user_logged_in() && ! is_null( $content ) ) || is_feed() ) {
			return do_shortcode( $content );
		}

	}


	/**
	 * Show content only for logged out users
	 *
	 * @access public
	 * @return string Content
	 */

	public function shortcode_loggedout( $atts, $content = null ) {

		if ( ( ! wpf_is_user_logged_in() && ! is_null( $content ) ) || is_feed() ) {
			return do_shortcode( $content );
		}

	}


	/**
	 * [usermeta_if] shortcode.
	 *
	 * @since  3.36.5
	 *
	 * @param  array  $atts    Shortcode atts.
	 * @param  string $content The content to display if the condition matches.
	 * @return string The content.
	 */
	public function shortcode_user_meta_if( $atts, $content = null ) {

		$atts = shortcode_atts(
			array(
				'field'        => '',
				'field_format' => '', // format for the value from meta
				'value'        => '',
				'value_format' => 'strval', // format for the value we enter
				'compare'      => '=',
			), $atts, 'user_meta_if'
		);

		// Check for curly quotes

		foreach ( $atts as $att ) {

			if ( false !== strpos( $att, '“' ) ) {
				return '<pre>' . __( '<strong>Oops!</strong> Curly quotes were found in a shortcode parameter of the [usermeta_if] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
			}
		}

		$user_id = wpf_get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		if ( ! $atts['field'] || ! $atts['value'] ) {
			return '';
		}

		$user_meta = wp_fusion()->user->get_user_meta( $user_id );

		if ( isset( $user_meta[ $atts['field'] ] ) ) {
			$meta_value = $user_meta[ $atts['field'] ];
		} else {
			$meta_value = '';
		}

		$meta_value = $atts['field_format'] ? call_user_func( $atts['field_format'], $meta_value ) : $meta_value;
		$value      = $atts['value_format'] ? call_user_func( $atts['value_format'], $atts['value'] ) : $atts['value'];

		if ( 'strtotime' == $atts['field_format'] && false === $meta_value ) {
			return sprintf( __( '<strong>Oops!</strong> Your input string to the <code>%s</code> attribute was not successfully <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">parsed by <code>strtotime()</code></a>.', 'wp-fusion-lite' ), 'userfield' );
		} elseif ( 'strtotime' == $atts['value_format'] && false === $value ) {
			return sprintf( __( '<strong>Oops!</strong> Your input string to the <code>%s</code> attribute was not successfully <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">parsed by <code>strtotime()</code></a>.', 'wp-fusion-lite' ), 'value' );
		}

		$atts['compare'] = wp_specialchars_decode( $atts['compare'] );

		$show_content = false;
		switch ( $atts['compare'] ) {
			case '<':
				$show_content = $meta_value < $value;
				break;
			case '<=':
				$show_content = $meta_value <= $value;
				break;
			case '>':
				$show_content = $meta_value > $value;
				break;
			case '>=':
				$show_content = $meta_value >= $value;
				break;
			default:
				$show_content = $meta_value === $value;
				break;
		}

		if ( ! $show_content ) {
			return '';
		}

		return do_shortcode( $content );

	}

}

new WPF_Shortcodes;
