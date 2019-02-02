<?php

/**
 * Runs batch processing jobs (like imports, exports, etc)
 */
class WPF_Batch {

	/**
	 * @var WP_Example_Process
	 */
	public $process;

	/**
	 * Get things started
	 *
	 * @since 3.0
	 * @return void
	 */

	public function __construct() {

		// Status monitor
		add_action( 'wpf_settings_after_page_title', array( $this, 'batch_status_bar' ) );

		// Global handlers
		add_action( 'wp_ajax_wpf_batch_init', array( $this, 'batch_init' ), 10, 2 );
		add_action( 'wp_ajax_wpf_batch_status', array( $this, 'batch_status' ) );
		add_action( 'wp_ajax_wpf_batch_cancel', array( $this, 'batch_cancel' ) );

		// Export users
		add_filter( 'wpf_batch_users_register_init', array( $this, 'users_register_init' ) );
		add_action( 'wpf_batch_users_register', array( $this, 'users_register_step' ) );

		// Push user meta
		add_filter( 'wpf_batch_users_meta_init', array( $this, 'users_meta_init' ) );
		add_action( 'wpf_batch_users_meta', array( $this, 'users_meta_step' ) );

		// Pull user meta
		add_filter( 'wpf_batch_pull_users_meta_init', array( $this, 'pull_users_meta_init' ) );
		add_action( 'wpf_batch_pull_users_meta', array( $this, 'pull_users_meta_step' ) );

		// Sync users
		add_filter( 'wpf_batch_users_sync_init', array( $this, 'users_sync_init' ) );
		add_action( 'wpf_batch_users_sync', array( $this, 'users_sync_step' ) );

		// Sync users (just tags)
		add_filter( 'wpf_batch_users_tags_sync_init', array( $this, 'users_tags_sync_init' ) );
		add_action( 'wpf_batch_users_tags_sync', array( $this, 'users_tags_sync_step' ) );

		// Import contacts
		add_filter( 'wpf_batch_import_users_init', array( $this, 'import_users_init' ) );
		add_action( 'wpf_batch_import_users', array( $this, 'import_users_step' ), 10, 2 );

		// Only run on WPF settings page unless a pending cron job needs attention
		if( is_admin() || wp_next_scheduled( 'wpf_batch_cron' ) != false ) {

			$this->includes();
			$this->init();

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
		require_once WPF_DIR_PATH . 'includes/admin/batch/class-batch-process.php';

	}

	/**
	 * Initialize batch processing library
	 *
	 * @since 3.0
	 * @return void
	 */

	public function init() {

		$this->process = new WPF_Batch_Process();

	}

	/**
	 * Show batch status bar at the top of settings page
	 *
	 * @since 3.0
	 * @return mixed
	 */

	public function batch_status_bar() {

		$active = false;

		// Get process status / try and restart stalled processes
		if ( $this->process->is_process_running() == true ) {

			$active = true;
			$remaining = $this->process->get_queue_remaining();

		} elseif ( $this->process->is_queue_empty() == false && $this->process->is_process_running() == false ) {

			$remaining = $this->process->get_queue_remaining();

			if( !empty( $remaining ) ) {

				$this->process->dispatch();
				$active = true;

			}

		}

		echo '<div id="wpf-batch-status" class="notice notice-info ' . ( $active ? 'active' : 'hidden' ) . '" ' . ( $active ? 'data-remaining="' . $remaining . '"' : '' ) . '>';
		echo '<i class="fa fa-li fa-spin fa-spinner"></i><p><span class="title"><strong>Background operation running:</strong></span> <span class="status">';

		if ( $active ) {
			echo 'Processing ' . $remaining . ' records ';
		}

		echo '</span><a id="cancel-batch" class="btn btn-default btn-xs">Cancel</a></p>';
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

		if( empty( $objects ) ) {
			echo 0;
			die();
		}

		foreach ( $objects as $object ) {
			$this->process->push_to_queue( array( 'action' => 'wpf_batch_' . $hook, 'args' => array( $object, $args ) ) );
		}

		$this->process->save()->dispatch();

		echo count( $objects );

		die();

	}

	/**
	 * Returns number of remaining items in the queue
	 *
	 * @since 3.0
	 * @return int Remaining
	 */

	public function batch_status() {

		echo $this->process->get_queue_remaining();

		die();

	}

	/**
	 * Cancels current batch process
	 *
	 * @since 3.0
	 * @return int Remaining
	 */

	public function batch_cancel() {

		$this->process->cancel_process();
		die();

	}

	/**
	 * Import users batch init
	 *
	 * @since 3.0
	 * @return array Contact IDs
	 */

	public function import_users_init($args) {

		$contact_ids = wp_fusion()->crm->load_contacts($args['tag']);

		if( is_wp_error( $contact_ids ) ) {

			wp_fusion()->logger->handle( 'error', 0, 'Error performing batch operation: ' . $contact_ids->get_error_message(), array( 'source' => wp_fusion()->crm->slug ) );
			return false;

		} elseif ( empty( $contact_ids ) ) {
			
			return false;

		}

		// Remove existing users

		// foreach( $contact_ids as $i => $contact_id ) {

		// 	if( wp_fusion()->user->get_user_id( $contact_id ) != false ) {

		// 		unset( $contact_ids[$i] );

		// 	}

		// }

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Import Contacts</strong> batch operation on ' . count($contact_ids) . ' contacts with tag <strong>' . wp_fusion()->user->get_tag_label( $args['tag'] ) . '</strong>', array( 'source' => 'batch-process' ) );

		// Keep track of import groups so they can be removed later
		$import_groups = get_option( 'wpf_import_groups', array() );

		$params = new stdClass;
		$params->import_users = array($args['tag']);

		$import_groups[current_time( 'timestamp' )] = array(
			'params'   => $params,
			'user_ids' => array(),
			'role'     => $args['role']
		);

		update_option( 'wpf_import_groups', $import_groups );

		return $contact_ids;

	}

	/**
	 * Import users batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */

	public function import_users_step( $contact_id, $args ) {

		add_action( 'wpf_user_imported', array( $this, 'count_imported_user' ), 10, 2 );
		wp_fusion()->user->import_user( $contact_id, $args['notify'], $args['role'] );

	}

	/**
	 * Counts an imported user and adds them to the import group
	 *
	 * @since 3.0
	 * @return void
	 */

	public function count_imported_user($user_id, $user_meta) {

		$import_groups = get_option( 'wpf_import_groups', array() );

		end($import_groups);
		$key = key($import_groups);
		reset($import_groups);

		$import_groups[$key]['user_ids'][] = $user_id;

		update_option( 'wpf_import_groups', $import_groups );

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

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Resync Contact IDs and Tags</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

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

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Resync Tags</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

		return $users;

	}

	/**
	 * Users (just tags) sync batch process - single step
	 *
	 * @since 3.0
	 * @return void
	 */

	public function users_tags_sync_step( $user_id ) {

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

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Export Users</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

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

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Push User Meta</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

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

		wp_fusion()->logger->handle( 'info', 0, 'Beginning <strong>Pull User Meta</strong> batch operation on ' . count($users) . ' users', array( 'source' => 'batch-process' ) );

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