<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Admin_Bar {

	public function __construct() {

		add_action( 'init', array( $this, 'init' ) );

	}

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	public function init() {

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action( 'wp_before_admin_bar_render', array( $this, 'render_admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );

		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'unset_query_arg' ) );

		add_filter( 'wpf_user_can_access', array( $this, 'admin_bar_overrides' ), 10, 3 );
		add_filter( 'wpf_user_can_access_widget', array( $this, 'admin_bar_overrides_widget' ), 10, 3 );
		add_filter( 'wpf_user_can_access_block', array( $this, 'admin_bar_overrides_block' ), 10, 3 );

	}

	/**
	 * Register admin bar scripts and styles
	 *
	 * @access public
	 * @return void
	 */

	public function admin_bar_style() {

		wp_enqueue_script( 'wpf-admin-bar', WPF_DIR_URL . '/assets/js/wpf-admin-bar.js', array( 'jquery' ) );
		wp_enqueue_style( 'wpf-admin-bar', WPF_DIR_URL . '/assets/css/wpf-admin-bar.css', array(), WP_FUSION_VERSION );

	}

	/**
	 * Allows for overriding content restriction via the admin bar
	 *
	 * @access public
	 * @return bool
	 */

	public function admin_bar_overrides( $can_access, $user_id, $required_tags ) {

		if( empty( get_query_var( 'wpf_tag' ) ) ) {
			return $can_access;
		}

		if ( get_query_var( 'wpf_tag' ) == 'unlock-all' ) {
			return true;
		}

		if ( get_query_var( 'wpf_tag' ) == 'lock-all' ) {
			return false;
		}

		if ( empty( $required_tags ) ) {
			return true;
		}

		$user_tags = wp_fusion()->user->get_tags( $user_id );

		if ( $current_filter = get_query_var( 'wpf_tag' ) ) {
			$user_tags[] = $current_filter;
		}

		// If user has the required tag
		$result = array_intersect( $required_tags, $user_tags );

		if( ! empty( $result ) ) {
			return true;
		}

		return $can_access;

	}

	/**
	 * Allows for overriding content restriction via the admin bar
	 *
	 * @access public
	 * @return mixed Instance / False
	 */

	public function admin_bar_overrides_widget( $can_access, $instance, $widget ) {

		if ( get_query_var( 'wpf_tag' ) == 'unlock-all' ) {
			return $instance;
		}

		if ( get_query_var( 'wpf_tag' ) == 'lock-all' ) {
			return false;
		}

		$user_tags = wp_fusion()->user->get_tags();

		if ( $current_filter = get_query_var( 'wpf_tag' ) ) {
			$user_tags[] = $current_filter;
		}

        if( isset( $instance[$widget->id_base . '_wpf_tags'] ) ) {
        	$widget_tags = $instance[$widget->id_base . '_wpf_tags'];
        } 
       
        if ( isset( $instance[$widget->id_base . '_wpf_tags_not'] )) {
   			$widget_tags_not = $instance[$widget->id_base . '_wpf_tags_not'];
        }

        if( ! isset( $widget_tags ) && ! isset( $widget_tags_not ) ) {

         	if( $can_access ) {
				return $instance;
			} else {
				return false;
			}

		}

		if( isset( $widget_tags ) ) {

        	$result = array_intersect( $widget_tags, $user_tags );

        	if( empty( $result ) ) {
        		$can_access = false;
        	}

        }

        if( $can_access == true && isset( $widget_tags_not ) ) {

        	$result = array_intersect( $widget_tags_not, $user_tags );

        	if( ! empty( $result ) ) {
        		$can_access = false;
        	}

        }

		if( $can_access ) {
			return $instance;
		} else {
			return false;
		}

	}

	/**
	 * Allows for overriding content restriction via the admin bar
	 *
	 * @access public
	 * @return mixed Instance / False
	 */

	public function admin_bar_overrides_block( $can_access, $instance, $block_tags ) {

		if ( get_query_var( 'wpf_tag' ) == 'unlock-all' ) {
			return true;
		}

		if ( get_query_var( 'wpf_tag' ) == 'lock-all' ) {
			return false;
		}

		$user_tags = wp_fusion()->user->get_tags( $user_id );

		$query_var = get_query_var( 'wpf_tag' );

		if( ! empty( $query_var ) ) {
			$user_tags[] = get_query_var( 'wpf_tag' );
		}

		// If user has the required tag
		$result = array_intersect( $block_tags, $user_tags );

		if( ! empty( $result ) ) {
			return true;
		}

		return $can_access;


	}

	/**
	 * Unregister WPF query var
	 *
	 * @access public
	 * @return array Vars
	 */

	public function unset_query_arg( $query ) {

		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$filter = get_query_var( 'wpf_tag' );

		if ( ! empty( $filter ) ) {

			$query->set( 'wpf_tag', null );

			global $wp;

			// unset wpf_tag var from $wp
			unset( $wp->query_vars['wpf_tag'] );

			// if in home (because $wp->query_vars is empty) and 'show_on_front' is page
			if ( empty( $wp->query_vars ) && get_option( 'show_on_front' ) === 'page' ) {
				// reset and re-parse query vars
				$wp->query_vars['page_id'] = get_option( 'page_on_front' );
				$query->parse_query( $wp->query_vars );

			}

			// Reset the query var now that the query has been modified
			set_query_var( 'wpf_tag', $filter );

		}

	}


	/**
	 * Register WPF query var
	 *
	 * @access public
	 * @return array Vars
	 */

	public function query_vars( $vars ) {

		$vars[] = "wpf_tag";

		return $vars;

	}

	/**
	 * Assists admin bar in sorting tags
	 *
	 * @access public
	 * @return void
	 */

	public function subval_sort( $a, $subkey ) {
		foreach ( $a as $k => $v ) {
			$b[ $k ] = strtolower( $v[ $subkey ] );
		}
		asort( $b );
		foreach ( $b as $key => $val ) {
			$c[ $key ] = $a[ $key ];
		}

		return $c;
	}

	/**
	 * Renders admin bar
	 *
	 * @access public
	 * @return void
	 */

	public function render_admin_bar() {

		if ( $current_filter = get_query_var( 'wpf_tag' ) ) {

			$current_filter = urldecode( $current_filter );

			if ( $current_filter == 'unlock-all' ) {

				$label = 'Viewing: Unlock All';

			} elseif ( $current_filter == 'lock-all' ) {

				$label = 'Viewing: Lock All';

			} else {

				$label = 'Viewing: ' . wp_fusion()->user->get_tag_label( $current_filter );

			}

		} else {
			$label = 'Preview With Tag';
		}

		$menu_id = 'wpf';

		global $wp_admin_bar;
		global $wp;
		$current_url = home_url( $wp->request ) . '/';

		// Add the parent admin bar container
		$wp_admin_bar->add_menu( array( 'id' => $menu_id, 'title' => $label ) );

		// If theres'a filter currently set, show the option to remove filters
		if ( $current_filter = get_query_var( 'wpf_tag' ) ) {
			$wp_admin_bar->add_menu( array(
				'parent' => $menu_id,
				'title'  => 'Remove Filters',
				'id'     => 'remove-filters',
				'href'   => $current_url
			) );
		}

		$wp_admin_bar->add_menu( array(
			'parent' => $menu_id,
			'title'  => 'Unlock All',
			'id'     => 'unlock-all',
			'href'   => $current_url . '?wpf_tag=unlock-all'
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => $menu_id,
			'title'  => 'Lock All',
			'id'     => 'lock-all',
			'href'   => $current_url . '?wpf_tag=lock-all'
		) );

		$tag_categories = array();
		$available_tags = (array) wp_fusion()->settings->get( 'available_tags' );

		if ( is_array( reset( $available_tags ) ) ) {

			foreach ( (array) $available_tags as $value ) {
				$tag_categories[] = $value['category'];
			}

			$tag_categories = array_unique( $tag_categories );
			sort( $tag_categories );

			// Add the submenu headers
			foreach ( $tag_categories as $tag_category ) {
				$sub_menu_id = 'wpf-cat-' . sanitize_title_with_dashes( $tag_category );
				$wp_admin_bar->add_menu( array(
					'parent' => $menu_id,
					'title'  => $tag_category,
					'id'     => $sub_menu_id,
					'meta'   => array( 'class' => 'wpf-category-header' )
				) );
			}

			$without_category_id = null;

			// Sort by label
			$available_tags = $this->subval_sort( $available_tags, 'label' );

			// Add the submenu links
			foreach ( $available_tags as $tag_id => $tag_data ) {

				$parent_id = 'wpf-cat-' . sanitize_title_with_dashes( $tag_data['category'] );

				// If no category specified
				if ( sanitize_title_with_dashes( $tag_data['category'] ) == '0' ) {

					if ( $without_category_id == null ) {
						$without_category_id = 'no-category';
						$wp_admin_bar->add_menu( array(
							'parent' => $menu_id,
							'title'  => 'Without Category',
							'id'     => $without_category_id,
							'meta'   => array( 'class' => 'wpf-category-header' )
						) );
					}

					$parent_id = $without_category_id;
				}

				$wp_admin_bar->add_menu( array(
					'parent' => $parent_id,
					'title'  => $tag_data['label'],
					'id'     => 'wpf-tag-' . $tag_id,
					'href'   => $current_url . '?wpf_tag=' . $tag_id
				) );

			}

		} else {

			asort( $available_tags );

			foreach ( $available_tags as $tag ) {

				$wp_admin_bar->add_menu( array(
					'parent' => $menu_id,
					'title'  => $tag,
					'id'     => $tag,
					'href'   => $current_url . '?wpf_tag=' . urlencode( $tag )
				) );

			}

		}

	}


}

new WPF_Admin_Bar;