<?php

/**
 * Handles the settings in the admin
 *
 * @since <creation_version>
 */
class WPF_Settings {

	/**
	 * Contains all plugin settings
	 */
	public $options = array();


	/**
	 * Make batch processing utility publicly accessible
	 */
	public $batch;


	/**
	 * Get things started
	 *
	 * @since 1.0
	 * @return void
	 */

	public function __construct() {

		if ( is_admin() ) {

			$this->options = get_option( 'wpf_options', array() ); // No longer loading this on the frontend.

			$this->init();

		}

		// Default settings.
		add_filter( 'wpf_get_setting_license_key', array( $this, 'get_license_key' ) );
		add_filter( 'wpf_get_setting_contact_fields', array( $this, 'get_contact_fields' ) );

	}

	/**
	 * Fires up settings in admin
	 *
	 * @since 1.0
	 * @return void
	 */

	private function init() {

		$this->includes();

		// Custom fields.
		add_action( 'show_field_contact_fields', array( $this, 'show_field_contact_fields' ), 10, 2 );
		add_action( 'show_field_contact_fields_begin', array( $this, 'show_field_contact_fields_begin' ), 10, 2 );
		add_action( 'show_field_assign_tags', array( $this, 'show_field_assign_tags' ), 10, 2 );
		add_action( 'validate_field_assign_tags', array( $this, 'validate_field_assign_tags' ), 10, 3 );
		add_action( 'show_field_integrations_overview_begin', array( $this, 'show_field_integrations_overview_begin' ), 10, 2 );
		add_action( 'show_field_integrations_overview', array( $this, 'show_field_integrations_overview' ), 10, 2 );
		add_action( 'show_field_import_users', array( $this, 'show_field_import_users' ), 10, 2 );
		add_action( 'show_field_import_users_end', array( $this, 'show_field_import_users_end' ), 10, 2 );
		add_action( 'show_field_import_groups', array( $this, 'show_field_import_groups' ), 10, 2 );
		add_action( 'show_field_export_options', array( $this, 'show_field_export_options' ), 10, 2 );
		add_action( 'show_field_status_hooks', array( $this, 'show_field_status_hooks' ), 10, 2 );
		add_action( 'show_field_test_webhooks', array( $this, 'show_field_test_webhooks' ), 10, 2 );
		add_action( 'show_field_crm_field', array( $this, 'show_field_crm_field' ), 10, 2 );
		add_action( 'show_field_webhook_url', array( $this, 'show_field_webhook_url' ), 10, 2 );

		// CRM setup layouts.
		add_action( 'show_field_api_validate', array( $this, 'show_field_api_validate' ), 10, 2 );
		add_action( 'show_field_api_validate_end', array( $this, 'show_field_api_validate_end' ), 10, 2 );
		add_action( 'show_field_oauth_authorize', array( $this, 'show_field_oauth_authorize' ), 10, 2 );
		add_action( 'show_field_oauth_authorize_end', array( $this, 'show_field_api_validate_end' ), 10, 2 );

		// Resync button at top.
		add_action( 'wpf_settings_page_title', array( $this, 'header_resync_button' ) );

		// AJAX.
		add_action( 'wp_ajax_sync_tags', array( $this, 'sync_tags' ) );
		add_action( 'wp_ajax_add_tags_api', array( $this, 'add_tag' ) );
		add_action( 'wp_ajax_sync_custom_fields', array( $this, 'sync_custom_fields' ) );
		add_action( 'wp_ajax_import_users', array( $this, 'import_users' ) );
		add_action( 'wp_ajax_delete_import_group', array( $this, 'delete_import_group' ) );

		// Setup scripts and initialize.
		add_filter( 'wpf_meta_fields', array( $this, 'prepare_meta_fields' ), 60 );

		add_filter( 'wpf_configure_settings', array( $this, 'get_settings' ), 5, 2 ); // Initial settings.
		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ), 100, 2 ); // Settings cleanup / tweaks.

		// Individual settings defaults (runs later, when page is being rendered).
		add_filter( 'wpf_configure_setting_query_filter_post_types', array( $this, 'configure_setting_query_filter_post_types' ), 10, 2 );

		add_filter( 'wpf_initialize_options', array( $this, 'initialize_options' ) );
		add_action( 'wpf_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Resetting options.
		add_action( 'wpf_resetting_options', array( $this, 'reset_contact_ids_and_tags' ) );

		// Set tag labels.
		add_action( 'wpf_sync', array( $this, 'save_tag_labels' ) );
		add_filter( 'gettext', array( $this, 'set_tag_labels' ), 10, 3 );

		// Validation.
		add_filter( 'validate_field_contact_fields', array( $this, 'validate_field_contact_fields' ), 10, 3 );

		// Plugin action links and messages.
		add_filter( 'plugin_action_links_' . WPF_PLUGIN_PATH, array( $this, 'add_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_row_links' ), 10, 2 );

		if ( wp_fusion()->is_full_version() ) {

			add_action( 'show_field_edd_license_begin', array( $this, 'show_field_edd_license_begin' ), 10, 2 );
			add_action( 'show_field_edd_license', array( $this, 'show_field_edd_license' ), 10, 2 );

			add_action( 'wp_ajax_edd_activate', array( $this, 'edd_activate' ) );
			add_action( 'wp_ajax_edd_deactivate', array( $this, 'edd_deactivate' ) );

			add_action( 'admin_init', array( $this, 'maybe_activate_site' ) );

		}

		// Fire up the options framework.
		new WP_Fusion_Options( $this->get_setup(), $this->get_sections() );

	}


	/**
	 * Include options and batch libraries
	 *
	 * @since 1.0
	 * @return void
	 */

	private function includes() {

		require_once WPF_DIR_PATH . 'includes/admin/options/class-options.php';

	}

	/**
	 * Magic get, quicker than using get().
	 *
	 * @since  3.36.10
	 *
	 * @param  string $key    The options key to get.
	 * @return mixed The return value.
	 */
	public function __get( $key ) {
		return $this->get( $key );
	}


	/**
	 * Get the value of a specific setting
	 *
	 * @since 1.0
	 * @return mixed
	 */
	public function get( $key, $default = false ) {

		// Special fields first.

		if ( 'available_tags' == $key && empty( $this->options['available_tags'] ) ) {

			$setting = get_option( 'wpf_available_tags', array() );

			if ( ! empty( $setting ) ) {

				$this->options['available_tags'] = $setting;

			} elseif ( empty( $setting ) && empty( $this->options ) ) {

				// Fallback in case the data hasn't been moved yet (pre 3.37)
				$this->options = get_option( 'wpf_options', array() );

			}
		} elseif ( 'crm_fields' == $key && empty( $this->options['crm_fields'] ) ) {

			$setting = get_option( 'wpf_crm_fields', array() );

			if ( ! empty( $setting ) ) {

				$this->options['crm_fields'] = $setting;

			} elseif ( empty( $setting ) && empty( $this->options ) ) {

				// Fallback in case the data hasn't been moved yet (pre 3.37).
				$this->options = get_option( 'wpf_options', array() );

			}
		} elseif ( empty( $this->options ) ) {

			$this->options = get_option( 'wpf_options', array() );

		}

		if ( ! empty( $this->options[ $key ] ) ) {

			$value = $this->options[ $key ];

		} elseif ( isset( $this->options[ $key ] ) && ( 0 === $this->options[ $key ] || '0' === $this->options[ $key ] ) ) {

			// Checkboxes saved as false.
			$value = false;

		} elseif ( isset( $this->options[ $key ] ) && is_array( $this->options[ $key ] ) && false === $default ) {

			// If it's an empty array we'll return that instead of false.
			$value = array();

		} else {

			$value = $default;

		}

		return apply_filters( 'wpf_get_setting_' . $key, $value );
	}

	/**
	 * Set the value of a specific setting.
	 *
	 * This can be used by integrations to update a single setting value.
	 * Sanitizing and saving the main settings page is handled by WPF_Options.
	 *
	 * @since 1.0
	 *
	 * @param string $key    The settings key.
	 * @param mixed  $value  The settings value.
	 */
	public function set( $key, $value ) {

		$value = apply_filters( 'wpf_set_setting_' . $key, $value );

		// Sanitize.
		if ( is_numeric( $value ) ) {
			$value = absint( $value );
		} else {
			$value = wpf_clean( $value );
		}

		$this->options[ $key ] = $value;
		$options_to_save       = $this->options;

		// Special fields get saved to their own keys to reduce memory usage.

		if ( 'available_tags' === $key ) {
			update_option( 'wpf_available_tags', $value, false );
		} elseif ( 'crm_fields' === $key ) {
			update_option( 'wpf_crm_fields', $value, false );
		}

		if ( ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
			// Don't save the main settings if we've switched to another blog.
			// This fixes the settings getting overwritten.
			return;
		}

		// Remove special fields.
		if ( isset( $options_to_save['available_tags'] ) ) {
			unset( $options_to_save['available_tags'] );
		}

		if ( isset( $options_to_save['crm_fields'] ) ) {
			unset( $options_to_save['crm_fields'] );
		}

		update_option( 'wpf_options', $options_to_save );
	}

	/**
	 * Get all settings.
	 *
	 * @deprecated  3.38.0.
	 * @return array
	 */
	public function get_all() {

		if ( empty( $this->options ) ) {
			$this->options = get_option( 'wpf_options', array() );
		}

		return $this->options;
	}

	/**
	 * Allows setting multiple settings in one function call.
	 *
	 * @since 3.38.0
	 *
	 * @param array $options The options.
	 */
	public function set_multiple( $options ) {

		foreach ( $options as $key => $value ) {
			$this->set( $key, $value );
		}
	}

	/**
	 * Set all settings.
	 *
	 * @deprecated 3.38.0 Deprecated in favor of set_multiple()
	 *
	 * @param array $options The options.
	 */
	public function set_all( $options ) {

		_deprecated_function( 'set_all', '3.38.0', 'set_multiple' );

		$this->set_multiple( $options );

	}

	/**
	 * Set defaults for contact fields to avoid undefined index errors.
	 *
	 * @since  3.37.30
	 *
	 * @param  array $fields The fields.
	 * @return array  Contact fields
	 */
	public function get_contact_fields( $fields ) {

		$defaults = array(
			'active'    => false,
			'type'      => 'text',
			'crm_field' => false,
		);

		if ( ! empty( $fields ) ) {

			foreach ( $fields as $usermeta_key => $data ) {
				$fields[ $usermeta_key ]           = wp_parse_args( $data, $defaults );
				$fields[ $usermeta_key ]['active'] = boolval( $fields[ $usermeta_key ]['active'] ); // we want this to be a bool, not "1".
			}
		}

		return $fields;

	}

	/**
	 * Gets available tags without categories
	 *
	 * @since 3.33.4
	 * @return array
	 */
	public function get_available_tags_flat( $include_read_only = true ) {

		$available_tags = $this->get( 'available_tags', array() );

		$data = array();

		foreach ( $available_tags as $id => $label ) {

			if ( is_array( $label ) ) {

				if ( false !== strpos( $label['category'], 'Read Only' ) && ! $include_read_only ) {
					continue; // don't include read only tags.
				}

				if ( strpos( $label['category'], 'Read Only' ) !== false ) {
					$label['label'] .= ' <small>(' . esc_html__( 'read only', 'wp-fusion-lite' ) . ')</small>';
				}

				$label = $label['label'];

			}

			$data[ $id ] = $label;

		}

		asort( $data );

		return $data;

	}

	/**
	 * Gets available fields without categories
	 *
	 * @since 3.33.19
	 * @return array
	 */
	public function get_crm_fields_flat() {

		$crm_fields = $this->get( 'crm_fields', array() );

		if ( ! is_array( reset( $crm_fields ) ) ) {

			natcasesort( $crm_fields );

			return $crm_fields;

		} else {

			$fields_flat = array();

			foreach ( $crm_fields as $category ) {

				foreach ( $category as $key => $label ) {
					$fields_flat[ $key ] = $label;
				}
			}

			natcasesort( $fields_flat );
			return $fields_flat;

		}

	}

	/**
	 * Utility function for adding a setting before an existing setting
	 *
	 * @since 1.0
	 * @return array
	 */

	public function insert_setting_before( $key, array $array, $new_key, $new_value = null ) {

		if ( isset( $array[ $key ] ) ) {
			$new = array();
			foreach ( $array as $k => $value ) {
				if ( $k === $key ) {
					if ( is_array( $new_key ) && count( $new_key ) > 0 ) {
						$new = array_merge( $new, $new_key );
					} else {
						$new[ $new_key ] = $new_value;
					}
				}
				$new[ $k ] = $value;
			}

			return $new;
		}

		return $array;
	}


	/**
	 * Utility function for adding a setting after an existing setting
	 *
	 * @since 1.0
	 * @return array
	 */

	public function insert_setting_after( $key, array $array, $new_key, $new_value = null ) {

		if ( isset( $array[ $key ] ) ) {
			$new = array();

			foreach ( $array as $k => $value ) {
				$new[ $k ] = $value;
				if ( $k === $key ) {
					if ( is_array( $new_key ) && count( $new_key ) > 0 ) {
						$new = array_merge( $new, $new_key );
					} else {
						$new[ $new_key ] = $new_value;
					}
				}
			}

			return $new;

		} else {

			$array = $array + $new_key;

		}

		return $array;
	}

	/**
	 * Add settings link to plugin page
	 *
	 * @access public
	 * @return array Links
	 */

	public function add_action_links( $links ) {

		if ( ! is_array( $links ) ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( get_admin_url( null, 'options-general.php?page=wpf-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-fusion-lite' ) . '</a>';

		return $links;
	}


	/**
	 * Adds link to changelog in plugins list.
	 *
	 * @since  3.36.0
	 *
	 * @param  array   $links  The links.
	 * @param  string  $file   The plugin file name.
	 * @return array   The row links.
	 */
	public function add_row_links( $links, $file ) {

		if ( WPF_PLUGIN_PATH === $file ) {

			$links[] = sprintf( '<a href="https://wpfusion.com/documentation/faq/changelog/" target="_blank" rel="noopener">%s</a>', esc_html__( 'View changelog', 'wp-fusion-lite' ) );

		}

		return $links;

	}

	/**
	 * Enqueue options page scripts
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_scripts() {

		// Style.
		wp_enqueue_style( 'wpf-options', WPF_DIR_URL . 'assets/css/wpf-options.css', array(), WP_FUSION_VERSION );
		wp_enqueue_style( 'wpf-admin', WPF_DIR_URL . 'assets/css/wpf-admin.css', array(), WP_FUSION_VERSION );

		// Scripts.
		wp_enqueue_script( 'wpf-options', WPF_DIR_URL . 'assets/js/wpf-options.js', array( 'jquery', 'select4' ), WP_FUSION_VERSION, true );
		wp_enqueue_script( 'jquery-tiptip', WPF_DIR_URL . 'assets/js/jquery-tiptip/jquery.tipTip.min.js', array( 'jquery' ), '4.0.1', true );

		$localize = array(
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wpf_settings_nonce' ),
			'optionsurl' => admin_url( 'options-general.php?page=wpf-settings' ),
			'connected'  => (bool) $this->get( 'connection_configured' ),
			'sitetitle'  => rawurlencode( get_bloginfo( 'name' ) ),
			'tag_type'   => $this->get( 'crm_tag_type' ),
			'strings'    => array(
				'deleteImportGroup'       => __( 'WARNING: All users from this import will be deleted, and any user content will be reassigned to your account.', 'wp-fusion-lite' ),
				'batchErrorsEncountered'  => __( 'errors encountered. Check the logs for more details.', 'wp-fusion-lite' ),
				'batchOperationComplete'  => sprintf(
					'<strong>%s</strong> %s...',
					__( 'Batch operation complete.', 'wp-fusion-lite' ),
					__( 'Terminating...', 'wp-fusion-lite' )
				),
				'backgroundWorkerBlocked' => __( 'The background worker is being blocked by your server. Starting alternate (slower) method. Please do not refresh the page until the process completes.', 'wp-fusion-lite' ),
				'processing'              => __( 'Processing', 'wp-fusion-lite' ),
				'beginningProcessing'     => sprintf( __( 'Beginning %s Processing', 'wp-fusion-lite' ), 'ACTIONTITLE' ),
				'startBatchWarning'       => __( "Heads Up: These background operations can potentially alter a lot of data and are irreversible. If you're not sure you need to run one, please contact our support.\n\nIf you want to resynchronize the dropdowns of available tags and fields, click \"Resynchronize Tags & Fields\" from the setup tab.\n\nPress OK to proceed or Cancel to cancel.", 'wp-fusion-lite' ),
				'webhooks'                => array(
					'testing'         => __( 'Testing...', 'wp-fusion-lite' ),
					'unexpectedError' => __( 'Unexpected error. Try again or contact support.', 'wp-fusion-lite' ),
					'success'         => sprintf( __( 'Success! Your site is able to receive incoming webhooks. Note that this means your site is not blocking webhooks from wpfusion.com, it is still possible for your server to block webhooks from %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
					'unauthorized'    => __( 'Unauthorized. Your site is currently blocking incoming webhooks. Try changing your security settings, or contact our support.', 'wp-fusion-lite' ),
					'cloudflare'      => __( 'Your site appears to be using CloudFlare CDN services. If you encounter issues with webhooks check your CloudFlare firewall.', 'wp-fusion-lite' ),
				),
				'error'                   => __( 'Error', 'wp-fusion-lite' ),
				'syncTags'                => __( 'Syncing Tags &amp; Fields', 'wp-fusion-lite' ),
				'loadContactIDs'          => __( 'Loading Contact IDs and Tags', 'wp-fusion-lite' ),
				'connectionSuccess'       => sprintf( __( 'Congratulations: you\'ve successfully established a connection to %s and your tags and custom fields have been imported. Press "Save Changes" to continue.', 'wp-fusion-lite' ), 'CRMNAME' ),
				'connecting'              => __( 'Connecting', 'wp-fusion-lite' ),
				'refreshingTags'          => __( 'Refreshing tags', 'wp-fusion-lite' ),
				'refreshingFields'        => __( 'Refreshing fields', 'wp-fusion-lite' ),
				'licenseError'            => __( 'Error processing request. Debugging info below:', 'wp-fusion-lite' ),
				'addFieldUnknown'         => __( "This doesn't look like a valid field key from the wp_usermeta database table.\n\nIf you're not sure what your field keys are please check your database.\n\nRegistering invalid keys for sync may result in unexpected behavior. Most likely it just won't do anything.", 'wp-fusion-lite' ),
				'syncPasswordsWarning'    => sprintf( __( "----- WARNING -----\n\nWith 'user_pass' enabled, real user passwords will be synced bidirectionally with %s.\n\nWe strongly advise against this, as it introduces a significant security liability, and is illegal in many countries and jurisdictions.\n\nThere is almost never any reason to store real user passwords in plain text in your CRM.\n\nPress OK to proceed or Cancel to cancel.", 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
		);

		wp_localize_script( 'wpf-options', 'wpf_ajax', $localize );

		wp_enqueue_script( 'wpf-admin', WPF_DIR_URL . 'assets/js/wpf-admin.js', array( 'jquery', 'select4', 'jquery-tiptip' ), WP_FUSION_VERSION, true );
		wp_localize_script( 'wpf-admin', 'wpf', array( 'crm_supports' => wp_fusion()->crm->supports ) );

	}

	/**
	 * When resetting the main options page, also delete the cache of contact
	 * IDs and tags for all users.
	 *
	 * @since 3.38.33
	 *
	 * @param array $options The options.
	 */
	public function reset_contact_ids_and_tags( $options ) {

		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key = %s", "{$options['crm']}_contact_id" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key = %s", "{$options['crm']}_tags" ) );

	}

	/**
	 * Saves any tag label overrides on initial sync
	 *
	 * @access public
	 * @return void
	 */

	public function save_tag_labels() {

		if ( isset( wp_fusion()->crm->tag_type ) ) {
			$this->set( 'crm_tag_type', wp_fusion()->crm->tag_type );
		}

	}

	/**
	 * Allows text to be overridden for CRMs that use different segmentation labels (groups, lists, etc)
	 *
	 * @access public
	 * @return string Text
	 */

	public function set_tag_labels( $translation, $text, $domain ) {

		if ( 0 === strpos( $domain, 'wp-fusion-lite' ) ) {

			$tag_type = $this->get( 'crm_tag_type' );

			if ( $this->connection_configured && ! empty( $tag_type ) ) {

				if ( strpos( $translation, ' Tag' ) !== false ) {

					$translation = str_replace( ' Tag', ' ' . $tag_type, $translation );

				}

				if ( strpos( $translation, ' tag' ) !== false ) {

					$translation = str_replace( ' tag', ' ' . strtolower( $tag_type ), $translation );

				}
			}
		}

		return $translation;

	}

	/**
	 * Filters out internal WordPress fields from showing up in syncable meta fields list and sets labels and types for built in fields
	 *
	 * @since 1.0
	 * @return array
	 */

	public function prepare_meta_fields( $meta_fields ) {

		// Load the reference of standard WP field names and types.
		include dirname( __FILE__ ) . '/wordpress-fields.php';

		// Sets field types and labels for all built in fields.
		foreach ( $wp_fields as $key => $data ) {

			if ( ! isset( $data['group'] ) ) {
				$data['group'] = 'wp';
			}

			$meta_fields[ $key ] = $data;

		}

		// Get any additional wp_usermeta data.
		$all_fields = get_user_meta( get_current_user_id() );

		// Exclude some stuff we don't need to see listed.
		$exclude_fields = array(
			'contact_id',
			'wpf_tags',
			'rich_editing',
			'comment_shortcuts',
			'admin_color',
			'use_ssl',
			'show_admin_bar_front',
			'wp_user_level',
			'dismissed_wp_pointers',
			'show_welcome_panel',
			'session_tokens',
			'wp_dashboard_quick_press_last_post_id',
			'nav_menu_recently_edited',
			'managenav-menuscolumnshidden',
			'metaboxhidden_nav-menus',
			'unique_id',
			'profilepicture',
			'action',
			'group',
			'shortcode',
			'up_username',
		);

		foreach ( $exclude_fields as $field ) {

			if ( isset( $all_fields[ $field ] ) ) {
				unset( $all_fields[ $field ] );
			}
		}

		// Some fields we can exclude via partials.
		$exclude_fields_partials = array(
			'metaboxhidden_',
			'meta-box-order_',
			'screen_layout_',
			'closedpostboxes_',
			'_contact_id',
			'_tags',
		);

		foreach ( $exclude_fields_partials as $partial ) {

			foreach ( $all_fields as $field => $data ) {

				if ( strpos( $field, $partial ) !== false ) {
					unset( $all_fields[ $field ] );
				}
			}
		}

		// Sets field types and labels for all built in fields.
		foreach ( $all_fields as $key => $data ) {

			// Skip hidden fields.
			if ( substr( $key, 0, 1 ) === '_' || substr( $key, 0, 5 ) === 'hide_' || substr( $key, 0, 3 ) === 'wp_' ) {
				continue;
			}

			if ( ! isset( $meta_fields[ $key ] ) ) {

				$meta_fields[ $key ] = array(
					'group' => 'extra',
				);

				if ( is_array( maybe_unserialize( $data[0] ) ) ) {
					$meta_fields[ $key ]['type'] = 'multiselect';
				} else {
					$meta_fields[ $key ]['type'] = 'text';
				}
			}
		}

		return $meta_fields;

	}

	/**
	 * Add a new tag via AJAX.
	 *
	 * @since 3.38.40
	 */
	public function add_tag() {

		check_ajax_referer( 'wpf_admin_nonce' );

		if ( empty( $_POST['tag'] ) || empty( $_POST['tag']['id'] ) ) {
			wp_send_json_error( new WP_Error( 'error', __( 'Tag name is empty!.', 'wp-fusion-lite' ) ) );
		}

		$tag_name = trim( sanitize_text_field( wp_unslash( $_POST['tag']['id'] ) ) );
		$tag_id   = wp_fusion()->crm->add_tag( $tag_name );

		if ( is_wp_error( $tag_id ) ) {
			wp_send_json_error( $tag_id );
		}

		wpf_log( 'info', wpf_get_current_user_id(), 'Created new tag ' . $tag_name . ' with ID ' . $tag_id );

		$available_tags = $this->get( 'available_tags' );

		if ( is_array( reset( $available_tags ) ) ) {

			// With categories. Just HubSpot for now.

			$available_tags[ $tag_id ] = array(
				'label'    => $tag_name,
				'category' => 'Static Lists',
			);

		} else {

			$available_tags[ $tag_id ] = $tag_name;

		}

		asort( $available_tags );

		$this->set( 'available_tags', $available_tags );

		wp_send_json_success(
			array(
				'tag_name' => $tag_name,
				'tag_id'   => $tag_id,
			)
		);
	}


	/**
	 * Sync tags
	 *
	 * @access public
	 * @return array New tags loaded from CRM
	 */

	public function sync_tags() {

		check_ajax_referer( 'wpf_admin_nonce' );

		$available_tags = wpf_get_option( 'available_tags' );
		$new_tags       = wp_fusion()->crm->sync_tags();

		foreach ( $new_tags as $id => $data ) {

			if ( ! isset( $available_tags[ $id ] ) ) {
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( wp_fusion()->user->get_tag_label( $id ) ) . '</option>';
			}
		}

		die();
	}


	/**
	 * Sync custom fields
	 *
	 * @access public
	 * @return array New custom fields loaded from CRM
	 */
	public function sync_custom_fields() {

		check_ajax_referer( 'wpf_admin_nonce' );

		$crm_fields = wpf_get_option( 'crm_fields' );
		$new_fields = wp_fusion()->crm->sync_crm_fields();

		if ( is_wp_error( $new_fields ) ) {

			wpf_log( 'error', 0, 'Failed to load custom fields: ' . $new_fields->get_error_message() );
			die();

		}

		if ( isset( $new_fields['Custom Fields'] ) ) {

			// Optgroup fields.
			$new_fields = $new_fields['Custom Fields'];

			foreach ( $new_fields as $id => $label ) {
				if ( ! isset( $crm_fields['Custom Fields'][ $id ] ) ) {
					echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
				}
			}
		} else {

			// Non optgroup.
			foreach ( $new_fields as $id => $label ) {
				if ( ! isset( $crm_fields[ $id ] ) ) {
					echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
				}
			}
		}

		die();
	}

	/**
	 * Deletes a previously imported group of contacts
	 *
	 * @access public
	 * @return bool
	 */
	public function delete_import_group() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( ! isset( $_POST['group_id'] ) ) {
			die();
		}

		$import_group  = absint( $_POST['group_id'] );
		$import_groups = get_option( 'wpf_import_groups' );

		global $current_user;

		foreach ( $import_groups[ $import_group ]['user_ids'] as $user_id ) {

			// Don't delete admins.
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				wp_delete_user( $user_id, $current_user->ID );
			}
		}

		unset( $import_groups[ $import_group ] );
		update_option( 'wpf_import_groups', $import_groups, false );
		wp_send_json_success();

		die();

	}

	/**
	 * Get active integrations for license check.
	 *
	 * @since  3.37.8
	 *
	 * @return array The active integrations.
	 */
	public function get_active_integrations() {

		$integrations = array();

		if ( ! empty( wp_fusion()->integrations ) ) {

			foreach ( wp_fusion()->integrations as $slug => $object ) {
				$integrations[] = $slug;
			}
		}

		// Addons.
		$addons = array(
			'WP_Fusion_Ecommerce',
			'WP_Fusion_Abandoned_Cart',
			'WP_Fusion_Logins',
			'WP_Fusion_Downloads',
			'WP_Fusion_Webhooks',
			'WP_Fusion_Media_Tools',
			'WP_Fusion_Event_Tracking',
		);

		foreach ( $addons as $class ) {

			if ( class_exists( $class ) ) {

				$slug = str_replace( 'WP_Fusion_', '', $class );
				$slug = str_replace( '_', '-', $slug );

				$integrations[] = strtolower( $slug );

			}
		}

		return $integrations;

	}


	/**
	 * Allows overriding the license key via wp-config.php
	 *
	 * @since  3.37.8
	 *
	 * @param  string|bool $license_key The license key or false.
	 * @return string|bool The license key.
	 */
	public function get_license_key( $license_key ) {

		// Allow setting license key via wp-config.php.

		if ( empty( $license_key ) && defined( 'WPF_LICENSE_KEY' ) ) {
			$license_key = WPF_LICENSE_KEY;
		}

		return $license_key;

	}

	/**
	 * Check EDD license.
	 *
	 * @access public
	 * @return string License Status
	 */
	public function edd_check_license( $license_key ) {

		$status = get_option( 'wpf_license_check', 0 ) + ( DAY_IN_SECONDS * 10 );

		// Run the license check a maximum of once every 10 days.

		if ( time() >= $status ) {

			update_option( 'wpf_license_check', time() );

			// data to send in our API request.
			$api_params = array(
				'edd_action'   => 'check_license',
				'license'      => $license_key,
				'item_name'    => rawurlencode( 'WP Fusion' ),
				'author'       => 'Very Good Plugins',
				'url'          => home_url(),
				'crm'          => isset( wp_fusion()->crm->menu_name ) ? wp_fusion()->crm->menu_name : wp_fusion()->crm->name,
				'integrations' => $this->get_active_integrations(),
				'version'      => WP_FUSION_VERSION,
				'edd_item_id'  => WPF_EDD_ITEM_ID,
			);

			// Call the custom API. This is a GET so CloudFlare can cache the response for 12h in cases where the transient in WP isn't working.
			$response = wp_safe_remote_get(
				WPF_STORE_URL,
				array(
					'timeout'   => 20,
					'sslverify' => false,
					'body'      => $api_params,
				)
			);

			// make sure the response came back okay.
			if ( is_wp_error( $response ) ) {
				return 'error';
			}

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			$this->set( 'license_status', $license_data->license );

			return $license_data->license;

		} else {

			// Return stored license data.
			return $this->get( 'license_status' );

		}

	}


	/**
	 * Activate EDD license.
	 *
	 * @access public
	 * @return bool
	 */
	public function edd_activate( $license_key = false ) {

		if ( wp_doing_ajax() ) {
			check_ajax_referer( 'wpf_settings_nonce' );
		}

		if ( empty( $license_key ) && isset( $_POST['key'] ) ) {
			$license_key = trim( sanitize_key( wp_unslash( $_POST['key'] ) ) );
		}

		// data to send in our API request.
		$api_params = array(
			'edd_action' => 'activate_license',
			'license'    => $license_key,
			'item_name'  => rawurlencode( 'WP Fusion' ), // the name of our product in EDD.
			'url'        => home_url(),
			'version'    => WP_FUSION_VERSION,
		);

		if ( wpf_get_option( 'connection_configured' ) ) {

			$api_params['crm']          = wp_fusion()->crm->name;
			$api_params['integrations'] = $this->get_active_integrations();

		}

		// Call the custom API.
		$response = wp_safe_remote_get(
			WPF_STORE_URL . '/?edd_action=activate',
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);

		if ( wp_doing_ajax() ) {

			// If we're doing this in an AJAX request (via the Activate button).
			if ( 403 === wp_remote_retrieve_response_code( $response ) ) {
				wp_send_json_error( '403 response. Please <a href="https://wpfusion.com/support/contact/" target="_blank">contact support</a>. IP address: ' . isset( $_SERVER['SERVER_ADDR'] ) ? esc_html( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'unknown' );
			}

			// Make sure the response came back okay.
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( esc_html( $response->get_error_message() ) . '&ndash; Please <a href="https://wpfusion.com/support/contact/" target="_blank">contact support</a> for further assistance.' );
			}

			// Decode the license data.
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			// $license_data->license will be either "valid" or "invalid"
			// Store the options locally
			$this->set( 'license_status', $license_data->license );
			$this->set( 'license_key', $license_key );

			if ( 'valid' === $license_data->license ) {
				wp_send_json_success( 'activated' );
			} else {

				switch ( $license_data->error ) {

					case 'expired':
						$message = sprintf(
							/* translators: the license key expiration date */
							__( 'Your license key expired on %s.', 'edd-sample-plugin' ),
							date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
						);
						break;

					case 'disabled':
					case 'revoked':
						$message = __( 'Your license key has been disabled.', 'edd-sample-plugin' );
						break;

					case 'missing':
						$message = __( 'Invalid license.', 'edd-sample-plugin' );
						break;

					case 'invalid':
					case 'site_inactive':
						$message = __( 'Your license is not active for this URL.', 'edd-sample-plugin' );
						break;

					case 'item_name_mismatch':
						/* translators: the plugin name */
						$message = sprintf( __( 'This appears to be an invalid license key for %s.', 'edd-sample-plugin' ), EDD_SAMPLE_ITEM_NAME );
						break;

					case 'no_activations_left':
						$message = __( 'Your license key has reached its activation limit.', 'edd-sample-plugin' );
						break;

					default:
						$message = __( 'An error occurred, please try again.', 'edd-sample-plugin' );
						break;
				}

				wp_send_json_error( $message . '<br /><pre>' . wpf_print_r( $license_data, true ) . '</pre>' );
			}
		} else {

			// If we're calling this in PHP.
			if ( ! is_wp_error( $response ) ) {

				$license_data = json_decode( wp_remote_retrieve_body( $response ) );

				if ( is_object( $license_data ) ) {

					$this->set( 'license_status', $license_data->license );
					$this->set( 'license_key', $license_key );

					return true;

				}
			}
		}

	}


	/**
	 * Deactivate EDD license
	 *
	 * @access public
	 * @return bool
	 */

	public function edd_deactivate() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( isset( $_POST['key'] ) ) {
			$license_key = trim( sanitize_key( wp_unslash( $_POST['key'] ) ) );
		} else {
			die();
		}

		// data to send in our API request.
		$api_params = array(
			'edd_action' => 'deactivate_license',
			'license'    => $license_key,
			'item_name'  => rawurlencode( 'WP Fusion' ), // the name of our product in EDD.
			'url'        => home_url(),
		);

		// Call the custom API.
		$response = wp_safe_remote_get(
			WPF_STORE_URL . '/?edd_action=deactivate',
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);

		// make sure the response came back okay
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
			die();
		}

		$this->set( 'license_status', 'invalid' );
		$this->set( 'license_key', false );

		wp_send_json_success( 'deactivated' );

	}

	/**
	 * Maybe activate the license key if it's defined in wp-config.php
	 *
	 * @since 3.37.8
	 */
	public function maybe_activate_site() {

		if ( ( empty( $this->get( 'license_status' ) ) || 'site_inactive' === $this->get( 'license_status' ) ) && defined( 'WPF_LICENSE_KEY' ) ) {
			$this->edd_activate( WPF_LICENSE_KEY );
		}

	}




	/**
	 * Set up options page
	 *
	 * @access public
	 * @return array Project args.
	 */
	private function get_setup() {
		$args = array(
			'project_name' => __( 'WP Fusion', 'wp-fusion-lite' ),
			'project_slug' => 'wpf',
			'menu'         => 'settings',
			'page_title'   => __( 'WP Fusion Settings', 'wp-fusion-lite' ),
			'menu_title'   => __( 'WP Fusion', 'wp-fusion-lite' ),
			'capability'   => 'manage_options',
			'option_group' => 'wpf_options',
			'slug'         => 'wpf-settings',
			'page_icon'    => 'tools',
		);

		return $args;

	}


	/**
	 * Get options page sections
	 *
	 * @access private
	 * @return array Sections.
	 */
	private function get_sections() {

		$sections = array();

		$sections['wpf-settings']['main']           = __( 'General Settings', 'wp-fusion-lite' );
		$sections['wpf-settings']['contact-fields'] = __( 'Contact Fields', 'wp-fusion-lite' );

		if ( wp_fusion()->is_full_version() ) {
			$sections['wpf-settings']['integrations'] = __( 'Integrations', 'wp-fusion-lite' );
		}

		$sections['wpf-settings']['import']   = __( 'Import Users', 'wp-fusion-lite' );
		$sections['wpf-settings']['setup']    = __( 'Setup', 'wp-fusion-lite' );
		$sections['wpf-settings']['advanced'] = __( 'Advanced', 'wp-fusion-lite' );

		return $sections;

	}


	/**
	 * Initialize settings to default values
	 *
	 * @access public
	 * @return array
	 */
	public function initialize_options( $options ) {

		// Access Key.
		if ( empty( $options['access_key'] ) ) {
			$options['access_key'] = substr( md5( microtime() . wp_rand() ), 0, 8 );
		}

		if ( empty( $options['site_url'] ) ) {
			$options['site_url'] = get_site_url();
		}

		if ( empty( $options['wp_fusion_version'] ) ) {
			$options['wp_fusion_version'] = WP_FUSION_VERSION;
		}

		// Staging CRM.
		if ( isset( $options['crm'] ) && 'staging' === $options['crm'] ) {
			$options['connection_configured'] = true;
		}

		// Set the initial field mappings for the CRM.
		if ( ! empty( $options['contact_fields']['user_email'] ) && empty( $options['contact_fields']['user_email']['crm_field'] ) && true === $options['connection_configured'] ) {
			$options = apply_filters( 'wpf_initialize_options_contact_fields', $options );
		}

		return $options;

	}


	/**
	 * Define all available settings
	 *
	 * @access private
	 * @return void
	 */

	public function get_settings( $settings, $options ) {

		$settings = array();

		$settings['general_desc'] = array(
			'desc'    => '<p>' . sprintf( __( 'For more information on these settings, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/general-settings/" target="_blank">', '</a>' ) . '</p>',
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['users_header'] = array(
			'title'   => __( 'Automatically Create Contact Records for New Users', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['create_users'] = array(
			'title'   => __( 'Create Contacts', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Create new contacts in %s when users register in WordPress.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'user_roles', 'assign_tags', 'opportunity_state' ),
			'tooltip' => sprintf( __( 'We <em>strongly</em> recommend leaving this setting enabled. If it\'s disabled only profile updates from existing users will be synced with %s. New users or customers will not be synced, and no tags will be applied to new users.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
		);

		$settings['assign_tags'] = array(
			'title'   => __( 'Assign Tags', 'wp-fusion-lite' ),
			'desc'    => __( 'The selected tags will be applied to anyone who registers an account in WordPress.', 'wp-fusion-lite' ),
			'type'    => 'multi_select',
			'choices' => array(),
			'section' => 'main',
		);

		/*
		// CONTACT DATA SYNC
		*/

		$settings['contact_sync_header'] = array(
			'title'   => __( 'Synchronize Contact Data', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['push'] = array(
			'title'   => __( 'Push', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Sync profile updates and custom field changes to the user\'s contact record in %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'push_all_meta' ),
		);

		$settings['push_all_meta'] = array(
			'title'   => __( 'Push All', 'wp-fusion-lite' ),
			'desc'    => __( 'Push meta data whenever a single "user_meta" entry is added or modified.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
			'tooltip' => __( 'This is useful if using non-supported plugins or manual user_meta updates, but may result in duplicate API calls and slower performance.', 'wp-fusion-lite' ),
		);

		$settings['login_sync'] = array(
			'title'   => __( 'Login Tags Sync', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Load the user\'s latest tags from %s on login.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'checkbox',
			'section' => 'main',
			'tooltip' => sprintf( __( 'Note: this is only necessary if you are applying tags via automations in %s and haven\'t set up webhooks to send the data back. Any tags applied via WP Fusion are available in WordPress immediately.' ), wp_fusion()->crm->name ),
		);

		$settings['login_meta_sync'] = array(
			'title'   => __( 'Login Meta Sync', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Load the user\'s latest meta data from %s on login.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'checkbox',
			'section' => 'main',
			'tooltip' => sprintf( __( 'Note: this is only necessary if you are manually updating contact data in %s and haven\'t set up webhooks to send the data back.' ), wp_fusion()->crm->name ),
		);

		// $settings['profile_update_tags'] = array(
		// 'title'   => __( 'Update Tag', 'wp-fusion-lite' ),
		// 'desc'    => __( 'Apply this tag when a contact record has been updated (useful for triggering data to be sent to other WP Fusion installs).', 'wp-fusion-lite' ),
		// 'std'     => false,
		// 'type'    => 'assign_tags',
		// 'section' => 'main'
		// ); // Removed in v3.3.3
		/*
		// RESTRICT PAGE ACCESS
		*/

		$settings['restrict_access_header'] = array(
			'title'   => __( 'Content Restriction', 'wp-fusion-lite' ),
			'url'     => 'https://wpfusion.com/documentation/getting-started/access-control/',
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['restrict_content'] = array(
			'title'   => __( 'Restrict Content', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable WP Fusion\'s access control system.', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array(
				'restrict_access_header',
				'exclude_admins',
				'hide_restricted',
				'hide_archives',
				'query_filter_post_types',
				'return_after_login',
				'return_after_login_priority',
				'default_not_logged_in_redirect',
				'default_redirect',
				'restricted_message',
				'per_post_messages',
				'site_lockout_header',
				'lockout_tags',
				'lockout_redirect',
				'lockout_allowed_urls',
				'seo_enabled',
				'seo_excerpt_length',
			),
			'tooltip' => sprintf( __( 'WP Fusion includes many features for restricting access to content based on tags in %s. However if you are not using these features (you only want to sync data with %s), you can un-check this setting to disable the access control interfaces and features.', 'wp-fusion-lite' ), wp_fusion()->crm->name, wp_fusion()->crm->name ),
		);

		$settings['exclude_admins'] = array(
			'title'   => __( 'Exclude Administrators', 'wp-fusion-lite' ),
			'desc'    => __( 'Users with Administrator accounts will be able to view all content, regardless of restrictions.', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings['hide_restricted'] = array(
			'title'   => __( 'Hide From Menus', 'wp-fusion-lite' ),
			'desc'    => __( 'Content that the user cannot access will be removed from menus.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings['hide_archives'] = array(
			'title'      => __( 'Filter Queries', 'wp-fusion-lite' ),
			'desc'       => __( 'Content that the user cannot access will be <strong>completely hidden</strong> from all post listings, grids, archives, and course navigation. <strong>Use with caution</strong>.', 'wp-fusion-lite' ),
			'std'        => false,
			'type'       => 'select',
			'section'    => 'main',
			'choices'    => array(
				false      => __( 'Off', 'wp-fusion-lite' ),
				'standard' => __( 'Standard', 'wp-fusion-lite' ),
				'advanced' => __( 'Advanced (slower)', 'wp-fusion-lite' ),
			),
			'unlock'     => array( 'query_filter_post_types' ),
			'allow_null' => false,
		);

		$settings['query_filter_post_types'] = array(
			'title'       => __( 'Post Types', 'wp-fusion-lite' ),
			'desc'        => __( 'Select post types to be used with query filtering. Leave blank for all post types.', 'wp-fusion-lite' ),
			'type'        => 'multi_select',
			'placeholder' => __( 'Select post types', 'wp-fusion-lite' ),
			'section'     => 'main',
		);

		$settings['return_after_login'] = array(
			'title'   => __( 'Return After Login', 'wp-fusion-lite' ),
			'desc'    => __( 'If a user has tried to view a restricted page, take them back to that page after logging in.', 'wp-fusion-lite' ),
			'tooltip' => __( 'When a user attempts to access a restricted page a cookie will be set. When the user logs in they will be redirected to the most recent restricted page they tried to access.', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'return_after_login_priority' ),
		);

		$settings['return_after_login_priority'] = array(
			'title'   => __( 'Return After Login Priority', 'wp-fusion-lite' ),
			'desc'    => __( 'Priority for the login redirect. A lower number means the redirect happens sooner in the login process.', 'wp-fusion-lite' ),
			'std'     => 10,
			'type'    => 'number',
			'section' => 'main',
		);

		$settings['default_not_logged_in_redirect'] = array(
			'title'   => __( 'Default Not Logged-In Redirect', 'wp-fusion-lite' ),
			'desc'    => __( 'A redirect URL specified here will take priority in cases where a visitor was denied access to a piece of content due to not being logged in.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings['default_redirect'] = array(
			'title'   => __( 'Default Generic Redirect', 'wp-fusion-lite' ),
			'desc'    => __( 'Default redirect URL for when access is denied to a piece of content. This can be overridden on a per-page basis. Leave blank to display error message below.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings['restricted_message'] = array(
			'title'         => __( 'Default Restricted Content Message', 'wp-fusion-lite' ),
			'desc'          => __( '<a href="https://wpfusion.com/documentation/getting-started/access-control/#restricted-content-message" target="_blank">Restricted content message</a> for when a redirect isn\'t set. Use <code>[the_excerpt]</code> or <code>[the_excerpt length="120"]</code> to display an excerpt of the restricted content.', 'wp-fusion-lite' ),
			'std'           => __( '<h2 style="text-align:center">Oops!</h2><p style="text-align:center">You don\'t have permission to view this page! Make sure you\'re logged in and try again, or contact support.</p>', 'wp-fusion-lite' ),
			'type'          => 'editor',
			'section'       => 'main',
			'textarea_rows' => 15,
		);

		$settings['per_post_messages'] = array(
			'title'   => __( 'Per Post Messages', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable this setting to allow specifying a different restricted content message for each page or post.', 'wp-fusion-lite' ),
			'tooltip' => __( 'With this setting enabled a new metabox will appear on all posts and pages where you can specify a unique content restriction message for that post. If no message is set the Default Restricted Content Message will be shown.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		/*
		// SITE LOCKOUT
		*/

		$settings['site_lockout_header'] = array(
			'title'   => __( 'Site Lockout', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
			'desc'    => __( 'Site lockout lets you restrict access to all pages on your site if a user has a specific tag (i.e. "Payment Failed"). The only pages accessible will be the URL specified as the Lockout Redirect below, and any additional URLs in the Allowed URLs setting.', 'wp-fusion-lite' ),
		);

		$settings['lockout_tags'] = array(
			'title'   => __( 'Lockout Tags', 'wp-fusion-lite' ),
			'desc'    => __( 'If the user has any of these tags the lockout will be activated.', 'wp-fusion-lite' ),
			'type'    => 'assign_tags',
			'section' => 'main',
		);

		$settings['lockout_redirect'] = array(
			'title'   => __( 'Lockout Redirect', 'wp-fusion-lite' ),
			'desc'    => __( 'URL to redirect to when lockout is active. (Must be a full URL)', 'wp-fusion-lite' ),
			'std'     => wp_login_url(),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings['lockout_allowed_urls'] = array(
			'title'       => __( 'Allowed URLs', 'wp-fusion-lite' ),
			'desc'        => __( 'The URLs listed here (one per line) will bypass the Site Lockout feature. You can use a wildcard * for partial matches.', 'wp-fusion-lite' ),
			'type'        => 'textarea',
			'section'     => 'main',
			'rows'        => 3,
			'placeholder' => esc_url( home_url( '/example-page/' ) ) . PHP_EOL . esc_url( home_url( '/my-account/*' ) ),
		);

		/*
		// SEO
		*/

		$settings['seo_header'] = array(
			'title'   => __( 'SEO', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['seo_enabled'] = array(
			'title'   => __( 'Show Excerpts', 'wp-fusion-lite' ),
			'desc'    => __( 'Show an excerpt of your restricted content to search engine spiders.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
			'unlock'  => array( 'seo_excerpt_length' ),
		);

		$settings['seo_excerpt_length'] = array(
			'title'   => __( 'Excerpt Length', 'wp-fusion-lite' ),
			'desc'    => __( 'Show the first X words of your content to search engines. Leave blank for default, which is usually 55 words.', 'wp-fusion-lite' ),
			'type'    => 'number',
			'section' => 'main',
		);

		/*
		// ACCESS KEY
		*/

		$settings['access_key_header'] = array(
			'title'   => __( 'Webhooks', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$settings['access_key_desc'] = array(
			'type'    => 'paragraph',
			'section' => 'main',
			'desc'    => sprintf( __( 'Webhooks allow you to send data from %s back to your website. See <a href="https://wpfusion.com/documentation/webhooks/about-webhooks/" target="_blank">our documentation</a> for more information on creating webhooks.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
		);

		$settings['access_key'] = array(
			'title'   => __( 'Access Key', 'wp-fusion-lite' ),
			'desc'    => __( 'Use this key when sending data back to WP Fusion via a webhook or ThriveCart.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings['webhook_url'] = array(
			'title'   => __( 'Webhook Base URL', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'This is the base URL for sending webhooks back to your site. <a href="http://wpfusion.com/documentation/#webhooks" target="_blank">See the documentation</a> for more information on how to structure the URL.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'webhook_url',
			'section' => 'main',
		);

		$settings['test_webhooks'] = array(
			'title'   => __( 'Test Webhooks', 'wp-fusion-lite' ),
			'desc'    => __( 'Click this button to test your site\'s ability to receive incoming webhooks.', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'main',
		);

		$settings['return_password_header'] = array(
			'title'   => __( 'Imported Users', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
			'desc'    => __( 'These settings apply to users imported either via a webhook, the Import Users tool, or from a ThriveCart success URL.', 'wp-fusion-lite' ),
		);

		$settings['return_password'] = array(
			'title'   => __( 'Return Password', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Send new users\' generated passwords back to %s after import.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'checkbox',
			'section' => 'main',
			'std'     => 1,
			'unlock'  => array( 'return_password_field' ),
		);

		$settings['return_password_field'] = array(
			'title'   => __( 'Return Password Field', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Select a field in %s where generated passwords will be stored for imported users.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'crm_field',
			'section' => 'main',
		);

		$settings['send_welcome_email'] = array(
			'title'   => __( 'Send Welcome Email', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'Send the WordPress default new user welcome email, with a password reset link.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$settings['username_format'] = array(
			'title'      => __( 'Username Format', 'wp-fusion-lite' ),
			'desc'       => sprintf( __( 'How should usernames be set for newly imported users? For more information, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/general-settings/" target="_blank">', '</a>' ),
			'type'       => 'select',
			'section'    => 'main',
			'std'        => 'email',
			'allow_null' => false,
			'choices'    => array(
				'email'    => __( 'Email Address', 'wp-fusion-lite' ),
				'flname'   => __( 'FirstnameLastname', 'wp-fusion-lite' ),
				'fnamenum' => __( 'Firstname12345', 'wp-fusion-lite' ),
			),
		);

		/*
		// INTEGRATIONS
		*/

		$settings['integrations_overview'] = array(
			'type'    => 'integrations_overview',
			'section' => 'integrations',
		);

		if ( in_array( 'leads', wp_fusion()->crm->supports ) ) {

			$settings['leads_header'] = array(
				'title'   => __( 'Leads', 'wp-fusion-lite' ),
				'type'    => 'heading',
				'section' => 'integrations',
			);

			$settings['leads'] = array(
				'title'   => __( 'Sync Leads', 'wp-fusion-lite' ),
				'desc'    => sprintf( __( 'Sync guest form submissions to Leads in %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
				'type'    => 'checkbox',
				'section' => 'integrations',
				'tooltip' => sprintf( __( 'WP Fusion now supports creating Leads in %s (instead of Contacts) when a lead form is submitted by a guest. When this setting is enabled, all form submissions will be synced to a Lead record, and any tags will be applied to the lead.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			);

		}

		/*
		// CONTACT FIELDS
		*/

		$settings['contact_fields'] = array(
			'title'   => __( 'Contact Fields', 'wp-fusion-lite' ),
			'std'     => array(),
			'type'    => 'contact-fields',
			'section' => 'contact-fields',
			'choices' => array(),
		);

		/*
		// IMPORT USERS
		*/

		$settings['import_users_p'] = array(
			'desc'    => sprintf( __( 'This feature allows you to import %s contacts as new WordPress users. Any fields configured on the <strong>Contact Fields</strong> tab will be imported.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'paragraph',
			'section' => 'import',
		);

		$settings['import_users'] = array(
			'title'   => __( 'Import Users', 'wp-fusion-lite' ),
			'desc'    => __( 'Contacts with the selected tags will be imported as new users.', 'wp-fusion-lite' ),
			'type'    => 'multi_select',
			'choices' => array(),
			'section' => 'import',
		);

		$settings['email_notifications'] = array(
			'title'   => __( 'Enable Notifications', 'wp-fusion-lite' ),
			'desc'    => __( 'Send a welcome email to new users containing their username and a password reset link.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'import',
		);

		$settings['import_groups'] = array(
			'title'   => __( 'Import Groups', 'wp-fusion-lite' ),
			'type'    => 'import_groups',
			'section' => 'import',
		);

		/*
		// API CONFIGURATION
		*/

		$settings['api_heading'] = array(
			'title'   => __( 'API Configuration', 'wp-fusion-lite' ),
			'desc'    => '<p>' . sprintf( __( 'For more information on setup, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/installation-guide/" target="_blank">', '</a>' ) . '</p>',
			'section' => 'setup',
			'type'    => 'heading',
		);

		$settings['crm'] = array(
			'title'       => __( 'Select CRM', 'wp-fusion-lite' ),
			'section'     => 'setup',
			'type'        => 'select',
			'placeholder' => __( 'Select your CRM', 'wp-fusion-lite' ),
			'allow_null'  => false,
			'choices'     => array(),
		);

		$settings['connection_configured'] = array(
			'std'     => false,
			'type'    => 'hidden',
			'section' => 'setup',
		);

		if ( wp_fusion()->is_full_version() ) {

			$settings['license_heading'] = array(
				'title'   => __( 'WP Fusion License', 'wp-fusion-lite' ),
				'section' => 'setup',
				'type'    => 'heading',
			);

			$settings['license_key'] = array(
				'title'          => __( 'License Key', 'wp-fusion-lite' ),
				'type'           => 'edd_license',
				'section'        => 'setup',
				'license_status' => 'invalid',
			);

			$settings['license_status'] = array(
				'type'    => 'hidden',
				'section' => 'setup',
			);
		}

		/*
		// ADVANCED
		*/

		$settings['advanced_header'] = array(
			'title'   => __( 'Advanced Features', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		global $wp_roles;

		$settings['user_roles'] = array(
			'title'       => __( 'Limit  User Roles', 'wp-fusion-lite' ),
			'desc'        => __( 'You can choose to create new contacts <strong>only when users are added to a certain role</strong>. Leave blank for all roles.', 'wp-fusion-lite' ),
			'type'        => 'multi_select',
			'choices'     => $wp_roles->role_names,
			'placeholder' => __( 'Select user roles', 'wp-fusion-lite' ),
			'section'     => 'advanced',
		);

		$settings['deletion_tags'] = array(
			'title'   => __( 'Deletion Tags', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'These tags will be applied in %s when a user is deleted from the site, or when a user deletes their own account.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'std'     => array(),
			'type'    => 'assign_tags',
			'section' => 'advanced',
		);

		$settings['link_click_tracking'] = array(
			'title'   => __( 'Link Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enqueue the script to handle link click tracking. See <a href="https://wpfusion.com/documentation/tutorials/link-click-tracking/" target="_blank">this tutorial</a> for more info.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings['js_leadsource_tracking'] = array(
			'title'   => __( 'JS Lead Source Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enqueue a script to handle setting the <a href="https://wpfusion.com/documentation/tutorials/lead-source-tracking/" target="_blank">lead source tracking cookies</a>. This fixes tracking on WP Engine, Flywheel, and other hosts that sanitize UTM parameters out of URLs.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings['system_header'] = array(
			'title'   => __( 'System Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['staging_mode'] = array(
			'title'   => __( 'Staging Mode', 'wp-fusion-lite' ),
			'desc'    => sprintf( __( 'When <a href="https://wpfusion.com/documentation/faq/staging-sites/" target="_blank">staging mode</a> is active, all normal WP Fusion features will be available, but no API calls will be made to %s, and no tags or access to content will be modified.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		if ( is_multisite() ) {

			$settings['multisite_prefix_keys'] = array(
				'title'   => __( 'Multisite - Blog Prefix', 'wp-fusion-lite' ),
				'desc'    => __( 'Prefix all WP Fusion usermeta keys with the blog ID of the current site in the network.', 'wp-fusion-lite' ),
				'tooltip' => __( 'By default, data for a single user is shared across all sites in a multisite network.<br /><br />This can cause problems if you have multiple sub-sites connected to different instances of the same CRM&ndash; a user\'s tags and contact ID can end up in use on the wrong site.<br /><br />Enabling this setting will create a new usermeta key for each user\'s tags and contact ID for every site in the network where they are a member.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'advanced',
			);
		}

		$settings['performance_header'] = array(
			'title'   => __( 'Performance', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['enable_queue'] = array(
			'title'   => __( 'Enable API Queue', 'wp-fusion-lite' ),
			'desc'    => __( 'Combine redundant API calls to improve performance.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => 'advanced',
			'tooltip' => __( 'It is <strong>strongly</strong> recommended to leave this on except for debugging purposes.', 'wp-fusion-lite' ),
			'unlock'  => array( 'staging_mode' ),
		);

		$settings['prevent_reapply'] = array(
			'title'   => __( 'Prevent Reapplying Tags', 'wp-fusion-lite' ),
			'desc'    => __( 'If a user already has a tag cached in WordPress, don\'t send an API call to re-apply the same tag.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => 'advanced',
			'tooltip' => __( 'It\'s recommended to leave this on for performance reasons but it can be turned off for debugging.', 'wp-fusion-lite' ),
		);

		$settings['enable_cron'] = array(
			'title'   => __( 'Enable Cron', 'wp-fusion-lite' ),
			'desc'    => __( 'Enables a cron task to start the background worker and check if any tasks need to be performed (usually used with <a href="https://wpfusion.com/documentation/other-common-issues/webhooks-not-being-received-by-wp-fusion/#the-async-method" target="_blank">asynchronous webhooks</a>).', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
			'unlock'  => array( 'cron_interval' ),
		);

		$settings['cron_interval'] = array(
			'title'   => __( 'Cron Interval', 'wp-fusion-lite' ),
			'desc'    => __( 'How often the cron job will run (in minutes).', 'wp-fusion-lite' ),
			'std'     => 5,
			'type'    => 'number',
			'section' => 'advanced',
		);

		$settings['logging_header'] = array(
			'title'   => __( 'Logging', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['enable_logging'] = array(
			'title'   => __( 'Enable Logging', 'wp-fusion-lite' ),
			'desc'    => __( 'Access detailed activity logs for WP Fusion.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => 'advanced',
			'unlock'  => array( 'logging_errors_only', 'logging_http_api' ),
		);

		$settings['logging_errors_only'] = array(
			'title'   => __( 'Only Errors', 'wp-fusion-lite' ),
			'desc'    => __( 'Only log errors (not all activity).', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		if ( isset( wp_fusion()->crm->params ) ) {

			$settings['logging_http_api'] = array(
				'title'   => __( 'HTTP API Logging', 'wp-fusion-lite' ),
				'desc'    => __( 'Log the raw data sent over the WordPress HTTP API.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'advanced',
				'tooltip' => __( 'This will result in a lot of data being written to the logs so it\'s recommended to only enable temporarily for debugging purposes.', 'wp-fusion-lite' ),
			);

		}

		$settings['logging_badge'] = array(
			'title'   => __( 'Show Badge', 'wp-fusion-lite' ),
			'desc'    => __( 'Show a notification badge on the Tools menu in the admin when there are unseen log errors.', 'wp-fusion-lite' ),
			'std'     => 1,
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings['interfaces_header'] = array(
			'title'   => __( 'Interfaces and Settings', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['enable_admin_bar'] = array(
			'title'   => __( 'Admin Bar', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable the "Preview With Tag" functionality on the admin bar.', 'wp-fusion-lite' ),
			'tooltip' => __( 'If you have a lot of tags and aren\'t using the Preview With Tag feature disabling this can make your admin bar load faster.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => 'advanced',
		);

		$settings['enable_menu_items'] = array(
			'title'   => __( 'Menu Item Visibility', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable the <a target="_blank" href="https://wpfusion.com/documentation/tutorials/menu-item-visibility/">menu item visibility controls</a> in the WordPress menu editor.', 'wp-fusion-lite' ),
			'tooltip' => __( 'If you\'re not using per-item menu visibility control, disabling this can make the admin menu interfaces load faster and be easier to work with.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => 'advanced',
		);

		$settings['hide_additional'] = array(
			'title'   => __( 'Hide Additional Fields', 'wp-fusion-lite' ),
			'desc'    => __( 'Hide the <code>wp_usermeta</code> Fields section from the Contact Fields tab.', 'wp-fusion-lite' ),
			'tooltip' => __( 'If you\'re not using any of the fields this can speed up performance and make the settings page load faster.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings['admin_permissions'] = array(
			'title'   => __( 'Admin Permissions', 'wp-fusion-lite' ),
			'desc'    => __( 'Require the <code>manage_options</code> capability to see WP Fusion meta boxes in the admin.', 'wp-fusion-lite' ),
			'tooltip' => __( 'Enable this setting if you have other user roles editing posts (for example Authors or Editors) and you don\'t want them to see WP Fusion\'s access control meta boxes.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'advanced',
		);

		$settings['export_header'] = array(
			'title'   => __( 'Batch Operations', 'wp-fusion-lite' ),
			'desc'    => __( 'Hover over the tooltip icons to get a description of what each operation does and what data will be modified. For more information on batch operations, <a target="_blank" href="https://wpfusion.com/documentation/tutorials/exporting-data/">see our documentation</a>.', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['export_options'] = array(
			'title'   => __( 'Operation', 'wp-fusion-lite' ),
			'type'    => 'export_options',
			'choices' => array(),
			'section' => 'advanced',
		);

		$settings['status_header'] = array(
			'title'   => __( 'Status', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['status_hooks'] = array(
			'title'   => __( 'Custom Code', 'wp-fusion-lite' ),
			'type'    => 'status_hooks',
			'section' => 'advanced',
		);

		$settings['reset_header'] = array(
			'title'   => __( 'Reset', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'advanced',
		);

		$settings['reset_options'] = array(
			'title'   => __( 'Reset', 'wp-fusion-lite' ),
			'desc'    => __( 'Check this box and click "Save Changes" below to reset all options to their defaults.', 'wp-fusion-lite' ),
			'type'    => 'reset',
			'section' => 'advanced',
		);

		return $settings;

	}


	/**
	 * Configure available settings
	 *
	 * @access public
	 * @return array
	 */
	public function configure_settings( $settings, $options ) {

		// Lock the license field if the license is valid.
		if ( 'valid' === $this->get( 'license_status' ) && ! empty( $options['license_key'] ) ) {
			$settings['license_key']['license_status'] = 'valid';
			$settings['license_key']['disabled']       = true;
		}

		// Everything else can be done after the API connection is set up.
		if ( $this->get( 'connection_configured' ) ) {

			$settings['import_users']['choices'] = $this->get( 'available_tags' );

			if ( ! get_option( 'wpf_import_groups' ) ) {
				$settings['import_groups']['type'] = 'hidden';
			}

			// Disable CRM change after initial connection is configured.
			$settings['crm']['disabled'] = true;
			$settings['crm']['desc']     = __( 'To change CRMs, please do a full reset of WP Fusion from the Advanced tab.', 'wp-fusion-lite' );

			// Fields that unlock other fields.
			foreach ( $settings as $setting => $data ) {

				if ( isset( $data['unlock'] ) ) {

					foreach ( $data['unlock'] as $unlock_field ) {

						if ( ! empty( $settings[ $unlock_field ] ) && empty( $settings[ $unlock_field ]['disabled'] ) ) {

							$settings[ $unlock_field ]['disabled'] = empty( $options[ $setting ] ) ? true : false;

						}
					}
				}

				if ( isset( $data['lock'] ) ) {

					foreach ( $data['lock'] as $lock_field ) {

						if ( ! empty( $settings[ $lock_field ] ) && isset( $options[ $setting ] ) ) {

							$settings[ $lock_field ]['disabled'] = ( true == $options[ $setting ] );

						}
					}
				}
			}
		}

		return $settings;

	}


	/**
	 * Set the available post types for query filtering.
	 *
	 * This needs to run when the page is being rendered since not all plugins
	 * have registered their post types by the time get_settings() runs.
	 *
	 * @since 3.37.0
	 *
	 * @param array $setting The setting parameters.
	 * @param array $options The options in the DB.
	 * @return array The setting parameters.
	 */
	public function configure_setting_query_filter_post_types( $setting, $options ) {

		$post_types = get_post_types( array( 'public' => true ) );

		unset( $post_types['attachment'] );
		unset( $post_types['revision'] );

		$setting['choices'] = $post_types;

		return $setting;

	}

	/**
	 * Shows API key field
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_api_validate( $id, $field ) {

		if ( isset( $field['password'] ) ) {
			$type = 'password';
		} else {
			$type = 'text';
		}

		echo '<input id="' . esc_attr( $id ) . '" class="form-control ' . esc_attr( $field['class'] ) . '" type="' . esc_attr( $type ) . '" name="wpf_options[' . esc_attr( $id ) . ']" value="' . esc_attr( $this->{ $id } ) . '">';

		if ( $this->connection_configured ) {

			$tip = sprintf( __( 'Refresh all custom fields and available tags from %s. Does not modify any user data or permissions.', 'wp-fusion-lite' ), wp_fusion()->crm->name );

			echo '<a id="test-connection" data-post-fields="' . esc_attr( implode( ',', $field['post_fields'] ) ) . '" class="button button-primary wpf-tip wpf-tip-right test-connection-button" data-tip="' . esc_attr( $tip ) . '">';
			echo '<span class="dashicons dashicons-update-alt"></span>';
			echo '<span class="text">' . esc_html__( 'Refresh Available Tags &amp; Fields', 'wp-fusion-lite' ) . '</span>';
			echo '</a>';

		} else {

			echo '<a id="test-connection" data-post-fields="' . esc_attr( implode( ',', $field['post_fields'] ) ) . '" class="button button-primary test-connection-button">';
			echo '<span class="dashicons dashicons-update-alt"></span>';
			echo '<span class="text">' . esc_html__( 'Connect', 'wp-fusion-lite' ) . '</span>';
			echo '</a>';

		}

	}

	/**
	 * Close out API validate field
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_api_validate_end( $id, $field ) {

		if ( ! empty( $field['desc'] ) ) {
			echo '<span class="description">' . wp_kses_post( $field['desc'] ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close CRM div.
		//echo '<table class="form-table">';

	}

	/**
	 * Shows the Authorize button on the setup tab for CRMs with OAuth
	 * authentication.
	 *
	 * @since 3.38.44
	 *
	 * @param string $id     The field ID.
	 * @param array  $field  The field parameters.
	 */
	public function show_field_oauth_authorize( $id, $field ) {

		echo '<a id="' . esc_attr( $field['slug'] ) . '-auth-btn" class="button ' . ( isset( $field['dis'] ) ? 'button-disabled' : 'button-primary' ) . '" href="' . esc_url( $field['url'] ) . '">' . sprintf( esc_html__( 'Authorize with %s', 'wp-fusion-lite' ), $field['name'] ) . '</a><br />';
		echo '<span class="description">' . sprintf( esc_html__( 'You\'ll be taken to %s to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ), $field['name'] ) . '</td>';

		if ( ! is_ssl() ) {
			echo '<p class="wpf-notice notice notice-error">' . sprintf( esc_html__( '<strong>Warning:</strong> Your site is not currently SSL secured (https://). You will not be able to connect to the %s API. Your Site Address must be set to https:// in Settings &raquo; General.', 'wp-fusion-lite' ), $field['name'] ) . '</p>';
		}

	}

	/**
	 * Shows the webhook URL field.
	 *
	 * @since 3.36.4
	 *
	 * @param string $id     The field ID.
	 * @param array  $field  The field data.
	 */
	public function show_field_webhook_url( $id, $field ) {

		echo '<input type="text" id="webhook-base-url" class="form-control" disabled value="' . esc_url( home_url( '/?access_key=' . $this->get( 'access_key' ) . '&wpf_action=' ) ) . '" />';

	}

	/**
	 * Opens EDD license field
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_edd_license_begin( $id, $field ) {
		echo '<tr valign="top">';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['title'] ) . '</label></th>';
		echo '<td>';
	}


	/**
	 * Displays EDD license field
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_edd_license( $id, $field ) {

		if ( 'valid' === $this->license_status ) {
			$type = 'password';
		} else {
			$type = 'text';
		}

		echo '<input id="' . esc_attr( $id ) . '" class="form-control" ' . ( $this->license_status == 'valid' ? 'disabled' : '' ) . ' type="' . esc_attr( $type ) . '" name="wpf_options[' . esc_attr( $id ) . ']" placeholder="' . esc_attr( $field['std'] ) . '" value="' . esc_attr( $this->{ $id } ) . '">';

		if ( 'valid' === $this->license_status ) {
			echo '<a id="edd-license" data-action="edd_deactivate" class="button">' . esc_html__( 'Deactivate License', 'wp-fusion-lite' ) . '</a>';
		} else {
			echo '<a id="edd-license" data-action="edd_activate" class="button">' . esc_html__( 'Activate License', 'wp-fusion-lite' ) . '</a>';
		}
		echo '<span class="description">' . esc_html__( 'Enter your license key for automatic updates and support.', 'wp-fusion-lite' ) . '</span>';
		echo '<div id="connection-output-edd"></div>';
	}

	/**
	 * Displays import users field
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_import_users( $id, $field ) {

		if ( empty( $this->options[ $id ] ) ) {
			$this->options[ $id ] = array();
		}

		$args = array(
			'meta_name' => 'wpf_options',
			'field_id'  => $id,
			'limit'     => 1,
			'read_only' => true,
		);

		wpf_render_tag_multiselect( $args );

		global $wp_roles;

		echo '<select class="select4" id="import_role" data-placeholder="' . esc_attr__( 'Select a user role', 'wp-fusion-lite' ) . '">';

		echo '<option></option>';

		foreach ( $wp_roles->role_names as $role => $name ) {

			// Set subscriber as default.
			echo '<option ' . selected( 'Subscriber', $name, false ) . ' value="' . esc_attr( $role ) . '">' . esc_html( $name ) . '</option>';

		}

		echo '</select>';

		echo '<a id="import-users-btn" class="button">' . esc_html__( 'Import', 'wp-fusion-lite' ) . '</a>';

	}

	/**
	 * Close import users field
	 *
	 * @access public
	 * @return mixed
	 */
	public function show_field_import_users_end() {

		echo '</td></tr></table>';
		echo '<div id="import-output"></div>';
		echo '<table class="form-table">';

	}

	/**
	 * Shows assign tags field
	 *
	 * @access public
	 * @return mixed
	 */
	public function show_field_assign_tags( $id, $field ) {

		if ( ! isset( $field['placeholder'] ) ) {
			$field['placeholder'] = __( 'Select tags', 'wp-fusion-lite' );
		}

		if ( ! isset( $field['limit'] ) ) {
			$field['limit'] = null;
		}

		$args = array(
			'setting'     => $this->get( $id, array() ),
			'meta_name'   => 'wpf_options',
			'field_id'    => $id,
			'placeholder' => $field['placeholder'],
			'disabled'    => $field['disabled'],
			'limit'       => $field['limit'],
			'read_only'   => ! empty( $field['read_only'] ) ? true : false,
		);

		wpf_render_tag_multiselect( $args );

	}

	/**
	 * Validate field assign tags
	 *
	 * @access public
	 * @return array Input
	 */

	public function validate_field_assign_tags( $input, $setting, $options ) {

		if ( ! empty( $input ) ) {
			$input = stripslashes_deep( $input );
		}

		return $input;

	}


	/**
	 * Displays a single CRM field
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_crm_field( $id, $field ) {

		$std = array( 'crm_field' => false );

		$setting = $this->get( $id, $std );

		if ( isset( $setting['crm_field'] ) ) {

			// We're in the process of changing the data storage on
			// this to not require the array format, so this check allows it to
			// work both ways for now.
			//
			// @since 3.39.4.

			$setting = $setting['crm_field'];
		}

		wpf_render_crm_field_select( $setting, 'wpf_options', $id );

	}

	/**
	 * Opening for contact fields table
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_contact_fields_begin( $id, $field ) {

		if ( ! isset( $field['disabled'] ) ) {
			$field['disabled'] = false;
		}

		echo '<tr valign="top"' . ( $field['disabled'] ? ' class="disabled"' : '' ) . '>';
		echo '<td>';
	}


	/**
	 * Displays contact fields table
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_contact_fields( $id, $field ) {

		// Lets group contact fields by integration if we can
		$field_groups = array(
			'wp' => array(
				'title'  => __( 'Standard WordPress Fields', 'wp-fusion-lite' ),
				'fields' => array(),
			),
		);

		$field_groups = apply_filters( 'wpf_meta_field_groups', $field_groups );

		$field_groups['custom'] = array(
			'title'  => __( 'Custom Field Keys (Added Manually)', 'wp-fusion-lite' ),
			'fields' => array(),
		);

		// Append ungrouped fields.
		$field_groups['extra'] = array(
			'title'  => __( 'Additional <code>wp_usermeta</code> Table Fields (For Developers)', 'wp-fusion-lite' ),
			'fields' => array(),
			'url'    => 'https://wpfusion.com/documentation/getting-started/syncing-contact-fields/#additional-fields',
		);

		/**
		 * Filters the available meta fields.
		 *
		 * @since 1.0.0
		 *
		 * @link https://wpfusion.com/documentation/filters/wpf_meta_fields
		 *
		 * @param array $fields    Tags to be removed from the user
		 */
		$field['choices'] = apply_filters( 'wpf_meta_fields', $field['choices'] );

		foreach ( $this->get( 'contact_fields', array() ) as $key => $data ) {

			if ( ! isset( $field['choices'][ $key ] ) ) {
				$field['choices'][ $key ] = $data;
			}
		}

		// Set some defaults to prevent notices, and then rebuild fields array into group structure

		foreach ( $field['choices'] as $meta_key => $data ) {

			if ( empty( $this->options[ $id ][ $meta_key ] ) || ! isset( $this->options[ $id ][ $meta_key ]['crm_field'] ) || ! isset( $this->options[ $id ][ $meta_key ]['active'] ) ) {
				$this->options[ $id ][ $meta_key ] = array(
					'active'    => false,
					'crm_field' => false,
				);
			}

			if ( ! empty( $this->options['custom_metafields'] ) && in_array( $meta_key, $this->options['custom_metafields'] ) ) {

				$field_groups['custom']['fields'][ $meta_key ] = $data;

			} elseif ( isset( $data['group'] ) && isset( $field_groups[ $data['group'] ] ) ) {

				$field_groups[ $data['group'] ]['fields'][ $meta_key ] = $data;

			} else {

				$field_groups['extra']['fields'][ $meta_key ] = $data;

			}
		}

		if ( $this->hide_additional ) {

			foreach ( $field_groups['extra']['fields'] as $key => $data ) {

				if ( ! isset( $data['active'] ) || $data['active'] != true ) {
					unset( $field_groups['extra']['fields'][ $key ] );
				}
			}
		}

		/**
		 * This filter is used in the CRM integrations to link up default field
		 * pairings. We used to use wpf_initialize_options but that doesn't work
		 * since it runs before any new fields are added by the wpf_meta_fields
		 * filter (above). This filter will likely be removed in a future update
		 * when we standardize how standard fields are managed.
		 *
		 * @since 3.37.24
		 *
		 * @param array $options The WP Fusion options.
		 */

		$this->options = apply_filters( 'wpf_initialize_options_contact_fields', $this->options );

		// These fields should be turned on by default

		if ( empty( $this->options['contact_fields']['user_email']['active'] ) ) {
			$this->options['contact_fields']['first_name']['active'] = true;
			$this->options['contact_fields']['last_name']['active']  = true;
			$this->options['contact_fields']['user_email']['active'] = true;
		}

		$field_types = array( 'text', 'date', 'multiselect', 'checkbox', 'state', 'country', 'int', 'raw' );

		$field_types = apply_filters( 'wpf_meta_field_types', $field_types );

		echo '<p>' . sprintf( esc_html__( 'For more information on these settings, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/syncing-contact-fields/" target="_blank">', '</a>' ) . '</p>';
		echo '<br />';

		// Display contact fields table.
		echo '<table id="contact-fields-table" class="table table-hover">';

		echo '<thead>';
		echo '<tr>';
		echo '<th class="sync">' . esc_html__( 'Sync', 'wp-fusion-lite' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'wp-fusion-lite' ) . '</th>';
		echo '<th>' . esc_html__( 'Meta Field', 'wp-fusion-lite' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'wp-fusion-lite' ) . '</th>';
		echo '<th>' . sprintf( esc_html__( '%s Field', 'wp-fusion-lite' ), esc_html( wp_fusion()->crm->name ) ) . '</th>';
		echo '</tr>';
		echo '</thead>';

		if ( empty( $this->options['table_headers'] ) ) {
			$this->options['table_headers'] = array();
		}

		foreach ( $field_groups as $group => $group_data ) {

			if ( empty( $group_data['fields'] ) && $group != 'extra' ) {
				continue;
			}

			// Output group section headers.
			if ( empty( $group_data['title'] ) ) {
				$group_data['title'] = 'none';
			}

			$group_slug = strtolower( str_replace( ' ', '-', $group_data['title'] ) );

			if ( ! isset( $this->options['table_headers'][ $group_slug ] ) ) {
				$this->options['table_headers'][ $group_slug ] = false;
			}

			if ( 'standard-wordpress-fields' !== $group_slug ) { // Skip the first one

				echo '<tbody class="labels">';
				echo '<tr class="group-header"><td colspan="5">';
				echo '<label for="' . esc_attr( $group_slug ) . '" class="group-header-title ' . ( $this->options['table_headers'][ $group_slug ] == true ? 'collapsed' : '' ) . '">';
				echo wp_kses_post( $group_data['title'] );

				if ( isset( $group_data['url'] ) ) {
					echo '<a class="table-header-docs-link" href="' . esc_url( $group_data['url'] ) . '" target="_blank">' . esc_html__( 'View documentation', 'wp-fusion-lite' ) . ' &rarr;</a>';
				}

				echo '<i class="fa fa-angle-down"></i><i class="fa fa-angle-up"></i></label><input type="checkbox" ' . checked( $this->options['table_headers'][ $group_slug ], true, false ) . ' name="wpf_options[table_headers][' . $group_slug . ']" id="' . $group_slug . '" data-toggle="toggle">';
				echo '</td></tr>';
				echo '</tbody>';

			}

			$table_class = 'table-collapse';

			if ( $this->options['table_headers'][ $group_slug ] == true ) {
				$table_class .= ' hide';
			}

			if ( ! empty( $group_data['disabled'] ) ) {
				$table_class .= ' disabled';
			}

			echo '<tbody class="' . esc_attr( $table_class ) . '">';

			foreach ( $group_data['fields'] as $user_meta => $data ) {

				if ( ! is_array( $data ) ) {
					$data = array();
				}

				// Allow hiding for internal fields.
				if ( isset( $data['hidden'] ) ) {
					continue;
				}

				echo '<tr' . ( $this->options[ $id ][ $user_meta ]['active'] == true ? ' class="success" ' : '' ) . '>';
				echo '<td><input class="checkbox contact-fields-checkbox"' . ( empty( $this->options[ $id ][ $user_meta ]['crm_field'] ) || 'user_email' == $user_meta ? ' disabled' : '' ) . ' type="checkbox" id="wpf_cb_' . esc_attr( $user_meta ) . '" name="wpf_options[' . esc_attr( $id ) . '][' . esc_attr( $user_meta ) . '][active]" value="1" ' . checked( $this->options[ $id ][ $user_meta ]['active'], 1, false ) . '/></td>';
				echo '<td class="wp_field_label">' . ( isset( $data['label'] ) ? esc_html( $data['label'] ) : '' );

				if ( 'user_pass' === $user_meta ) {

					$pass_message  = 'It is <em>strongly</em> recommended to leave this field disabled from sync. If it\'s enabled: <br /><br />';
					$pass_message .= '1. Real user passwords will be synced in plain text to ' . wp_fusion()->crm->name . ' when a user registers or changes their password. This is a security issue and may be illegal in your jurisdiction.<br /><br />';
					$pass_message .= '2. User passwords will be loaded from ' . wp_fusion()->crm->name . ' when webhooks are received. If not set up correctly this could result in your users\' passwords being unexpectedly reset, and/or password reset links failing to work.<br /><br />';
					$pass_message .= 'If you are importing users from ' . wp_fusion()->crm->name . ' via a webhook and wish to store their auto-generated password in a custom field, it is sufficient to check the box for <strong>Return Password</strong> on the General settings tab. You can leave this field disabled from syncing.';

					echo ' <i class="fa fa-question-circle wpf-tip wpf-tip-right" data-tip="' . esc_attr( $pass_message ) . '"></i>';
				}

				// Track custom registered fields.

				if ( ! empty( $this->options['custom_metafields'] ) && in_array( $user_meta, $this->options['custom_metafields'] ) ) {
					echo '(' . esc_html__( 'Added by user', 'wp-fusion-lite' ) . ')';
				}

				echo '</td>';
				echo '<td><span class="label label-default">' . esc_html( $user_meta ) . '</span></td>';
				echo '<td class="wp_field_type">';

				if ( ! isset( $data['type'] ) ) {
					$data['type'] = 'text';
				}

				// Allow overriding types via dropdown.
				if ( ! empty( $this->options['contact_fields'][ $user_meta ]['type'] ) ) {
					$data['type'] = $this->options['contact_fields'][ $user_meta ]['type'];
				}

				if ( ! in_array( $data['type'], $field_types ) ) {
					$field_types[] = $data['type'];
				}

				asort( $field_types );

				echo '<select class="wpf_type" name="wpf_options[' . esc_attr( $id ) . '][' . esc_attr( $user_meta ) . '][type]">';

				foreach ( $field_types as $type ) {
					echo '<option value="' . esc_attr( $type ) . '" ' . selected( $data['type'], $type, false ) . '>' . esc_html( $type ) . '</option>';
				}

				echo '<td>';

				wpf_render_crm_field_select( $this->options[ $id ][ $user_meta ]['crm_field'], 'wpf_options', 'contact_fields', $user_meta );

				// Indicate pseudo-fields that should only be synced one way.
				if ( isset( $data['pseudo'] ) ) {
					echo '<input type="hidden" name="wpf_options[' . esc_attr( $id ) . '][' . esc_attr( $user_meta ) . '][pseudo]" value="1">';
				}

				echo '</td>';

				echo '</tr>';

			}
		}

		// Add new.
		echo '<tr>';
		echo '<td><input class="checkbox contact-fields-checkbox" type="checkbox" disabled id="wpf_cb_new_field" name="wpf_options[contact_fields][new_field][active]" value="1" /></td>';
		echo '<td class="wp_field_label">Add new field</td>';
		echo '<td><input type="text" id="wpf-add-new-field" name="wpf_options[contact_fields][new_field][key]" placeholder="New Field Key" /></td>';
		echo '<td class="wp_field_type">';

		echo '<select class="wpf_type" name="wpf_options[contact_fields][new_field][type]">';

		foreach ( $field_types as $type ) {
			echo '<option value="' . esc_attr( $type ) . '" ' . selected( 'text', $type, false ) . '>' . esc_html( $type ) . '</option>';
		}

		echo '<td>';

		wpf_render_crm_field_select( false, 'wpf_options', 'contact_fields', 'new_field' );

		echo '</td>';

		echo '</tr>';

		echo '</tbody>';

		echo '</table>';

	}

	/**
	 * Open field for the integrations overview.
	 *
	 * @since 3.38.14
	 *
	 * @param string $id     The field ID.
	 * @param Array  $field  The field config.
	 */
	public function show_field_integrations_overview_begin( $id, $field ) {

		echo '<tr valign="top">';
		echo '<td style="padding: 0px;">';
	}


	/**
	 * Show the integrations overview.
	 *
	 * @since 3.38.14
	 *
	 * @param string $id     The field ID.
	 * @param Array  $field  The field config.
	 */
	public function show_field_integrations_overview( $id, $field ) {

		echo '<div id="wpf-integrations-overview">';

		echo '<p>' . sprintf( __( 'WP Fusion has detected and loaded compatibility modules for the plugins listed below. Click on each to learn how to make the most of the integration with %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name ) . '</p>';

		$integrations = array();

		foreach ( wp_fusion()->integrations as $integration ) {

			if ( isset( $integration->name ) && ! empty( $integration->docs_url ) ) {
				$integrations[ $integration->docs_url ] = $integration->name;
			}
		}

		asort( $integrations );

		foreach ( $integrations as $docs_url => $name ) {

			echo '<a class="wpf-integration" target="_blank" href="' . esc_url( $docs_url ) . '"><span class="dashicons dashicons-admin-links"></span>' . esc_html( $name ) . '</a>';

		}

		echo '</div>';

	}

	/**
	 * Displays import groups table
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_import_groups( $id, $field ) {

		if ( ! class_exists( 'WPF_User' ) ) {
			return;
		}

		$import_groups = get_option( 'wpf_import_groups' );

		if ( $import_groups == false ) {
			return;
		}

		echo '<table id="import-groups" class="table table-hover">';

		echo '<thead>';
		echo '<tr">';
		echo '<th class="import-date">Date</th>';
		echo '<th>Tag(s)</th>';
		echo '<th>Role</th>';
		echo '<th>Contacts</th>';
		echo '<th>Delete</th>';

		echo '</tr>';
		echo '</thead>';

		foreach ( $import_groups as $date => $data ) {

			// Fix for old import groups with dash in name
			if ( isset( $data['params']->{'import_users-tags'} ) ) {
				$tags = $data['params']->{'import_users-tags'};
			} elseif ( isset( $data['params']->{'import_users'} ) ) {
				$tags = $data['params']->{'import_users'};
			}

			$tag_labels = array();

			if ( isset( $tags ) ) {

				foreach ( $tags as $id ) {
					$tag_labels[] = wp_fusion()->user->get_tag_label( $id );
				}
			} else {
				$tags = 'All Contacts';
			}

			global $wp_roles;

			echo '<tr class="import-group-row">';
			echo '<td class="import-date">' . date( 'n/j/Y g:ia', absint( $date ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $tag_labels ) ) . '</td>';
			echo '<td>' . ( isset( $data['role'] ) && isset( $wp_roles->roles[ $data['role'] ] ) ? esc_html( $wp_roles->roles[ $data['role'] ]['name'] ) : 'Unknown' ) . '</td>';
			echo '<td>' . count( $data['user_ids'] ) . '</td>';
			echo '<td>';
			echo '<a class="delete-import-group button" data-delete="' . esc_attr( $date ) . '">Delete</a>';
			echo '</td>';
			echo '</tr>';

		}

		echo '</table>';
	}


	/**
	 * Displays export options
	 *
	 * @access public
	 * @return mixed
	 */

	public function show_field_export_options( $id, $field ) {
		foreach ( wp_fusion()->batch->get_export_options() as $key => $data ) {

			echo '<div class="wpf-export-option wpf-export-option-' . esc_attr( $key ) . '">';

			echo '<input class="radio export-options" process_again="' . ( isset( $data['process_again'] ) ? '1' : '0' ) . '" data-title="' . esc_attr( $data['title'] ) . '" type="radio" id="export_option_' . esc_attr( $key ) . '" name="export_options" value="' . esc_attr( $key ) . '" />';
			echo '<label for="export_option_' . esc_attr( $key ) . '">' . esc_html( $data['label'] );

			if ( isset( $data['tooltip'] ) ) {
				echo '<i class="fa fa-question-circle wpf-tip wpf-tip-right" data-tip="' . esc_attr( $data['tooltip'] ) . '"></i>';
			}

			echo '</label><br />';

			echo '</div>';

		}

		echo '<div class="skip-processed-container">';
			echo '<input class="checkbox" type="checkbox" id="skip_already_processed" name="skip_already_processed" value="1" checked />';
			echo '<label for="skip_already_processed">' . esc_html__( 'Skip already processed', 'wp-fusion-lite' ) . ' [placeholder]';
				echo '<i class="fa fa-question-circle wpf-tip" data-tip="' . esc_html__( 'If checked, [placeholder] that have already been successfully processed by WP Fusion will be excluded from the export.', 'wp-fusion-lite' ) . '"></i>';
			echo '</label><br />';
		echo '</div>';

		echo '<br /><a id="export-btn" class="button">' . esc_html__( 'Create Background Task', 'wp-fusion-lite' ) . '</a><br />';

	}

	/**
	 * Displays status/debug info.
	 *
	 * @since 3.38.33
	 *
	 * @param string $id     The field ID.
	 * @param array  $field  The field parameters.
	 */
	public function show_field_status_hooks( $id, $field ) {

		global $wp_filter;
		$hooks = array();

		foreach ( $wp_filter as $key => $hook ) {

			if ( 0 === strpos( $key, 'wpf_' ) ) {

				foreach ( $hook->callbacks as $priority => $callbacks ) {

					foreach ( $callbacks as $id => $callback ) {

						if ( is_array( $callback['function'] ) && 0 === strpos( get_class( $callback['function'][0] ), 'WPF_' ) ) {
							continue;
						}

						if ( 'wpf_get_users_with_contact_ids' === $callback['function'] ) {
							// this is in functions.php but isn't custom so doesn't need to be shown.
							continue;
						}

						$hooks[] = array(
							'hook'     => $key,
							'priority' => $priority,
							'callback' => $callback,
						);

					}
				}
			}
		}

		if ( ! empty( $hooks ) ) {

			echo '<table class="wpf-status-hooks-list">';

			echo '<tr>';
				echo '<th>' . esc_html__( 'Hook', 'wp-fusion-lite' ) . '</th>';
				echo '<th style="width: 32px;">' . esc_html__( 'Priority', 'wp-fusion-lite' ) . '</th>';
				echo '<th style="width: 32px;"></th>';
				echo '<th>' . esc_html__( 'Attached Function', 'wp-fusion-lite' ) . '</th>';
			echo '</tr>';

			foreach ( $hooks as $hook ) {

				echo '<tr>';
				echo '<td><code>' . esc_html( $hook['hook'] ) . '</code></td>';
				echo '<td>' . esc_html( $hook['priority'] ) . '</td>';

				echo '<td>&rarr;</td>';

				echo '<td>';

				if ( is_object( $hook['callback']['function'] ) ) {
					echo '<em>' . esc_html__( 'anonymous function', 'wp-fusion-lite' ) . '</em>';
				} elseif ( is_array( $hook['callback']['function'] ) ) {
					echo '<code>' . esc_html( get_class( $hook['callback']['function'][0] ) . '::' . $hook['callback']['function'][1] ) . '()</code>';
				} else {
					echo '<code>' . esc_html( $hook['callback']['function'] ) . '()</code>';
				}

				echo '</td>';

				echo '</tr>';

			}

			echo '</table>';

			echo '<span class="description">' . esc_html__( 'This table lists any custom code that might be modifying the behavior of WP Fusion.', 'wp-fusion-lite' ) . '</span>';

		} else {

			esc_html_e( 'No custom code detected.', 'wp-fusion-lite' );

		}

	}

	/**
	 * Displays webhooks test button
	 *
	 * @access public
	 * @return mixed
	 */
	public function show_field_test_webhooks( $id, $field ) {

		echo '<a class="button" data-url="' . esc_url( home_url() ) . '" id="test-webhooks-btn" href="#">' . esc_html__( 'Test Webhooks', 'wp-fusion-lite' ) . '</a>';

	}

	/**
	 * Show resync button in header
	 *
	 * @access  public
	 * @since   3.33.17
	 */
	public function header_resync_button() {

		if ( ! empty( $this->options['connection_configured'] ) ) {

			$tip = sprintf( __( 'Refresh all custom fields and available tags from %s. Does not modify any user data or permissions.', 'wp-fusion-lite' ), wp_fusion()->crm->name );

			echo '<a id="header-resync" class="button wpf-tip wpf-tip-right test-connection-button" data-tip="' . esc_attr( $tip ) . '">';
			echo '<span class="dashicons dashicons-update-alt"></span>';
			echo '<span class="text">' . esc_html__( 'Refresh Available Tags &amp; Fields', 'wp-fusion-lite' ) . '</span>';
			echo '</a>';

		}

	}

	/**
	 * Validation for contact field data
	 *
	 * @access public
	 * @return mixed
	 */
	public function validate_field_contact_fields( $input, $setting, $options_class ) {

		// Unset the empty ones.
		foreach ( $input as $field => $data ) {

			if ( 'new_field' === $field ) {
				continue;
			}

			if ( empty( $data['active'] ) && empty( $data['crm_field'] ) ) {
				unset( $input[ $field ] );
			}
		}

		// New fields.
		if ( ! empty( $input['new_field']['key'] ) ) {

			$input[ $input['new_field']['key'] ] = array(
				'active'    => true,
				'type'      => $input['new_field']['type'],
				'crm_field' => $input['new_field']['crm_field'],
			);

			// Track which ones have been custom registered.

			if ( ! isset( $options_class->options['custom_metafields'] ) ) {
				$options_class->options['custom_metafields'] = array();
			}

			if ( ! in_array( $input['new_field']['key'], $options_class->options['custom_metafields'] ) ) {
				$options_class->options['custom_metafields'][] = $input['new_field']['key'];
			}
		}

		unset( $input['new_field'] );

		$input = apply_filters( 'wpf_contact_fields_save', $input );

		return wpf_clean( $input );

	}

}
