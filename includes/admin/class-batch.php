<?php

/**
 * Runs batch processing jobs (like imports, exports, etc)
 */
class WPF_Batch {

	/**
	 * @var WPF_Background_Process
	 */
	public $process;

	/**
	 * @var WPF_Async_Process
	 */
	public $async;

	/**
	 * Get things started
	 *
	 * @since 3.0
	 * @return void
	 */
	public function __construct() {

		// Status monitor.
		add_action( 'wpf_settings_notices', array( $this, 'batch_status_bar' ) );

		// Global handlers.
		add_action( 'wp_ajax_wpf_batch_init', array( $this, 'batch_init' ), 10, 2 );
		add_action( 'wp_ajax_wpf_batch_status', array( $this, 'batch_status' ) );
		add_action( 'wp_ajax_wpf_batch_cancel', array( $this, 'batch_cancel' ) );

		// Error handling.
		add_action( 'wpf_handle_log', array( $this, 'handle_error' ), 10, 5 );

		// Export users.
		add_filter( 'wpf_batch_users_register_init', array( $this, 'users_register_init' ) );
		add_action( 'wpf_batch_users_register', array( $this, 'users_register_step' ) );

		// Tag all users with registration tags.
		add_filter( 'wpf_batch_users_register_tags_init', array( $this, 'users_sync_init' ) );
		add_action( 'wpf_batch_users_register_tags', array( $this, 'users_register_tags_step' ) );

		// Push user meta.
		add_filter( 'wpf_batch_users_meta_init', array( 'WPF_User', 'get_users_with_contact_ids' ) );
		add_action( 'wpf_batch_users_meta', array( $this, 'users_meta_step' ) );

		// Pull user meta.
		add_filter( 'wpf_batch_pull_users_meta_init', array( 'WPF_User', 'get_users_with_contact_ids' ) );
		add_action( 'wpf_batch_pull_users_meta', array( $this, 'pull_users_meta_step' ) );

		// Sync users (just CIDs).
		add_filter( 'wpf_batch_users_cid_sync_init', array( $this, 'users_sync_init' ) );
		add_action( 'wpf_batch_users_cid_sync', array( $this, 'users_cid_sync_step' ) );

		// Sync users (just tags).
		add_filter( 'wpf_batch_users_tags_sync_init', array( 'WPF_User', 'get_users_with_contact_ids' ) );
		add_action( 'wpf_batch_users_tags_sync', array( $this, 'users_tags_sync_step' ) );

		// Sync users.
		add_filter( 'wpf_batch_users_sync_init', array( $this, 'users_sync_init' ) );
		add_action( 'wpf_batch_users_sync', array( $this, 'users_sync_step' ) );

		// Import contacts.
		add_filter( 'wpf_batch_import_users_init', array( $this, 'import_users_init' ) );
		add_filter( 'wpf_batch_import_users_args', array( $this, 'import_users_args' ), 10, 2 );
		add_action( 'wpf_batch_import_users', array( $this, 'import_users_step' ), 10, 2 );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$this->includes();
		$this->init();
	}

	/**
	 * Enqueue scripts.
	 *
	 * @since 3.44.6
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();

		if ( $screen && ( 'settings_page_wpf-settings' === $screen->id || 'users' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id ) ) {
			$localize_data = array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpf_settings_nonce' ),
				'strings' => array(
					'batchErrorsEncountered'  => __( 'errors encountered. Check the logs for more details.', 'wp-fusion-lite' ),
					'batchOperationComplete'  => sprintf(
						'<strong>%s</strong> %s...',
						__( 'Batch operation complete.', 'wp-fusion-lite' ),
						__( 'Terminating...', 'wp-fusion-lite' )
					),
					'backgroundWorkerBlocked' => __( 'The background worker is being blocked by your server. Starting alternate (slower) method. Please do not refresh the page until the process completes.', 'wp-fusion-lite' ),
					'processing'              => __( 'Processing', 'wp-fusion-lite' ),
					'beginningProcessing'     => sprintf( __( 'Beginning %s processing', 'wp-fusion-lite' ), 'ACTIONTITLE' ),
					'startBatchWarning'       => __( "Heads Up: These background operations can potentially alter a lot of data and are irreversible. If you're not sure you need to run one, please contact our support.\n\nIf you want to resynchronize the dropdowns of available tags and fields, click \"Resynchronize Tags & Fields\" from the setup tab.\n\nPress OK to proceed or Cancel to cancel.", 'wp-fusion-lite' ),
					'atleastOneBatch'         => __( 'Please select at least one', 'wp-fusion-lite' ),
				),
			);

			wp_enqueue_script( 'wpf-batch', WPF_DIR_URL . 'assets/js/wpf-batch.js', array( 'jquery' ), WP_FUSION_VERSION, true );
			wp_localize_script( 'wpf-batch', 'wpf_batch_ajax', $localize_data );
		}
	}

	/**
	 * Get core batch options
	 *
	 * @since 3.33.16
	 * @return array Options.
	 */
	public function get_export_options() {

		$options = array(
			'users_cid_sync'      => array(
				'label'   => __( 'Resync contact IDs for every user', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'Looks up every WordPress user by email address in %s, and updates their cached contact ID. Does not modify any tags or trigger any automated enrollments.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
			'users_tags_sync'     => array(
				'label'   => __( 'Resync tags for every user', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'Updates tags for all WordPress users who already have a saved contact ID, and triggers any automated enrollments via linked tags.', 'wp-fusion-lite' ) ),
			),
			'users_sync'          => array(
				'label'   => __( 'Resync contact IDs and tags for every user', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'All WordPress users will have their contact IDs checked / updated based on email address and tags will be loaded from their %s contact record. Will trigger automated enrollments based on linked tags.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
			'users_register'      => array(
				'label'   => __( 'Export users', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'Queries any WordPress users without a saved %s contact ID, and either creates new contacts or updates existing ones based on email address. Any registration tags will be applied to new contacts. Any existing contacts will have their tags loaded and cached.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
			'users_register_tags' => array(
				'label'   => __( 'Apply registration tags', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => __( 'For every registered user on the site, apply the tags configured in the <strong>Assign Tags</strong> setting on the General options tab.<br /><br />Does not create any new contact records.', 'wp-fusion-lite' ),
			),
			'users_meta'          => array(
				'label'   => __( 'Push user meta', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'All WordPress users with a contact record will have their meta data pushed to %s, overriding any data on the contact record with the values from WordPress.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
			'pull_users_meta'     => array(
				'label'   => __( 'Pull user meta', 'wp-fusion-lite' ),
				'title'   => __( 'Users', 'wp-fusion-lite' ),
				'tooltip' => sprintf( __( 'All WordPress users with a contact record will have their meta data loaded from %s, overriding any data in their user meta with the values from their contact record.', 'wp-fusion-lite' ), wp_fusion()->crm->name ),
			),
		);

		return apply_filters( 'wpf_export_options', $options );
	}

	/**
	 * Get the title of a batch operation, by ID
	 *
	 * @since 3.35.7
	 * @return string Name of the batch operation
	 */
	public function get_operation_title( $id ) {

		$operations = $this->get_export_options();

		if ( isset( $operations[ $id ] ) ) {
			return $operations[ $id ]['title'];
		} else {
			return false;
		}
	}

	/**
	 * Include required files
	 *
	 * @since 3.0
	 * @return void
	 */
	public function includes() {

		require_once WPF_DIR_PATH . 'includes/admin/batch/class-async-request.php';
		require_once WPF_DIR_PATH . 'includes/admin/batch/class-background-process.php';
	}

	/**
	 * Initialize batch processing library.
	 *
	 * @since 3.0
	 * @return void
	 */
	public function init() {

		$this->process = new WPF_Background_Process();
	}


	/**
	 * Show batch status bar at the top of settings page
	 *
	 * @since 3.0
	 * @return mixed
	 */
	public function batch_status_bar() {

		$active = false;

		$status = array(
			'total'     => 0,
			'remaining' => 0,
		);

		$keys = $this->process->get_keys();

		if ( ! empty( $keys ) ) {
			$status = $this->process->get_status( $keys[0] );
			$active = true;
		}

		if ( isset( $_GET['wpf-cancel-batch'] ) ) {

			echo '<pre>Cancelled:<br/>';
			print_r( $keys );
			echo '</pre>';

			foreach ( $keys as $key ) {
				$this->process->cancel_process( $key );
			}

			delete_transient( 'wpf_background_process_process_lock' );

			return;

		}

		// Try and restart it if it's stalled.
		if ( ! $this->process->is_queue_empty() && ! $this->process->is_process_running() ) {
			$this->process->dispatch();
		}

		if ( ! is_array( $status ) ) {
			$status = array(
				'total'     => 0,
				'remaining' => 0,
				'key'       => 0,
			);
		}

		$total     = absint( $status['total'] );
		$remaining = absint( $status['remaining'] );
		$done      = $total - $remaining;

		echo '<div id="wpf-batch-status" class="notice notice-info ' . ( $active ? 'active' : 'hidden' ) . '" ' . ( $active ? 'data-remaining="' . esc_attr( $remaining ) . '"' : '' ) . ' ' . ( $active ? 'data-key="' . esc_attr( $status['key'] ) . '"' : '' ) . '>';
		echo '<p><span class="dashicons dashicons-update-alt wpf-spin"></span><span class="title"><strong>' . esc_html__( 'Background operation running:', 'wp-fusion-lite' ) . '</strong></span> <span class="status">';

		$title = 'records';

		// Get the title from the status.
		if ( ! empty( $status['next_step'] ) ) {
			$title = $this->get_operation_title( $status['next_step'][0] );
		}

		if ( $active ) {
			echo esc_html__( 'Processing', 'wp-fusion-lite' ) . esc_html( ' ' . $done . ' / ' . $total . ' ' . $title );
		}

		echo '</span><a id="cancel-batch" class="button button-small">' . esc_html__( 'Cancel', 'wp-fusion-lite' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Initialize batch process and return count of objects to be processed.
	 *
	 * @since 3.0
	 * @return int Count
	 */
	public function batch_init( $hook = false, $args = array() ) {

		if ( wp_doing_ajax() ) {
			check_ajax_referer( 'wpf_settings_nonce' );
		}

		if ( isset( $_POST['hook'] ) ) {
			$hook = sanitize_key( $_POST['hook'] );
		}

		if ( isset( $_POST['args'] ) && is_array( $_POST['args'] ) ) {
			$args = array_map( 'sanitize_text_field', wp_unslash( $_POST['args'] ) );
		}

		if ( ! empty( $_POST['object_ids'] ) && is_array( $_POST['object_ids'] ) ) {
			// Allow passing specific records, for example via the Users or Orders screens.
			$objects = array_map( 'sanitize_text_field', wp_unslash( $_POST['object_ids'] ) );
		} elseif ( isset( $args['object_ids'] ) && is_array( $args['object_ids'] ) ) {
			// Allow passing specific records, for example via the Users or Orders screens.
			$objects = array_map( 'sanitize_text_field', $args['object_ids'] );
			unset( $args['object_ids'] );
		} else {
			$objects = apply_filters( 'wpf_batch_' . $hook . '_init', $args );
		}

		$objects = apply_filters( 'wpf_batch_objects', $objects, $args );

		$args = apply_filters( 'wpf_batch_' . $hook . '_args', $args, $objects );
		$args = apply_filters( 'wpf_batch_args', $args, $objects );

		if ( empty( $objects ) ) {

			if ( wp_doing_ajax() ) {
				wp_send_json_success( $objects );
			} else {
				return false;
			}
		}

		reset( $objects ); // fix the pointer in cases where objects might have been removed by the filter.

		$operations = $this->get_export_options();

		// This one we'll hardcode since it doesn't show up in the usual list.
		$operations['import_users'] = array(
			'label' => 'Import users',
			'title' => 'Contacts',
		);

		wpf_log(
			'info',
			wpf_get_current_user_id(),
			sprintf(
				// translators: 1: Operation title, 2: Count of records to be processed, 3: Type of records being processed ("users", "orders", etc).
				__( 'Beginning %1$s batch operation on %2$d %3$s.', 'wp-fusion-lite' ),
				'<strong>' . $operations[ $hook ]['label'] . '</strong>',
				count( $objects ),
				strtolower( $operations[ $hook ]['title'] )
			),
			array( 'source' => 'batch-process' )
		);

		// Int IDs are smaller in the DB than strings, but sometimes we'll still need to use strings (i.e. Drip subscriber IDs).
		if ( is_numeric( $objects[0] ) ) {
			$objects = array_map( 'intval', $objects );
		} else {
			$objects = array_map( 'sanitize_text_field', $objects );
		}

		if ( isset( $args['skip_processed'] ) ) {
			// We only need this for the initial query, can remove it now and save some space.
			unset( $args['skip_processed'] );
		}

		foreach ( $objects as $object ) {

			// This is the new smaller array to help with max_allowed_packet issues.

			$data = array( $hook, array( $object ) );

			if ( ! empty( $args ) ) {
				$data[1][] = $args;
			}

			$this->process->push_to_queue( $data );

		}

		$this->process->save()->dispatch();

		if ( wp_doing_ajax() ) {
			wp_send_json_success( $objects );
		} else {
			return $objects;
		}
	}

	/**
	 * Returns number of remaining items in the queue.
	 *
	 * @since 3.0.0
	 */
	public function batch_status() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$key = false;

		if ( isset( $_POST['key'] ) ) {
			$key = sanitize_key( $_POST['key'] );
		} else {

			$keys = $this->process->get_keys();

			if ( ! empty( $keys ) ) {
				$key = $keys[0];
			}
		}

		$status = $this->process->get_status( $key );

		if ( ! empty( $status['next_step'] ) ) {
			$status['title'] = $this->get_operation_title( $status['next_step'][0] );
		} elseif ( isset( $status['title'] ) ) {
			$status['title'] = false;
		}

		echo wp_json_encode( $status );

		die();
	}

	/**
	 * Cancels current batch process.
	 *
	 * @since 3.0.0
	 */
	public function batch_cancel() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( isset( $_POST['key'] ) ) {

			$key = sanitize_key( $_POST['key'] );

		} else {

			$keys = $this->process->get_keys();

			if ( ! empty( $keys ) ) {
				$key = $keys[0];
			}
		}

		if ( ! empty( $key ) ) {

			// We'll set this in the DB and then the background worker will pick up on it on the next operation.

			$this->process->cancel( $key );

		}

		wp_send_json_success();
	}

	/**
	 * Quick add a single item for async request
	 *
	 * @since 3.24.2
	 * @since 3.37.23 Added $start.
	 *
	 * @param string $action The action to perform.
	 * @param arrat  $args   The arguments.
	 * @param bool   $start  Whether or not to start it right away.
	 */
	public function quick_add( $action, $args, $start = true ) {

		if ( empty( $this->process ) ) {
			$this->includes();
			$this->init();
		}

		$this->process->push_to_queue( array( $action, $args ) )->save();

		if ( $start && ! wpf_get_option( 'enable_cron' ) ) {
			$this->process->dispatch();
		}
	}

	/**
	 * Record errors to the status tracker.
	 *
	 * @since 3.29.3
	 *
	 * @param string $timestamp Log timestamp.
	 * @param string $level     emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message   Log message.
	 * @param array  $context   Additional information for log handlers.
	 */
	public function handle_error( $timestamp, $level, $user, $message, $context ) {

		if ( 'error' == $level ) {

			$keys = $this->process->get_keys();

			if ( empty( $keys ) ) {
				return;
			}

			$status = get_site_option( $this->process->get_status_key( $keys[0] ) );

			if ( ! is_array( $status ) ) {
				return;
			}

			if ( ! isset( $status['errors'] ) ) {
				$status['errors'] = 0;
			}

			++$status['errors'];

			update_site_option( $this->process->get_status_key( $keys[0] ), $status );

		}
	}

	/**
	 * Import users batch init
	 *
	 * @since 3.0
	 * @return array Contact IDs
	 */
	public function import_users_init( $args ) {

		if ( 'false' === $args['tag'] ) {
			$args['tag'] = false; // allow importing all contacts.
		}

		$contact_ids = wp_fusion()->crm->load_contacts( $args['tag'] );

		if ( is_wp_error( $contact_ids ) ) {

			wpf_log( 'error', 0, 'Error performing batch operation: ' . $contact_ids->get_error_message(), array( 'source' => 'batch-process' ) );
			return false;

		} elseif ( empty( $contact_ids ) ) {
			return false;
		}

		$removed = 0;

		// Remove existing users if the option is not set to update existing users.
		if ( empty( $args['update_existing_users'] ) || 'false' === $args['update_existing_users'] ) {

			foreach ( $contact_ids as $i => $contact_id ) {

				if ( wp_fusion()->user->get_user_id( $contact_id ) ) {

					unset( $contact_ids[ $i ] );
					++$removed;

				}
			}
		}

		// Logging
		$message = 'Beginning <strong>Import Contacts</strong> batch operation on ' . count( $contact_ids ) . ' contacts with tag <strong>' . wp_fusion()->user->get_tag_label( $args['tag'] ) . '</strong>.';

		if ( $removed ) {
			$message .= ' ' . $removed . ' contacts were excluded from the import because they already have user accounts.';
		}

		wpf_log( 'info', 0, $message, array( 'source' => 'batch-process' ) );

		return array_values( $contact_ids );
	}

	/**
	 * Filters the args for the import users batch operation.
	 *
	 * @since 3.43.3
	 *
	 * @param array $args The args.
	 * @param array $objects The objects.
	 * @return array The args.
	 */
	public function import_users_args( $args, $objects ) {

		// Keep track of import groups so they can be removed later.
		$import_groups = get_option( 'wpf_import_groups', array() );

		$params               = new stdClass();
		$params->import_users = array( $args['tag'] );

		$import_id = current_time( 'timestamp' );

		$import_groups[ $import_id ] = array(
			'params'   => $params,
			'user_ids' => array(),
			'role'     => $args['role'],
		);

		update_option( 'wpf_import_groups', $import_groups, false );

		$args['import_id'] = $import_id;

		unset( $args['tag'] ); // don't need this anymore and it will save some space in the DB not to have it.

		return $args;
	}

	/**
	 * Import users batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function import_users_step( $contact_id, $args = array() ) {

		if ( ! isset( $args['notify'] ) || $args['notify'] === 'false' ) {
			$args['notify'] = false;
		}

		if ( ! isset( $args['role'] ) ) {
			$args['role'] = false;
		}

		$user_id = wp_fusion()->user->import_user( $contact_id, $args['notify'], $args['role'] );

		if ( ! is_wp_error( $user_id ) && isset( $args['import_id'] ) ) {

			// Track the imported users.
			$import_groups = get_option( 'wpf_import_groups', array() );

			if ( ! isset( $import_groups[ $args['import_id'] ]['user_ids'] ) ) {
				$import_groups[ $args['import_id'] ]['user_ids'] = array();
			}

			if ( ! in_array( $user_id, $import_groups[ $args['import_id'] ]['user_ids'] ) ) {
				// In case the data was loaded for an existing user.
				$import_groups[ $args['import_id'] ]['user_ids'][] = $user_id;
			}

			update_option( 'wpf_import_groups', $import_groups, false );

		} elseif ( is_wp_error( $user_id ) ) {

			wpf_log( 'error', 0, 'Error importing contact #' . $contact_id . ': ' . $user_id->get_error_message() );

		}
	}


	/**
	 * Users sync batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function users_cid_sync_step( $user_id ) {

		wp_fusion()->user->get_contact_id( $user_id, true );
	}

	/**
	 * Users (just tags) sync batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function users_tags_sync_step( $user_id ) {

		wp_fusion()->user->get_tags( $user_id, true, false );
	}

	/**
	 * Users sync batch process init
	 *
	 * @since 3.0
	 * @return array Users
	 */
	public function users_sync_init() {

		$args = array(
			'fields'  => 'ID',
			'orderby' => 'ID',
		);

		return get_users( $args );
	}

	/**
	 * Users sync batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function users_sync_step( $user_id ) {

		// In case they've reset the CRM while the sync was running.
		if ( wp_fusion()->user ) {
			wp_fusion()->user->get_tags( $user_id, true );
		}
	}

	/**
	 * Users register batch process init
	 *
	 * @since 3.0
	 * @return array Users
	 */
	public function users_register_init() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => WPF_CONTACT_ID_META_KEY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => WPF_CONTACT_ID_META_KEY,
					'value' => false,
				),
			),
		);

		return get_users( $args );
	}

	/**
	 * Users register batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function users_register_step( $user_id ) {

		wp_fusion()->user->user_register( $user_id );
	}

	/**
	 * Users register batch process - single step
	 *
	 * @since 3.35.17
	 *
	 * @param $user_id The ID of the user to process
	 * @return void
	 */
	public function users_register_tags_step( $user_id ) {

		$assign_tags = wpf_get_option( 'assign_tags' );

		if ( ! empty( $assign_tags ) ) {
			wp_fusion()->user->apply_tags( $assign_tags, $user_id );
		}
	}

	/**
	 * Users meta batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function users_meta_step( $user_id ) {

		wp_fusion()->user->push_user_meta( $user_id );
	}

	/**
	 * Users meta batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */
	public function pull_users_meta_step( $user_id ) {

		wp_fusion()->user->pull_user_meta( $user_id );
	}
}
