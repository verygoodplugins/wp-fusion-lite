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
			add_shortcode( 'user_meta', array( $this, 'shortcode_user_meta' ) );
		}

		if ( ! shortcode_exists( 'user_meta_if' ) ) {
			add_shortcode( 'user_meta_if', array( $this, 'shortcode_user_meta_if' ) );
		}

		if ( ! shortcode_exists( 'the_excerpt' ) ) {
			add_shortcode( 'the_excerpt', array( $this, 'shortcode_the_excerpt' ) );
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
			),
			$atts,
			'wpf'
		);

		$atts = wpf_shortcode_atts( $atts );

		if ( false !== strpos( $atts['tag'], '“' ) || false !== strpos( $atts['not'], '“' ) ) {
			return '<pre>' . esc_html__( 'Oops! Curly quotes were found in a shortcode parameter of the [wpf] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
		}

		// Hide content for non-logged in users.
		if ( ! wpf_is_user_logged_in() && false === $atts['logged_out'] ) {
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

			// If we're overriding.
			if ( get_query_var( 'wpf_tag' ) ) {
				if ( in_array( get_query_var( 'wpf_tag' ), $tags ) ) {
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

			// If we're overriding.
			if ( get_query_var( 'wpf_tag' ) ) {
				if ( in_array( get_query_var( 'wpf_tag' ), $tags ) ) {
					return false;
				}
			}
		} else {
			$proceed_not = true;
		}

		// Check for else condition.

		if ( false !== strpos( $content, '[else]' ) ) {

			// Clean up old [/else] from pre 3.33.19.
			$content = str_replace( '[/else]', '', $content );

			$else_content = explode( '[else]', $content );

			// Remove the else content from the main content.
			$content      = $else_content[0];
			$else_content = $else_content[1];

		}

		if ( $proceed_tag == true && $proceed_not == true ) {
			$can_access = true;
		} else {
			$can_access = false;
		}

		global $post;

		// If admins are excluded from restrictions.
		if ( wpf_admin_override() ) {
			$can_access = true;
		}

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), false );

		if ( true === $can_access ) {

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

	public function shortcode_update_tags() {

		if ( wpf_is_user_logged_in() && ! is_admin() ) {
			wp_fusion()->user->get_tags( wpf_get_current_user_id(), true );
		}

		return '<!-- wpf_update_tags -->';
	}

	/**
	 * Update meta data shortcode
	 *
	 * @access public
	 * @return null
	 */

	public function shortcode_update_meta() {

		if ( wpf_is_user_logged_in() && ! is_admin() ) {
			wp_fusion()->user->pull_user_meta( wpf_get_current_user_id() );
		}

		return '<!-- wpf_update_meta -->';
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
				'field'           => '',
				'date-format'     => '',
				'format'          => '',
				'timezone-offset' => '0',
				'sync_if_empty'   => false,
			),
			$atts
		);

		$atts = wpf_shortcode_atts( $atts );

		if ( false !== strpos( $atts['field'], '“' ) || false !== strpos( $atts['format'], '“' ) ) {
			return '<pre>' . esc_html__( 'Oops! Curly quotes were found in a shortcode parameter of the [user_meta] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
		}

		if ( empty( $atts['field'] ) ) {
			return;
		}

		if ( ! wpf_is_user_logged_in() ) {
			return do_shortcode( $content );
		}

		if ( 'user_id' === $atts['field'] ) {
			$atts['field'] = 'ID';
		}

		$user_id   = wpf_get_current_user_id();
		$user_data = get_userdata( $user_id );

		if ( is_object( $user_data ) && property_exists( $user_data->data, $atts['field'] ) ) {

			$value = $user_data->data->{$atts['field']};

		} else {

			$value = get_user_meta( $user_id, $atts['field'], true );

		}

		// Maybe refresh the data once from the CRM if the key doesn't exist at all.
		if ( empty( $value ) && $atts['sync_if_empty'] && ! did_action( 'wpf_user_updated' ) && wp_fusion()->crm->is_field_active( $atts['field'] ) ) {

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

		// Prevent array-to-string conversion warnings when this value is an array.

		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		if ( ! empty( $atts['date-format'] ) && ! empty( $value ) ) {

			if ( ! is_numeric( $value ) || 8 >= strlen( strval( $value ) ) ) {
				// Allows for dates like 2024 or 20240101 to not be treated as timestamps.
				$value = strtotime( $value );
			}

			if ( ! empty( $atts['timezone-offset'] ) ) {
				$value += intval( $atts['timezone-offset'] ) * HOUR_IN_SECONDS;
			}

			// At this point the date is in GMT, let's switch it to local timezone for display.
			$value = date_i18n( $atts['date-format'], $value );

		}

		if ( 'ucwords' === $atts['format'] ) {
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

		if ( false === $atts['id'] ) {
			$atts['id'] = get_the_ID();
		} else {
			$atts['id'] = absint( $atts['id'] );
		}

		// Check for else condition.
		if ( false !== strpos( $content, '[else]' ) ) {

			$else_content = explode( '[else]', $content );

			// Remove the else content from the main content.
			$content      = $else_content[0];
			$else_content = $else_content[1];

		}

		$can_access = wp_fusion()->access->user_can_access( $atts['id'] );

		// If admins are excluded from restrictions.
		if ( wpf_admin_override() ) {
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
				'field_format' => '', // format for the value from meta.
				'value'        => '',
				'value_format' => 'strval', // format for the value we enter.
				'compare'      => '=',
			),
			$atts,
			'user_meta_if'
		);

		$atts = wpf_shortcode_atts( $atts );

		// Check for curly quotes.

		foreach ( $atts as $att ) {

			if ( false !== strpos( $att, '“' ) ) {
				return '<pre>' . esc_html__( 'Oops! Curly quotes were found in a shortcode parameter of the [usermeta_if] shortcode. Curly quotes do not work with shortcode attributes.', 'wp-fusion-lite' ) . '</pre>';
			}
		}

		$user_id = wpf_get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		if ( ! $atts['field'] ) {
			return '';
		}

		$user_meta = wp_fusion()->user->get_user_meta( $user_id );

		if ( isset( $user_meta[ $atts['field'] ] ) ) {
			$meta_value = $user_meta[ $atts['field'] ];
		} else {
			$meta_value = '';
		}

		$allowed_functions = array(
			'strtolower',
			'strotoupper',
			'strval',
			'abs',
			'ceil',
			'floor',
			'round',
			'strtotime',
		);

		$meta_value = $atts['field_format'] && in_array( $atts['field_format'], $allowed_functions, true ) ? call_user_func( $atts['field_format'], $meta_value ) : $meta_value;
		$value      = $atts['value_format'] && in_array( $atts['value_format'], $allowed_functions, true ) ? call_user_func( $atts['value_format'], $atts['value'] ) : $atts['value'];

		if ( 'strtotime' === $atts['field_format'] && false === $meta_value ) {
			return sprintf( wp_kses_post( 'Oops! Your input string to the <code>%s</code> attribute was not successfully <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">parsed by <code>strtotime()</code></a>.', 'wp-fusion-lite' ), $atts['field'] );
		} elseif ( 'strtotime' === $atts['value_format'] && false === $value ) {
			return sprintf( wp_kses_post( 'Oops! Your input string to the <code>%s</code> attribute was not successfully <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">parsed by <code>strtotime()</code></a>.', 'wp-fusion-lite' ), $atts['field'] );
		}

		$atts['compare'] = wp_specialchars_decode( $atts['compare'] );

		$show_content = false;
		switch ( $atts['compare'] ) {
			case '<':
			case 'less':
				$show_content = $meta_value < $value;
				break;
			case '<=':
				$show_content = $meta_value <= $value;
				break;
			case '>':
			case 'greater':
				$show_content = $meta_value > $value;
				break;
			case '>=':
				$show_content = $meta_value >= $value;
				break;
			case 'IN':
				if ( is_array( $meta_value ) ) {
					$value = explode( ',', $value );
					$value = array_intersect( $meta_value, $value );
					if ( empty( $value ) ) {
						$show_content = false;
					} else {
						$show_content = true;
					}
				} else {

					$value = explode( ',', $value );

					foreach ( $value as $search ) {
						if ( false !== strpos( $meta_value, trim( $search ) ) ) {
							$show_content = true;
							break;
						}
					}
				}
				break;
			case 'NOT IN':
				if ( is_array( $meta_value ) ) {
					$value = explode( ',', $value );
					$value = array_map( 'trim', $value );
					$value = array_intersect( $meta_value, $value );
					if ( empty( $value ) ) {
						$show_content = true;
					} else {
						$show_content = false;
					}
				} else {
					$value = explode( ',', $value );

					foreach ( $value as $search ) {
						if ( false !== strpos( trim( $meta_value ), trim( $search ) ) ) {
							$show_content = false;
							break;
						} else {
							$show_content = true;
						}
					}
				}
				break;
			case 'ALL':
				if ( is_array( $meta_value ) ) {
					$value = explode( ',', $value );
					$value = array_map( 'trim', $value );
					if ( count( $value ) === count( $meta_value ) ) {
						$value = array_diff( $meta_value, $value );
						if ( empty( $value ) ) {
							$show_content = true;
						}
					}
				} else {
					$value      = explode( ',', $value );
					$value      = array_map( 'trim', $value );
					$meta_value = explode( ',', $meta_value );
					$meta_value = array_map( 'trim', $meta_value );

					if ( count( $value ) === count( $meta_value ) ) {
						$value = array_diff( $meta_value, $value );
						if ( empty( $value ) ) {
							$show_content = true;
						} else {
							$show_content = false;
						}
					}
				}
				break;
			case 'EMPTY':
				$show_content = empty( $meta_value );
				break;
			case 'NOT EMPTY':
				$show_content = ! empty( $meta_value );
				break;
			case '!=':
				$show_content = $meta_value !== $value;
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

	/**
	 * [the_excerpt] shortcode.
	 *
	 * @since 3.40.7
	 *
	 * @param array $atts   Shortcode atts.
	 * @return string The excerpt.
	 */
	public function shortcode_the_excerpt( $atts ) {

		if ( doing_filter( 'get_the_excerpt' ) ) {
			return false; // prevent looping.
		}

		$atts = shortcode_atts(
			array(
				'length' => '',
			),
			$atts,
			'the_excerpt'
		);

		$atts = wpf_shortcode_atts( $atts );

		if ( ! empty( $atts['length'] ) ) {

			// Possibly modify the excerpt length.

			$length = $atts['length'];

			add_filter(
				'excerpt_length',
				function () use ( &$length ) {
					return $length;
				},
				4242 // 4242 so it's hopefully unique when we remove it.
			);
		}

		$excerpt = get_the_excerpt();

		// Remove the filter.
		remove_all_filters( 'excerpt_length', 4242 );

		return $excerpt;
	}
}

new WPF_Shortcodes();
