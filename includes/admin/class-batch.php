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

		add_filter( 'wpf_export_options', array( $this, 'export_options' ) );

		// Status monitor
		add_action( 'wpf_settings_after_page_title', array( $this, 'batch_status_bar' ) );

		// Global handlers
		add_action( 'wp_ajax_wpf_batch_init', array( $this, 'batch_init' ), 10, 2 );
		add_action( 'wp_ajax_wpf_batch_status', array( $this, 'batch_status' ) );
		add_action( 'wp_ajax_wpf_batch_cancel', array( $this, 'batch_cancel' ) );

		// Error handling
		add_action( 'wpf_handle_log', array( $this, 'handle_error' ), 10, 5 );

		// Export users
		add_filter( 'wpf_batch_users_register_init', array( $this, 'users_register_init' ) );
		add_action( 'wpf_batch_users_register', array( $this, 'users_register_step' ) );

		// Push user meta
		add_filter( 'wpf_batch_users_meta_init', array( $this, 'users_meta_init' ) );
		add_action( 'wpf_batch_users_meta', array( $this, 'users_meta_step' ) );

		// Pull user meta
		add_filter( 'wpf_batch_pull_users_meta_init', array( $this, 'pull_users_meta_init' ) );
		add_action( 'wpf_batch_pull_users_meta', array( $this, 'pull_users_meta_step' ) );

		// Sync users (just CIDs)
		add_filter( 'wpf_batch_users_cid_sync_init', array( $this, 'users_cid_sync_init' ) );
		add_action( 'wpf_batch_users_cid_sync', array( $this, 'users_cid_sync_step' ) );

		// Sync users (just tags)
		add_filter( 'wpf_batch_users_tags_sync_init', array( $this, 'users_tags_sync_init' ) );
		add_action( 'wpf_batch_users_tags_sync', array( $this, 'users_tags_sync_step' ) );

		// Sync users
		add_filter( 'wpf_batch_users_sync_init', array( $this, 'users_sync_init' ) );
		add_action( 'wpf_batch_users_sync', array( $this, 'users_sync_step' ) );

		// Import contacts
		add_filter( 'wpf_batch_import_users_init', array( $this, 'import_users_init' ) );
		add_action( 'wpf_batch_import_users', array( $this, 'import_users_step' ), 10, 2 );

		$this->includes();
		$this->init();

	}

	/**
	 * Get core batch options
	 *
	 * @since 3.33.16
	 * @return array Options
	 */

	public function export_options( $options ) {

		$core_options = array(
			'users_cid_sync' => array(
				'label'     => __( 'Resync contact IDs for every user', 'wp-fusion-lite' ),
				'title'     => __( 'Users (contact IDs)', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'Looks up every WordPress user by email address in %s, and updates their cached contact ID. Does not modify any tags or trigger any automated enrollments.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			),
			'users_tags_sync' => array(
				'label'     => __( 'Resync tags for every user', 'wp-fusion-lite' ),
				'title'     => __( 'Users (tags)', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'Updates tags for all WordPress users who already have a saved contact ID, and triggers any automated enrollments via linked tags.', 'wp-fusion-lite' ) )
			),
			'users_sync' => array(
				'label'     => __( 'Resync contact IDs and tags for every user', 'wp-fusion-lite' ),
				'title'     => __( 'Users (contact IDs and tags)', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'All WordPress users will have their contact IDs checked / updated based on email address and tags will be loaded from their %s contact record. Will trigger automated enrollments based on linked tags.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			),
			'users_register' => array(
				'label'     => __( 'Export users', 'wp-fusion-lite' ),
				'title'     => __( 'Users', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'All WordPress users without a matching %s contact record will be exported as new contacts.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			),
			'users_meta'     => array(
				'label'     => __( 'Push user meta', 'wp-fusion-lite' ),
				'title'     => __( 'Users', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'All WordPress users with a contact record will have their meta data pushed to %s, overriding any data on the contact record with the values from WordPress.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			),
			'pull_users_meta'     => array(
				'label'     => __( 'Pull user meta', 'wp-fusion-lite' ),
				'title'     => __( 'Users', 'wp-fusion-lite' ),
				'tooltip'   => sprintf( __( 'All WordPress users with a contact record will have their meta data loaded from %s, overriding any data in their user meta with the values from their contact record.', 'wp-fusion-lite' ), wp_fusion()->crm->name )
			),
		);

		return array_merge( $options, $core_options );

	}

	/**
	 * Initialize batch processing library
	 *
	 * @since 3.35.7
	 * @return string Name of the batch operation
	 */

	public function get_operation_title( $id ) {

		$operations = apply_filters( 'wpf_export_options', array() );

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
	 * Initialize batch processing library
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

		// Try and restart it if it's stalled
		if ( $this->process->is_queue_empty() == false && $this->process->is_process_running() == false ) {
			$this->process->dispatch();
		}

		$total     = intval( $status['total'] );
		$remaining = intval( $status['remaining'] );
		$done      = $total - $remaining;

		echo '<div id="wpf-batch-status" class="notice notice-info ' . ( $active ? 'active' : 'hidden' ) . '" ' . ( $active ? 'data-remaining="' . $remaining . '"' : '' ) . ' ' . ( $active ? 'data-key="' . $status['key'] . '"' : '' ) . '>';
		echo '<i class="fa fa-li fa-spin fa-spinner"></i><p><span class="title"><strong>' . __( 'Background operation running:', 'wp-fusion-lite' ) . '</strong></span> <span class="status">';

		$title = 'records';

		// Get the title from the status
		if ( ! empty( $status['next_step'] ) ) {
			$title = $this->get_operation_title( $status['next_step'][0] );
		}

		if ( $active ) {
			echo __( 'Processing', 'wp-fusion-lite' ) . ' ' . $done . ' / ' . $total . ' ' . $title . ' ';
		}

		echo '</span><a id="cancel-batch" class="btn btn-default btn-xs">' . __( 'Cancel', 'wp-fusion-lite' ) . '</a></p>';
		echo '</div>';

	}

	/**
	 * Initialize batch process and return count of objects to be processed
	 *
	 * @since 3.0
	 * @return int Count
	 */

	public function batch_init( $hook = false, $args = array() ) {

		if( isset( $_POST['hook'] ) ) {
			$hook = $_POST['hook'];
		}

		if(isset($_POST['args']) && is_array($_POST['args'])) {
			$args = $_POST['args'];
		}

		$objects = apply_filters( 'wpf_batch_' . $hook . '_init', $args );

		$objects = apply_filters( 'wpf_batch_objects', $objects, $args );

		if ( empty( $objects ) ) {
			wp_send_json_success( json_encode( $objects ) );
			die();
		}

		// Int IDs are smaller in the DB than strings, but sometimes we'll still need to use strings (i.e. Drip subscriber IDs)
		if ( is_numeric( $objects[0] ) ) {
			$objects = array_map( 'intval', $objects );
		}

		foreach ( $objects as $object ) {

			// This is the new smaller array to help with max_allowed_packet issues

			$data = array( $hook, array( $object ) );

			if ( ! empty( $args ) ) {
				$data[1][] = $args;
			}

			$this->process->push_to_queue( $data );

		}

		$this->process->save()->dispatch();

		wp_send_json_success( json_encode( $objects ) );

		die();

	}

	/**
	 * Returns number of remaining items in the queue
	 *
	 * @since 3.0
	 * @return int Remaining
	 */

	public function batch_status() {

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
		} else {
			$status['title'] = false;
		}

		echo json_encode( $status );

		die();

	}

	/**
	 * Cancels current batch process
	 *
	 * @since 3.0
	 * @return int Remaining
	 */

	public function batch_cancel() {

		$key = sanitize_key( $_POST['key'] );

		// We'll set this in the DB and then the background worker will pick up on it when it's a good time
		set_site_transient( 'wpfb_cancel_' . $key, true, MINUTE_IN_SECONDS );

		die();

	}

	/**
	 * Quick add a single item for async request
	 *
	 * @since 3.24.2
	 * @return void
	 */

	public function quick_add( $action, $args ) {

		if ( empty( $this->process ) ) {
			$this->includes();
			$this->init();
		}

		$this->process->push_to_queue( array( 'action' => $action, 'args' => $args ) );
		$this->process->save()->dispatch();

	}

	/**
	 * Record errors to the status tracker
	 *
	 * @since 3.29.3
	 * @return int Remaining
	 */

	public function handle_error( $timestamp, $level, $user, $message, $context ) {

		if ( 'error' == $level ) {

			$keys = $this->process->get_keys();

			if ( empty( $keys ) ) {
				return;
			}

			$status = get_site_option( 'wpfb_status_' . $keys[0] );

			if ( ! is_array( $status ) ) {
				return;
			}

			if ( ! isset( $status['errors'] ) ) {
				$status['errors'] = 0;
			}

			$status['errors']++;

			update_site_option( 'wpfb_status_' . $keys[0], $status );


		}

	}

	/**
	 * Import users batch init
	 *
	 * @since 3.0
	 * @return array Contact IDs
	 */

	public function import_users_init($args) {

		$contact_ids = wp_fusion()->crm->load_contacts($args['tag']);

		if ( is_wp_error( $contact_ids ) ) {

			wpf_log( 'error', 0, 'Error performing batch operation: ' . $contact_ids->get_error_message(), array( 'source' => 'batch-process' ) );
			return false;

		} elseif ( empty( $contact_ids ) ) {

			return false;

		}

		// Remove existing users

		$removed = 0;

		foreach( $contact_ids as $i => $contact_id ) {

			if( wp_fusion()->user->get_user_id( $contact_id ) != false ) {

				unset( $contact_ids[$i] );
				$removed++;

			}

		}

		// Logging

		$message = 'Beginning <strong>Import Contacts</strong> batch operation on ' . count( $contact_ids ) . ' contacts with tag <strong>' . wp_fusion()->user->get_tag_label( $args['tag'] ) . '</strong>.';

		if ( $removed ) {
			$message .= $removed . ' contacts were excluded from the import because they already have user accounts.';
		}

		wpf_log( 'info', 0, $message, array( 'source' => 'batch-process' ) );

		// Keep track of import groups so they can be removed later
		$import_groups = get_option( 'wpf_import_groups', array() );

		$params = new stdClass;
		$params->import_users = array($args['tag']);

		$import_groups[current_time( 'timestamp' )] = array(
			'params'   => $params,
			'user_ids' => array(),
			'role'     => $args['role']
		);

		update_option( 'wpf_import_groups', $import_groups, false );

		return $contact_ids;

	}

	/**
	 * Import users batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */

	public function import_users_step( $contact_id, $args ) {

		if ( ! isset( $args['notify'] ) || $args['notify'] === 'false' ) {
			$args['notify'] = false;
		}

		if ( ! isset( $args['role'] ) ) {
			$args['role'] = false;
		}

		$user_id = wp_fusion()->user->import_user( $contact_id, $args['notify'], $args['role'] );

		if ( $user_id ) {

			// Track the imported users

			$import_groups = get_option( 'wpf_import_groups', array() );

			end( $import_groups );
			$key = key( $import_groups );
			reset( $import_groups );

			$import_groups[ $key ]['user_ids'][] = $user_id;

			update_option( 'wpf_import_groups', $import_groups, false );

		}

	}


	/**
	 * Users (just contact IDs) sync batch process init
	 *
	 * @since 3.33.16
	 * @return array Users
	 */

	public function users_cid_sync_init() {

		$args = array( 'fields' => 'ID' );

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Resync Contact IDs</strong> batch operation on ' . count( $users ) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

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
	 * Users (just tags) sync batch process init
	 *
	 * @since 3.0
	 * @return array Users
	 */

	public function users_tags_sync_init() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation'   => 'AND',
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'compare' => 'EXISTS'
				),
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'value'   => false,
					'compare' => '!='
				)
			)
		);

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Resync Tags</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

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

		$args = array( 'fields' => 'ID' );

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Resync Contact IDs and Tags</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

	}

	/**
	 * Users sync batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */

	public function users_sync_step( $user_id ) {

		wp_fusion()->user->get_tags( $user_id, true );

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
				'relation'   => 'OR',
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'compare' => 'NOT EXISTS'
				),
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'value'   => false
				)
			)
		);

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Export Users</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

	}

	/**
	 * Users register batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */

	public function users_register_step( $user_id ) {

		wp_fusion()->user->user_register( $user_id, null, true );

	}

	/**
	 * Users meta batch process init
	 *
	 * @since 3.0
	 * @return array Users
	 */

	public function users_meta_init() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation'   => 'AND',
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'compare' => 'EXISTS'
				),
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'value'   => false,
					'compare' => '!='
				)
			)
		);

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Push User Meta</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

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
	 * Users meta batch process init
	 *
	 * @since 3.0
	 * @return array Users
	 */

	public function pull_users_meta_init() {

		$args = array(
			'fields'     => 'ID',
			'meta_query' => array(
				'relation'   => 'AND',
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'compare' => 'EXISTS'
				),
				array(
					'key'     => wp_fusion()->crm->slug . '_contact_id',
					'value'   => false,
					'compare' => '!='
				)
			)
		);

		$users = get_users( $args );

		wpf_log( 'info', 0, 'Beginning <strong>Pull User Meta</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

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