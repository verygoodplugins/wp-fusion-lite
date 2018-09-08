<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Shortcodes {

	public function __construct() {

		add_shortcode( 'wpf', array( $this, 'shortcodes' ) );
		add_shortcode( 'else', array( $this, 'else_shortcode' ) );
		add_shortcode( 'wpf_update_tags', array( $this, 'shortcode_update_tags' ) );
		add_shortcode( 'wpf_update_meta', array( $this, 'shortcode_update_meta' ) );

		if( ! shortcode_exists( 'user_meta' ) ) {
			add_shortcode( 'user_meta', array( $this, 'shortcode_user_meta' ), 10, 2 );
		}

	}


	/**
	 * Handles content restriction shortcodes
	 *
	 * @access public
	 * @return mixed
	 */

	public function shortcodes( $atts, $content = '' ) {

		if( ( is_array( $atts ) && in_array( 'logged_out', $atts ) ) || $atts == 'logged_out' ) {
			$atts['logged_out'] = true;
		}

		$atts = shortcode_atts( array(
			'tag'    		=> '',
			'not'    		=> '',
			'method' 		=> '',
			'logged_out'	=> false
		), $atts, 'wpf' );

		// Hide content for non-logged in users
		if ( ! is_user_logged_in() && $atts['logged_out'] == false) {
			return false;
		}

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			return do_shortcode( shortcode_unautop( $content ) );
		}

		if ( $current_filter = get_query_var( 'wpf_tag' ) ) {

			if ( $current_filter == 'unlock-all' ) {

				return do_shortcode( shortcode_unautop( $content ) );

			} elseif ( $current_filter == 'lock-all' ) {

				return false;

			}

		}

		$user_tags = wp_fusion()->user->get_tags();

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
					$tags[] = wp_fusion()->user->get_tag_id( trim( $tag ) );
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
			if ( $current_filter == get_query_var( 'wpf_tag' ) ) {
				if ( in_array( $current_filter, $tags ) ) {
					return false;
				}
			}

		} else {
			$proceed_not = true;
		}

		// Check for else condition
		if ( preg_match('/(?<=\[else\]).*(?=\[\/else])/s', $content, $else_content) ) {
			$else_content = $else_content[0];
			$content = preg_replace('/\[else\].*\[\/else]/s', '', $content );
		}

		if ( $proceed_tag == true && $proceed_not == true ) {

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

		if ( is_user_logged_in() ) {
			wp_fusion()->user->get_tags( get_current_user_id(), true );
		}

	}

	/**
	 * Update meta data shortcode
	 *
	 * @access public
	 * @return null
	 */

	public function shortcode_update_meta( $atts ) {

		if ( is_user_logged_in() ) {
			wp_fusion()->user->pull_user_meta( get_current_user_id() );
		}

	}

	/**
	 * Show a piece of user meta
	 *
	 * @access public
	 * @return string
	 */

	public function shortcode_user_meta( $atts, $content = null ) {

		$atts = shortcode_atts( array('field' => ''), $atts );

		if(empty($atts['field']))
			return;

		if(!is_user_logged_in()) {
			return do_shortcode($content);
		}

		$user_data = get_userdata( get_current_user_id() );

		if( is_object($user_data) && property_exists( $user_data->data, $atts['field'] ) ) {

			$value = $user_data->data->{$atts['field']};

		} else {

			$value = get_user_meta( get_current_user_id(), $atts['field'], true );

		}

		if(empty($value)) {
			return do_shortcode($content);
		} else {
			return $value;
		}

	}

}

new WPF_Shortcodes;