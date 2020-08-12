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

		if ( ! wpf_is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action( 'wp_before_admin_bar_render', array( $this, 'render_admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );

		add_filter( 'wpf_get_setting_exclude_admins', array( $this, 'bypass_exclude_admins' ) );

		add_filter( 'wpf_user_tags', array( $this, 'user_tags' ), 10, 2 );
		add_filter( 'wpf_user_can_access', array( $this, 'admin_bar_overrides' ), 10, 3 );

	}

	/**
	 * Register admin bar scripts and styles
	 *
	 * @access public
	 * @return void
	 */

	public function admin_bar_style() {

		wp_enqueue_style( 'wpf-admin-bar', WPF_DIR_URL . 'assets/css/wpf-admin-bar.css', array(), WP_FUSION_VERSION );

	}


	/**
	 * Merges admin bar tag selection into user's tags
	 *
	 * @access public
	 * @return array Tags
	 */

	public function user_tags( $user_tags, $user_id ) {

		if ( ! empty( $_GET['wpf_tag'] ) ) {
			$user_tags[] = urldecode( $_GET['wpf_tag'] );
		}

		return $user_tags;

	}


	/**
	 * Allows for overriding content restriction via the admin bar
	 *
	 * @access public
	 * @return bool Can Access
	 */

	public function admin_bar_overrides( $can_access, $user_id, $post_id ) {

		if( empty( $_GET['wpf_tag'] ) ) {
			return $can_access;
		}

		if ( $_GET['wpf_tag'] == 'unlock-all' ) {
			return true;
		}

		if ( $_GET['wpf_tag'] == 'lock-all' ) {
			return false;
		}

		return $can_access;

	}


	/**
	 * Bypass the Exclude Admins option when an admin bar filter is selected
	 *
	 * @access public
	 * @return bool Value
	 */

	public function bypass_exclude_admins( $value ) {

		if ( ! empty( $_GET['wpf_tag'] ) ) {
			$value = false;
		}

		return $value;

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

		if ( isset( $_GET['wpf_tag'] ) ) {

			$current_filter = urldecode( $_GET['wpf_tag'] );

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
		if ( isset( $_GET['wpf_tag'] ) ) {
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

			foreach ( $available_tags as $id => $tag ) {

				$wp_admin_bar->add_menu( array(
					'parent' => $menu_id,
					'title'  => $tag,
					'id'     => $id,
					'href'   => $current_url . '?wpf_tag=' . urlencode( $id )
				) );

			}

		}

	}


}

new WPF_Admin_Bar;