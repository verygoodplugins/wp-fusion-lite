<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Access_Control {


	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		add_filter( 'post_link', array( $this, 'rewrite_permalinks' ), 10, 3 );
		add_filter( 'page_link', array( $this, 'rewrite_permalinks' ), 10, 3 );
		add_filter( 'post_type_link', array( $this, 'rewrite_permalinks' ), 10, 3 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'hide_menu_items' ), 10, 3 );

		// Query / archive filtering
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_action( 'get_terms_args', array( $this, 'get_terms_args' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'exclude_restricted_posts' ), 10, 2 );

		// Page / post / widget access control
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 15 );
		add_filter( 'widget_display_callback', array( $this, 'hide_restricted_widgets' ), 10, 3 );

		// Return after login
		add_action( 'wp_login', array( $this, 'return_after_login' ), 10, 2 );

		// Apply tags on view scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'apply_tags_on_view' ) );

	}


	/**
	 * Checks if a user can access a given post
	 *
	 * @access public
	 * @return bool Whether or not a user can access specified content.
	 */

	public function user_can_access( $post_id, $user_id = false ) {

		if ( $post_id == null ) {
			return false;
		}

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		$post_restrictions = get_post_meta( $post_id, 'wpf-settings', true );

		if ( empty( $post_restrictions ) || ! is_array( $post_restrictions ) ) {
			$post_restrictions = array();
		}

		if ( ! isset( $post_restrictions['allow_tags'] ) ) {
			$post_restrictions['allow_tags'] = array();
		}

		if ( ! isset( $post_restrictions['allow_tags_all'] ) ) {
			$post_restrictions['allow_tags_all'] = array();
		}

		// See if taxonomy restrictions are in effect
		if ( $this->user_can_access_term( $post_id, $user_id ) == false ) {
			return apply_filters( 'wpf_user_can_access', false, $user_id, $post_id );
		}

		// If content isn't locked
		if ( empty( $post_restrictions ) || ! isset( $post_restrictions['lock_content'] ) || $post_restrictions['lock_content'] != true ) {
			return true;
		}

		// If not logged in
		if ( ! is_user_logged_in() && empty( $user_id ) ) {
			return apply_filters( 'wpf_user_can_access', false, false, $post_id );
		}

		// If no tags specified for restriction, but user is logged in, allow access
		if ( empty( $post_restrictions['allow_tags'] ) && empty( $post_restrictions['allow_tags_all'] ) ) {
			return apply_filters( 'wpf_user_can_access', true, $user_id, $post_id );
		}

		$user_tags = wp_fusion()->user->get_tags( $user_id );

		// If user has no valid tags
		if ( empty( $user_tags ) ) {
			return apply_filters( 'wpf_user_can_access', false, $user_id, $post_id );
		}

		if ( ! empty( $post_restrictions['allow_tags'] ) ) {

			// Check if user has required tags for content
			$result = array_intersect( (array) $post_restrictions['allow_tags'], $user_tags );

			if ( ! empty( $result ) ) {
				$can_access = true;
			} else {
				$can_access = false;
			}
		} else {

			$can_access = true;

		}

		if ( ! empty( $post_restrictions['allow_tags_all'] ) ) {

			$result = array_intersect( (array) $post_restrictions['allow_tags_all'], $user_tags );

			if ( count( $result ) == count( $post_restrictions['allow_tags_all'] ) && $can_access == true ) {
				$can_access = true;
			} else {
				$can_access = false;
			}
		}

		// If no tags matched
		return apply_filters( 'wpf_user_can_access', $can_access, $user_id, $post_id );

	}

	/**
	 * Checks if a user can access a given archive
	 *
	 * @access public
	 * @return bool Whether or not a user can access specified content.
	 */

	public function user_can_access_archive( $term_id, $user_id = false ) {

		if ( $term_id == null ) {
			return false;
		}

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		if ( ! isset( $taxonomy_rules[ $term_id ] ) ) {
			return true;
		}

		$archive_restrictions = $taxonomy_rules[ $term_id ];

		// If content isn't locked
		if ( empty( $archive_restrictions ) || ! isset( $archive_restrictions['lock_content'] ) || $archive_restrictions['lock_content'] != true ) {
			return true;
		}

		// If not logged in
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( $user_id == false ) {
			$user_id = get_current_user_id();
		}

		// If no tags specified for restriction, but user is logged in, allow access
		if ( empty( $archive_restrictions['allow_tags'] ) ) {
			return apply_filters( 'wpf_user_can_access_archive', true, $user_id, $term_id );
		}

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_tags = wp_fusion()->user->get_tags( $user_id );

		// If user has no valid tags
		if ( empty( $user_tags ) ) {
			return apply_filters( 'wpf_user_can_access_archive', false, $user_id, $term_id );
		}

		// Check if user has required tags for archive
		$result = array_intersect( (array) $archive_restrictions['allow_tags'], $user_tags );

		if ( ! empty( $result ) ) {
			$can_access = true;
		} else {
			$can_access = false;
		}

		// If no tags matched
		return apply_filters( 'wpf_user_can_access_archive', $can_access, $user_id, $term_id );

	}

	/**
	 * Checks if a user can access a given post based on restrictions configured for the taxonomy terms
	 *
	 * @access public
	 * @return bool Whether or not a user can access specified content.
	 */

	public function user_can_access_term( $post_id, $user_id = false ) {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		// Skip early if no taxonomy rules supplied
		if ( empty( $taxonomy_rules ) ) {
			return true;
		}

		if ( $user_id == false ) {
			$user_id == get_current_user_id();
		}

		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) );

		$terms = wp_get_post_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) );

		$user_tags = wp_fusion()->user->get_tags( $user_id );

		$can_access = true;

		$term_id = false;

		foreach ( $terms as $term_id ) {

			if ( ! isset( $taxonomy_rules[ $term_id ] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_content'] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_posts'] ) || ! isset( $taxonomy_rules[ $term_id ]['allow_tags'] ) ) {
				continue;
			}

			$result = array_intersect( $taxonomy_rules[ $term_id ]['allow_tags'], $user_tags );

			if ( empty( $result ) ) {
				$can_access = false;
				break;
			}
		}

		return apply_filters( 'wpf_user_can_access_term', $can_access, $user_id, $term_id );

	}


	/**
	 * Rewrites permalinks for restricted content
	 *
	 * @access public
	 * @return string URL of permalink
	 */

	public function rewrite_permalinks( $url, $post, $leavename ) {

		// Don't run on admin
		if ( is_admin() ) {
			return $url;
		}

		if ( is_object( $post ) ) {
			$post = $post->ID;
		}

		if ( $this->user_can_access( $post ) == true ) {
			return $url;
		}

		$redirect = $this->get_redirect( $post );

		if ( ! empty( $redirect ) ) {

			return $redirect;

		} else {

			return $url;

		}

	}

	/**
	 * Finds redirect for a given post
	 *
	 * @access public
	 * @return mixed
	 */

	public function get_redirect( $post_id ) {

		$defaults = array(
			'redirect_url' => '',
			'redirect'     => '',
		);

		// Get settings
		$post_restrictions = wp_parse_args( get_post_meta( $post_id, 'wpf-settings', true ), $defaults );

		// If term restrictions are in place, they override the post restrictions
		$term_restrictions = $this->get_redirect_term( $post_id );

		if ( ! empty( $term_restrictions ) ) {
			$post_restrictions = $term_restrictions;
		}

		$default_redirect = wp_fusion()->settings->get( 'default_redirect' );

		// Get redirect URL if one is set
		if ( ! empty( $post_restrictions['redirect_url'] ) ) {

			$redirect = $post_restrictions['redirect_url'];

		} elseif ( ! empty( $post_restrictions['redirect'] ) ) {

			// Fix for pre 1.6.2 when redirects were stored as full URLs and not IDs
			if ( is_numeric( $post_restrictions['redirect'] ) ) {

				// Don't allow infinite redirect
				if ( $post_restrictions['redirect'] == $post_id ) {
					return false;
				}

				$redirect = get_permalink( $post_restrictions['redirect'] );

			} else {

				$redirect = $post_restrictions['redirect'];

			}
		} elseif ( ! empty( $default_redirect ) ) {

			$redirect = $default_redirect;

		}

		if ( ! isset( $redirect ) ) {
			$redirect = false;
		}

		return $redirect;

	}

	/**
	 * Finds redirect for a given term
	 *
	 * @access public
	 * @return mixed
	 */

	public function get_redirect_term( $post_id ) {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		// Skip early if no taxonomy rules supplied
		if ( empty( $taxonomy_rules ) ) {
			return false;
		}

		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) );

		$terms = wp_get_post_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) );

		$user_tags = wp_fusion()->user->get_tags();

		foreach ( $terms as $term_id ) {

			if ( ! isset( $taxonomy_rules[ $term_id ] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_content'] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_posts'] ) || ! isset( $taxonomy_rules[ $term_id ]['allow_tags'] ) ) {
				continue;
			}

			// If user doesn't have the tags to access that term, return the redirect for that term
			$result = array_intersect( $taxonomy_rules[ $term_id ]['allow_tags'], $user_tags );

			if ( empty( $result ) ) {
				return $taxonomy_rules[ $term_id ];
			}
		}

		return false;

	}


	/**
	 * Handles redirects for locked content
	 *
	 * @access public
	 * @return bool
	 */

	public function template_redirect() {

		// Allow bypassing redirect
		do_action( 'wpf_begin_redirect' );

		$bypass = apply_filters( 'wpf_begin_redirect', false, get_current_user_id() );

		if ( $bypass == true ) {
			return true;
		}

		if ( is_admin() || is_home() || is_search() ) {

			return true;

		} elseif ( is_archive() ) {

			// Archive / taxonomy redirects
			$queried_object = get_queried_object();

			if ( ! isset( $queried_object->term_id ) ) {
				return true;
			}

			if ( $this->user_can_access_archive( $queried_object->term_id ) == true ) {
				return true;
			}

			$taxonomy_rules       = get_option( 'wpf_taxonomy_rules', array() );
			$archive_restrictions = $taxonomy_rules[ $queried_object->term_id ];

			$redirect         = false;
			$default_redirect = wp_fusion()->settings->get( 'default_redirect' );

			// Get redirect URL if one is set
			if ( ! empty( $archive_restrictions['redirect_url'] ) ) {

				$redirect = $archive_restrictions['redirect_url'];

			} elseif ( ! empty( $archive_restrictions['redirect'] ) ) {

				$redirect = get_permalink( $archive_restrictions['redirect'] );

			} elseif ( ! empty( $default_redirect ) ) {

				$redirect = $default_redirect;

			}

			$redirect = apply_filters( 'wpf_redirect_url', $redirect, false );

			if ( ! empty( $redirect ) ) {

				wp_redirect( $redirect );
				exit();

			}

			// Don't do anything for archives if no redirect specified
			return true;

		} else {

			// Single post redirects
			global $post;

			if ( empty( $post ) ) {
				return true;
			}

			// For inheriting restrictions from another post
			$post_id = apply_filters( 'wpf_redirect_post_id', $post->ID );

			if ( $post_id == 0 ) {
				return true;
			}

			// If user can access, return without doing anything
			if ( $this->user_can_access( $post_id ) == true ) {
				return true;
			}

			// Allow search engines to see excerpts
			if ( wp_fusion()->settings->get( 'seo_enabled' ) == true && $this->verify_bot() ) {

				add_filter( 'the_content', array( $this, 'seo_content_filter' ) );
				return true;

			}

			// Get redirect URL for the post
			$redirect = $this->get_redirect( $post_id );

			$redirect = apply_filters( 'wpf_redirect_url', $redirect, $post_id );

			if ( ! empty( $redirect ) ) {

				if ( wp_fusion()->settings->get( 'return_after_login' ) == true && ! empty( $post_id ) ) {
					setcookie( 'wpf_return_to', $post_id, time() + 5 * MINUTE_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
				}

				wp_redirect( $redirect );
				exit();

			} else {

				add_filter( 'the_content', array( $this, 'restricted_content_filter' ) );

				add_filter( 'comments_open', array( $this, 'turn_off_comments' ), 10, 2 );

				add_filter( 'post_password_required', array( $this, 'hide_comments' ), 10, 2 );

				do_action( 'wpf_filtering_page_content', $post_id );

				return true;

			}
		}

	}


	/**
	 * Outputs "restricted" message on restricted content
	 *
	 * @access public
	 * @return mixed
	 */

	public function restricted_content_filter( $content ) {

		$message = false;

		if ( wp_fusion()->settings->get( 'per_post_messages', false ) == true ) {

			global $post;
			$settings = get_post_meta( $post->ID, 'wpf-settings', true );

			if ( ! empty( $settings['message'] ) ) {

				$message = wp_specialchars_decode( $settings['message'], ENT_QUOTES );

			}
		}

		if ( $message == false ) {

			$message = wp_fusion()->settings->get( 'restricted_message' );

		}

		return do_shortcode( stripslashes( $message ) );

	}


	/**
	 * Turn off comments if post is restricted and no redirect is specified
	 *
	 * @access public
	 * @return bool
	 */

	public function turn_off_comments( $open, $post_id ) {

		return false;

	}


	/**
	 * Sets the post to be password required so existing comments are hidden
	 *
	 * @access public
	 * @return bool
	 */

	public function hide_comments( $hide, $post ) {

		if ( ! empty( get_comments_number( $post->ID ) ) ) {
			return true;
		}

		return $hide;

	}

	/**
	 * Determines whether a search engine or bot is trying to crawl the page
	 *
	 * @access public
	 * @return bool
	 */

	public function verify_bot() {

		$agent = 'no-agent-found';

		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = strtolower( $_SERVER['HTTP_USER_AGENT'] );
		}

		$known_engines = array( 'google', 'bing', 'msn', 'yahoo', 'ask' );

		foreach ( $known_engines as $engine ) {

			if ( strpos( $agent, $engine ) !== false ) {

				$ip_to_check = $_SERVER['REMOTE_ADDR'];

				// Lookup the host by this IP address
				$hostname = gethostbyaddr( $ip_to_check );

				if ( $engine == 'google' && ! preg_match( '#^.*\.googlebot\.com$#', $hostname ) ) {
					break;
				}

				if ( ( $engine == 'bing' || $engine == 'msn' ) && ! preg_match( '#^.*\.search\.msn\.com$#', $hostname ) ) {
					break;
				}

				if ( $engine == 'ask' && ! preg_match( '#^.*\.ask\.com$#', $hostname ) ) {
					break;
				}

				// Even though yahoo is contracted with bingbot, they do still send out slurp to update some entries etc
				if ( ( $engine == 'yahoo' || $engine == 'slurp' ) && ! preg_match( '#^.*\.crawl\.yahoo\.net$#', $hostname ) ) {
					break;
				}

				if ( $hostname !== false && $hostname != $ip_to_check ) {

					// Do the reverse lookup
					$ip_to_verify = gethostbyname( $hostname );

					if ( $ip_to_verify != $hostname && $ip_to_verify == $ip_to_check ) {
						return true;
					}
				}
			}
		}

		// Otherwise return false
		return false;

	}

	/**
	 * Outputs excerpt for bots and search engines
	 *
	 * @access public
	 * @return mixed
	 */

	public function seo_content_filter( $content ) {

		$length = wp_fusion()->settings->get( 'seo_excerpt_length' );

		if ( ! empty( $length ) ) {

			return wp_trim_words( $content, $length );

		} else {

			return wp_trim_words( $content );

		}

	}


	/**
	 * Removes restricted pages from menu
	 *
	 * @access public
	 * @return array Menu items
	 */

	public function hide_menu_items( $items, $menu, $args ) {

		if ( wp_fusion()->settings->get( 'hide_restricted' ) == true && ! is_admin() ) {

			foreach ( $items as $key => $item ) {

				if ( $item->type == 'taxonomy' && $this->user_can_access_archive( $item->object_id ) === false ) {

					unset( $items[ $key ] );

				} elseif ( $this->user_can_access( $item->object_id ) === false ) {

					unset( $items[ $key ] );

				}
			}
		}

		return $items;

	}

	/**
	 * Sets "suppress_filters" to false when query filtering is enabled
	 *
	 * @access  public
	 * @return  void
	 */

	public function pre_get_posts( $query ) {

		if ( empty( $query ) || ! is_object( $query ) ) {
			return;
		}

		if ( ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! $query->is_main_query() || $query->is_search() || $query->is_archive() ) {

			$setting = wp_fusion()->settings->get( 'hide_archives' );

			if ( $setting === true || $setting == 'standard' ) {

				$query->set( 'suppress_filters', false );

			} elseif ( $setting == 'advanced' ) {

				$post_type = $query->get( 'post_type' );

				if( empty( $post_type ) ) {
					return;
				}

				remove_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

				$args = array(
					'post_type'  => $post_type,
					'nopaging'   => true,
					'fields'     => 'ids',
					'meta_query' => array(
						array(
							'key'     => 'wpf-settings',
							'compare' => 'EXISTS',
						),
					),
				);

				$post_ids = get_posts( $args );

				if ( ! empty( $post_ids ) ) {

					$not_in = $query->get( 'post__not_in' );

					foreach ( $post_ids as $post_id ) {

						if ( ! $this->user_can_access( $post_id ) ) {

							$not_in[] = $post_id;

						}
					}

					$query->set( 'post__not_in', $not_in );

				}

				add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

			}
		}

	}

	/**
	 * Filters out restricted term items
	 *
	 * @access  public
	 * @return  array Args
	 */

	public function get_terms_args( $args, $taxonomies ) {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		if ( empty( $taxonomy_rules ) ) {
			return $args;
		}

		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			return $args;
		}

		$user_tags = wp_fusion()->user->get_tags();

		foreach ( $taxonomy_rules as $term_id => $rule ) {

			if ( ! isset( $rule['lock_content'] ) || ! isset( $rule['hide_term'] ) || empty( $rule['allow_tags'] ) ) {
				continue;
			}

			if ( ! is_user_logged_in() ) {

				if ( ! is_array( $args['exclude'] ) ) {
					$args['exclude'] = array();
				}

				$args['exclude'][] = $term_id;

			} else {

				$result = array_intersect( $rule['allow_tags'], $user_tags );

				if ( empty( $result ) ) {

					if ( ! is_array( $args['exclude'] ) ) {
						$args['exclude'] = array();
					}

					$args['exclude'][] = $term_id;
				}
			}
		}

		return $args;

	}

	/**
	 * Removes restricted products from shop archives
	 *
	 * @access  public
	 * @return  array Posts
	 */

	public function exclude_restricted_posts( $posts, $query ) {

		if ( ( is_admin() && ! defined( 'DOING_AJAX' ) ) || ( $query->is_main_query() && ! $query->is_archive() && ! $query->is_search() ) ) {
			return $posts;
		}

		// Woo variations fixed
		if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'woocommerce_load_variations' ) {
			return $posts;
		}

		$setting = wp_fusion()->settings->get( 'hide_archives' );

		if ( $setting === true || $setting == 'standard' ) {

			foreach ( $posts as $index => $post ) {

				if ( ! $this->user_can_access( $post->ID ) ) {
					unset( $posts[ $index ] );
				}
			}

			$posts = array_values( $posts );

		}

		return $posts;

	}

	/**
	 * Redirect back to restricted content after login
	 *
	 * @access public
	 * @return void
	 */

	public function return_after_login( $user_login, $user = false ) {

		if ( false === $user ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( isset( $_COOKIE['wpf_return_to'] ) ) {

			$post_id = intval( $_COOKIE['wpf_return_to'] );

			remove_filter( 'post_link', array( $this, 'rewrite_permalinks' ), 10, 3 );
			remove_filter( 'page_link', array( $this, 'rewrite_permalinks' ), 10, 3 );
			remove_filter( 'post_type_link', array( $this, 'rewrite_permalinks' ), 10, 3 );

			$url = get_permalink( $post_id );

			setcookie( 'wpf_return_to', '', time() - ( 15 * 60 ) );

			if ( ! empty( $url ) && $this->user_can_access( $post_id, $user->ID ) ) {

				wp_redirect( $url );
				exit();

			}
		}

	}

	/**
	 * Removes restricted widgets from sidebars
	 *
	 * @access public
	 * @return array Widget Instance
	 */

	public function hide_restricted_widgets( $instance, $widget, $args ) {

		if ( ! isset( $instance['wpf_conditional'] ) ) {
			return $instance;
		}

		// If not logged in
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$can_access = true;

		$widget_tags     = array();
		$widget_tags_not = array();

		if ( isset( $instance[ $widget->id_base . '_wpf_tags' ] ) ) {

			$widget_tags = $instance[ $widget->id_base . '_wpf_tags' ];

			if ( empty( $widget_tags ) ) {
				$widget_tags = array();
			}
		}

		if ( isset( $instance[ $widget->id_base . '_wpf_tags_not' ] ) ) {

			$widget_tags_not = $instance[ $widget->id_base . '_wpf_tags_not' ];

			if ( empty( $widget_tags_not ) ) {
				$widget_tags_not = array();
			}
		}

		// See if user has required tags
		$user_tags = wp_fusion()->user->get_tags();

		if ( ! empty( $widget_tags ) ) {

			$result = array_intersect( $widget_tags, $user_tags );

			if ( empty( $result ) ) {
				$can_access = false;
			}
		}

		if ( $can_access == true && ! empty( $widget_tags_not ) ) {

			$result = array_intersect( $widget_tags_not, $user_tags );

			if ( ! empty( $result ) ) {
				$can_access = false;
			}
		}

		// If admins are excluded from restrictions
		if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {
			$can_access = true;
		}

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, get_current_user_id(), null );

		// Widget filter
		$can_access = apply_filters( 'wpf_user_can_access_widget', $can_access, $instance, $widget );

		if ( $can_access ) {
			return $instance;
		} else {
			return false;
		}

	}

	/**
	 * Applies tags when a page is viewed
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags_on_view() {

		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		// Don't apply tags if restricted
		if ( ! wp_fusion()->access->user_can_access( $post->ID ) ) {
			return;
		}

		$wpf_settings = get_post_meta( $post->ID, 'wpf-settings', true );

		if ( ! empty( $wpf_settings['apply_tags'] ) || ! empty( $wpf_settings['remove_tags'] ) ) {

			if ( empty( $wpf_settings['apply_delay'] ) ) {

				if ( ! empty( $wpf_settings['apply_tags'] ) ) {

					wp_fusion()->user->apply_tags( $wpf_settings['apply_tags'] );

				}

				if ( ! empty( $wpf_settings['remove_tags'] ) ) {

					wp_fusion()->user->remove_tags( $wpf_settings['remove_tags'] );

				}

			} else {

				wp_enqueue_script( 'wpf-apply-tags', WPF_DIR_URL . '/assets/js/wpf-apply-tags.js', array( 'jquery' ), WP_FUSION_VERSION, true );

				if( ! isset( $wpf_settings['apply_tags'] ) ) {
					$wpf_settings['apply_tags'] = null;
				}

				if( ! isset( $wpf_settings['remove_tags'] ) ) {
					$wpf_settings['remove_tags'] = null;
				}

				$localize_data = array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'tags'    => $wpf_settings['apply_tags'],
					'remove'  => $wpf_settings['remove_tags'],
					'delay'   => $wpf_settings['apply_delay'],
				);

				wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', $localize_data );

			}
		}

	}

}
