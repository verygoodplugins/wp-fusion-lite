<?php

use WP_Fusion\Includes\Admin\WPF_Tags_Select_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Admin_Interfaces {

	/**
	 * Contains user profile admin interfaces.
	 *
	 * @var WPF_User_Profile
	 * @since 3.0
	 */

	public $user_profile;

	/**
	 * Prevent the settings from getting output twice on the same menu item
	 *
	 * @var array
	 * @since 3.36.8
	 */

	private $menu_items = array();

	/**
	 * Contains the default values for the main WPF access control meta box.
	 *
	 * @var array
	 * @since 3.36.7
	 */

	public static $meta_box_defaults = array(
		'lock_content'   => false,
		'allow_tags'     => array(),
		'allow_tags_all' => array(),
		'allow_tags_not' => array(),
		'apply_tags'     => array(),
		'remove_tags'    => array(),
		'check_tags'     => false,
		'apply_delay'    => 0,
		'redirect'       => '',
		'redirect_url'   => '',
	);


	public function __construct() {

		$this->includes();

		// Scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ), 20 ); // 20 so WooCommerce has a change to register TipTip.

		// Taxonomy settings.
		add_action( 'admin_init', array( $this, 'register_taxonomy_form_fields' ) );

		// User search / filter by tag.
		add_action( 'restrict_manage_users', array( $this, 'restrict_manage_users' ), 30 );
		add_filter( 'pre_get_users', array( $this, 'custom_users_filter' ), 5 );

		// Bulk edit / quick edit interfaces.
		add_filter( 'manage_posts_columns', array( $this, 'bulk_edit_columns' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'bulk_edit_columns' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit_box' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'bulk_edit_save' ) );

		// User columns.
		add_filter( 'manage_users_columns', array( $this, 'manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'manage_users_custom_column' ), 10, 3 );

		// User edit links.
		add_filter( 'user_row_actions', array( $this, 'user_row_actions' ), 10, 2 );

		// Meta box content.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );

		if ( wpf_get_option( 'restrict_content', true ) ) {

			// Lock symbol in list table.
			add_filter( 'display_post_states', array( $this, 'admin_table_post_states' ), 10, 2 );

			// Menus.
			add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'admin_menu_fields' ), 10, 5 );
			add_action( 'wp_update_nav_menu_item', array( $this, 'admin_menu_save' ), 10, 2 );

			// Meta box content.
			add_action( 'wpf_meta_box_content', array( $this, 'restrict_content_checkbox' ), 10, 2 );
			add_action( 'wpf_meta_box_content', array( $this, 'required_tags_select' ), 15, 2 );
			add_action( 'wpf_meta_box_content', array( $this, 'page_redirect_select' ), 20, 2 );
			add_action( 'wpf_meta_box_content', array( $this, 'force_check_tags_checkbox' ), 25, 2 );

			// Widget interfaces.
			add_action( 'in_widget_form', array( $this, 'widget_form' ), 5, 3 );
			add_filter( 'widget_update_callback', array( $this, 'widget_form_update' ), 5, 4 );
		}

		add_action( 'wpf_meta_box_content', array( $this, 'apply_tags_select' ), 30, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'apply_to_children' ), 40, 2 );

		// Search available tags.
		add_action( 'wp_ajax_wpf_search_available_tags', array( $this, 'search_available_tags' ) );

		// Search redirect pages.
		add_action( 'wp_ajax_wpf_get_redirect_options', array( $this, 'get_redirect_options' ) );

		// Seasrch users dropdown in the logs.
		add_action( 'wp_ajax_wpf_get_log_users', array( $this, 'get_log_users' ) );

		// Saving metabox.
		add_action( 'save_post', array( $this, 'save_meta_box_data' ) );
		add_action( 'wpf_meta_box_save', array( $this, 'save_changes_to_children' ), 10, 2 );

		// Sanitize meta box inputs.
		add_filter( 'wpf_sanitize_meta_box', array( $this, 'sanitize_meta_box' ) );
		add_filter( 'wpf_sanitize_meta_box', array( $this, 'sanitize_tags_settings' ) );

		// Debug stuff.
		add_action( 'add_meta_boxes', array( $this, 'add_debug_meta_box' ) );
	}


	/**
	 * Includes
	 *
	 * @access private
	 * @return void
	 */

	private function includes() {

		require_once WPF_DIR_PATH . 'includes/admin/class-user-profile.php';
		$this->user_profile = new WPF_User_Profile();
	}


	/**
	 * Sanitizes user data input from WPF meta box
	 *
	 * @access public
	 * @return array Settings
	 */

	public function sanitize_meta_box( $settings ) {

		if ( ! isset( $settings['lock_content'] ) ) {
			$settings['lock_content'] = false;
		}

		if ( isset( $settings['redirect'] ) ) {
			$settings['redirect'] = sanitize_text_field( $settings['redirect'] );
		}

		if ( isset( $settings['redirect_url'] ) ) {
			$settings['redirect_url'] = wp_sanitize_redirect( $settings['redirect_url'] );
		}

		if ( isset( $settings['apply_delay'] ) ) {
			$settings['apply_delay'] = absint( $settings['apply_delay'] );
		}

		if ( isset( $settings['message'] ) ) {
			$settings['message'] = esc_textarea( $settings['message'] );
		}

		return $settings;
	}


	/**
	 * Helper for sanitizing an array of tags settings before being saved to the
	 * database.
	 *
	 * @since  3.37.31
	 *
	 * @param  array $settings The settings.
	 * @return array The sanitized settings.
	 */
	public static function sanitize_tags_settings( $settings ) {

		foreach ( $settings as $i => $setting ) {
			if ( is_array( $setting ) ) {
				$settings[ $i ] = array_unique( array_filter( $settings[ $i ] ) );
				$settings[ $i ] = array_map( 'sanitize_text_field', $setting );
			}
		}

		return $settings;
	}

	/**
	 * Enqueue meta box scripts
	 *
	 * @access public
	 * @return void
	 */

	public function admin_scripts() {

		wp_enqueue_style( 'select4', WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.css', array(), '4.0.1' );
		wp_enqueue_script( 'select4', WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.js', array( 'jquery' ), '4.0.1', true );

		wp_enqueue_script( 'jquery-tiptip', WPF_DIR_URL . 'assets/js/jquery-tiptip/jquery.tipTip.min.js', array( 'jquery' ), '1.3', true );

		wp_enqueue_style( 'wpf-admin', WPF_DIR_URL . 'assets/css/wpf-admin.css', array(), WP_FUSION_VERSION );
		wp_enqueue_script( 'wpf-admin', WPF_DIR_URL . 'assets/js/wpf-admin.js', array( 'jquery', 'select4', 'jquery-tiptip' ), WP_FUSION_VERSION, true );

		$localize = array(
			'crm_name'             => wp_fusion()->crm->name,
			'crm_supports'         => wp_fusion()->crm->supports,
			'nonce'                => wp_create_nonce( 'wpf_admin_nonce' ),
			'tag_type'             => wpf_get_option( 'crm_tag_type' ),
			'connected'            => (bool) wpf_get_option( 'connection_configured' ),
			'tagSelect4'           => false == apply_filters( 'wpf_disable_tag_select4', false ) ? true : false,
			'fieldSelect4'         => false == apply_filters( 'wpf_disable_crm_field_select4', false ) ? true : false,
			'settings_page'        => esc_url( admin_url( 'options-general.php?page=wpf-settings' ) ),
			'reserved_events_keys' => ( isset( wp_fusion()->crm->reserved_events_keys ) ? wp_fusion()->crm->reserved_events_keys : '' ),
			'availableTags'        => WPF_Tags_Select_API::get_formatted_tags_array(),
			'strings'              => array(
				'addNew'                => __( 'add new', 'wp-fusion-lite' ),
				'addNewTags'            => __( '(type to add new)', 'wp-fusion-lite' ),
				'noResults'             => __( 'No results found: click to resynchronize', 'wp-fusion-lite' ),
				'loadingTags'           => __( 'Loading tags, please wait...', 'wp-fusion-lite' ),
				'resyncComplete'        => __( 'Resync complete. Please try searching again.', 'wp-fusion-lite' ),
				'loadingFields'         => __( 'Loading fields, please wait...', 'wp-fusion-lite' ),
				'linkedTagChanged'      => sprintf(
					__( 'It looks like you\'ve just changed a linked tag. To manually trigger automated enrollments, run a <em>Resync tags for every user</em> operation from the <a target="_blank" href="%1$s">WP Fusion settings page</a>. Any user with the <strong>%2$s</strong> tag will be enrolled. Any user without the <strong>%2$s</strong> tag will be unenrolled.', 'wp-fusion-lite' ),
					esc_url( admin_url( 'options-general.php?page=wpf-settings' ) ) . '#advanced',
					'TAGNAME'
				),
				'syncing'               => __( 'Syncing', 'wp-fusion-lite' ),
				'noContact'             => __( 'No contact record found.', 'wp-fusion-lite' ),
				'noTags'                => __( 'No tags applied.', 'wp-fusion-lite' ),
				'foundTags'             => __( 'Reload page to see tags.', 'wp-fusion-lite' ),
				'resyncContact'         => __( 'Resync Tags', 'wp-fusion-lite' ),
				'maxSelected'           => sprintf(
					__( 'You can only select %s item', 'wp-fusion-lite' ),
					'MAX'
				),
				'error'                 => __( 'Error', 'wp-fusion-lite' ),
				'syncTags'              => __( 'Syncing Tags', 'wp-fusion-lite' ),
				'applyTags'             => __( 'Apply Tags', 'wp-fusion-lite' ),
				'linkWithTag'           => __( 'Link with Tag', 'wp-fusion-lite' ),
				'connecting'            => __( 'Connecting', 'wp-fusion-lite' ),
				'reserved_keys_warning' => sprintf( __( '%s prevents the "{key_name}" string to be part of the event key.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
		);

		wp_localize_script( 'wpf-admin', 'wpf_admin', $localize );
	}

	/**
	 * Show tag search under all users list
	 *
	 * @access public
	 * @return mixed
	 */

	public function restrict_manage_users() {

		if ( isset( $_REQUEST['wpf_filter_tag'] ) && ! empty( $_REQUEST['wpf_filter_tag'] ) ) {
			$val = sanitize_text_field( $_REQUEST['wpf_filter_tag'] );
		} else {
			$val = 0;
		}

		$filter_options = array(
			false     => esc_html__( 'Filter By Tag', 'wp-fusion-lite' ),
			'no_tags' => esc_html__( '(No Tags)', 'wp-fusion-lite' ),
			'no_cid'  => esc_html__( '(No Contact ID)', 'wp-fusion-lite' ),
		);

		$filter_options = apply_filters( 'wpf_users_list_filter_options', $filter_options );

		$available_tags = wpf_get_option( 'available_tags', array() );

		?>

		<div id="wpf-user-filter" style="float:right;margin:0 4px">

			<label class="screen-reader-text" for="wpf_filter_tag"><?php esc_html_e( 'Filter by tag', 'wp-fusion-lite' ); ?></label>

			<select class="postform" id="wpf_filter_tag" name="wpf_filter_tag">

				<?php

				foreach ( $filter_options as $key => $label ) {

					echo '<option value="' . esc_attr( $key ) . '" ' . selected( $val, $key, false ) . '>' . esc_html( $label ) . '</option>';

				}

				if ( is_array( reset( $available_tags ) ) ) {

					// Tags with categories.
					$tag_categories = array();

					foreach ( $available_tags as $value ) {
						$tag_categories[] = $value['category'];
					}

					$tag_categories = array_unique( $tag_categories );

					foreach ( $tag_categories as $tag_category ) {

						echo '<optgroup label="' . esc_attr( $tag_category ) . '">';

						foreach ( $available_tags as $id => $field_data ) {

							if ( $field_data['category'] === $tag_category ) {
								echo '<option value="' . esc_attr( $id ) . '" ' . selected( $val, $id, false ) . '>' . esc_html( $field_data['label'] ) . '</option>';
							}
						}
						echo '</optgroup>';
					}
				} else {

					asort( $available_tags );

					foreach ( $available_tags as $id => $label ) {
						echo '<option value="' . esc_attr( $id ) . '" ' . selected( $val, $id, false ) . '>' . esc_html( $label ) . '</option>';
					}
				}

				?>

			</select>

			<input id="wpf_tag" class="button" value="<?php esc_html_e( 'Filter', 'wp-fusion-lite' ); ?>" type="submit" />

		</div>


		<?php
	}

	/**
	 * Filter users by tag
	 *
	 * @access public
	 * @return object Query
	 */

	public function custom_users_filter( $query ) {

		global $pagenow;

		if ( is_admin() && $pagenow == 'users.php' && isset( $_GET['wpf_filter_tag'] ) && ! empty( $_GET['wpf_filter_tag'] ) ) {

			$filter = sanitize_text_field( wp_unslash( $_GET['wpf_filter_tag'] ) );

			if ( 'no_tags' === $filter ) {

				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'     => WPF_TAGS_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => WPF_TAGS_META_KEY,
						'value' => null,
					),
					array(
						'key'   => WPF_TAGS_META_KEY,
						'value' => 'a:0:{}',
					),
				);

			} elseif ( 'no_cid' === $filter ) {

				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'     => WPF_CONTACT_ID_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => WPF_CONTACT_ID_META_KEY,
						'value' => null,
					),
					array(
						'key'   => WPF_CONTACT_ID_META_KEY,
						'value' => false,
					),
				);

			} else {

				$meta_query = array(
					array(
						'key'     => WPF_TAGS_META_KEY,
						'value'   => '"' . $filter . '"',
						'compare' => 'LIKE',
					),
				);

			}

			$meta_query = apply_filters( 'wpf_users_list_meta_query', $meta_query, $filter );

			$query->set( 'meta_query', $meta_query );

		}

		return $query;
	}

	/**
	 * Add settings to taxonomies
	 *
	 * @access public
	 * @return void
	 */

	public function register_taxonomy_form_fields() {

		$registered_taxonomies = get_taxonomies();

		foreach ( $registered_taxonomies as $slug => $taxonomy ) {
			add_action( $slug . '_edit_form_fields', array( $this, 'taxonomy_form_fields' ), 15, 2 );
			add_action( 'edited_' . $slug, array( $this, 'save_taxonomy_form_fields' ), 15, 2 );
		}
	}

	/**
	 * Output settings to taxonomies
	 *
	 * @access public
	 * @return mixed HTML Output
	 */

	public function taxonomy_form_fields( $term ) {

		$t_id = $term->term_id;

		// retrieve the existing value(s) for this meta field. This returns an array
		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		if ( isset( $taxonomy_rules[ $t_id ] ) ) {

			$settings = $taxonomy_rules[ $t_id ];

		} else {
			$settings = array();
		}

		$defaults = array(
			'lock_content'   => false,
			'lock_posts'     => false,
			'hide_term'      => false,
			'allow_tags'     => array(),
			'allow_tags_all' => array(),
			'redirect'       => false,
			'redirect_url'   => false,
			'apply_tags'     => array(),
		);

		$settings = array_merge( $defaults, $settings );

		$taxonomy = get_taxonomy( $term->taxonomy );

		?>

		</table>

		<table class="wpf-settings-table form-table wpf-meta">

			<tbody>

				<tr class="form-field">
					<th style="padding-bottom: 0px;" colspan="2"><h3 style="margin: 0px;"><?php esc_html_e( 'WP Fusion - Access Settings', 'wp-fusion-lite' ); ?></h3></th>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="lock_content"><?php esc_html_e( 'Users must be logged in to access archives', 'wp-fusion-lite' ); ?></label></th>
					<td>
						<input class="checkbox" type="checkbox" data-unlock="lock_posts hide_term wpf-settings-allow_tags wpf-settings-allow_tags_all wpf-redirect wpf_redirect_url" id="lock_content" name="wpf-settings[lock_content]" value="1" <?php echo checked( $settings['lock_content'], 1, false ); ?> />
						<span class="description"><?php esc_html_e( '(Note that to protect archive pages you must specify a redirect below.)', 'wp-fusion-lite' ); ?></span>
					</td>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="lock_posts"><?php esc_html_e( 'Users must be logged in to access all posts', 'wp-fusion-lite' ); ?></label></th>
					<td>
						<input class="checkbox" type="checkbox" 
						<?php
						if ( $settings['lock_content'] != true ) {
							echo 'disabled="disabled"';}
						?>
						id="lock_posts" name="wpf-settings[lock_posts]" value="1" <?php echo checked( $settings['lock_posts'], 1, false ); ?> />
						<span class="description"><?php printf( esc_html__( 'Apply these restrictions to all posts in the %1$s %2$s.', 'wp-fusion-lite' ), $term->name, $taxonomy->labels->singular_name ); ?></span>
					</td>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="lock_posts"><?php esc_html_e( 'Hide term', 'wp-fusion-lite' ); ?></label></th>
					<td>
						<input class="checkbox" type="checkbox" 
						<?php
						if ( $settings['lock_content'] != true ) {
							echo 'disabled="disabled"';}
						?>
						id="hide_term" name="wpf-settings[hide_term]" value="1" <?php echo checked( $settings['hide_term'], 1, false ); ?> />
						<span class="description"><?php esc_html_e( 'The taxonomy term will be completely hidden from all term listings. (Note that this just hides the term itself. To completely hide all restricted posts enable Filter Queries in the WP Fusion settings)', 'wp-fusion-lite' ); ?></span>
					</td>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="wpf-lock-content"><?php esc_html_e( 'Required tags (any)', 'wp-fusion-lite' ); ?></label></th>
					<td style="max-width: 400px;">
						<?php
						if ( $settings['lock_content'] != true ) {
							$disabled = true;
						} else {
							$disabled = false;
						}

						$args = array(
							'setting'   => $settings['allow_tags'],
							'meta_name' => 'wpf-settings',
							'field_id'  => 'allow_tags',
							'disabled'  => $disabled,
							'read_only' => true,
						);

						wpf_render_tag_multiselect( $args );
						?>

					</td>
				</tr>


				<tr class="form-field">
					<th scope="row" valign="top"><label for="wpf_redirect"><?php esc_html_e( 'Redirect if access is denied', 'wp-fusion-lite' ); ?></label></th>
					<td>
						<?php $post = new stdClass(); ?>
						<?php $post->ID = 0; ?>
						<?php $this->page_redirect_select( $post, $settings, $disabled ); ?>
					</td>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="lock_content"><?php esc_html_e( 'Or enter a URL', 'wp-fusion-lite' ); ?></label></th>
					<td>
						<input <?php echo ( $settings['lock_content'] == 1 ? '' : ' disabled' ); ?> type="text" id="wpf_redirect_url" name="wpf-settings[redirect_url]" value="<?php echo esc_url( $settings['redirect_url'] ); ?>" />
					</td>
				</tr>

				<tr class="form-field">
					<th scope="row" valign="top"><label for="apply_tags"><?php esc_html_e( 'Apply tags', 'wp-fusion-lite' ); ?></label></th>
					<td style="max-width: 400px;">

						<?php

						$args = array(
							'setting'   => $settings['apply_tags'],
							'meta_name' => 'wpf-settings',
							'field_id'  => 'apply_tags',
						);

						wpf_render_tag_multiselect( $args );
						?>

						<span class="description"><?php printf( esc_html__( 'Apply these tags when any post in this %s is viewed', 'wp-fusion-lite' ), $taxonomy->labels->singular_name ); ?></span>

					</td>
				</tr>

			</tbody>

		<?php
	}

	/**
	 * Save taxonomy settings
	 *
	 * @access public
	 * @return void
	 */

	public function save_taxonomy_form_fields( $term_id ) {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		if ( isset( $_POST['wpf-settings'] ) ) {

			$settings = apply_filters( 'wpf_sanitize_meta_box', $_POST['wpf-settings'] );

			$settings = array_filter( $settings );

			if ( ! empty( $settings ) ) {

				$taxonomy_rules[ $term_id ] = $settings;

			} elseif ( isset( $taxonomy_rules[ $term_id ] ) ) {

				unset( $taxonomy_rules[ $term_id ] );

			}

			// Save the option array.
			update_option( 'wpf_taxonomy_rules', $taxonomy_rules, true ); // yes to autoload, so there's no DB hit.

		} else {

			// No option.
			if ( isset( $taxonomy_rules[ $term_id ] ) ) {

				unset( $taxonomy_rules[ $term_id ] );

				if ( ! empty( $taxonomy_rules ) ) {
					update_option( 'wpf_taxonomy_rules', $taxonomy_rules, true ); // yes to autoload, so there's no DB hit.
				} else {
					delete_option( 'wpf_taxonomy_rules' );
				}
			}
		}
	}


	/**
	 * Show post access controls in the posts table
	 *
	 * @access public
	 * @return array Post States
	 */

	public function admin_table_post_states( $post_states, $post ) {

		if ( ! is_object( $post ) ) {
			return $post_states;
		}

		$settings = wp_fusion()->access->get_post_access_meta( $post->ID );

		if ( ! empty( $settings ) && ! empty( $settings['lock_content'] ) ) {

			$post_type_object = get_post_type_object( $post->post_type );
			$post_type_object = apply_filters( 'wpf_restrict_content_post_type_object_label', strtolower( $post_type_object->labels->singular_name ), $post );

			if ( ! empty( $settings['allow_tags'] ) || ! empty( $settings['allow_tags_all'] ) ) {

				$tags = array();

				if ( ! empty( $settings['allow_tags'] ) ) {
					$allow_tags = array_map( array( wp_fusion()->user, 'get_tag_label' ), $settings['allow_tags'] );
					$tags       = array_merge( $tags, $allow_tags );
				}

				if ( ! empty( $settings['allow_tags_all'] ) ) {
					$allow_tags_all = array_map( array( wp_fusion()->user, 'get_tag_label' ), $settings['allow_tags_all'] );
					$tags           = array_merge( $tags, $allow_tags_all );
				}

				$content = sprintf( __( 'This %1$s is protected by %2$s tags: ', 'wp-fusion-lite' ), $post_type_object, wp_fusion()->crm->name );

				$content .= implode( ', ', $tags );

			} else {

				$content = sprintf( __( 'This %1$s is protected by WP Fusion. Users must be logged in to view this %2$s.', 'wp-fusion-lite' ), $post_type_object, $post_type_object );

			}

			$content .= '<br /><br />';

			if ( ! empty( $settings['redirect'] ) ) {

				$content .= sprintf( __( 'If access is denied, users will be redirected to %s.', 'wp-fusion-lite' ), '<strong>' . get_the_title( $settings['redirect'] ) . '</strong>' );

			} elseif ( ! empty( $settings['redirect_url'] ) ) {

				$content .= sprintf( __( 'If access is denied, users will be redirected to %s.', 'wp-fusion-lite' ), '<strong>' . $settings['redirect_url'] . '</strong>' );

			} else {

				$content .= __( 'If access is denied, the restricted content message will be displayed.', 'wp-fusion-lite' );

			}

			$classes = 'dashicons dashicons-lock wpf-tip wpf-tip-right';

			if ( ! empty( $settings['allow_tags'] ) && ! empty( array_diff( $settings['allow_tags'], array_keys( wpf_get_option( 'available_tags', array() ) ) ) ) ) {
				$classes .= ' error';
			}

			$post_states['wpfusion'] = '<span class="' . $classes . '" data-tip="' . esc_attr( $content ) . '"></span>';

		}

		return $post_states;
	}


	/**
	 * Bulk edit columns config
	 *
	 * @access public
	 * @return array Columns
	 */

	public function bulk_edit_columns( $columns, $post_type = null ) {

		$columns['wpf_settings'] = false;

		return $columns;
	}

	/**
	 * Bulk edit columns config
	 *
	 * @access public
	 * @return array Columns
	 */

	public function manage_users_columns( $columns ) {

		$columns['wpf_tags'] = sprintf( esc_html__( '%s Tags', 'wp-fusion-lite' ), wp_fusion()->crm->name );

		return $columns;
	}

	/**
	 * Bulk edit columns config
	 *
	 * @access public
	 * @return array Columns
	 */

	public function manage_users_custom_column( $val, $column_name, $user_id ) {

		if ( 'wpf_tags' === $column_name ) {

			$tags = get_user_meta( $user_id, WPF_TAGS_META_KEY, true );

			if ( ! empty( $tags ) && is_array( $tags ) ) {

				return '<div class="wpf-users-tags">' . esc_html( implode( ', ', array_map( 'wpf_get_tag_label', $tags ) ) ) . '</div>';

			} elseif ( empty( $tags ) && is_array( $tags ) ) {

				// Has a contact record.
				return '-';

			} elseif ( empty( get_user_meta( $user_id, WPF_CONTACT_ID_META_KEY, true ) ) ) {

				// No contact record.

				return esc_html__( '(no contact ID)', 'wp-fusion-lite' );

			}
		}

		return $val;
	}


	/**
	 * Link to CRM contact record from user action links.
	 *
	 * @since  3.38.34
	 *
	 * @param  array   $actions The actions.
	 * @param  WP_User $user    The user.
	 * @return array   The action links.
	 */
	public function user_row_actions( $actions, $user ) {

		$edit_url = wp_fusion()->user->get_contact_edit_url( $user->ID );

		if ( false !== $edit_url ) {
			$actions['wp_fusion'] = '<a href="' . esc_url( $edit_url ) . '" target="_blank">' . sprintf( esc_html__( 'View in %s', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Bulk edit / inline editing boxes
	 *
	 * @access public
	 * @return mixed
	 */

	public function bulk_edit_box( $column_name, $post_type ) {

		if ( 'wpf_settings' !== $column_name ) {
			return;
		}

		// Get first post of type for passing to the action.
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => 1,
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return;
		}

		$post = $posts[0];

		?>

		<div id="wpf-meta" class="inline-edit-col-wpf">
			<div class="inline-edit-col">
				<div style="margin: 10px">
					<?php $this->restrict_content_checkbox( $post, self::$meta_box_defaults ); ?>
				</div>
				<?php $this->required_tags_select( $post, self::$meta_box_defaults ); ?>
				<?php $this->page_redirect_select( $post, self::$meta_box_defaults ); ?>

				<div style="margin: 20px 10px 10px;">
					<input type="checkbox" name="wpf-settings[bulk_edit_merge]" value="1"> Merge Changes <br />
				</div>

			</div>
		</div>
		</div>

		<?php
	}

	/**
	 * Save changes made by bulk edit
	 *
	 * @access public
	 * @return void
	 */

	public function bulk_edit_save() {

		if ( ! isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-posts' );

		if ( isset( $_REQUEST['post_type'] ) ) {
			$ptype = get_post_type_object( sanitize_text_field( wp_unslash( $_REQUEST['post_type'] ) ) );
		} else {
			$ptype = get_post_type_object( 'post' );
		}

		if ( ! current_user_can( $ptype->cap->edit_posts ) ) {
			wp_die( __( 'Sorry, you are not allowed to edit posts.', 'wp-fusion-lite' ) );
		}

		$post_ids = ( ! empty( $_REQUEST['post'] ) ) ? array_map( 'intval', $_REQUEST['post'] ) : null;

		// If we have post IDs.
		if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {

			// If it has a value, doesn't update if empty on bulk.
			if ( ! empty( $_REQUEST['wpf-settings'] ) ) {

				$settings = apply_filters( 'wpf_sanitize_meta_box', $_REQUEST['wpf-settings'] );

				$settings = wp_parse_args( $settings, self::$meta_box_defaults );

				if ( $settings['lock_content'] == false && empty( $settings['allow_tags'] ) && empty( $settings['redirect'] ) ) {
					return;
				}

				// Merge changes vs. overwrite them
				if ( isset( $settings['bulk_edit_merge'] ) && $settings['bulk_edit_merge'] == true ) {

					unset( $settings['bulk_edit_merge'] );

					foreach ( $post_ids as $post_id ) {

						$current_settings = wp_parse_args( get_post_meta( $post_id, 'wpf-settings', true ), self::$meta_box_defaults );

						$new_allow_tags     = array_merge( $current_settings['allow_tags'], $settings['allow_tags'] );
						$new_allow_tags_all = array_merge( $current_settings['allow_tags_all'], $settings['allow_tags_all'] );

						if ( empty( $settings['redirect'] ) ) {
							unset( $settings['redirect'] );
						}

						if ( empty( $settings['redirect_url'] ) ) {
							unset( $settings['redirect_url'] );
						}

						$new_settings = array_merge( $current_settings, $settings );

						$new_settings['allow_tags']     = $new_allow_tags;
						$new_settings['allow_tags_all'] = $new_allow_tags_all;

						update_post_meta( $post_id, 'wpf-settings', $new_settings );
					}
				} else {

					foreach ( $post_ids as $post_id ) {
						update_post_meta( $post_id, 'wpf-settings', $settings );
					}
				}
			}
		}
	}


	/**
	 * Adds WPF settings to admin menus
	 *
	 * @access public
	 * @return void
	 */

	public function admin_menu_fields( $item_id, $item, $depth, $args, $id = false ) {

		if ( ! wpf_get_option( 'enable_menu_items', true ) ) {
			return;
		}

		// Track which menu items the settings have been output on so they're not printed twice.
		if ( in_array( $item_id, $this->menu_items ) ) {
			return;
		}

		$this->menu_items[] = $item_id;

		$defaults = array(
			'lock_content'   => false,
			'allow_tags'     => array(),
			'allow_tags_all' => array(),
			'allow_tags_not' => array(),
		);

		// Get the settings saved for the menu item.
		$settings = wp_parse_args( get_post_meta( $item->ID, 'wpf-settings', true ), $defaults );

		if ( isset( $settings['loggedout'] ) ) {
			$settings['lock_content'] = 'loggedout';
		}

		$settings = apply_filters( 'wpf_menu_item_settings', $settings, $item->ID );

		// Whether to display the tag selector.
		$hidden = $settings['lock_content'] === '1' ? '' : 'display: none;';

		?>

		<input type="hidden" name="wpf-nav-menu-nonce" value="<?php echo wp_create_nonce( 'wpf-nav-menu-nonce-name' ); ?>" />

		<div class="wpf_nav_menu_field description-wide" style="margin: 5px 0;">
			<h4 style="margin-bottom: 0.6em;"><?php esc_html_e( 'WP Fusion Menu Settings', 'wp-fusion-lite' ); ?></h4>

			<input type="hidden" class="nav-menu-id" value="<?php echo esc_attr( $item->ID ); ?>" />

			<p class="description description-wide"><?php esc_html_e( 'Who can see this menu link?', 'wp-fusion-lite' ); ?></p>

			<label for="wpf_nav_menu-for-<?php echo esc_attr( $item->ID ); ?>">

				<!-- lets only render this if the section is unhidden. otherwise we'll clone it from elsehwere as needed -->

				<select name="wpf-nav-menu[<?php echo esc_attr( $item->ID ); ?>][lock_content]" id="wpf_nav_menu-for-<?php echo esc_attr( $item->ID ); ?>" class="wpf-nav-menu">

					<option value="0" <?php selected( false, $settings['lock_content'] ); ?> ><?php esc_html_e( 'Everyone', 'wp-fusion-lite' ); ?></option>
					<option value="1" <?php selected( true, $settings['lock_content'] ); ?> ><?php esc_html_e( 'Logged In Users', 'wp-fusion-lite' ); ?></option>
					<option value="loggedout" <?php selected( 'loggedout', $settings['lock_content'] ); ?> ><?php esc_html_e( 'Logged Out Users', 'wp-fusion-lite' ); ?></option>

				</select>

			</label>

		</div>

		<div class="wpf_nav_menu_tags_field description-wide" style="<?php echo $hidden; ?>">
			<p class="description description-wide"><?php esc_html_e( 'Required tags (any)', 'wp-fusion-lite' ); ?>: <span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php echo esc_attr__( 'The user must be logged in and have at least one of the tags specified to access the item.', 'wp-fusion-lite' ); ?>"></span></p>
			<br />

			<?php

			$args = array(
				'setting'   => $settings['allow_tags'],
				'meta_name' => 'wpf-nav-menu[' . $item->ID . ']',
				'field_id'  => 'allow_tags',
				'read_only' => true,
				'lazy_load' => true,
			);

			wpf_render_tag_multiselect( $args );

			?>

		</div>

		<?php if ( apply_filters( 'wpf_show_additional_menu_item_settings', false ) || has_filter( 'wpf_menu_item_settings' ) ) : // we only show these if User Menus is active. ?>


			<div class="wpf_nav_menu_tags_field description-wide" style="<?php echo $hidden; ?>">
				<p class="description description-wide"><?php esc_html_e( 'Required tags (all)', 'wp-fusion-lite' ); ?>: <span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php echo esc_attr__( 'The user must be logged in and have <em>all</em> of the tags specified to access the item.', 'wp-fusion-lite' ); ?>"></span></p>
				<br />

				<?php

				$args = array(
					'setting'   => $settings['allow_tags_all'],
					'meta_name' => 'wpf-nav-menu[' . $item->ID . ']',
					'field_id'  => 'allow_tags_all',
					'read_only' => true,
					'lazy_load' => true,
				);

				wpf_render_tag_multiselect( $args );

				?>

			</div>

			<div class="wpf_nav_menu_tags_field description-wide">
				<p class="description description-wide"><?php esc_html_e( 'Required tags (not)', 'wp-fusion-lite' ); ?>: <span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="<?php echo esc_attr__( 'If the user is logged in, they must have <em>none</em> of the tags specified to access the item.', 'wp-fusion-lite' ); ?>"></span></p>

				<br />

				<?php

				$args = array(
					'setting'   => $settings['allow_tags_not'],
					'meta_name' => 'wpf-nav-menu[' . $item->ID . ']',
					'field_id'  => 'allow_tags_not',
					'read_only' => true,
					'lazy_load' => true,
				);

				wpf_render_tag_multiselect( $args );

				?>

			</div>

		<?php endif; // end check for has_filter(). ?>

		<?php
	}


	/**
	 * Save the menu settings
	 *
	 * @access public
	 * @return void
	 */

	public function admin_menu_save( $menu_id, $menu_item_db_id ) {

		// Verify this came from our screen and with proper authorization.
		if ( ! isset( $_POST['wpf-nav-menu-nonce'] ) || ! wp_verify_nonce( $_POST['wpf-nav-menu-nonce'], 'wpf-nav-menu-nonce-name' ) ) {
			return;
		}

		if ( isset( $_POST['wpf-nav-menu'][ $menu_item_db_id ] ) && ! empty( array_filter( $_POST['wpf-nav-menu'][ $menu_item_db_id ] ) ) ) {

			$settings = $_POST['wpf-nav-menu'][ $menu_item_db_id ];

			$settings = $this::sanitize_tags_settings( $settings );

			if ( 'loggedout' === $settings['lock_content'] ) {
				$settings['lock_content'] = false;
				$settings['loggedout']    = true;
			}

			$settings = apply_filters( 'wpf_sanitize_meta_box', $settings );

			update_post_meta( $menu_item_db_id, 'wpf-settings', $settings );

		} else {
			delete_post_meta( $menu_item_db_id, 'wpf-settings' );
		}
	}


	/**
	 * Adds meta boxes to the configured post types
	 *
	 * @access public
	 * @return void
	 */

	public function add_meta_box() {

		if ( wpf_get_option( 'admin_permissions' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ) );

		unset( $post_types['attachment'] );
		unset( $post_types['revision'] );

		$post_types = apply_filters( 'wpf_meta_box_post_types', $post_types );

		$per_post_messages = wpf_get_option( 'per_post_messages', false );

		foreach ( $post_types as $post_type ) {

			add_meta_box( 'wpf-meta', __( 'WP Fusion', 'wp-fusion-lite' ), array( $this, 'meta_box_callback' ), $post_type, 'side', 'core' );

			if ( $per_post_messages ) {
				add_meta_box( 'wpf-restricted-content-message', __( 'WP Fusion - Restricted Content Message', 'wp-fusion-lite' ), array( $this, 'restricted_content_message_callback' ), $post_type );
			}
		}
	}


	/**
	 * Shows restrict content checkbox
	 *
	 * @access public
	 * @return void
	 */

	public function restrict_content_checkbox( $post, $settings ) {

		echo '<input class="checkbox wpf-restrict-access-checkbox" type="checkbox" data-unlock="wpf-settings-allow_tags wpf-settings-allow_tags_all" id="wpf-lock-content" name="wpf-settings[lock_content]" value="1" ' . checked( $settings['lock_content'], 1, false ) . ' /> <label for="wpf-lock-content" class="wpf-restrict-access">';
		// translators: %s: singular post type name
		$message = sprintf( __( 'Users must be logged in to view this %s', 'wp-fusion-lite' ), $post->post_type_singular_name );
		$message = apply_filters( 'wpf_restrict_content_checkbox_label', $message, $post );
		echo esc_html( $message );
		echo '</label>';
	}

	/**
	 * Shows required tags input
	 *
	 * @access public
	 * @return void
	 */

	public function required_tags_select( $post, $settings ) {

		if ( $settings['lock_content'] ) {
			$disabled = false;
		} else {
			$disabled = true;
		}

		echo '<p class="wpf-required-tags-select"><label' . ( $settings['lock_content'] ? '' : ' class="disabled"' ) . ' for="wpf-allow-tags"><small>' . esc_html__( 'Required tags (any)', 'wp-fusion-lite' ) . ':</small>';

		echo '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . esc_attr__( 'The user must be logged in and have at least one of the tags specified to access the content.', 'wp-fusion-lite' ) . '"></span></label>';

		$args = array(
			'setting'   => $settings['allow_tags'],
			'meta_name' => 'wpf-settings',
			'field_id'  => 'allow_tags',
			'disabled'  => $disabled,
			'read_only' => true,
		);

		wpf_render_tag_multiselect( $args );

		echo '</p>';

		echo '<p class="wpf-required-tags-select"><label' . ( $settings['lock_content'] ? '' : ' class="disabled"' ) . ' for="wpf-allow-tags-all"><small>' . esc_html__( 'Required tags (all)', 'wp-fusion-lite' ) . ':</small>';

		echo '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . esc_attr__( 'The user must be logged in and have <em>all</em> of the tags specified to access the content.', 'wp-fusion-lite' ) . '"></span></label>';

		$args = array(
			'setting'   => $settings['allow_tags_all'],
			'meta_name' => 'wpf-settings',
			'field_id'  => 'allow_tags_all',
			'disabled'  => $disabled,
			'read_only' => true,
		);

		wpf_render_tag_multiselect( $args );

		echo '</p>';

		echo '<p class="wpf-required-tags-select"><label for="wpf-allow-tags-not"><small>' . esc_html__( 'Required tags (not)', 'wp-fusion-lite' ) . ':</small>';

		echo '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . esc_attr__( 'If the user is logged in, they must have <em>none</em> of the tags specified to access the content.', 'wp-fusion-lite' ) . '"></span></label>';

		if ( ! isset( $settings['allow_tags_not'] ) ) {
			$settings['allow_tags_not'] = array();
		}

		$args = array(
			'setting'   => $settings['allow_tags_not'],
			'meta_name' => 'wpf-settings',
			'field_id'  => 'allow_tags_not',
			'read_only' => true,
		);

		wpf_render_tag_multiselect( $args );

		echo '</p>';
	}


	/**
	 * Shows page redirect select
	 *
	 * @access public
	 * @return void
	 */

	public function page_redirect_select( $post, $settings, $disabled = false ) {

		echo '<p class="wpf-page-redirect-select"><label for="wpf-redirect"><small>' . esc_html__( 'Redirect if access is denied (page or URL):', 'wp-fusion-lite' ) . '</small>';

		echo '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . esc_attr__( 'Select a page on your site, or enter an external URL. If you do not specify a redirect WP Fusion will try to replace the content area of the post with the restricted content message configured in the WP Fusion settings.', 'wp-fusion-lite' ) . '"></span></label>';

		echo '<select ' . ( $disabled ? 'disabled' : '' ) . ' id="wpf-redirect" class="select4-select-page" style="width: 100%;" data-placeholder="' . __( 'Show restricted content message', 'wp-fusion-lite' ) . '" name="wpf-settings[redirect]">';

		echo '<option></option>';

		if ( ! empty( $settings['redirect_url'] ) ) {
			$settings['redirect'] = $settings['redirect_url']; // pre 3.41.0 data storage.
		}

		if ( is_numeric( $settings['redirect'] ) ) {
			$title = get_the_title( $settings['redirect'] ); // posts.
		} else {
			$title = $settings['redirect']; // URLs.
		}

		if ( ! empty( $settings['redirect'] ) ) {
			echo '<option value="' . esc_attr( $settings['redirect'] ) . '" selected>' . esc_html( $title ) . '</option>';
		}

		echo '</select></p>';
	}

	/**
	 * Shows Force Check tags checkbox.
	 *
	 * @since 3.41.0
	 *
	 * @param  object $post     The post object.
	 * @param  array  $settings The settings array.
	 * @return mixed HTML output.
	 */
	public function force_check_tags_checkbox( $post, $settings ) {

		$disabled = true;

		if ( ! empty( $settings['allow_tags'] ) || ! empty( $settings['allow_tags_all'] ) ) {
			$disabled = false;
		}

		echo '<input class="checkbox" type="checkbox" ' . disabled( $disabled, true, false ) . ' id="wpf-check-tags" name="wpf-settings[check_tags]" value="1" ' . checked( $settings['check_tags'], 1, false ) . ' />';
		echo '<label id="wpf-check-tags-label" for="wpf-check-tags" class="' . ( $disabled ? 'disabled' : '' ) . '">';

		esc_html_e( 'Refresh tags if access is denied', 'wp-fusion-lite' );
		echo '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . sprintf( esc_attr__( 'If the user is logged in and does not have the required tags, this will force-refresh their tags from %s.', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ) . '"></span></label>';

		echo '</label>';

		echo '<hr />';
	}


	/**
	 * Shows select field with tags to apply on page load, with delay
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags_select( $post, $settings ) {

		echo '<p class="wpf-apply-tags-select"><label for="wpf-apply-tags"><small>' . __( 'Apply tags on view', 'wp-fusion-lite' ) . ':</small></label>';

		$args = array(
			'setting'   => $settings['apply_tags'],
			'meta_name' => 'wpf-settings',
			'field_id'  => 'apply_tags',
		);

		wpf_render_tag_multiselect( $args );

		echo '</p>';

		echo '<p class="wpf-apply-tags-select"><label for="wpf-remove-tags"><small>' . __( 'Remove tags on view', 'wp-fusion-lite' ) . ':</small></label>';

		$args = array(
			'setting'   => $settings['remove_tags'],
			'meta_name' => 'wpf-settings',
			'field_id'  => 'remove_tags',
		);

		wpf_render_tag_multiselect( $args );

		echo '</p>';

		/*
		// Delay before applying tags
		*/

		echo '<p class="wpf-apply-tags-delay-input"><label for="wpf-apply-delay"><small>' . esc_html__( 'Delay (in ms) before applying / removing tags', 'wp-fusion-lite' ) . ':</small></label>';
		echo '<input type="text" id="wpf-apply-delay" name="wpf-settings[apply_delay]" value="' . (int) $settings['apply_delay'] . '" size="15" />';
		echo '</p>';
	}


	/**
	 * Shows apply settings to children textbox
	 *
	 * @access public
	 * @return void
	 */

	public function apply_to_children( $post, $settings ) {

		$children = get_pages(
			array(
				'child_of'  => $post->ID,
				'post_type' => $post->post_type,
			)
		);

		if ( empty( $settings['apply_children'] ) ) {
			$settings['apply_children'] = false;
		}

		if ( ! empty( $children ) && is_array( $children ) ) {
			echo '<p><input class="checkbox" type="checkbox" id="wpf-apply-children" name="wpf-settings[apply_children]" value="1" ' . checked( $settings['apply_children'], 1, false ) . ' /> Apply these settings to ' . esc_html( count( $children ) ) . ' children</p>';
		}
	}

	/**
	 * Get the available tags via AJAX for sites with more than 2000 tags.
	 *
	 * @since  3.37.19
	 *
	 * @return array The tags.
	 */
	public function search_available_tags() {

		if ( empty( $_POST['search'] ) ) {
			wp_die();
		}

		$search = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		$tags   = wp_fusion()->settings->get_available_tags_flat();

		$return = array(
			'results' => array(),
		);

		foreach ( $tags as $id => $tag ) {

			if ( false !== stripos( $tag, $search ) ) {
				$return['results'][] = array(
					'id'   => strval( $id ),
					'text' => $tag,
				);
			}
		}

		echo wp_json_encode( $return );

		wp_die();
	}


	/**
	 * Gets the redirect options for the AJAX page redirect select.
	 *
	 * @since  3.37.0
	 *
	 * @return array The redirect options.
	 */
	public function get_redirect_options() {

		if ( empty( $_POST['search'] ) ) {
			wp_die();
		}

		$search = sanitize_text_field( wp_unslash( $_POST['search'] ) );

		$args = array(
			'post_type'      => 'any',
			'posts_per_page' => 100,
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$results = get_posts( $args );

		$temp_data = array();

		foreach ( $results as $result ) {

			if ( false === strpos( strtolower( $result->post_title ), strtolower( $search ) ) ) {
				continue; // lets only search in the title.
			}

			if ( ! isset( $temp_data[ $result->post_type ] ) ) {
				$temp_data[ $result->post_type ] = array();
			}
			$temp_data[ $result->post_type ][] = $result;
		}

		$return = array(
			'results' => array(),
		);

		foreach ( $temp_data as $post_type => $posts ) {

			$post_type_object = get_post_type_object( $post_type );

			$children = array();

			foreach ( $posts as $post ) {

				$children[] = array(
					'id'   => $post->ID,
					'text' => $post->post_title,
				);
			}

			$return['results'][] = array(
				'text'     => $post_type_object->label,
				'children' => $children,
			);

		}

		echo wp_json_encode( $return );

		wp_die();
	}


	/**
	 * Get log users for logging dropdown.
	 *
	 * @since  3.38.27
	 */
	public function get_log_users() {

		check_ajax_referer( 'wpf_admin_nonce' );

		if ( empty( $_POST['search'] ) ) {
			wp_die();
		}

		$search = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		global $wpdb;

		$users_ids = $wpdb->get_col(
			"
			SELECT DISTINCT user
			FROM {$wpdb->prefix}wpf_logging
			WHERE user != ''
			ORDER BY user ASC
		"
		);

		$return = array(
			'results' => array(),
		);

		if ( empty( $users_ids ) ) {

			echo wp_json_encode( $return );
			wp_die();

		}

		$args = array(
			'include'        => array_values( $users_ids ),
			'search'         => '*' . esc_attr( $search ) . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
		);

		$user_query = new WP_User_Query( $args );

		foreach ( $user_query->get_results() as $result ) {
			$return['results'][] = array(
				'id'   => $result->ID,
				'text' => $result->user_login . ' (#' . $result->ID . ' - ' . $result->user_email . ')',
			);
		}

		echo wp_json_encode( $return );

		wp_die();
	}


	/**
	 * Saves settings to children if "apply to children" is checked
	 *
	 * @access public
	 * @return void
	 */

	public function save_changes_to_children( $post_id, $data ) {

		if ( ! isset( $_POST['post_type'] ) ) {
			return;
		}

		$post_type = sanitize_text_field( $_POST['post_type'] );

		// Apply settings to children if required
		if ( ! empty( $data['apply_children'] ) && post_type_exists( $post_type ) ) {

			$children = get_pages(
				array(
					'child_of'  => $post_id,
					'post_type' => $post_type,
				)
			);

			if ( ! empty( $children ) ) {

				foreach ( $children as $child ) {
					update_post_meta( $child->ID, 'wpf-settings', $data );
				}
			}
		}
	}

	/**
	 * Renders WPF meta box
	 *
	 * @access public
	 * @return void
	 */

	public function meta_box_callback( $post ) {

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'wpf_meta_box', 'wpf_meta_box_nonce' );

		if ( ! empty( $post->ID ) ) {
			$settings = wp_parse_args( get_post_meta( $post->ID, 'wpf-settings', true ), self::$meta_box_defaults );
		} else {

			// Cases where there is no "ID", like a BuddyPress group.
			$settings = self::$meta_box_defaults;
		}

		$settings = apply_filters( 'wpf_settings_for_meta_box', $settings, $post );

		if ( ! is_a( $post, 'WP_Post' ) ) {

			// Use a dummy post here to prevent warnings.
			$post = new WP_Post( (object) 0 );

		}

		// Get the object label.

		$post_type_object = get_post_type_object( $post->post_type );

		if ( is_a( $post_type_object, 'WP_Post_Type' ) ) {
			$label = strtolower( $post_type_object->labels->singular_name );
		} else {
			$label = 'content';
		}

		$post->post_type_singular_name = apply_filters( 'wpf_restrict_content_post_type_object_label', $label, $post );

		// Outputs the different input fields for the WPF meta box.
		do_action( 'wpf_meta_box_content', $post, $settings );

		do_action( "wpf_meta_box_content_{$post->post_type}", $post, $settings );
	}

	/**
	 * Renders WPF meta box
	 *
	 * @access public
	 * @return void
	 */

	public function restricted_content_message_callback( $post ) {

		$settings = array(
			'message' => false,
		);

		if ( get_post_meta( $post->ID, 'wpf-settings', true ) ) {
			$settings = array_merge( $settings, (array) get_post_meta( $post->ID, 'wpf-settings', true ) );
		}

		echo '<textarea name="wpf-settings[message]" id="wpf-settings-message" rows="6">' . wp_kses_post( $settings['message'] ) . '</textarea>';

		echo '<span class="description">You can enter a message here that will be displayed in place of the post content if the post is restricted and no redirect is specified. Leave blank to use the <a href="' . esc_url( get_admin_url() ) . '/options-general.php?page=wpf-settings">site default</a>.</span>';
	}

	/**
	 * Saves WPF meta boxes
	 *
	 * @access public
	 * @return void
	 */

	public function save_meta_box_data( $post_id ) {

		if ( isset( $_POST['post_ID'] ) && $_POST['post_ID'] != $post_id ) {
			return;
		}

		$post_data = isset( $_POST['wpf-settings'] ) ? $_POST['wpf-settings'] : array();
		$settings  = apply_filters( 'wpf_sanitize_meta_box', $post_data );
		$settings  = array_filter( $settings );

		if ( isset( $_POST['wpf_meta_box_nonce'] ) && empty( $settings ) ) {

			// Delete if empty.
			delete_post_meta( $post_id, 'wpf-settings' );

		} elseif ( ! empty( $settings ) ) {

			// Update the meta field in the database.
			update_post_meta( $post_id, 'wpf-settings', $settings );

			// Allow other plugins to save their own data.
			do_action( 'wpf_meta_box_save', $post_id, $settings );

		}
	}

	/**
	 * //
	 * // WIDGETS (Deprecated in WP 5.8)
	 * //
	 **/

	/**
	 * Renders WPF access controls on widgets
	 *
	 * @access public
	 * @return mixed
	 */

	public function widget_form( $widget, $return, $instance ) {

		if ( ! isset( $instance['wpf_conditional'] ) ) {
			$instance['wpf_conditional'] = false;
		}

		?>
		<div class="wpf-widget-controls">

			<p class="widgets-tags-conditional">
				<input id="<?php echo esc_attr( $widget->get_field_id( 'wpf_conditional' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'wpf_conditional' ) ); ?>" type="checkbox" class="widget-filter-by-tag" value="1" <?php echo checked( $instance['wpf_conditional'], 1, false ); ?> class="widget-tags-checkbox" />
				<label for="<?php echo esc_attr( $widget->get_field_id( 'wpf_conditional' ) ); ?>" class="widgets-tags-conditional-label"><?php esc_html_e( 'Users must be logged in to see this widget', 'wp-fusion-lite' ); ?></label>
			</p>
			<span class="tags-container<?php echo ( $instance['wpf_conditional'] == true ? '' : ' hide' ); ?>">

				<label class="screen-reader-text" for="wpf_filter_tag"><?php esc_html_e( 'Allowable Tags', 'wp-fusion-lite' ); ?></label>

				<?php

				if ( empty( $instance[ $widget->id_base . '_wpf_tags' ] ) ) {
					$instance[ $widget->id_base . '_wpf_tags' ] = array();
				}

				$setting = array( $widget->id_base . '_wpf_tags' => $instance[ $widget->id_base . '_wpf_tags' ] );

				$args = array(
					'setting'   => $setting[ $widget->id_base . '_wpf_tags' ],
					'meta_name' => "widget-{$widget->id_base}[{$widget->number}][{$widget->id_base}_wpf_tags]",
				);

				wpf_render_tag_multiselect( $args );

				?>
				<span class="description">(Users must have at least one of these tags to see the widget)</span>

				<label class="screen-reader-text" for="wpf_filter_tag"><?php esc_html_e( 'Allowable Tags', 'wp-fusion-lite' ); ?></label>

				<?php

				if ( empty( $instance[ $widget->id_base . '_wpf_tags_not' ] ) ) {
					$instance[ $widget->id_base . '_wpf_tags_not' ] = array();
				}

				$setting = array( $widget->id_base . '_wpf_tags_not' => $instance[ $widget->id_base . '_wpf_tags_not' ] );

				$args = array(
					'setting'   => $setting[ $widget->id_base . '_wpf_tags_not' ],
					'meta_name' => "widget-{$widget->id_base}[{$widget->number}][{$widget->id_base}_wpf_tags_not]",
				);

				wpf_render_tag_multiselect( $args );

				?>
				<span class="description">(If users <i>have</i> any of these tags, the widget will be hidden)</span>
			</span>

		</div>
		<?php
	}

	/**
	 * Merge / remove additional fields into widget instance during form updates
	 *
	 * @access public
	 * @return array Instance
	 */

	public function widget_form_update( $instance, $new_instance, $old_instance, $widget ) {

		if ( isset( $new_instance['wpf_conditional'] ) ) {

			$instance['wpf_conditional'] = $new_instance['wpf_conditional'];

		} elseif ( isset( $instance['wpf_conditional'] ) ) {

			unset( $instance['wpf_conditional'] );

		}

		if ( isset( $new_instance[ $widget->id_base . '_wpf_tags' ] ) ) {

			$instance[ $widget->id_base . '_wpf_tags' ] = $new_instance[ $widget->id_base . '_wpf_tags' ];

		} elseif ( isset( $instance[ $widget->id_base . '_wpf_tags' ] ) ) {

			unset( $instance[ $widget->id_base . '_wpf_tags' ] );

		}

		if ( isset( $new_instance[ $widget->id_base . '_wpf_tags_not' ] ) ) {

			$instance[ $widget->id_base . '_wpf_tags_not' ] = $new_instance[ $widget->id_base . '_wpf_tags_not' ];

		} elseif ( isset( $instance[ $widget->id_base . '_wpf_tags_not' ] ) ) {

			unset( $instance[ $widget->id_base . '_wpf_tags_not' ] );

		}

		return $instance;
	}


	/**
	 * Adds debug meta box
	 *
	 * @access public
	 * @return void
	 */

	public function add_debug_meta_box() {

		if ( isset( $_GET['wpf-debug-meta'] ) ) {

			$post_types = get_post_types();

			foreach ( $post_types as $post_type ) {

				add_meta_box( 'wpf-debug', 'WP Fusion - Post Meta Debug', array( $this, 'debug_meta_box' ), $post_type );

			}
		}
	}

	/**
	 * Debug meta box output
	 *
	 * @access public
	 * @return mixed Debug output
	 */

	public function debug_meta_box( $post ) {

		echo '<pre>';
		echo esc_textarea( wpf_print_r( get_post_meta( $post->ID ), true ) );
		echo '</pre>';
	}
}
