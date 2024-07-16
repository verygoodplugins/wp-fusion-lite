<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Access_Control {

	/**
	 * Cache of post IDs the user can or can't access
	 *
	 * @since 3.37.4
	 * @var array
	 */

	public $can_access_posts = array();

	/**
	 * Determine if the current post has a content area that's filterable by
	 * the_content.
	 *
	 * @since 3.37.30
	 * @var  bool
	 */
	public $can_filter_content = false;

	/**
	 * Keep the taxonomy rules here to avoid hitting the DB multiple times.
	 *
	 * @since 3.41.46
	 * @var  bool|array
	 */
	public $taxonomy_rules = false;

	/**
	 * Keep track of whether we've refreshed the tags for the current user.
	 *
	 * @since 3.43.7
	 * @var  bool
	 */
	public $refreshed_tags = false;

	/**
	 * The priority for the filter queries hook.
	 *
	 * @since 3.43.15
	 * @var  int
	 */
	public $filter_queries_priority = 10;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		// Apply tags on view scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'apply_tags_on_view' ) );

		add_action( 'template_redirect', array( $this, 'handle_refresh' ), 10 );

		if ( ! wpf_get_option( 'restrict_content', true ) ) {
			return;
		}

		add_filter( 'wp_get_nav_menu_items', array( $this, 'hide_menu_items' ), 10, 3 );

		// This is expensive so we'll only filter terms if rules are set.
		if ( $this->get_taxonomy_rules() ) {
			add_filter( 'get_terms_args', array( $this, 'get_terms_args' ), 10, 2 );
		}

		// Query / archive filtering
		add_action( 'pre_get_posts', array( $this, 'filter_queries' ), $this->filter_queries_priority );
		add_filter( 'posts_where', array( $this, 'posts_where_restricted_terms' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'exclude_restricted_posts' ), 10, 2 );

		// Protect pseudo-pages
		add_filter( 'wpf_redirect_post_id', array( $this, 'protect_blog_index' ) );

		// Cache the access
		add_filter( 'wpf_user_can_access', array( $this, 'cache_can_access' ), 100, 3 );
		add_action( 'wpf_tags_modified', array( $this, 'clear_access_cache' ) );
		add_action( 'wpf_started_auto_login', array( $this, 'clear_access_cache' ) );

		// Protect post types
		add_filter( 'wpf_post_access_meta', array( $this, 'check_post_type' ), 10, 2 );

		// Protect REST API responses.
		add_filter( 'rest_prepare_post', array( $this, 'protect_rest_content_response' ), 10, 2 );

		// Page / post / widget access control
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 15 );
		add_filter( 'widget_display_callback', array( $this, 'hide_restricted_widgets' ), 10, 3 );

		// Site lockout
		add_action( 'template_redirect', array( $this, 'maybe_do_lockout' ), 13 );

		// Tags as body classes.
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// Return after login

		if ( wpf_get_option( 'return_after_login' ) ) {

			$priority = wpf_get_option( 'return_after_login_priority', 10 );
			add_action( 'wp_login', array( $this, 'return_after_login' ), absint( $priority ) );
			add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
			add_action( 'wpf_started_auto_login', array( $this, 'return_after_login' ) );

		}

		// Check to see if a page is protected but no redirect is specified, and there's no content area
		add_filter( 'the_content', array( $this, 'test_the_content' ) );
		add_action( 'wp_footer', array( $this, 'maybe_doing_it_wrong' ) );
	}


	/**
	 * Gets the post access settings.
	 *
	 * @since 3.37.26
	 *
	 * @param int $post_id The post ID.
	 * @return array The access settings.
	 */
	public function get_post_access_meta( $post_id ) {

		$settings = wp_parse_args( get_post_meta( $post_id, 'wpf-settings', true ), WPF_Admin_Interfaces::$meta_box_defaults );
		$settings = apply_filters( 'wpf_post_access_meta', $settings, $post_id ); // also see WPF_Access_Control::check_post_type().

		// Parse args again in case the filter messed them up.
		return wp_parse_args( $settings, WPF_Admin_Interfaces::$meta_box_defaults );

	}


	/**
	 * Gets the taxonomy rules.
	 *
	 * Since 3.38.22, taxonomy rules are set to autoload. That means if they
	 * aren't in the object cache, there aren't any taxonomy rules configured,
	 * and we can avoid a database hit.
	 *
	 * @since  3.38.22
	 * @since  3.38.25 Added filter on the return value.
	 *
	 * @return array The taxonomy rules.
	 */
	public function get_taxonomy_rules() {

		if ( false === $this->taxonomy_rules ) {

			$rules = (array) get_option( 'wpf_taxonomy_rules', array() );

			foreach ( $rules as $i => $rule ) {

				if ( empty( array_filter( $rule ) ) ) {
					unset( $rules[ $i ] );
				}
			}

			$this->taxonomy_rules = apply_filters( 'wpf_taxonomy_rules', $rules );

		}

		return $this->taxonomy_rules;

	}

	/**
	 * Checks if a user can access a given post.
	 *
	 * @since  1.0.0
	 *
	 * @param  int       $post_id The post ID.
	 * @param  int|false $user_id The user identifier, or false for current user.
	 * @return bool      Whether or not a user can access the content.
	 */
	public function user_can_access( $post_id, $user_id = false ) {

		if ( empty( $post_id ) ) {
			return true;
		}

		// If admins are excluded from restrictions.
		if ( wpf_admin_override() && ! $user_id ) {
			return true;
		}

		if ( ! $user_id ) {
			$user_id = wpf_get_current_user_id();
		}

		// Allow inheriting protections from another post.

		$post_id = apply_filters( 'wpf_user_can_access_post_id', $post_id );

		// Use the cache if we're checking for the current user.

		if ( wpf_get_current_user_id() === $user_id ) {

			if ( isset( $this->can_access_posts[ $post_id ] ) ) {
				return $this->can_access_posts[ $post_id ];
			}
		}

		$can_access = true;

		if ( ! $this->user_can_access_term( $post_id, $user_id ) ) {

			// See if taxonomy restrictions are in effect.
			$can_access = false;

		} elseif ( ! $this->user_can_access_post_type( get_post_type( $post_id ), $user_id ) ) {

			// See if post type restrictions are in effect.
			$can_access = false;

		}

		if ( true === $can_access ) {

			// If we passed for the post type and terms, we can check the setting on the individual post.

			$settings = $this->get_post_access_meta( $post_id );

			if ( empty( array_filter( $settings ) ) ) {

				// If no settings are set.
				$can_access = true;

			} if ( ! empty( $user_id ) && wpf_has_tag( $settings['allow_tags_not'], $user_id ) ) {

				// If user is logged in and a Not tag is specified.
				$can_access = false;

			} elseif ( empty( $settings['lock_content'] ) ) {

				// If content isn't locked.
				$can_access = true;

			} elseif ( ! wpf_is_user_logged_in() && empty( $user_id ) ) {

				// If not logged in.
				$can_access = false;

			} elseif ( empty( $settings['allow_tags'] ) && empty( $settings['allow_tags_all'] ) ) {

				// If no tags specified for restriction, but user is logged in, allow access.
				$can_access = true;

			} elseif ( empty( wpf_get_tags( $user_id ) ) ) {

				// If user has no tags.
				$can_access = false;

			} elseif ( ! empty( $settings['allow_tags_all'] ) && count( array_intersect( $settings['allow_tags_all'], wpf_get_tags( $user_id ) ) ) !== count( $settings['allow_tags_all'] ) ) {

				// If Required Tags (all) are specified and the user doesn't have all of them.
				$can_access = false;

			} elseif ( ! empty( $settings['allow_tags'] ) && ! wpf_has_tag( $settings['allow_tags'], $user_id ) ) {

				// If user has at least one of the required tags.
				$can_access = false;

			}

			// Possibly refresh the tags, but only once per page load.

			if ( false === $can_access && $user_id && doing_action( 'template_redirect' ) && ! empty( $settings['check_tags'] ) && ! $this->refreshed_tags ) {

				wpf_get_tags( $user_id, true );
				$this->refreshed_tags = true;

				$can_access = $this->user_can_access( $post_id, $user_id );

			}

		}

		/**
		 * Determine whether a user can access a post.
		 *
		 * @since 1.0.0
		 *
		 * @link  https://wpfusion.com/documentation/filters/wpf_user_can_access/
		 *
		 * @param bool  $can_access Whether or not the user can access the post.
		 * @param int   $user_id    The user ID.
		 * @param int   $post_id    The post ID.
		 */

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, $user_id, $post_id );

		// Cache the result for cases where we need to check the same post ID in
		// a single page load, this will save a few database hits.

		$this->cache_can_access( $can_access, $user_id, $post_id );

		return $can_access;

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

		$taxonomy_rules = $this->get_taxonomy_rules();

		if ( ! isset( $taxonomy_rules[ $term_id ] ) ) {
			return true;
		}

		$archive_restrictions = $taxonomy_rules[ $term_id ];

		// If content isn't locked
		if ( empty( $archive_restrictions ) || empty( $archive_restrictions['lock_content'] ) ) {
			return true;
		}

		// If not logged in
		if ( ! wpf_is_user_logged_in() ) {
			return apply_filters( 'wpf_user_can_access_archive', false, $user_id, $term_id );
		}

		if ( empty( $user ) ) {
			$user_id = wpf_get_current_user_id();
		}

		// If no tags specified for restriction, but user is logged in, allow access
		if ( empty( $archive_restrictions['allow_tags'] ) ) {
			return apply_filters( 'wpf_user_can_access_archive', true, $user_id, $term_id );
		}

		// If admins are excluded from restrictions
		if ( wpf_admin_override() ) {
			return true;
		}

		$user_tags = wpf_get_tags( $user_id );

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
	 * Checks if a user can access a given post based on restrictions configured
	 * for the taxonomy terms.
	 *
	 * @since  3.0.3
	 *
	 * @param  int  $post_id The post ID.
	 * @param  bool $user_id The user ID.
	 * @return bool Whether or not a user can access specified content.
	 */
	public function user_can_access_term( $post_id, $user_id = false ) {

		$restricted_terms = $this->get_restricted_terms( $user_id );

		if ( empty( $restricted_terms ) ) {
			return true;
		}

		// Now we've determined which terms the user can't access. Let's see if
		// this post has any of those terms. This is better for performance than
		// getting all the terms on the post first and checking them for
		// permissions.

		global $wpdb;

		// See if the post has the term.

		$in_str = implode( ',', array_map( 'absint', $restricted_terms ) );

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(*) FROM
				$wpdb->term_relationships
				WHERE object_id = %s
				AND term_taxonomy_id IN ($in_str)
				",
				$post_id
			)
		);

		// NB: this has to be $in_str and not %s because $wpdb->prepare will
		// place single quotes around %s and this breaks the query.

		if ( ! empty( $result ) ) {

			$can_access = false;

		} else {

			$can_access = true;

		}

		return apply_filters( 'wpf_user_can_access_term', $can_access, $user_id, $post_id );

	}

	/**
	 * Checks if a user can access a given post type based on the post type rules
	 *
	 * @access public
	 * @return bool Whether or not a user can access specified content.
	 */

	public function user_can_access_post_type( $post_type, $user_id = false ) {

		if ( false == $user_id ) {
			$user_id = wpf_get_current_user_id();
		}

		$settings = wpf_get_option( 'post_type_rules', array() );
		$settings = apply_filters( 'wpf_post_type_rules', $settings );

		$can_access = true;

		if ( ! empty( $settings[ $post_type ] ) && ! empty( $settings[ $post_type ]['allow_tags'] ) ) {

			if ( ! wpf_is_user_logged_in() ) {

				$can_access = false;

			} else {

				$user_tags = wpf_get_tags( $user_id );

				$result = array_intersect( $settings[ $post_type ]['allow_tags'], $user_tags );

				if ( empty( $result ) ) {
					$can_access = false;
				}
			}
		}

		return apply_filters( 'wpf_user_can_access_post_type', $can_access, $user_id, $post_type );

	}

	/**
	 * Finds redirect for a given post
	 *
	 * @access public
	 * @return mixed
	 */

	public function get_redirect( $post_id ) {

		// Get settings.
		$post_restrictions = $this->get_post_access_meta( $post_id );

		// If term restrictions are in place, they override the post restrictions.
		$term_restrictions = $this->get_redirect_term( $post_id );

		// The term redirect only applies if no post redirect is set.
		if ( ! empty( $term_restrictions ) && empty( $post_restrictions['redirect'] ) ) {
			$post_restrictions = array_merge( $post_restrictions, $term_restrictions );
		}

		// Not logged in redirect.

		$redirect = wpf_get_option( 'default_not_logged_in_redirect' );

		if ( ! wpf_is_user_logged_in() && ! empty( $redirect ) ) {
			return $redirect;
		}

		$default_redirect = wpf_get_option( 'default_redirect' );

		// Get redirect URL if one is set. (old data storage, pre 3.41.0)
		if ( ! empty( $post_restrictions['redirect_url'] ) ) {

			$redirect = $post_restrictions['redirect_url'];

		} elseif ( ! empty( $post_restrictions['redirect'] ) ) {

			// Don't allow infinite redirect.
			if ( absint( $post_restrictions['redirect'] ) === absint( $post_id ) ) {
				return false;
			}

			if ( 'home' === $post_restrictions['redirect'] ) {
				$redirect = home_url();
			} elseif ( 'login' === $post_restrictions['redirect'] ) {
				$redirect = wp_login_url();
			} elseif ( is_numeric( $post_restrictions['redirect'] ) ) {
				$redirect = get_permalink( $post_restrictions['redirect'] );
			} else {
				$redirect = $post_restrictions['redirect'];
			}
		} elseif ( ! empty( $default_redirect ) ) {

			$redirect = $default_redirect;

		} else {

			$redirect = false;

		}

		return apply_filters( 'wpf_redirect_url', $redirect, $post_id );

	}

	/**
	 * Finds redirect for a given term
	 *
	 * @access public
	 * @return mixed
	 */

	public function get_redirect_term( $post_id ) {

		$taxonomy_rules = $this->get_taxonomy_rules();

		// Skip early if no taxonomy rules supplied
		if ( empty( $taxonomy_rules ) ) {
			return false;
		}

		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) );

		$terms = wp_get_post_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) );

		$user_tags = wpf_get_tags();

		foreach ( $terms as $term_id ) {

			if ( ! isset( $taxonomy_rules[ $term_id ] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_content'] ) || ! isset( $taxonomy_rules[ $term_id ]['lock_posts'] ) ) {
				continue;
			}

			if ( ! wpf_is_user_logged_in() && empty( $taxonomy_rules[ $term_id ]['allow_tags'] ) ) {

				return $taxonomy_rules[ $term_id ];

			} elseif ( ! empty( $taxonomy_rules[ $term_id ]['allow_tags'] ) ) {

				// If user doesn't have the tags to access that term, return the redirect for that term
				$result = array_intersect( $taxonomy_rules[ $term_id ]['allow_tags'], $user_tags );

				if ( empty( $result ) ) {
					return $taxonomy_rules[ $term_id ];
				}
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

		// Allow bypassing redirect.

		if ( apply_filters( 'wpf_begin_redirect', false, wpf_get_current_user_id() ) ) {
			return true;
		}

		if ( is_admin() || is_search() || ( is_front_page() && is_home() ) ) { // Don't run if the front page is the blog index page

			return true;

		} elseif ( is_post_type_archive() ) {

			// Check the post type rules
			$queried_object = get_queried_object();

			if ( true == $this->user_can_access_post_type( $queried_object->name ) ) {
				return true;
			}

			$settings = wpf_get_option( 'post_type_rules', array() );
			$settings = apply_filters( 'wpf_post_type_rules', $settings );

			$redirect = false;

			if ( ! empty( $settings[ $queried_object->name ] ) && ! empty( $settings[ $queried_object->name ]['redirect'] ) ) {

				$redirect = get_permalink( $settings[ $queried_object->name ]['redirect'] );

			} elseif ( ! empty( wpf_get_option( 'default_redirect' ) ) ) {

				$redirect = wpf_get_option( 'default_redirect' );

			}

			$redirect = apply_filters( 'wpf_redirect_url', $redirect, false );

			if ( ! empty( $redirect ) ) {

				wp_redirect( $redirect, 302, 'WP Fusion; Post Type ' . $queried_object->name );
				exit();

			}

			// Don't do anything for post type archives if no redirect specified
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

			$taxonomy_rules       = $this->get_taxonomy_rules();
			$archive_restrictions = $taxonomy_rules[ $queried_object->term_id ];

			$redirect         = false;
			$default_redirect = wpf_get_option( 'default_redirect' );

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

				wp_redirect( $redirect, 302, 'WP Fusion; Term ID ' . $queried_object->term_id );
				exit();

			}

			// Don't do anything for archives if no redirect specified.
			return true;

		} else {

			// Single post redirects.
			global $post;

			if ( empty( $post ) || ! is_a( $post, 'WP_Post' ) ) {
				return true;
			}

			// For inheriting restrictions from another post.
			$post_id = apply_filters( 'wpf_redirect_post_id', $post->ID );

			if ( empty( $post_id ) ) {
				return true;
			}

			// If user can access, return without doing anything.
			if ( $this->user_can_access( $post_id ) ) {
				return true;
			}

			// Allow search engines to see excerpts.
			if ( wpf_get_option( 'seo_enabled' ) && $this->verify_bot() ) {

				add_filter( 'the_content', array( $this, 'seo_content_filter' ) );
				return true;

			}

			// Return after login.
			$this->set_return_after_login( $post_id );

			// Get redirect URL for the post.
			$redirect = $this->get_redirect( $post_id );

			if ( ! empty( $redirect ) ) {

				wp_redirect( $redirect, 302, 'WP Fusion; Post ID ' . $post_id );
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
	 * Sets the cookie to redirect a user after login
	 *
	 * @access public
	 * @return void
	 */

	public function set_return_after_login( $post_id ) {

		if ( wpf_is_user_logged_in() || ! wpf_get_option( 'return_after_login' ) || empty( $post_id ) ) {
			return;
		}

		$post_id = apply_filters( 'wpf_return_after_login_post_id', $post_id );

		setcookie( 'wpf_return_to', absint( $post_id ), time() + 5 * MINUTE_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

	}

	/**
	 * Outputs "restricted" message on restricted content
	 *
	 * @access public
	 * @return mixed
	 */

	public function restricted_content_filter( $content ) {

		// Remove the filter so the call to do_shortcode() doesn't run another content filter (Reusable Blocks for Gutenberg).

		remove_filter( 'the_content', array( $this, 'restricted_content_filter' ) );

		$content = $this->get_restricted_content_message();

		add_filter( 'the_content', array( $this, 'restricted_content_filter' ) );

		return $content;

	}

	/**
	 * Get restricted content message for a post
	 *
	 * @access public
	 * @return mixed
	 */

	public function get_restricted_content_message( $post_id = false ) {

		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();
		}

		$message = false;

		if ( wpf_get_option( 'per_post_messages' ) ) {

			$settings = get_post_meta( $post_id, 'wpf-settings', true );

			if ( ! empty( $settings['message'] ) ) {

				$message = wp_specialchars_decode( $settings['message'], ENT_QUOTES );

			}
		}

		if ( false === $message ) {
			$message = wpf_get_option( 'restricted_message' );
		}

		$extended = get_extended( get_post_field( 'post_content', $post_id, 'display' ) );

		if ( ! empty( $extended['extended'] ) ) {
			$message = $extended['main'] . trim( preg_replace( '/\[the_excerpt .*\]/', '', $message ) ); // Remove any [the_excerpt] shortcodes.
		}

		$content = do_shortcode( stripslashes( $message ) );

		$content = apply_filters( 'wpf_restricted_content_message', $content, $post_id );

		return $content;

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
	 * Handles site lockout functionality
	 *
	 * @access public
	 * @return void
	 */

	public function maybe_do_lockout() {

		// If the user is an admin and exclude admins is enabled, return.
		if ( wpf_admin_override() ) {
			return;
		}

		if ( ! wpf_is_user_logged_in() ) {
			return;
		}

		$lockout_tags = wpf_get_option( 'lockout_tags', array() );

		if ( empty( $lockout_tags ) ) {
			return;
		}

		$user_tags = wpf_get_tags();

		$result = array_intersect( $lockout_tags, $user_tags );

		if ( ! empty( $result ) ) {

			$lockout_url = wpf_get_option( 'lockout_redirect' );

			if ( empty( $lockout_url ) || ! wp_http_validate_url( $lockout_url ) ) {
				$lockout_url = wp_login_url();
			}

			if ( $lockout_url == wp_login_url() && $GLOBALS['pagenow'] === 'wp-login.php' ) {
				return;
			}

			$lockout_urls = array( $lockout_url );

			$additional_urls = wpf_get_option( 'lockout_allowed_urls' );

			if ( ! empty( $additional_urls ) ) {

				$additional_urls = explode( PHP_EOL, $additional_urls );
				$additional_urls = array_map( 'trim', $additional_urls );

				$lockout_urls = array_merge( $lockout_urls, $additional_urls );

			}

			// Get the requested URL

			$requested_url  = is_ssl() ? 'https://' : 'http://';
			$requested_url .= $_SERVER['HTTP_HOST'];
			$requested_url .= $_SERVER['REQUEST_URI'];

			// Check the current post to see if it's allowed

			foreach ( $lockout_urls as $url ) {

				if ( fnmatch( $url, $requested_url ) ) {
					return;
				}
			}

			wp_redirect( $lockout_url, 302, 'WP Fusion; Lockout' );
			exit();

		}

	}

	/**
	 * Applies the current user's tags as CSS classes to the <body> element.
	 *
	 * @since 3.42.1
	 *
	 * @param array $classes The CSS classes.
	 * @return array The CSS classes.
	 */
	public function body_class( $classes ) {

		if ( ! wpf_get_option( 'tags_as_classes' ) || ! wpf_is_user_logged_in() ) {
			return $classes;
		}

		foreach ( wpf_get_tags() as $tag_id ) {

			$tag_name  = sanitize_title( wpf_get_tag_label( $tag_id ) );
			$classes[] = 'tag-' . $tag_name;

		}

		return $classes;
	}

	/**
	 * Determines whether a search engine or bot is trying to crawl the page
	 *
	 * @access public
	 * @return bool
	 */

	public function verify_bot() {

		$agent = 'no-agent-found';

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );
		}

		$known_engines = array( 'google', 'bing', 'msn', 'yahoo', 'ask', 'facebook' );

		foreach ( $known_engines as $engine ) {

			if ( strpos( $agent, $engine ) !== false && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {

				$ip_to_check = $_SERVER['REMOTE_ADDR'];

				// Lookup the host by this IP address.
				$hostname = gethostbyaddr( $ip_to_check );

				if ( $engine === 'google' && ! preg_match( '#^.*\.googlebot\.com$#', $hostname ) ) {
					break;
				}

				if ( $engine === 'facebook' && ! preg_match( '#^.*\.fbsv\.net$#', $hostname ) ) {
					break;
				}

				if ( ( $engine === 'bing' || $engine === 'msn' ) && ! preg_match( '#^.*\.search\.msn\.com$#', $hostname ) ) {
					break;
				}

				if ( $engine === 'ask' && ! preg_match( '#^.*\.ask\.com$#', $hostname ) ) {
					break;
				}

				// Even though yahoo is contracted with bingbot, they do still send out slurp to update some entries etc
				if ( ( $engine === 'yahoo' || $engine === 'slurp' ) && ! preg_match( '#^.*\.crawl\.yahoo\.net$#', $hostname ) ) {
					break;
				}

				if ( $hostname !== false && $hostname !== $ip_to_check ) {

					// Do the reverse lookup.
					$ip_to_verify = gethostbyname( $hostname );

					if ( $ip_to_verify != $hostname && $ip_to_verify == $ip_to_check ) {
						return true;
					}
				}
			}
		}

		// Otherwise return false.
		return false;

	}

	/**
	 * Outputs excerpt for bots and search engines
	 *
	 * @access public
	 * @return mixed
	 */

	public function seo_content_filter( $content ) {

		$length = wpf_get_option( 'seo_excerpt_length', 55 );

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

		if ( is_admin() ) {
			return $items;
		}

		// General menu item hiding.

		if ( wpf_get_option( 'hide_restricted' ) ) {

			foreach ( $items as $key => $item ) {

				if ( $item->type == 'taxonomy' && $this->user_can_access_archive( $item->object_id ) === false ) {

					unset( $items[ $key ] );

				} elseif ( $item->type == 'post_type' && $this->user_can_access( $item->object_id ) === false ) {

					unset( $items[ $key ] );

				} elseif ( $item->type == 'custom' ) {

					/*
					 * Removed for 3.37.5

					if ( class_exists( 'SitePress' ) ) {
						continue; // WPML sometimes crashes out running url_to_postid() here and we don't know why
					}

					// If it's a post, try and get the ID

					$post_id = url_to_postid( $item->url );

					if ( 0 !== $post_id && false === $this->user_can_access( $post_id ) ) {
						unset( $items[ $key ] );
					}

					*/
				}
			}
		}

		// Specific item access rules.

		if ( wpf_get_option( 'enable_menu_items', true ) ) {

			foreach ( $items as $key => $item ) {

				$item_id = $item->ID;

				$settings = get_post_meta( $item->ID, 'wpf-settings', true );

				if ( empty( $settings ) ) {

					$item_id = $item->menu_item_parent;

					// Also hide if parent is hidden.
					$parent_settings = get_post_meta( $item_id, 'wpf-settings', true );

					if ( ! empty( $parent_settings ) ) {
						$settings = $parent_settings;
					}
				}

				// Skip for loggedout setting.
				if ( isset( $settings['loggedout'] ) && ! wpf_is_user_logged_in() ) {
					continue;
				}

				if ( ! $this->user_can_access( $item_id ) ) {

					unset( $items[ $key ] );

				} elseif ( isset( $settings['loggedout'] ) && wpf_is_user_logged_in() ) {

					unset( $items[ $key ] );

				}
			}
		}

		return $items;

	}


	/**
	 * Filters out restricted term items
	 *
	 * @access  public
	 * @return  array Args
	 */

	public function get_terms_args( $args, $taxonomies ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $args;
		}

		// Stop this from breaking things if it's called before pluggable.php is loaded.

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return $args;
		}

		if ( wpf_admin_override() ) { // if we're currently an admin and Exclude Admins is on.
			return $args;
		}

		$taxonomy_rules = $this->get_taxonomy_rules();

		if ( empty( $taxonomy_rules ) ) {
			return $args;
		}

		$user_tags = wpf_get_tags();

		foreach ( $taxonomy_rules as $term_id => $rule ) {

			if ( ! isset( $rule['lock_content'] ) || ! isset( $rule['hide_term'] ) || empty( $rule['allow_tags'] ) ) {
				continue;
			}

			if ( ! wpf_is_user_logged_in() ) {

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
	 * Is a post type eligible for query filtering?
	 *
	 * @since  3.37.6
	 *
	 * @param  array|string $post_types The post types to check.
	 * @return bool         Whether or not the post type is eligible.
	 */
	public function is_post_type_eligible_for_query_filtering( $post_types = array() ) {

		$is_eligible = true;

		// Some queries use multiple post types.

		if ( ! empty( $post_types ) && ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		} elseif ( empty( $post_types ) ) {
			$post_types = array();
		}

		// Don't run on non-public post types.

		$public_post_types = array_values( get_post_types( array( 'public' => true ) ) );

		// We'll also consider "any" to be a valid post type, during searches.

		$public_post_types[] = 'any';

		if ( ! empty( $post_types ) && empty( array_intersect( $post_types, $public_post_types ) ) ) {
			$is_eligible = false;
		}

		// See if the post type is enabled in the WPF settings.

		if ( $is_eligible ) {

			$allowed_post_types = wpf_get_option( 'query_filter_post_types', array() );

			if ( ! empty( $allowed_post_types ) ) {

				// We'll also consider "any" to be a valid post type, during searches.

				$allowed_post_types[] = 'any';

				if ( empty( array_intersect( $post_types, $allowed_post_types ) ) ) {
					$is_eligible = false;
				}
			}
		}

		/**
		 * Is post type eligible for query filtering?
		 *
		 * @since 3.37.6
		 *
		 * @param bool  $is_eligible Whether or not the post types are eligible.
		 * @param array $post_types  The post types.
		 */

		return apply_filters( 'is_post_type_eligible_for_query_filtering', $is_eligible, $post_types );

	}

	/**
	 * Is a query eligible for query filtering?
	 *
	 * @since 3.37.6
	 *
	 * @param WP_Query $query  The query.
	 * @return bool  To filter the query or not.
	 */
	public function should_filter_query( $query ) {

		if ( empty( $query ) || ! is_object( $query ) ) {
			return false;
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return false;
		}

		if ( defined( 'DOING_CRON' ) ) {
			return false;
		}

		if ( doing_filter( 'wpf_user_can_access' ) ) {
			return false; // if we're already inside the access check filter, don't filter queries again.
		}

		if ( $query->is_main_query() && ! $query->is_archive() && ! $query->is_search() && ! $query->is_home() ) {
			return false;
		}

		if ( doing_wpf_webhook() ) {
			return false; // Don't want to hide any auto-enrollment content during a webhook.
		}

		// If the setting isn't enabled.
		if ( ! wpf_get_option( 'hide_archives' ) ) {
			return false;
		}

		// Don't bother doing anything if we're not restricting access to admins anyway.
		if ( wpf_admin_override() ) {
			return false;
		}

		// See if the post type is eligible.
		$post_type = $query->get( 'post_type', 'post' );

		if ( ! $this->is_post_type_eligible_for_query_filtering( $post_type ) ) {
			return false;
		}

		/**
		 * Should filter query?
		 *
		 * Sometimes query filtering just doesn't work. We can quit early if
		 * needed.
		 *
		 * @since 3.37.6
		 *
		 * @link  https://wpfusion.com/documentation/filters/wpf_should_filter_query/
		 *
		 * @param bool     $should_filter Whether or not to filter the query.
		 * @param WP_Query $query         The query.
		 */

		return apply_filters( 'wpf_should_filter_query', true, $query );

	}

	/**
	 * Gets the restricted posts for a post type.
	 *
	 * Used with Filter Queries - Advanced to pass an array of post IDs into
	 * post__not_in.
	 *
	 * This only gets posts that are restricted directly via their postmeta, not
	 * posts inheriting protetcions or posts protected by taxonomy terms.
	 *
	 * @since  3.37.6
	 * @since  3.40.28 Added second parameter $in.
	 *
	 * @param  array $post_types The post types.
	 * @param  array $in         Optionally limit it to specific posts.
	 * @return array The restricted post IDs.
	 */
	public function get_restricted_posts( $post_types, $in = array() ) {

		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		// Save a query if the user is an admin and admins are excluded.

		if ( wpf_admin_override() ) {
			return array();
		}

		$post_types = array_values( $post_types );

		$user_id = wpf_get_current_user_id();
		$not_in  = wp_cache_get( "wpf_query_filter_{$user_id}_{$post_types[0]}" );

		if ( false === $not_in ) {

			$args = array(
				'post_type'      => $post_types,
				'posts_per_page' => 200, // 200 sounds like a safe place for performance?
				'fields'         => 'ids',
				'post__in'       => $in,
				'meta_query'     => array(
					array(
						'key'     => 'wpf-settings',
						'compare' => 'EXISTS',
					),
				),
			);

			/**
			 * Query get post args.
			 *
			 * In case you need to change a parameter with the pre-query to find
			 * the restricted posts.
			 *
			 * @since 3.37.6
			 *
			 * @param array $args   Array of WP_Query args.
			 */

			$args = apply_filters( 'wpf_query_filter_get_posts_args', $args );

			// Don't loop back on this or it will be bad.

			remove_action( 'pre_get_posts', array( $this, 'filter_queries' ), $this->filter_queries_priority );

			// Get the posts.

			$post_ids = get_posts( $args );

			add_action( 'pre_get_posts', array( $this, 'filter_queries' ), $this->filter_queries_priority );

			if ( count( $post_ids ) === $args['posts_per_page'] ) {
				wpf_log( 'notice', wpf_get_current_user_id(), 'Filter Queries is running on the <strong>' . implode( ', ', $post_types ) . '</strong> post type(s), but more than ' . $args['posts_per_page'] . ' posts were found with WP Fusion access rules. To protect the stability of your site, additional posts beyond the first ' . $args['posts_per_page'] . ' will not be filtered. This can be modified with the <a href="https://wpfusion.com/documentation/filters/wpf_query_filter_get_posts_args/" target="_blank"><code>wpf_query_filter_get_posts_args</code> filter</a>.' );
			}

			$not_in = array();

			foreach ( $post_ids as $post_id ) {

				if ( ! $this->user_can_access( $post_id, $user_id ) ) {

					$not_in[] = $post_id;

				}
			}

			// Cache it so we can use it later.

			$cache_time = apply_filters( 'wpf_query_filter_cache_time', MINUTE_IN_SECONDS, $user_id, $post_types );

			wp_cache_set( "wpf_query_filter_{$user_id}_{$post_types[0]}", $not_in, '', $cache_time );

		}

		return $not_in;

	}

	/**
	 * Extends the WHERE clause on queries filtered in Advanced mode to exclude
	 * post IDs the user can't access based on the taxonomy rules.
	 *
	 * @since  3.38.17
	 *
	 * @param  string   $where  The where clause.
	 * @param  WP_Query $query  The query.
	 * @return string   The where clause.
	 */
	public function posts_where_restricted_terms( $where, $query ) {

		if ( ! $query->get( 'wpf_filtering_query' ) ) {
			return $where; // only relevant when Filter Queries is running on the query.

		}

		$restricted_terms = $this->get_restricted_terms();

		if ( ! empty( $restricted_terms ) ) {

			global $wpdb;

			$not_in = implode( ',', array_map( 'absint', $restricted_terms ) );

			$where .= " AND ( {$wpdb->posts}.ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($not_in) ) )";

		}

		return $where;

	}

	/**
	 * Gets all of the terms that the current user does not have access to.
	 *
	 * @since  3.38.17
	 * @since  3.38.25 Added filter on the return value.
	 *
	 * @param  int $user_id The user ID to check.
	 * @return array  The restricted termm IDs.
	 */
	public function get_restricted_terms( $user_id = false ) {

		$taxonomy_rules = $this->get_taxonomy_rules();

		// Skip early if no taxonomy rules supplied.
		if ( empty( $taxonomy_rules ) ) {
			return array();
		}

		if ( empty( $user_id ) ) {
			$user_id = wpf_get_current_user_id();
		}

		$term_ids = array();

		foreach ( $taxonomy_rules as $term_id => $settings ) {

			if ( empty( $settings['lock_posts'] ) ) {
				continue;
			}

			if ( empty( $user_id ) ) {

				$term_ids[] = $term_id; // lock posts is checked and user isn't logged in.

			} elseif ( ! empty( $settings['allow_tags'] ) && ! wpf_has_tag( $settings['allow_tags'], $user_id ) ) {

				// User is logged in and doesn't have the required tags.
				$term_ids[] = $term_id;

			}
		}

		return apply_filters( 'wpf_restricted_terms_for_user', $term_ids, $user_id );

	}

	/**
	 * Handles Filter Queries.
	 *
	 * @access  public
	 * @return  void
	 */

	public function filter_queries( $query ) {

		if ( $this->should_filter_query( $query ) ) {

			$setting = wpf_get_option( 'hide_archives' );

			/**
			 * Query filtering mode.
			 *
			 * Lets you change the query filtering mode between standard /
			 * advanced / off depending on the query object. For example
			 * disabling query filtering on bbPress topics in favor of the
			 * parent forum.
			 *
			 * @since 3.37.0
			 *
			 * @param string   $setting The seting.
			 * @param WP_Query $query   The query.
			 */

			$setting = apply_filters( 'wpf_query_filtering_mode', $setting, $query );

			if ( 'standard' === $setting ) {

				$query->set( 'suppress_filters', false );

			} elseif ( 'advanced' === $setting ) {

				$post_types = $query->get( 'post_type', 'post' );

				if ( ! is_array( $post_types ) ) {
					$post_types = array( $post_types );
				}

				$allowed_post_types = wpf_get_option( 'query_filter_post_types', array() );

				if ( ! empty( $allowed_post_types ) ) {
					$post_types = array_intersect( $post_types, $allowed_post_types );
				}

				if ( empty( $post_types ) && $query->is_search() ) {

					if ( empty( $allowed_post_types ) ) {

						$post_types = 'any'; // This will be slower but allows filtering to work on search results.

					} else {
						$post_types = $allowed_post_types;
					}
				} elseif ( empty( $post_types ) ) {
					return;
				}

				$not_in = $this->get_restricted_posts( $post_types, $query->get( 'post__in' ) );

				if ( ! empty( $not_in ) ) {

					// Maybe merge existing.

					if ( ! empty( $query->get( 'post__not_in' ) ) ) {
						$not_in = array_merge( $query->get( 'post__not_in' ), $not_in );
					}

					$query->set( 'post__not_in', $not_in );

					$query->set( 'wpf_filtering_query', true );

					// If the query has a post__in, that will take priority, so we'll adjust for that here.

					if ( ! empty( $query->get( 'post__in' ) ) ) {
						$in = array_diff( $query->get( 'post__in' ), $not_in );
						$query->set( 'post__in', $in );
					}
				}
			}
		}

	}

	/**
	 * Removes restricted posts if Filter Queries is on (standard mode)
	 *
	 * @access  public
	 * @return  array Posts
	 */

	public function exclude_restricted_posts( $posts, $query ) {

		if ( ! is_array( $posts ) ) {
			return $posts;
		}

		if ( ! $this->should_filter_query( $query ) ) {
			return $posts;
		}

		// Woo variations bug fixed.
		if ( isset( $_REQUEST['action'] ) && 'woocommerce_load_variations' === $_REQUEST['action'] ) {
			return $posts;
		}

		// Sometimes we may want to change the mode depending on the post type (for example bbPress topics).

		$setting = wpf_get_option( 'hide_archives' );

		/**
		 * Query filtering mode.
		 *
		 * Lets you change the query filtering mode between standard /
		 * advanced / off depending on the query object. For example
		 * disabling query filtering on bbPress topics in favor of the
		 * parent forum.
		 *
		 * @since 3.37.0
		 *
		 * @param string   $setting The seting.
		 * @param WP_Query $query   The query.
		 */

		$setting = apply_filters( 'wpf_query_filtering_mode', $setting, $query );

		if ( 'standard' === $setting ) {

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
	 * Protect blog index page
	 *
	 * @access  public
	 * @return  int Post ID
	 */

	public function protect_blog_index( $post_id ) {

		if ( is_home() && ! is_front_page() ) {
			return get_option( 'page_for_posts' );
		}

		return $post_id;

	}

	/**
	 * Check post type restrictions for post
	 *
	 * @access public
	 * @return array Access Meta
	 */

	public function check_post_type( $access_meta, $post_id ) {

		$settings = wpf_get_option( 'post_type_rules', array() );

		$settings = apply_filters( 'wpf_post_type_rules', $settings );

		if ( empty( $settings ) ) {
			return $access_meta;
		}

		$post_type = get_post_type( $post_id );

		if ( ! isset( $settings[ $post_type ] ) ) {
			return $access_meta;
		}

		// Let the post redirect override the global redirect if applicable.
		if ( ! empty( $access_meta['redirect'] ) ) {
			unset( $settings[ $post_type ]['redirect'] );
		}

		$access_meta = array_merge( $access_meta, array_filter( $settings[ $post_type ] ) );

		return $access_meta;

	}

	/**
	 * Protects restricted post content when accessed over the REST API.
	 *
	 * @since 3.42.2
	 *
	 * @param WP_REST_Response $data The REST response object.
	 * @param WP_Post          $post Post object.
	 */
	public function protect_rest_content_response( $data, $post ) {

		if ( ! $this->user_can_access( $post->ID ) ) {
			$data->data['content']['rendered'] = '<!-- protected by WP Fusion, post ID ' . $post->ID . ' -->';
		}

		return $data;

	}


	/**
	 * Cache the user_can_access check in memory so we don't have to hit the
	 * database twice for the same post.
	 *
	 * @since 3.37.4
	 *
	 * @param bool     $can_access Indicates if user can access.
	 * @param int      $user_id    The user ID.
	 * @param int|bool $post_id    The post ID.
	 * @return bool Whether or not the user can access.
	 */
	public function cache_can_access( $can_access, $user_id, $post_id = false ) {

		if ( false !== $post_id && ! isset( $this->can_access_posts[ $post_id ] ) ) { // Don't set it twice for the same post ID.
			$this->can_access_posts[ $post_id ] = $can_access;
		}

		return $can_access;

	}

	/**
	 * If tags are applied, new content may be unlocked, so clear the cache.
	 *
	 * @since 3.40.28
	 */
	public function clear_access_cache() {

		$this->can_access_posts = array();

	}


	/**
	 * Redirect back to restricted content after login
	 *
	 * @access public
	 * @return void
	 */

	public function return_after_login( $user_login ) {

		if ( wp_doing_ajax() ) {
			return; // Don't try to do a redirect during an AJAX request.
		}

		if ( isset( $_COOKIE['wpf_return_to_override'] ) ) {

			$user    = get_user_by( 'login', $user_login );
			$post_id = absint( $_COOKIE['wpf_return_to_override'] );
			$url     = get_permalink( $post_id );

			if ( $user && ! empty( $url ) && $this->user_can_access( $post_id, $user->ID ) ) {

				setcookie( 'wpf_return_to_override', '', time() - ( 15 * 60 ) );
				setcookie( 'wpf_return_to', '', time() - ( 15 * 60 ) );

				wp_safe_redirect( $url, 302, 'WP Fusion; Return after login' );
				exit();

			}
		}

		if ( isset( $_COOKIE['wpf_return_to'] ) ) {

			$user    = get_user_by( 'login', $user_login ); // Since WordPress 6.0 wp_get_current_user() no longer works at this point.
			$post_id = absint( $_COOKIE['wpf_return_to'] );
			$url     = get_permalink( $post_id );

			setcookie( 'wpf_return_to', '', time() - ( 15 * 60 ) );

			if ( $user && ! empty( $url ) && $this->user_can_access( $post_id, $user->ID ) ) {

				wp_safe_redirect( $url, 302, 'WP Fusion; Return after login' );
				exit();

			}
		}

	}

	/**
	 * This is a fallback on the login_redirect filter for cases where other
	 * plugins might run a login redirect on the wp_login action before our
	 * redirect has a chance to run.
	 *
	 * @since 3.40.21
	 *
	 * @param string  $redirect_to           The redirect URI.
	 * @param string  $requested_redirect_to The requested redirect URI.
	 * @param WP_User $user                  The WP_User (or WP_Error) logging in.
	 * @return string The redirect URI.
	 */
	public function login_redirect( $redirect_to, $requested_redirect_to, $user = false ) {

		if ( false === $user ) {
			$user = wpf_get_current_user();
		}

		if ( isset( $_COOKIE['wpf_return_to'] ) && is_a( $user, 'WP_User' ) ) {

			$post_id = absint( $_COOKIE['wpf_return_to'] );
			$url     = get_permalink( $post_id );

			setcookie( 'wpf_return_to', '', time() - ( 15 * 60 ) );

			if ( ! empty( $url ) && $this->user_can_access( $post_id, $user->ID ) ) {
				return $url;
			}

		}

		return $redirect_to;

	}

	/**
	 * Removes restricted widgets from sidebars
	 *
	 * @access public
	 * @return array Widget Instance
	 */

	public function hide_restricted_widgets( $instance, $widget, $args ) {

		if ( empty( $instance['wpf_conditional'] ) ) {
			return $instance;
		}

		// If not logged in.
		if ( ! wpf_is_user_logged_in() ) {
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
		$user_tags = wpf_get_tags();

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

		// If admins are excluded from restrictions.
		if ( wpf_admin_override() ) {
			$can_access = true;
		}

		$can_access = apply_filters( 'wpf_user_can_access', $can_access, wpf_get_current_user_id(), false );

		// Widget filter.
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

		// We used to check for wpf_is_logged_in() here as well, but we're not going to anymore
		// Since an auto-login session on WP Engine will show as not-logged-in, even though the AJAX call to apply tags (with a delay) works

		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		// Don't apply tags if restricted.
		if ( ! wp_fusion()->access->user_can_access( $post->ID ) ) {
			return;
		}

		if ( false === apply_filters( 'wpf_apply_tags_on_view', true, $post->ID ) ) {
			return;
		}

		$defaults = array(
			'apply_tags'  => array(),
			'remove_tags' => array(),
		);

		$settings = get_post_meta( $post->ID, 'wpf-settings', true );

		$settings = wp_parse_args( $settings, $defaults );

		// Get term settings.

		$taxonomy_rules = $this->get_taxonomy_rules();

		if ( ! empty( $taxonomy_rules ) ) {

			foreach ( $taxonomy_rules as $term_id => $term_settings ) {

				if ( empty( $term_settings['apply_tags'] ) ) {
					continue;
				}

				// We need to un-hide hidden terms to see if the post is accessible.
				remove_action( 'get_terms_args', array( $this, 'get_terms_args' ), 10, 2 );

				// Now we've determined the term should apply tags when viewed. Let's see if this post has that term
				// This is better for performance than checking every term the post has.

				$term = get_term( $term_id );

				if ( is_a( $term, 'WP_Term' ) && is_object_in_term( $post->ID, $term->taxonomy, $term_id ) ) {
					$settings['apply_tags'] = array_merge( $settings['apply_tags'], $term_settings['apply_tags'] );
				}

				add_action( 'get_terms_args', array( $this, 'get_terms_args' ), 10, 2 );

			}
		}

		if ( ! empty( $settings['apply_tags'] ) || ! empty( $settings['remove_tags'] ) ) {

			if ( empty( $settings['apply_delay'] ) ) {

				// Won't do any good applying tags if they aren't logged in.

				if ( ! wpf_is_user_logged_in() ) {
					return;
				}

				if ( ! empty( $settings['apply_tags'] ) ) {

					wp_fusion()->user->apply_tags( $settings['apply_tags'] );

				}

				if ( ! empty( $settings['remove_tags'] ) ) {

					wp_fusion()->user->remove_tags( $settings['remove_tags'] );

				}

			} else {

				wp_enqueue_script( 'wpf-apply-tags', WPF_DIR_URL . 'assets/js/wpf-apply-tags.js', array( 'jquery' ), WP_FUSION_VERSION, true );

				$localize_data = array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wpf_ajax_nonce' ),
					'delay'   => $settings['apply_delay'],
				);

				if ( ! empty( $settings['apply_tags'] ) ) {
					$localize_data['tags'] = $settings['apply_tags'];
				}

				if ( ! empty( $settings['remove_tags'] ) ) {
					$localize_data['remove'] = $settings['remove_tags'];
				}

				wp_localize_script( 'wpf-apply-tags', 'wpf_ajax', $localize_data );

			}
		}

	}

	/**
	 * Refreshes the users tags and/or metadata based on a query string.
	 *
	 * @since 3.40.55
	 */
	public function handle_refresh() {

		if ( ! wpf_is_user_logged_in() ) {
			return;
		}

		if ( ! isset( $_GET['wpf-refresh'] ) ) {
			return;
		}

		$refresh = sanitize_text_field( wp_unslash( $_GET['wpf-refresh'] ) );

		if ( 'all' === $refresh || 'tags' === $refresh ) {
			wp_fusion()->user->get_tags( wpf_get_current_user_id(), true );
		}

		if ( 'all' === $refresh || 'meta' === $refresh ) {
			wp_fusion()->user->pull_user_meta();
		}

		// Redirect so the query string doesn't stick around.
		wp_safe_redirect( remove_query_arg( 'wpf-refresh', add_query_arg() ) );

	}

	/**
	 * Lets us know if the_content is filterable by WP Fusion for a given page.
	 *
	 * @since  3.37.30
	 *
	 * @param  string $content The content.
	 * @return string The content.
	 */
	public function test_the_content( $content ) {

		if ( in_the_loop() ) {
			$this->can_filter_content = true;
		}

		return $content;

	}

	/**
	 * Display a warning if the page is protected by WP Fusion, but no redirect
	 * was specified, and no content area is found.
	 *
	 * @since 3.37.30
	 *
	 * @return mixed HTML message.
	 */
	public function maybe_doing_it_wrong() {

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$access_meta = $this->get_post_access_meta( $post->ID );

		if ( true === $access_meta['lock_content'] && false === $this->can_filter_content && empty( $this->get_redirect( $post->ID ) ) ) {

			echo '<div style="padding: 10px 30px; border: 4px solid #ff0000; text-align: center; position: fixed; top: 32px; background: #fff; width: 100%; z-index: 999;">';

			echo '<p>' . wp_kses_post( '<strong>Warning:</strong> You\'ve protected this content with WP Fusion, but <u>you did not specify a redirect</u> for when access is denied. <strong>This content will be publicly accessible</strong>. For more information, see <em><a href="https://wpfusion.com/documentation/getting-started/access-control/#restricted-content-message-vs-redirect" target="_blank">Restricted Content Message vs Redirect</a></em>.', 'wp-fusion-lite' ) . '</p>';

			echo '<p><em><small>(' . esc_html__( 'This message is only shown to admins and won\'t be visible to regular visitors. To remove this message, either specify a redirect, or disable WP Fusion protection on this content.', 'wp-fusion-lite' ) . ')</small></em></p>';

			echo '</div>';

		}

	}

	/**
	 * //
	 * // DEPRECATED
	 * //
	 **/

	/**
	 * Rewrites permalinks for restricted content (deprecated in v3.29.3)
	 *
	 * @access public
	 * @return string URL of permalink
	 */

	public function rewrite_permalinks( $url, $post, $leavename ) {

		// Don't run on admin.
		if ( is_admin() ) {
			return $url;
		}

		if ( is_object( $post ) ) {
			$post = $post->ID;
		}

		if ( $this->user_can_access( $post ) ) {
			return $url;
		}

		$redirect = $this->get_redirect( $post );

		if ( ! empty( $redirect ) ) {

			return $redirect;

		} else {

			return $url;

		}

	}

}
