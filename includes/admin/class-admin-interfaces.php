<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WPF_Admin_Interfaces {

	/**
	 * Contains user profile admin interfaces
	 *
	 * @var WPF_User_Profile
	 * @since 3.0
	 */

	public $user_profile;

	public function __construct() {

		$this->includes();

		// Scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		// Taxonomy settings
		add_action( 'admin_init', array( $this, 'register_taxonomy_form_fields' ) );

		// User search / filter by tag
		add_action( 'restrict_manage_users', array( $this, 'restrict_manage_users' ), 30 );
		add_filter( 'pre_get_users', array( $this, 'custom_users_filter' ) );

		// Bulk edit / quick edit interfaces
		add_filter( 'manage_posts_columns', array( $this, 'bulk_edit_columns' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'bulk_edit_columns' ), 10, 2 );
		add_filter( 'manage_posts_custom_column', array( $this, 'bulk_edit_custom_column' ), 10, 2 );
		add_filter( 'manage_pages_custom_column', array( $this, 'bulk_edit_custom_column' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit_box' ), 10, 2 );
		add_action( 'wp_ajax_wpf_bulk_edit_save', array( $this, 'bulk_edit_save' ) );

		// Meta box content
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );
		add_action( 'wpf_meta_box_content', array( $this, 'restrict_content_checkbox' ), 10, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'required_tags_select' ), 15, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'page_redirect_select' ), 20, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'external_redirect_input' ), 25, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'apply_tags_select' ), 30, 2 );
		add_action( 'wpf_meta_box_content', array( $this, 'apply_to_children' ), 40, 2 );

		// Saving metabox
		add_action( 'save_post', array( $this, 'save_meta_box_data' ) );
		add_action( 'wpf_meta_box_save', array( $this, 'save_changes_to_children' ), 10, 2 );

		// Widget interfaces
        add_action( 'in_widget_form', array( $this, 'widget_form' ), 5, 3 );
        add_filter( 'widget_update_callback', array( $this, 'widget_form_update' ), 5, 4 );

	}

	/**
	 * Includes
	 *
	 * @access private
	 * @return void
	 */

	private function includes() {

		require_once WPF_DIR_PATH . 'includes/admin/class-user-profile.php';
		$this->user_profile = new WPF_User_Profile;

	}

	/**
	 * Enqueue meta box scripts
	 *
	 * @access public
	 * @return void
	 */

	public function admin_scripts() {

		wp_enqueue_style( 'select4', WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.css', array(), '4.0.1' );
		wp_enqueue_script( 'select4', WPF_DIR_URL . 'includes/admin/options/lib/select2/select4.min.js', array( 'jquery' ), '4.0.1' );

		wp_enqueue_style( 'wpf-admin', WPF_DIR_URL . 'assets/css/wpf-admin.css', array(), WP_FUSION_VERSION );
		wp_enqueue_script( 'wpf-admin', WPF_DIR_URL . 'assets/js/wpf-admin.js', array('jquery', 'select4'), WP_FUSION_VERSION, true );

		wp_localize_script( 'wpf-admin', 'wpf_admin', array( 'crm_supports' => wp_fusion()->crm->supports ) );

	}

	/**
	 * Show tag search under all users list
	 *
	 * @access public
	 * @return mixed
	 */

	public function restrict_manage_users() {

		if(isset($_REQUEST['wpf_filter_tag']) && !empty($_REQUEST['wpf_filter_tag']) ) {
			$val = $_REQUEST['wpf_filter_tag'];
		} else {
			$val = array();
		}

		?>

		<div id="wpf-user-filter" style="float:right;margin:0 4px">

			<label class="screen-reader-text" for="wpf_filter_tag"><?php _e('Filter by tag','wp-fusion'); ?></label>

			<?php

			$args = array(
				'setting' 		=> $val,
				'meta_name' 	=> 'wpf_filter_tag',
				'placeholder'	=> 'Filter by tag',
				'limit'			=> 1,
				'prepend'		=> array( 'no_tags' => '(No Tags)', 'no_cid' => '(No Contact ID)' )
				);

			wpf_render_tag_multiselect( $args );

			?>

			<input id="wpf_tag" class="button" value="<?php _e('Filter'); ?>" type="submit" />

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

		if ( is_admin() && $pagenow == 'users.php' && isset($_GET['wpf_filter_tag']) && !empty($_GET['wpf_filter_tag']) ) {

			$filter = $_GET['wpf_filter_tag'][0];

			if( $filter == 'no_tags' ) {

				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'		=> wp_fusion()->crm->slug . '_tags',
						'compare'	=> 'NOT EXISTS'
						),
					array(
						'key'		=> wp_fusion()->crm->slug . '_tags',
						'value'		=> null
						)
					);

			} elseif ( $filter == 'no_cid' ) {

				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'		=> wp_fusion()->crm->slug . '_contact_id',
						'compare'	=> 'NOT EXISTS'
						),
					array(
						'key'		=> wp_fusion()->crm->slug . '_contact_id',
						'value'		=> null
						)
					);

			} else {

				$meta_query = array(
					array(
						'key'		=> wp_fusion()->crm->slug . '_tags',
						'value' 	=> '"' . $_GET['wpf_filter_tag'][0] . '"',
						'compare'	=> 'LIKE'
						)
					);

			}

			$query->set('meta_query', $meta_query);

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

        foreach($registered_taxonomies as $slug => $taxonomy) {
            add_action( $slug . '_edit_form_fields', array( $this, 'taxonomy_form_fields' ), 10, 2 );
	        add_action( 'edited_' . $slug , array( $this, 'save_taxonomy_form_fields' ), 10, 2 );
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
		$taxonomy_rules = get_option( "wpf_taxonomy_rules", array() ); 
		if(isset($taxonomy_rules[$t_id])) {
			$settings = $taxonomy_rules[$t_id];
		} else {
			$settings = array();
		}

		?>

        </table>

        <table id="wpf-meta" class="form-table" style="max-width: 800px;">

            <tbody>

                <tr class="form-field">
                    <th style="padding-bottom: 0px;" colspan="2"><h3 style="margin: 0px;">WP Fusion Settings</h3></th>
                </tr>

                <tr class="form-field">
                    <th scope="row" valign="top"><label for="lock_content"><?php _e( 'Restrict access to archives', 'wp-fusion' ); ?></label></th>
                    <td>
                        <input class="checkbox" type="checkbox" data-unlock="lock_posts allow_tags wpf-redirect wpf_redirect_url" id="lock_content" name="wpf-settings[lock_content]" value="1" <?php echo checked( $settings['lock_content'], 1, false ); ?> />
                    </td>
                </tr>

                <tr class="form-field">
                    <th scope="row" valign="top"><label for="lock_posts"><?php _e( 'Restrict access to all posts', 'wp-fusion' ); ?></label></th>
                    <td>
                        <input class="checkbox" type="checkbox" <?php if ( $settings['lock_content'] != true ) echo 'disabled="disabled"'; ?> id="lock_posts" name="wpf-settings[lock_posts]" value="1" <?php echo checked( $settings['lock_posts'], 1, false ); ?> />
                        <?php $term = get_term($_GET['tag_ID']); ?>
                        <span class="description">Apply these restrictions to all posts in the <?php echo $term->name . ' ' . $term->taxonomy; ?>.</p>
                    </td>
                </tr>

                <tr class="form-field">
                    <th scope="row" valign="top"><label for="wpf-lock-content"><?php _e( 'Required tags (any)', 'wp-fusion' ); ?></label></th>
                    <td style="max-width: 400px;">
                        <?php
                        if ( $settings['lock_content'] != true ) {
                        	$disabled = true;
                        } else {
                        	$disabled = false;
                        }

						$args = array(
							'setting' 		=> $settings['allow_tags'],
							'meta_name' 	=> 'wpf-settings',
							'field_id'		=> 'allow_tags',
							'disabled'		=> $disabled
						);

                        wpf_render_tag_multiselect( $args ); ?>

                    </td>
                </tr>

                <tr class="form-field">
                    <th scope="row" valign="top"><label for="wpf_redirect"><?php _e( 'Redirect if access is denied', 'wp-fusion' ); ?></label></th>
                    <td>
                        <?php $post = new stdClass(); ?>
                        <?php $post->ID = 0; ?>
                        <?php $this->page_redirect_select($post, $settings); ?>
                    </td>
                </tr>

                <tr class="form-field">
                    <th scope="row" valign="top"><label for="lock_content"><?php _e( 'Or enter a URL', 'wp-fusion' ); ?></label></th>
                    <td>
                        <input <?php echo ( $settings['lock_content'] == 1 ? "" : ' disabled' ) ?> type="text" id="wpf_redirect_url" name="wpf-settings[redirect_url]" value="<?php echo esc_attr( $settings['redirect_url'] ) ?>" />
                    </td>
                </tr>

        <?php
	}

	/**
	 * Save taxonomy settings
	 *
	 * @access public
	 * @return void
	 */

	public function save_taxonomy_form_fields( $term_id ) {

		if ( isset( $_POST['wpf-settings'] ) ) {

			$wpf_settings = $_POST['wpf-settings'];

			if(!isset($wpf_settings['lock_content']))
				$wpf_settings['lock_content'] = false;

			$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );
			$taxonomy_rules[$term_id] = $wpf_settings;

			// Save the option array.
			update_option( "wpf_taxonomy_rules", $taxonomy_rules );

		}


	}

	/**
	 * Bulk edit columns config
	 *
	 * @access public
	 * @return array Columns
	 */

	public function bulk_edit_columns( $columns, $post_type = null) {

		$columns['wpf_settings'] = '<span class="dashicons dashicons-lock" data-tip="Restricted by WP Fusion"></span>';

		return $columns;

	}

	/**
	 * Bulk edit columns config
	 *
	 * @access public
	 * @return array Columns
	 */

	public function bulk_edit_custom_column( $column, $post_id ) {

		if ( $column == 'wpf_settings' ) {
			$wpf_settings = get_post_meta( $post_id, 'wpf-settings', true );

			if ( ! empty( $wpf_settings ) && isset( $wpf_settings['lock_content'] ) && $wpf_settings['lock_content'] == true ) {
				echo '<span class="dashicons dashicons-lock"></span>';
			}

		}

	}

	/**
	 * Bulk edit / inline editing boxes
	 *
	 * @access public
	 * @return mixed
	 */

	public function bulk_edit_box( $column_name, $post_type ) {

		if ( $column_name != 'wpf_settings' ) {
			return;
		}

		// Get first post of type for passing to the action
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => 1
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return;
		}

		$post = $posts[0];

		// Set defaults
		$settings = array(
			'lock_content' => 0,
			'allow_tags'   => array(),
			'apply_tags'   => array(),
			'apply_delay'  => 0,
			'redirect'     => '',
			'redirect_url' => ''
		);

		?>

        <div id="wpf-meta" class="inline-edit-col-wpf">
            <div class="inline-edit-col">
				<?php $this->restrict_content_checkbox( $post, $settings ); ?>
				<?php $this->required_tags_select( $post, $settings ); ?>
				<?php $this->page_redirect_select( $post, $settings ); ?>
				<?php $this->external_redirect_input( $post, $settings ); ?>
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

		$post_ids = ( ! empty( $_POST['post_ids'] ) ) ? $_POST['post_ids'] : null;

		// if we have post IDs
		if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {

			// if it has a value, doesn't update if empty on bulk
			if ( ! empty( $_POST['wpf_settings'] ) ) {

				// update for each post ID
				foreach ( $post_ids as $post_id ) {
					update_post_meta( $post_id, 'wpf-settings', $_POST['wpf_settings'] );
				}

			}

		}

	}

	/**
	 * Adds meta boxes to the configured post types
	 *
	 * @access public
	 * @return void
	 */

	public function add_meta_box() {

		$post_types = get_post_types();

		unset( $post_types['attachment'] );
		unset( $post_types['revision'] );

		$post_types = apply_filters( 'wpf_meta_box_post_types', $post_types );

		foreach ( $post_types as $post_type ) {
			add_meta_box( 'wpf-meta', 'WP Fusion', array( $this, 'meta_box_callback' ), $post_type, 'side', 'core' );
		}

	}


	/**
	 * Shows restrict content checkbox
	 *
	 * @access public
	 * @return void
	 */

	public function restrict_content_checkbox( $post, $settings ) {

		$post_type_object = get_post_type_object( $post->post_type );

		echo '<span class="wpf-restrict-content-checkbox"><input class="checkbox" type="checkbox" data-unlock="allow_tags wpf-redirect wpf-redirect-url" id="wpf-lock-content" name="wpf-settings[lock_content]" value="1" ' . checked( $settings['lock_content'], 1, false ) . ' /> <strong>Restrict access to this ' . strtolower( $post_type_object->labels->singular_name ) . '</strong></p>';

	}

	/**
	 * Shows required tags input
	 *
	 * @access public
	 * @return void
	 */

	public function required_tags_select( $post, $settings ) {

		echo '<p class="wpf-required-tags-select"><label' . ( $settings['lock_content'] != true ? "" : ' class="disabled"' ) . ' for="wpf-allow-tags"><small>Required tags (any):</small></label>';

		if ( $settings['lock_content'] != true ) {
			$disabled = true;
		} else {
			$disabled = false;
		}

		$args = array(
			'setting' 		=> $settings['allow_tags'],
			'meta_name' 	=> 'wpf-settings',
			'field_id'		=> 'allow_tags',
			'disabled'		=> $disabled
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

	public function page_redirect_select( $post, $settings ) {

		$post_types      = get_post_types( array( 'public' => true ) );
		$available_posts = array();

		unset( $post_types['attachment'] );
		$post_types = apply_filters( 'wpf_redirect_post_types', $post_types );

		foreach ( $post_types as $post_type ) {

			$posts = get_posts( array(
				'post_type'      => $post_type,
				'posts_per_page' => 200,
				'orderby'        => 'post_title',
				'order'          => 'ASC',
				'post__not_in'   => array( $post->ID )
			) );

			foreach ( $posts as $post ) {
				$available_posts[ $post_type ][ $post->ID ] = $post->post_title;
			}

		}

		echo '<p class="wpf-page-redirect-select"><label' . ( $settings['lock_content'] == 1 ? "" : ' class="disabled"' ) . ' for="wpf-redirect"><small>Redirect if access is denied:</small></label>';

		// Compatibility fix for earlier versions where we stored the URL
		if ( ! empty( $settings['redirect'] ) && ! is_numeric( $settings['redirect'] ) ) {
			$settings['redirect'] = url_to_postid( $settings['redirect'] );
		}

		echo '<select' . ( $settings['lock_content'] == 1 ? "" : ' disabled' ) . ' id="wpf-redirect" class="select4-search" style="width: 100%;" data-placeholder="None" name="wpf-settings[redirect]">';

		echo '<option></option>';

		foreach ( $available_posts as $post_type => $data ) {

			echo '<optgroup label="' . $post_type . '">';

			foreach ( $available_posts[ $post_type ] as $id => $post_name ) {
				echo '<option value="' . $id . '"' . selected( $id, $settings['redirect'], false ) . '>' . $post_name . '</option>';
			}

			echo '</optgroup>';
		}

		echo '</select></p>';


	}


	/**
	 * Shows external redirect text input
	 *
	 * @access public
	 * @return void
	 */

	public function external_redirect_input( $post, $settings ) {

		echo '<p class="wpf-external-redirect-input"><label' . ( $settings['lock_content'] == 1 ? "" : ' class="disabled"' ) . ' for="wpf-redirect-url"><small>Or enter a URL below:</small></label>';
		echo '<input' . ( $settings['lock_content'] == 1 ? "" : ' disabled' ) . ' type="text" id="wpf-redirect-url" name="wpf-settings[redirect_url]" value="' . esc_attr( $settings['redirect_url'] ) . '" />';
		echo '</p>';

	}


	/**
	 * Shows select field with tags to apply on page load, with delay
	 *
	 * @access public
	 * @return void
	 */

	public function apply_tags_select( $post, $settings ) {

		$post_type_object = get_post_type_object( $post->post_type );

		echo '<p class="wpf-apply-tags-select"><label for="wpf-apply-tags"><small>Apply tags when a user views this ' . strtolower( $post_type_object->labels->singular_name ) . ':</small></label>';
		
		$args = array(
			'setting' 		=> $settings['apply_tags'],
			'meta_name' 	=> 'wpf-settings',
			'field_id'		=> 'apply_tags',
		);

        wpf_render_tag_multiselect( $args );

		echo '</p>';

		/*
		// Delay before applying tags
		*/

		echo '<p class="wpf-apply-tags-delay-input"><label for="wpf-apply-delay"><small>Delay (in ms) before applying tags:</small></label>';
		echo '<input type="text" id="wpf-apply-delay" name="wpf-settings[apply_delay]" value="' . esc_attr( $settings['apply_delay'] ) . '" size="15" />';
		echo '</p>';


	}


	/**
	 * Shows apply settings to children textbox
	 *
	 * @access public
	 * @return void
	 */

	public function apply_to_children( $post, $settings ) {

		$children = get_pages( array( 'child_of' => $post->ID, 'post_type' => $post->post_type ) );

		if( empty($settings['apply_children']) ) {
			$settings['apply_children'] = false;
		}

		if ( ! empty( $children ) ) {
			echo '<p><input class="checkbox" type="checkbox" id="wpf-apply-children" name="wpf-settings[apply_children]" value="1" ' . checked( $settings['apply_children'], 1, false ) . ' /> Apply these settings to ' . count( $children ) . ' children</p>';
		}

	}


	/**
	 * Shows apply settings to children textbox
	 *
	 * @access public
	 * @return void
	 */

	public function save_changes_to_children( $post_id, $data ) {

		// Apply settings to children if required
		if ( ! empty( $data['apply_children'] ) ) {

			$children = get_pages( array( 'child_of' => $post_id, 'post_type' => $_POST['post_type'] ) );

			if( ! empty( $children ) ) {

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

		$settings = array(
			'lock_content' => 0,
			'allow_tags'   => array(),
			'apply_tags'   => array(),
			'apply_delay'  => 0,
			'redirect'     => '',
			'redirect_url' => ''
		);

		if ( get_post_meta( $post->ID, 'wpf-settings', true ) ) {
			$settings = array_merge( $settings, (array) get_post_meta( $post->ID, 'wpf-settings', true ) );
		}

		// Outputs the different input fields for the WPF meta box
		do_action( 'wpf_meta_box_content', $post, $settings );


	}

	function save_meta_box_data( $post_id ) {

		/*
		 * We need to verify this came from our screen and with proper authorization,
		 * because the save_post action can be triggered at other times.
		 */

		// Check if our nonce is set.
		if ( ! isset( $_POST['wpf_meta_box_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $_POST['wpf_meta_box_nonce'], 'wpf_meta_box' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}


		// Don't update on revisions
		if ( $_POST['post_type'] == 'revision' ) {
			return;
		}


		if ( isset( $_POST['wpf-settings'] ) ) {

			$data = $_POST['wpf-settings'];

			// Sanitize
			if ( isset( $data['apply_delay'] ) ) {
				$data['apply_delay'] = sanitize_text_field( $data['apply_delay'] );
			}

			if ( isset( $data['redirect_url'] ) ) {
				$data['redirect_url'] = sanitize_text_field( $data['redirect_url'] );
			}

		} else {
			$data = array();
		}

		if ( ! isset( $data['lock_content'] ) ) {
			$data['lock_content'] = 0;
		}

		// Allow other plugins to save their own data
		do_action( 'wpf_meta_box_save', $post_id, $data );

		// Update the meta field in the database.
		update_post_meta( $post_id, 'wpf-settings', $data );
	}

	/**
	 * //
	 * // WIDGETS
	 * //
	 **/

	/**
	 * Renders WPF access controls on widgets
	 *
	 * @access public
	 * @return mixed
	 */

	public function widget_form( $widget, $return, $instance ) {

		if( ! isset( $instance['wpf_conditional'] ) ) {
			$instance['wpf_conditional'] = false;
		}

        ?>
        <div class="wpf-widget-controls">

	        <p class="widgets-tags-conditional">
	            <input id="<?php echo $widget->get_field_id('wpf_conditional'); ?>" name="<?php echo $widget->get_field_name('wpf_conditional'); ?>" type="checkbox" class="widget-filter-by-tag" value="1" <?php  echo checked( $instance['wpf_conditional'], 1, false ); ?> class="widget-tags-checkbox" />
	            <label for="<?php echo $widget->get_field_id('wpf_conditional'); ?>" class="widgets-tags-conditional-label"><?php _e('Conditionally Display This Widget','wp-fusion'); ?></label>
	        </p>
	        <span class="tags-container<?php echo ($instance['wpf_conditional'] == true ? '' : ' hide'); ?>">

	            <label class="screen-reader-text" for="wpf_filter_tag"><?php _e('Allowable Tags','wp-fusion'); ?></label>

	            <?php

	            if( empty( $instance[$widget->id_base . '_wpf_tags'] ) ) {
	            	$instance[$widget->id_base . '_wpf_tags'] = array();
	            }

	            $setting = array( $widget->id_base . '_wpf_tags' => $instance[$widget->id_base . '_wpf_tags'] );

	            $args = array(
	                'setting' 		=> $setting,
	                'meta_name' 	=> 'widget-' . $widget->id_base,
	                'field_id'		=> $widget->number,
	                'field_sub_id'	=> $widget->id_base . '_wpf_tags',
	            );

	            wpf_render_tag_multiselect( $args );

	            ?>
	            <span class="description">(This widget will be hidden for users without any of the specified tags)</span>

	            <label class="screen-reader-text" for="wpf_filter_tag"><?php _e('Allowable Tags','wp-fusion'); ?></label>

	            <?php

	            if( empty( $instance[$widget->id_base . '_wpf_tags_not'] ) ) {
	            	$instance[$widget->id_base . '_wpf_tags_not'] = array();
	            }

	            $setting = array( $widget->id_base . '_wpf_tags_not' => $instance[$widget->id_base . '_wpf_tags_not'] );

	            $args = array(
	                'setting' 		=> $setting,
	                'meta_name' 	=> 'widget-' . $widget->id_base,
	                'field_id'		=> $widget->number,
	                'field_sub_id'	=> $widget->id_base . '_wpf_tags_not',
	            );

	            wpf_render_tag_multiselect( $args );

	            ?>
	            <span class="description">(This widget will be hidden from users who <i>have</i> any of the specified tags)</span>
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

    public function widget_form_update($instance, $new_instance, $old_instance, $widget) {

    	if( isset($new_instance['wpf_conditional']) ) {

    		$instance['wpf_conditional'] = $new_instance['wpf_conditional'];

    	} elseif( isset( $instance['wpf_conditional'] ) ) {

    		unset( $instance['wpf_conditional'] );

    	}

    	if( isset($new_instance[$widget->id_base . '_wpf_tags']) ) {

    		$instance[$widget->id_base . '_wpf_tags'] = $new_instance[$widget->id_base . '_wpf_tags'];

    	} elseif( isset($instance[$widget->id_base . '_wpf_tags']) ) {

    		unset( $instance[$widget->id_base . '_wpf_tags'] );

    	}

    	if( isset($new_instance[$widget->id_base . '_wpf_tags_not']) ) {

    		$instance[$widget->id_base . '_wpf_tags_not'] = $new_instance[$widget->id_base . '_wpf_tags_not'];

    	} elseif( isset($instance[$widget->id_base . '_wpf_tags_not']) ) {

    		unset( $instance[$widget->id_base . '_wpf_tags_not'] );

    	}

        return $instance;
    }


}