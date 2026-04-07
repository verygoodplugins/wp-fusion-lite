<?php

class WPF_HubSpot_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_hubspot_header_begin', array( $this, 'show_field_hubspot_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ), 1 );

		// Hooks for the migration widget custom field in the Setup tab.
		add_action( 'show_field_hubspot_migration_widget_begin', array( $this, 'show_field_migration_widget_begin' ), 10, 2 );
		add_action( 'show_field_hubspot_migration_widget', array( $this, 'render_migration_settings_widget' ), 10, 2 );
		add_action( 'show_field_hubspot_migration_widget_end', array( $this, 'show_field_migration_widget_end' ), 10, 2 );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function init() {

		add_filter( 'wpf_compatibility_notices', array( $this, 'compatibility_notices' ) );
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_action( 'wpf_resetting_options', array( $this, 'uninstall_app_on_disconnect' ) );

		// Always register the AJAX migration handler when HubSpot is active.
		add_action( 'wp_ajax_wpf_hubspot_migrate_ids', array( $this, 'ajax_migrate_list_ids' ) );

		// Show the migration notice when needed and not yet completed.
		if ( get_option( 'wpf_hubspot_v3_migration_needed' )
			&& ! wpf_get_option( 'wpf_hubspot_v3_migrated' )
			&& current_user_can( 'manage_options' ) ) {
			add_action( 'admin_notices', array( $this, 'migration_notice' ) );
			add_action( 'wpf_settings_notices', array( $this, 'migration_notice' ) );
		}

		// Auto-build the safety net ID map after the v1 API cutoff date.
		add_action( 'admin_init', array( $this, 'maybe_auto_build_safety_net_map' ) );
	}


	/**
	 * Compatibility checks
	 *
	 * @access public
	 * @return array Notices
	 */
	public function compatibility_notices( $notices ) {

		if ( is_plugin_active( 'leadin/leadin.php' ) ) {

			$notices['hs-plugin'] = 'The <strong>HubSpot for WordPress</strong> plugin is active. For best compatibility with WP Fusion it\'s recommended to deactivate support for Non-HubSpot Forms at Forms &raquo; Non-HubSpot Forms <a href="' . admin_url( 'admin.php?page=leadin_settings' ) . '">in the settings</a>.';

		}

		$notices['marketing-consent'] = sprintf( __( '<strong>Heads up!</strong> If you haven\'t done so already, we recommend %1$senabling marketing contacts%2$s for the WP Fusion integration in HubSpot.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/installation-guides/how-to-connect-hubspot-to-wordpress/#marketing-contacts" target="_blank">', '</a>' );

		return $notices;
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] == 'hubspot' ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$params = array(
				'user-agent' => 'WP Fusion; ' . home_url(),
				'headers'    => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'       => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->crm->client_id,
					'client_secret' => $this->crm->client_secret,
					'redirect_uri'  => apply_filters( 'wpf_hubspot_redirect_uri', 'https://wpfusion.com/oauth/?action=wpf_get_hubspot_token' ),
					'code'          => $code,
				),
			);

			$response = wp_safe_remote_post( 'https://api.hubapi.com/oauth/v1/token', $params );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! isset( $body->access_token ) ) {

				wpf_log( 'error', 0, 'Error requesting access token: <pre>' . print_r( $body, true ) . '</pre>' );
				return false;

			} else {

				wp_fusion()->settings->set( 'hubspot_token', $body->access_token );
				wp_fusion()->settings->set( 'hubspot_refresh_token', $body->refresh_token );
				wp_fusion()->settings->set( 'crm', $this->slug );

				wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
				exit;
			}
		}
	}


	/**
	 * Loads HubSpot connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['hubspot_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // auth URL must be HTTPs, even if the site isn't.

		$auth_url = 'https://wpfusion.com/oauth/?redirect=' . urlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=hubspot' ) . '&action=wpf_get_hubspot_token&client_id=' . $this->crm->client_id;
		$auth_url = apply_filters( 'wpf_hubspot_auth_url', $auth_url );

		if ( empty( $options['hubspot_token'] ) && ! isset( $_GET['code'] ) ) {

			$new_settings['hubspot_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $auth_url,
				'name'    => $this->name,
				'slug'    => $this->slug,
			);

		} else {

			if ( ! empty( $options['connection_configured'] ) && 'hubspot' === wpf_get_option( 'crm' ) ) {
				$new_settings['hubspot_tag_type'] = array(
					'title'   => __( 'Segmentation Type', 'wp-fusion-lite' ),
					'std'     => 'lists',
					'type'    => 'radio',
					'section' => 'setup',
					'choices' => array(
						'lists'       => 'Lists',
						'multiselect' => 'Multi-Select',
					),
					'unlock'  => array(
						'multiselect' => array( 'hubspot_multiselect_field' ),
						'lists'       => array(), // No fields to unlock for lists
					),
					'desc'    => __( 'For more information, see <a href="https://wpfusion.com/documentation/crm-specific-docs/how-lists-work-with-hubspot/#using-a-multiselect-for-segmentation" target="_blank">HubSpot - How to use lists</a>.', 'wp-fusion-lite' ),
				);

				$new_settings['hubspot_multiselect_field'] = array(
					'title'       => __( 'Multi-select Field', 'wp-fusion-lite' ),
					'type'        => 'select',
					'choices'     => wpf_get_option( 'hubspot_multiselect_fields', array() ),
					'placeholder' => __( 'Select a field', 'wp-fusion-lite' ),
					'section'     => 'setup',
				);

			}

			$new_settings['hubspot_oauth_status'] = array(
				'title'       => __( 'Connection Status', 'wp-fusion-lite' ),
				'type'        => 'oauth_connection_status',
				'section'     => 'setup',
				'name'        => $this->name,
				'url'         => $auth_url,
				'post_fields' => array( 'hubspot_token', 'hubspot_refresh_token' ),
			);

			// Migration section — only relevant when Lists mode is enabled.
			if ( ! empty( $options['connection_configured'] )
				&& 'hubspot' === wpf_get_option( 'crm' )
				&& 'multiselect' !== wpf_get_option( 'hubspot_tag_type', 'lists' ) ) {

				$new_settings['hubspot_migration_header'] = array(
					'title'   => __( 'HubSpot List ID Migration', 'wp-fusion-lite' ),
					'type'    => 'heading',
					'section' => 'setup',
				);

				$new_settings['hubspot_migration_widget'] = array(
					'type'    => 'hubspot_migration_widget',
					'section' => 'setup',
				);
			}
		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads HubSpot specific settings fields
	 *
	 * @access  public
	 * @return  array Settings
	 */
	public function register_settings( $settings, $options ) {

		// Add site tracking option.
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'HubSpot Site Tracking', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'main',
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion-lite' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://knowledge.hubspot.com/articles/kcs_article/account/how-does-hubspot-track-visitors">HubSpot site tracking</a>.', 'wp-fusion-lite' ),
			'type'    => 'checkbox',
			'section' => 'main',
		);

		$site_tracking['site_tracking_id'] = array(
			'std'     => '',
			'type'    => 'hidden',
			'section' => 'main',
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'login_meta_sync', $settings, $site_tracking );

		return $settings;
	}


	/**
	 * Loads standard HubSpot field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/hubspot-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $hubspot_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $hubspot_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_hubspot_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$access_token  = sanitize_text_field( wp_unslash( $_POST['hubspot_token'] ) );
		$refresh_token = sanitize_text_field( wp_unslash( $_POST['hubspot_refresh_token'] ) );

		$connection = $this->crm->connect( $access_token, $refresh_token, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = array();
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}

		die();
	}


	/**
	 * Renders an admin notice for the v1→v3 list ID migration.
	 *
	 * Displays a dismissible banner with a "Run Migration" button and
	 * inline JavaScript that drives the multi-step AJAX migration.
	 *
	 * @since 3.47.7
	 *
	 * @return void
	 */
	public function migration_notice() {

		$nonce = wp_create_nonce( 'wpf_hubspot_migrate' );

		?>
		<div id="wpf-hubspot-migration" class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'WP Fusion', 'wp-fusion-lite' ); ?>:</strong>
				<?php esc_html_e( 'WP Fusion is currently using HubSpot\'s legacy v1 Lists API for compatibility.', 'wp-fusion-lite' ); ?>
			</p>
		<p>
			<?php esc_html_e( 'Run this migration to switch to the v3 Lists API and update saved list references before April 30, 2026.', 'wp-fusion-lite' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Please back up your database before proceeding.', 'wp-fusion-lite' ); ?></strong>
			<?php esc_html_e( 'This migration will update HubSpot list ID references stored across your posts, users, taxonomy rules, and plugin settings.', 'wp-fusion-lite' ); ?>
		</p>
		<p>
			<button id="wpf-run-migration" class="button button-primary">
				<?php esc_html_e( 'Run Migration', 'wp-fusion-lite' ); ?>
			</button>
				<span id="wpf-migration-status" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		(function() {
			var btn    = document.getElementById( 'wpf-run-migration' );
			var status = document.getElementById( 'wpf-migration-status' );
			var wrap   = document.getElementById( 'wpf-hubspot-migration' );

			if ( ! btn ) {
				return;
			}

				btn.addEventListener( 'click', function() {
					btn.disabled = true;
					status.textContent = '<?php echo esc_js( __( 'Starting migration...', 'wp-fusion-lite' ) ); ?>';
					runStep( 'reset', 0, '', false );
				});

				function runStep( step, cursor, confirmToken, confirmed ) {
					var data = new FormData();
					data.append( 'action', 'wpf_hubspot_migrate_ids' );
					data.append( '_wpnonce', '<?php echo esc_js( $nonce ); ?>' );
					data.append( 'step', step );
					data.append( 'cursor', cursor );
					if ( confirmToken ) {
						data.append( 'confirm_token', confirmToken );
					}
					if ( confirmed ) {
						data.append( 'confirmed', '1' );
					}

				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( r ) {
						if ( ! r.success ) {
							status.textContent = r.data && r.data.message
								? r.data.message
								: '<?php echo esc_js( __( 'Migration failed.', 'wp-fusion-lite' ) ); ?>';
							btn.disabled = false;
							return;
							}

							status.textContent = r.data.message || '';

							if ( r.data.requires_confirm ) {
								if ( ! window.confirm( r.data.summary || '<?php echo esc_js( __( 'Proceed with migration?', 'wp-fusion-lite' ) ); ?>' ) ) {
									status.textContent = '<?php echo esc_js( __( 'Migration cancelled.', 'wp-fusion-lite' ) ); ?>';
									btn.disabled = false;
									return;
								}

								runStep( 'confirm', 0, r.data.confirm_token || '', true );
								return;
							}

							if ( r.data.done ) {
								wrap.className = 'notice notice-success';
								wrap.innerHTML = '<p><strong><?php echo esc_js( __( 'WP Fusion', 'wp-fusion-lite' ) ); ?>:</strong> '
								+ '<?php echo esc_js( __( 'HubSpot list ID migration completed successfully.', 'wp-fusion-lite' ) ); ?>'
								+ '</p>';
								return;
							}

							runStep( r.data.next_step, r.data.cursor, '', false );
						})
					.catch( function() {
						status.textContent = '<?php echo esc_js( __( 'An unexpected error occurred.', 'wp-fusion-lite' ) ); ?>';
						btn.disabled = false;
					});
			}
		})();
		</script>
		<?php
	}


	/**
	 * AJAX dispatcher for the v1→v3 list ID migration.
	 *
	 * Routes each incoming step to the appropriate helper method and
	 * returns a JSON response with the next step, cursor, message, and
	 * a done flag.
	 *
	 * @since 3.47.7
	 *
	 * @return void
	 */
	public function ajax_migrate_list_ids() {

		check_ajax_referer( 'wpf_hubspot_migrate' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-fusion-lite' ) ) );
		}

		$step          = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';
		$cursor        = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$confirm_token = isset( $_POST['confirm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_token'] ) ) : '';
		$confirmed     = isset( $_POST['confirmed'] ) ? '1' === sanitize_text_field( wp_unslash( $_POST['confirmed'] ) ) : false;
		$id_map        = get_transient( 'wpf_hubspot_id_map' );
		$handler       = new WPF_Admin_Tag_Migration( is_array( $id_map ) ? $id_map : array() );

		switch ( $step ) {

			case 'reset':
				// Clear stale migration state so the wizard starts fresh.
				wpf_update_option( 'wpf_hubspot_v3_migrated', false );
				delete_transient( 'wpf_hubspot_orphaned_ids' );
				delete_transient( 'wpf_hubspot_id_map' );
				delete_transient( 'wpf_hubspot_migration_counts' );
				delete_transient( 'wpf_hubspot_migration_confirm_token' );
				delete_transient( 'wpf_hubspot_migration_confirmed' );
				update_option( 'wpf_hubspot_v3_migration_needed', true, false );

				wp_send_json_success(
					array(
						'next_step' => 'scan_posts',
						'cursor'    => 0,
						'message'   => __( 'Starting migration...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_posts':
				$result = $handler->scan_postmeta( $cursor );

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( 'postmeta', $result['rows_scanned'], $result['ids_found'] );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'scan_users',
							'cursor'    => 0,
							'message'   => __( 'Post meta scanned. Scanning user meta...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'scan_posts',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning post meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_users':
				$result = $handler->scan_usermeta( $cursor );

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( 'usermeta', $result['rows_scanned'], $result['ids_found'] );

				if ( 0 === $result['next_cursor'] ) {
					$tax_result = $handler->scan_taxonomy_rules();
					$this->merge_orphaned_ids( $tax_result['found_ids'] );
					$this->record_scan_progress(
						'taxonomy_rules',
						$tax_result['rows_scanned'],
						$tax_result['ids_found']
					);

					wp_send_json_success(
						array(
							'next_step' => 'scan_termmeta',
							'cursor'    => 0,
							'message'   => __( 'User meta scanned. Scanning term meta...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'scan_users',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning user meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_termmeta':
				$result = $handler->scan_termmeta( $cursor );

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( 'termmeta', $result['rows_scanned'], $result['ids_found'] );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'scan_options',
							'cursor'    => 0,
							'message'   => __( 'Term meta scanned. Scanning options...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'scan_termmeta',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning term meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_options':
				$result = $handler->scan_options( $cursor );

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( 'options', $result['rows_scanned'], $result['ids_found'] );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'scan_wpf_options',
							'cursor'    => 0,
							'message'   => __( 'Options scanned. Scanning WP Fusion settings...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'scan_options',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning options...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_wpf_options':
				$result = $handler->scan_wpf_options();

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( 'wpf_options', $result['rows_scanned'], $result['ids_found'] );

				wp_send_json_success(
					array(
						'next_step' => 'scan_custom_fluent_forms',
						'cursor'    => 0,
						'message'   => __( 'WP Fusion settings scanned. Scanning custom tables...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_custom_fluent_forms':
			case 'scan_custom_bookingpress':
			case 'scan_custom_amelia_services':
			case 'scan_custom_amelia_events':
			case 'scan_custom_wpf_meta':
			case 'scan_custom_rcp_groupmeta':
				$source_map = array(
					'scan_custom_fluent_forms'    => 'fluent_forms',
					'scan_custom_bookingpress'    => 'bookingpress',
					'scan_custom_amelia_services' => 'amelia_services',
					'scan_custom_amelia_events'   => 'amelia_events',
					'scan_custom_wpf_meta'        => 'wpf_meta',
					'scan_custom_rcp_groupmeta'   => 'rcp_groupmeta',
				);

				$next_steps = array(
					'scan_custom_fluent_forms'    => 'scan_custom_bookingpress',
					'scan_custom_bookingpress'    => 'scan_custom_amelia_services',
					'scan_custom_amelia_services' => 'scan_custom_amelia_events',
					'scan_custom_amelia_events'   => 'scan_custom_wpf_meta',
					'scan_custom_wpf_meta'        => 'scan_custom_rcp_groupmeta',
					'scan_custom_rcp_groupmeta'   => 'scan_integrations',
				);

				$source = $source_map[ $step ];
				$result = $handler->scan_custom_table( $source, $cursor );

				$this->merge_orphaned_ids( $result['found_ids'] );
				$this->record_scan_progress( $source, $result['rows_scanned'], $result['ids_found'] );

				if ( 0 === $result['next_cursor'] ) {
					$next_step = $next_steps[ $step ];
					$message   = 'scan_integrations' === $next_step
						? __( 'Custom tables scanned. Scanning integrations...', 'wp-fusion-lite' )
						: __( 'Scanning custom tables...', 'wp-fusion-lite' );

					wp_send_json_success(
						array(
							'next_step' => $next_step,
							'cursor'    => 0,
							'message'   => $message,
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => $step,
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning custom tables...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'scan_integrations':
				$result = $handler->scan_integrations( $cursor );

				if ( ! empty( $result['location'] ) ) {
					$this->merge_orphaned_ids( $result['found_ids'] );
					$this->record_scan_progress(
						$result['location'],
						$result['rows_scanned'],
						$result['ids_found']
					);
				}

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'map',
							'cursor'    => 0,
							'message'   => __( 'Scan complete. Fetching ID mapping from HubSpot...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'scan_integrations',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Scanning integrations...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'map':
				$result = $this->fetch_legacy_id_mapping();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}

				if ( 0 === $result ) {
					$this->finalize_migration();

					wp_send_json_success(
						array(
							'done'    => true,
							'message' => __( 'No legacy IDs found that need migration.', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'preflight',
						'cursor'    => 0,
						// translators: %d is the number of list IDs mapped.
						'message'   => sprintf( __( '%d list IDs mapped. Preparing preflight summary...', 'wp-fusion-lite' ), $result ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'preflight':
				$counts = $this->get_scan_progress();
				$id_map = get_transient( 'wpf_hubspot_id_map' );

				if ( empty( $id_map ) || ! is_array( $id_map ) ) {
					wp_send_json_error( array( 'message' => __( 'No migration map available.', 'wp-fusion-lite' ) ) );
				}

				$total_rows = 0;
				$map_count  = count( $id_map );
				$orphaned   = get_transient( 'wpf_hubspot_orphaned_ids' );
				$total_ids  = is_array( $orphaned ) ? count( $orphaned ) : 0;

				foreach ( $counts as $location => $data ) {
					$total_rows                         += absint( $data['rows_scanned'] );
					$counts[ $location ]['rows_scanned'] = absint( $data['rows_scanned'] );
					$counts[ $location ]['ids_found']    = absint( $data['ids_found'] );
				}

				$token = wp_generate_password( 16, false, false );
				set_transient( 'wpf_hubspot_migration_confirm_token', $token, HOUR_IN_SECONDS );

				/* translators: 1: mapped ID count. 2: rows to update. */
				$summary_template = __( 'Dry run complete. %1$d IDs can be migrated across approximately %2$d rows. Continue?', 'wp-fusion-lite' );
				$summary          = sprintf( $summary_template, $map_count, $total_rows );

				wp_send_json_success(
					array(
						'next_step'            => 'confirm',
						'cursor'               => 0,
						'requires_confirm'     => true,
						'confirm_token'        => $token,
						'summary'              => $summary,
						'counts_by_location'   => $counts,
						'total_ids_found'      => $total_ids,
						'total_rows_to_update' => $total_rows,
						'message'              => __( 'Dry run complete. Confirmation required.', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'confirm':
				if ( ! $confirmed ) {
					wp_send_json_error( array( 'message' => __( 'Migration confirmation required.', 'wp-fusion-lite' ) ) );
				}

				$stored_token = get_transient( 'wpf_hubspot_migration_confirm_token' );

				if ( empty( $confirm_token ) || empty( $stored_token ) || $confirm_token !== $stored_token ) {
					wp_send_json_error( array( 'message' => __( 'Invalid confirmation token.', 'wp-fusion-lite' ) ) );
				}

				set_transient( 'wpf_hubspot_migration_confirmed', true, HOUR_IN_SECONDS );

				wp_send_json_success(
					array(
						'next_step' => 'update_posts',
						'cursor'    => 0,
						'message'   => __( 'Confirmation received. Updating post meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_posts':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$next_cursor = $handler->update_postmeta( $cursor );

				if ( 0 === $next_cursor ) {
					wp_send_json_success(
						array(
							'next_step' => 'update_users',
							'cursor'    => 0,
							'message'   => __( 'Post meta updated. Updating user meta...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'update_posts',
						'cursor'    => $next_cursor,
						'message'   => __( 'Updating post meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_users':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$next_cursor = $handler->update_usermeta( $cursor );

				if ( 0 === $next_cursor ) {
					wp_send_json_success(
						array(
							'next_step' => 'update_termmeta',
							'cursor'    => 0,
							'message'   => __( 'User meta updated. Updating term meta...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'update_users',
						'cursor'    => $next_cursor,
						'message'   => __( 'Updating user meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_termmeta':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$result = $handler->update_termmeta_batch( $cursor );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'update_options',
							'cursor'    => 0,
							'message'   => __( 'Term meta updated. Updating options...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'update_termmeta',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Updating term meta...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_options':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$result = $handler->update_options_batch( $cursor );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'update_wpf_options',
							'cursor'    => 0,
							'message'   => __( 'Options updated. Updating WP Fusion settings...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'update_options',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Updating options...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_wpf_options':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$handler->update_wpf_options();

				wp_send_json_success(
					array(
						'next_step' => 'update_custom_fluent_forms',
						'cursor'    => 0,
						'message'   => __( 'WP Fusion settings updated. Updating custom tables...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_custom_fluent_forms':
			case 'update_custom_bookingpress':
			case 'update_custom_amelia_services':
			case 'update_custom_amelia_events':
			case 'update_custom_wpf_meta':
			case 'update_custom_rcp_groupmeta':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$source_map = array(
					'update_custom_fluent_forms'    => 'fluent_forms',
					'update_custom_bookingpress'    => 'bookingpress',
					'update_custom_amelia_services' => 'amelia_services',
					'update_custom_amelia_events'   => 'amelia_events',
					'update_custom_wpf_meta'        => 'wpf_meta',
					'update_custom_rcp_groupmeta'   => 'rcp_groupmeta',
				);

				$next_steps = array(
					'update_custom_fluent_forms'    => 'update_custom_bookingpress',
					'update_custom_bookingpress'    => 'update_custom_amelia_services',
					'update_custom_amelia_services' => 'update_custom_amelia_events',
					'update_custom_amelia_events'   => 'update_custom_wpf_meta',
					'update_custom_wpf_meta'        => 'update_custom_rcp_groupmeta',
					'update_custom_rcp_groupmeta'   => 'update_integrations',
				);

				$source = $source_map[ $step ];
				$result = $handler->update_custom_table_batch( $source, $cursor );

				if ( 0 === $result['next_cursor'] ) {
					$next_step = $next_steps[ $step ];
					$message   = 'update_integrations' === $next_step
						? __( 'Custom tables updated. Updating integrations...', 'wp-fusion-lite' )
						: __( 'Updating custom tables...', 'wp-fusion-lite' );

					wp_send_json_success(
						array(
							'next_step' => $next_step,
							'cursor'    => 0,
							'message'   => $message,
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => $step,
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Updating custom tables...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'update_integrations':
				if ( ! $this->is_migration_confirmed() ) {
					wp_send_json_error( array( 'message' => __( 'Migration must be confirmed before writing.', 'wp-fusion-lite' ) ) );
				}

				$result = $handler->update_integrations_batch( $cursor );

				if ( 0 === $result['next_cursor'] ) {
					wp_send_json_success(
						array(
							'next_step' => 'finalize',
							'cursor'    => 0,
							'message'   => __( 'All settings updated. Finalizing...', 'wp-fusion-lite' ),
						)
					);
				}

				wp_send_json_success(
					array(
						'next_step' => 'update_integrations',
						'cursor'    => $result['next_cursor'],
						'message'   => __( 'Updating integrations...', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			case 'finalize':
				$this->finalize_migration();
				delete_transient( 'wpf_hubspot_migration_confirmed' );
				delete_transient( 'wpf_hubspot_migration_confirm_token' );

				wp_send_json_success(
					array(
						'done'    => true,
						'message' => __( 'Migration completed successfully.', 'wp-fusion-lite' ),
					)
				);
				break; // @phpstan-ignore deadCode.unreachable

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown migration step.', 'wp-fusion-lite' ) ) );
		}
	}


	/**
	 * Merges newly found orphaned IDs into the migration transient.
	 *
	 * @since 3.47.8
	 *
	 * @param array<string, bool> $new_ids Newly found IDs keyed by value.
	 * @return void
	 */
	private function merge_orphaned_ids( $new_ids ) {

		if ( empty( $new_ids ) ) {
			return;
		}

		$orphaned_ids = get_transient( 'wpf_hubspot_orphaned_ids' );

		if ( ! is_array( $orphaned_ids ) ) {
			$orphaned_ids = array();
		}

		foreach ( $new_ids as $tag_id => $found ) {
			if ( true === $found ) {
				$orphaned_ids[ (string) $tag_id ] = true;
			}
		}

		set_transient( 'wpf_hubspot_orphaned_ids', $orphaned_ids, HOUR_IN_SECONDS );
	}


	/**
	 * Records scan progress per location for dry-run reporting.
	 *
	 * @since 3.47.8
	 *
	 * @param string $location Location key.
	 * @param int    $rows     Rows scanned in this batch.
	 * @param int    $ids      Legacy IDs found in this batch.
	 * @return void
	 */
	private function record_scan_progress( $location, $rows, $ids ) {

		$counts = get_transient( 'wpf_hubspot_migration_counts' );

		if ( ! is_array( $counts ) ) {
			$counts = array();
		}

		if ( ! isset( $counts[ $location ] ) || ! is_array( $counts[ $location ] ) ) {
			$counts[ $location ] = array(
				'rows_scanned' => 0,
				'ids_found'    => 0,
			);
		}

		$counts[ $location ]['rows_scanned'] += absint( $rows );
		$counts[ $location ]['ids_found']    += absint( $ids );

		set_transient( 'wpf_hubspot_migration_counts', $counts, HOUR_IN_SECONDS );
	}


	/**
	 * Gets accumulated scan progress.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string, array{rows_scanned:int, ids_found:int}>
	 */
	private function get_scan_progress() {

		$counts = get_transient( 'wpf_hubspot_migration_counts' );

		return is_array( $counts ) ? $counts : array();
	}


	/**
	 * Checks if the migration has been confirmed in this run.
	 *
	 * @since 3.47.8
	 *
	 * @return bool
	 */
	private function is_migration_confirmed() {
		return (bool) get_transient( 'wpf_hubspot_migration_confirmed' );
	}


	/**
	 * Fetches the legacy-to-v3 ID mapping from HubSpot.
	 *
	 * Reads orphaned IDs from the transient, sends them to the
	 * HubSpot idmapping endpoint in chunks of 10 000, and stores the
	 * resulting map in a transient for the update steps.
	 *
	 * @since 3.47.7
	 *
	 * @return int|WP_Error Number of mapped IDs, or WP_Error on failure.
	 */
	private function fetch_legacy_id_mapping() {

		$orphaned_ids = get_transient( 'wpf_hubspot_orphaned_ids' );

		if ( empty( $orphaned_ids ) || ! is_array( $orphaned_ids ) ) {
			return 0;
		}

		// The API expects string IDs.
		$legacy_ids = array_map( 'strval', array_keys( $orphaned_ids ) );

		if ( ! $this->crm->params ) {
			$this->crm->get_params();
		}

		if ( empty( $this->crm->params ) ) {
			return new WP_Error( 'auth', __( 'Unable to connect to HubSpot.', 'wp-fusion-lite' ) );
		}

		$v3_lists = $this->crm->sync_tags_v3();

		if ( is_wp_error( $v3_lists ) ) {
			return $v3_lists;
		}

		$v3_list_ids = array_map( 'strval', array_keys( $v3_lists ) );
		$id_map      = array();

		foreach ( array_chunk( $legacy_ids, 10000 ) as $chunk ) {

			$params           = $this->crm->params;
			$params['body']   = wp_json_encode( $chunk );
			$params['method'] = 'POST';

			$response = wp_remote_request( 'https://api.hubapi.com/crm/v3/lists/idmapping', $params );

			if ( is_wp_error( $response ) ) {
				wpf_log( 'error', 0, 'HubSpot v1→v3 ID mapping API error: ' . $response->get_error_message() );
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $body['legacyListIdsToIdsMapping'] ) || ! is_array( $body['legacyListIdsToIdsMapping'] ) ) {
				continue;
			}

			foreach ( $body['legacyListIdsToIdsMapping'] as $mapping ) {
				$legacy_id = (string) $mapping['legacyListId'];
				$new_id    = (string) $mapping['listId'];

				// Skip self-mappings (no change needed).
				if ( $legacy_id === $new_id ) {
					continue;
				}

				// Skip if the legacy ID already exists as a valid v3 list.
				// This prevents corrupting settings that reference the
				// v3 list when a v1 list shares the same numeric ID.
				if ( in_array( $legacy_id, $v3_list_ids, true ) ) {
					continue;
				}

				// Only map to IDs that exist in live v3 list results.
				if ( in_array( $new_id, $v3_list_ids, true ) ) {
					$id_map[ $legacy_id ] = $new_id;
				}
			}
		}

		if ( empty( $id_map ) ) {
			return 0;
		}

		set_transient( 'wpf_hubspot_id_map', $id_map, HOUR_IN_SECONDS );

		return count( $id_map );
	}

	/**
	 * Finalizes the v1→v3 list ID migration.
	 *
	 * Updates taxonomy rules with new IDs, cleans up transients, and
	 * sets the migration-complete flag.
	 *
	 * @since 3.47.7
	 *
	 * @return void
	 */
	private function finalize_migration() {

		$id_map = get_transient( 'wpf_hubspot_id_map' );

		// Update taxonomy rules if we have a mapping.
		if ( ! empty( $id_map ) && is_array( $id_map ) ) {
			$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

			if ( ! empty( $taxonomy_rules ) && is_array( $taxonomy_rules ) ) {
					$tag_sub_keys = WPF_Admin_Tag_Migration::get_taxonomy_rule_tag_keys();
				$changed          = false;

				foreach ( $taxonomy_rules as $term_id => $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}

					foreach ( $tag_sub_keys as $tag_key ) {
						if ( empty( $rule[ $tag_key ] ) || ! is_array( $rule[ $tag_key ] ) ) {
							continue;
						}

						foreach ( $rule[ $tag_key ] as $i => $tag_id ) {
							if ( isset( $id_map[ (string) $tag_id ] ) ) {
								$taxonomy_rules[ $term_id ][ $tag_key ][ $i ] = $id_map[ (string) $tag_id ];
								$changed                                      = true;
							}
						}
					}
				}

				if ( $changed ) {
					update_option( 'wpf_taxonomy_rules', $taxonomy_rules );
				}
			}
		}

		// Snapshot current available_tags before overwriting with v3.
		$prev_tags = wpf_get_option( 'available_tags', array() );

		if ( ! empty( $prev_tags ) ) {
			wpf_update_option( 'wpf_available_tags_prev', $prev_tags );
		}

		// Store the ID map permanently for runtime translation.
		$id_map = get_transient( 'wpf_hubspot_id_map' );

		if ( ! empty( $id_map ) && is_array( $id_map ) ) {
			wpf_update_option( 'wpf_tag_id_map', $id_map );
		}

		// Cleanup transients.
		delete_transient( 'wpf_hubspot_orphaned_ids' );
		delete_transient( 'wpf_hubspot_id_map' );

		wpf_update_option( 'wpf_hubspot_lists_api_mode', 'v3' );
		delete_option( 'wpf_hubspot_v3_migration_needed' );
		wpf_update_option( 'wpf_hubspot_v3_migrated', true );

		$available_tags = $this->crm->sync_tags_v3();

		if ( ! is_wp_error( $available_tags ) ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		} else {
			wpf_log(
				'error',
				0,
				'HubSpot v3 list sync failed after migration: ' . $available_tags->get_error_message()
			);
		}

		wpf_log( 'notice', 0, 'HubSpot v1→v3 list ID migration completed.' );
	}


	/**
	 * Conditionally auto-builds the safety-net ID map after the v1 cutoff date.
	 *
	 * Called on admin_init. Before April 30, 2026, users must opt in. After that
	 * date we silently build the map in the background so the runtime translation
	 * layer always has something to fall back on.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function maybe_auto_build_safety_net_map() {

		// Only act when HubSpot is the active CRM.
		if ( 'hubspot' !== wpf_get_option( 'crm' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip if migration is already fully complete.
		if ( wpf_get_option( 'wpf_hubspot_v3_migrated' ) ) {
			return;
		}

		// Before the cutoff, users must opt in via the migration notice.
		$cutoff = strtotime( '2026-04-30' );

		if ( time() < $cutoff ) {
			return;
		}

		// After the cutoff, automatically build the safety-net map if missing.
		if ( ! wpf_get_option( 'wpf_tag_id_map' ) ) {
			$this->auto_build_safety_net_map();
		}
	}


	/**
	 * Builds the safety-net ID map by calling the HubSpot idmapping API.
	 *
	 * Fetches the v1→v3 mapping for every list currently stored in
	 * available_tags, then saves it to the wpf_tag_id_map option so the
	 * WPF_Tag_Migration runtime layer can translate legacy IDs on the fly.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	private function auto_build_safety_net_map() {

		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( empty( $available_tags ) ) {
			return;
		}

		$v1_ids = array_keys( $available_tags );

		$id_map = $this->crm->get_v3_list_ids( $v1_ids );

		if ( is_wp_error( $id_map ) || empty( $id_map ) ) {
			wpf_log( 'notice', 0, 'HubSpot safety-net map auto-build failed: ' . ( is_wp_error( $id_map ) ? $id_map->get_error_message() : 'empty response' ) );
			return;
		}

		wpf_update_option( 'wpf_tag_id_map', $id_map );

		wpf_log( 'notice', 0, 'HubSpot safety-net ID map built automatically (' . count( $id_map ) . ' entries).' );
	}


	/**
	 * Renders the opening wrapper for the migration widget field.
	 *
	 * @since 3.47.8
	 *
	 * @param string $id The field ID.
	 * @param array  $field The field configuration.
	 * @return void
	 */
	public function show_field_migration_widget_begin( $id = '', $field = array() ) {
		unset( $id, $field );
		echo '<tr valign="top">';
		echo '<td colspan="2" class="wpf-migration-widget-cell">';
	}


	/**
	 * Renders the closing wrapper for the migration widget field.
	 *
	 * @since 3.47.8
	 *
	 * @param string $id The field ID.
	 * @param array  $field The field configuration.
	 * @return void
	 */
	public function show_field_migration_widget_end( $id = '', $field = array() ) {
		unset( $id, $field );
		echo '</td>';
		echo '</tr>';
	}


	/**
	 * Renders the migration status widget inside the HubSpot Setup tab.
	 *
	 * Displays status and a "Run Migration" button so users who have already
	 * dismissed the admin notice can still trigger the wizard from Settings.
	 *
	 * @since 3.47.8
	 *
	 * @param string $id The field ID.
	 * @param array  $field The field configuration.
	 * @return void
	 */
	public function render_migration_settings_widget( $id = '', $field = array() ) {
		unset( $id, $field );

		$migrated = wpf_get_option( 'wpf_hubspot_v3_migrated' );
		$nonce    = wp_create_nonce( 'wpf_hubspot_migrate' );

		if ( $migrated ) {
			echo '<p class="description">';
			esc_html_e( 'Migration complete. Your site is using the HubSpot v3 Lists API.', 'wp-fusion-lite' );
			echo '</p>';
			return;
		}

		?>
		<div id="wpf-hubspot-migration-settings">
			<p>
				<?php esc_html_e( 'Your site has legacy HubSpot v1 list IDs stored in the database that need to be updated to v3 IDs.', 'wp-fusion-lite' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Please back up your database before proceeding.', 'wp-fusion-lite' ); ?></strong>
				<?php esc_html_e( 'This migration will update list ID references across all posts, users, taxonomy rules, and plugin settings.', 'wp-fusion-lite' ); ?>
			</p>
			<p>
				<button type="button" id="wpf-run-migration-settings" class="button button-primary">
					<?php esc_html_e( 'Run Migration', 'wp-fusion-lite' ); ?>
				</button>
				<span id="wpf-migration-settings-status" style="margin-left:10px;"></span>
			</p>
		</div>
		<script>
		(function() {
			var btn    = document.getElementById( 'wpf-run-migration-settings' );
			var status = document.getElementById( 'wpf-migration-settings-status' );
			var wrap   = document.getElementById( 'wpf-hubspot-migration-settings' );

			if ( ! btn ) {
				return;
			}

				btn.addEventListener( 'click', function() {
					btn.disabled      = true;
					status.textContent = '<?php echo esc_js( __( 'Starting migration...', 'wp-fusion-lite' ) ); ?>';
					runStep( 'reset', 0, '', false );
				});

				function runStep( step, cursor, confirmToken, confirmed ) {
					var data = new FormData();
					data.append( 'action', 'wpf_hubspot_migrate_ids' );
					data.append( '_wpnonce', '<?php echo esc_js( $nonce ); ?>' );
					data.append( 'step', step );
					data.append( 'cursor', cursor );
					if ( confirmToken ) {
						data.append( 'confirm_token', confirmToken );
					}
					if ( confirmed ) {
						data.append( 'confirmed', '1' );
					}

				fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( r ) {
						if ( ! r.success ) {
							status.textContent = r.data && r.data.message
								? r.data.message
								: '<?php echo esc_js( __( 'Migration failed.', 'wp-fusion-lite' ) ); ?>';
							btn.disabled = false;
							return;
							}

							status.textContent = r.data.message || '';

							if ( r.data.requires_confirm ) {
								if ( ! window.confirm( r.data.summary || '<?php echo esc_js( __( 'Proceed with migration?', 'wp-fusion-lite' ) ); ?>' ) ) {
									status.textContent = '<?php echo esc_js( __( 'Migration cancelled.', 'wp-fusion-lite' ) ); ?>';
									btn.disabled = false;
									return;
								}

								runStep( 'confirm', 0, r.data.confirm_token || '', true );
								return;
							}

							if ( r.data.done ) {
								wrap.innerHTML = '<p class="description" style="color:#46b450;font-weight:bold;">'
									+ '<?php echo esc_js( __( 'Migration completed successfully.', 'wp-fusion-lite' ) ); ?>'
								+ '</p>';
								return;
							}

							runStep( r.data.next_step, r.data.cursor, '', false );
						})
					.catch( function() {
						status.textContent = '<?php echo esc_js( __( 'An unexpected error occurred.', 'wp-fusion-lite' ) ); ?>';
						btn.disabled = false;
					});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Uninstalls the HubSpot app when the user disconnects.
	 *
	 * @since x.x.x
	 *
	 * @param array $options The options being reset.
	 */
	public function uninstall_app_on_disconnect( $options ) {

		if ( ! empty( $options['hubspot_token'] ) ) {

			$result = $this->crm->uninstall_app();

			if ( is_wp_error( $result ) ) {
				wpf_log( 'error', 0, 'Failed to uninstall HubSpot app on disconnect: ' . $result->get_error_message() );
			} else {
				wpf_log( 'info', 0, 'HubSpot app successfully uninstalled during disconnect.' );
			}
		}
	}
}
