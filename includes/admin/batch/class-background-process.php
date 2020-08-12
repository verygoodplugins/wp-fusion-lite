<?php
/**
 * WP Background Process
 *
 * @package WP-Background-Processing
 */

if ( ! class_exists( 'WPF_Background_Process' ) ) {

	/**
	 * Abstract WP_Background_Process class.
	 *
	 * @abstract
	 * @extends WP_Async_Request
	 */
	class WPF_Background_Process extends WPF_Async_Request {

		/**
		 * Action
		 *
		 * (default value: 'background_process')
		 *
		 * @var string
		 * @access protected
		 */
		protected $action = 'background_process';

		/**
		 * Start time of current process.
		 *
		 * (default value: 0)
		 *
		 * @var int
		 * @access protected
		 */
		protected $start_time = 0;

		/**
		 * Cron_hook_identifier
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $cron_hook_identifier;

		/**
		 * Cron_interval_identifier
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $cron_interval_identifier;

		/**
		 * Initiate new background process
		 */
		public function __construct() {
			parent::__construct();

			$this->cron_hook_identifier     = $this->identifier . '_cron';
			$this->cron_interval_identifier = $this->identifier . '_cron_interval';

			add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
			add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ), 50 );
		}


		/**
		 * Dispatch
		 *
		 * @access public
		 * @return array Response from wp_remote_post
		 */
		public function dispatch() {

			// Schedule the cron healthcheck.
			$this->schedule_event();

			// Perform remote post.
			return parent::dispatch();
		}


		/**
		 * Push to queue
		 *
		 * @param mixed $data Data.
		 *
		 * @return $this
		 */
		public function push_to_queue( $data ) {

			$this->data[] = $data;

			return $this;
		}

		/**
		 * Save queue
		 *
		 * @return $this
		 */
		public function save() {

			// Key is wpf_background_process_{random}

			$key = $this->generate_key();

			if ( count( $this->data ) > 1 ) {

				// Save status for health check. Don't track status on quick-add from Woo orders etc.

				$status = array(
					'key'       => $key,
					'total'     => count( $this->data ),
					'remaining' => count( $this->data ),
				);

				// Get max packet size

				global $wpdb;
				$max_packet_size = $wpdb->get_var( "SHOW VARIABLES LIKE 'max_allowed_packet'", 1 );

				$max_packet_size           = $max_packet_size / 1024 / 1024;
				$status['max_packet_size'] = $max_packet_size . 'MB';

				// Get data size

				if ( function_exists( 'mb_strlen' ) ) {

					$data_size           = mb_strlen( serialize( $this->data ), '8bit' );
					$data_size           = $data_size / 1024;
					$status['data_size'] = round( $data_size ) . 'KB';

				}

				// Get human readable max memory

				$max_memory = $this->get_memory_limit();
				$max_memory = $max_memory / 1024 / 1024;

				$status['max_memory'] = $max_memory;

				update_site_option( 'wpfb_status', $status );

			}

			if ( ! empty( $this->data ) ) {

				$result = update_site_option( $key, $this->data );

			}

			// Cases where the data is too big to be saved

			if ( false === $result && isset( $status ) ) {

				$status['saved'] = 'Failed to save';
				update_site_option( 'wpfb_status', $status );

			}

			return $this;
		}

		/**
		 * Update queue
		 *
		 * @param string $key Key.
		 * @param array $data Data.
		 *
		 * @return $this
		 */
		public function update( $key, $data ) {
			if ( ! empty( $data ) ) {
				update_site_option( $key, $data );
			}

			return $this;
		}

		/**
		 * Delete queue
		 *
		 * @param string $key Key.
		 *
		 * @return $this
		 */
		public function delete( $key ) {

			delete_site_option( $key );

			return $this;
		}

		/**
		 * Generate key
		 *
		 * Generates a unique key based on microtime. Queue items are
		 * given a unique key so that they can be merged upon save.
		 *
		 * @param int $length Length.
		 *
		 * @return string
		 */
		protected function generate_key( $length = 24 ) {
			$unique  = md5( microtime() . rand() );
			$prepend = $this->identifier . '_';

			return substr( $prepend . $unique, 0, $length );
		}

		/**
		 * Maybe process queue
		 *
		 * Checks whether data exists within the queue and that
		 * the process is not already running.
		 */
		public function maybe_handle() {

			// Don't lock up other requests while processing
			session_write_close();

			if ( $this->is_process_running() ) {
				// Background process already running.
				wp_die();
			}

			if ( $this->is_queue_empty() ) {
				// No data to process.
				wp_die();
			}

			$this->handle();

			wp_die();
		}

		/**
		 * Get status
		 *
		 * @return bool
		 */
		public function get_status() {

			$status = get_site_option( 'wpfb_status' );

			if ( empty( $status ) ) {
				return false;
			}

			return $status;

		}

		/**
		 * Is queue empty
		 *
		 * @return bool
		 */
		public function is_queue_empty() {

			global $wpdb;

			$table  = $wpdb->options;
			$column = 'option_name';

			if ( is_multisite() ) {
				$table  = $wpdb->sitemeta;
				$column = 'meta_key';
			}

			$key = $this->identifier . '_%';

			$count = $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(*)
			FROM {$table}
			WHERE {$column} LIKE %s
			", $key ) );

			return ( $count > 0 ) ? false : true;
		}

		/**
		 * Is process running
		 *
		 * Check whether the current process is already running
		 * in a background process.
		 */
		public function is_process_running() {
			if ( get_site_transient( $this->identifier . '_process_lock' ) ) {

				// Process already running.
				return true;

			}

			return false;
		}

		/**
		 * Lock process
		 *
		 * Lock the process so that multiple instances can't run simultaneously.
		 * Override if applicable, but the duration should be greater than that
		 * defined in the time_exceeded() method.
		 */
		protected function lock_process() {
			$this->start_time = time(); // Set start time of current process.

			$lock_duration = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60; // 1 minute
			$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', $lock_duration );

			set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
		}

		/**
		 * Unlock process
		 *
		 * Unlock the process so that other instances can spawn.
		 *
		 * @return $this
		 */
		protected function unlock_process() {
			delete_site_transient( $this->identifier . '_process_lock' );
			return $this;
		}

		/**
		 * Get batch
		 *
		 * @return stdClass Return the first batch from the queue
		 */
		protected function get_batch() {

			global $wpdb;

			$table        = $wpdb->options;
			$column       = 'option_name';
			$key_column   = 'option_id';
			$value_column = 'option_value';

			if ( is_multisite() ) {
				$table        = $wpdb->sitemeta;
				$column       = 'meta_key';
				$key_column   = 'meta_id';
				$value_column = 'meta_value';
			}

			$key = $this->identifier . '_%';

			$query = $wpdb->get_row( $wpdb->prepare( "
				SELECT *
				FROM {$table}
				WHERE {$column} LIKE %s
				ORDER BY {$key_column} ASC
				LIMIT 1
			", $key ) );

			$batch      = new stdClass();
			$batch->key = $query->$column;
			$batch->data = maybe_unserialize( $query->$value_column );

			return $batch;

		}

		/**
		 * Update Status
		 *
		 * We'll keep track of what's going on in an option key for troubleshooting
		 *
		 */

		protected function update_status( $batch, $key, $starttime ) {

			$status = get_site_option( 'wpfb_status' );

			if ( false !== $status && is_array( $status ) && $status['key'] == $batch->key ) {

				$next_key = $key + 1;

				$status['remaining'] = count( $batch->data );
				$status['last_step'] = $batch->data[ $key ];

				if ( false !== $starttime ) {
					$status['time_last_step'] = microtime( true ) - $starttime;
				}

				if ( isset( $batch->data[ $next_key ] ) ) {
					$status['next_step']      = $batch->data[ $next_key ];
				}

				$status['total_time']     = time() - $this->start_time;
				$status['memory_percent'] = ( memory_get_usage( true ) / $status['max_memory'] ) * 100;

				update_site_option( 'wpfb_status', $status );

			}

		}

		/**
		 * Handle
		 *
		 * Pass each queue item to the task handler, while remaining
		 * within server memory and time limit constraints.
		 */
		protected function handle() {

			$this->lock_process();

			do {

				$batch = $this->get_batch();

				foreach ( $batch->data as $key => $value ) {

					$starttime = microtime( true );

					// Update status before the task in case of timeout
					$this->update_status( $batch, $key, false );

					$task = $this->task( $value );

					if ( false !== $task ) {
						$batch->data[ $key ] = $task;
					} else {

						// Update status after task
						$this->update_status( $batch, $key, $starttime );

						unset( $batch->data[ $key ] );

					}

					if ( $this->time_exceeded() || $this->memory_exceeded() || $this->is_cancelled() ) {
						// Batch limits reached.
						break;
					}

				}

				// Update or delete current batch.
				if ( ! empty( $batch->data ) && $this->is_process_running() ) {

					$this->update( $batch->key, $batch->data );

				} else {

					$this->delete( $batch->key );

				}

			} while( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() && ! $this->is_cancelled() );

			$this->unlock_process();

			// Start next batch or complete process.
			if ( $this->is_cancelled() ) {

				$this->cancel_process();

			} elseif ( ! $this->is_queue_empty() ) {

				$this->dispatch();

			} else {

				$this->complete( $batch->key );

			}

			wp_die();
		}

		/**
		 * Memory exceeded
		 *
		 * Ensures the batch process never exceeds 90%
		 * of the maximum WordPress memory.
		 *
		 * @return bool
		 */
		protected function memory_exceeded() {
			$memory_limit   = $this->get_memory_limit() * 0.80; // 80% of max memory
			$current_memory = memory_get_usage( true );
			$return         = false;

			if ( $current_memory >= $memory_limit ) {
				$return = true;
			}

			return apply_filters( $this->identifier . '_memory_exceeded', $return );
		}

		/**
		 * Get memory limit
		 *
		 * @return int
		 */
		public function get_memory_limit() {
			if ( function_exists( 'ini_get' ) ) {
				$memory_limit = ini_get( 'memory_limit' );
			} else {
				// Sensible default.
				$memory_limit = '128M';
			}

			if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
				// Unlimited, set to 512M.
				$memory_limit = '512M';
			}

			return intval( $memory_limit ) * 1024 * 1024;
		}

		/**
		 * Time exceeded.
		 *
		 * Ensures the batch never exceeds a sensible time limit.
		 * A timeout limit of 30s is common on shared hosting.
		 *
		 * @return bool
		 */
		protected function time_exceeded() {
			$finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
			$return = false;

			if ( time() >= $finish ) {
				$return = true;
			}

			// Identifier is wpf_batch

			return apply_filters( $this->identifier . '_time_exceeded', $return );
		}

		/**
		 * Check to see if batch is cancelled
		 *
		 *
		 * @return bool
		 */
		protected function is_cancelled() {

			if(get_site_option('wpfb_status') === false) {
				return true;
			} else {
				return false;
			}

		}

		/**
		 * Complete.
		 *
		 * Override if applicable, but ensure that the below actions are
		 * performed, or, call parent::complete().
		 */
		protected function complete( $key ) {

			// Unschedule the cron healthcheck.
			$this->clear_scheduled_event();

			$status = get_site_option( 'wpfb_status' );

			if ( ! empty( $status ) && $status[ 'key' ] == $key ) {

				// Delete counter variable
				delete_site_option( 'wpfb_status' );

			}

		}

		/**
		 * Schedule cron healthcheck
		 *
		 * @access public
		 *
		 * @param mixed $schedules Schedules.
		 *
		 * @return mixed
		 */
		public function schedule_cron_healthcheck( $schedules ) {
			$interval = apply_filters( $this->identifier . '_cron_interval', 5 );

			if ( property_exists( $this, 'cron_interval' ) ) {
				$interval = apply_filters( $this->identifier . '_cron_interval', $this->cron_interval_identifier );
			}

			// Adds every 5 minutes to the existing schedules.
			$schedules[ $this->identifier . '_cron_interval' ] = array(
				'interval' => MINUTE_IN_SECONDS * $interval,
				'display'  => sprintf( __( 'Every %d Minutes' ), $interval ),
			);

			return $schedules;
		}

		/**
		 * Handle cron healthcheck
		 *
		 * Restart the background process if not already running
		 * and data exists in the queue.
		 */
		public function handle_cron_healthcheck() {

			if ( $this->is_process_running() ) {
				// Background process already running.
				exit;
			}

			if ( $this->is_queue_empty() ) {
				// No data to process.
				$this->clear_scheduled_event();
				exit;
			}

			$this->handle();

			exit;
		}

		/**
		 * Schedule event
		 */
		protected function schedule_event() {
			if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
				wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
			}
		}

		/**
		 * Clear scheduled event
		 */
		protected function clear_scheduled_event() {
			$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
			}
		}

		/**
		 * Cancel Process
		 *
		 * Stop processing queue items, clear cronjob and delete batch.
		 *
		 */
		public function cancel_process() {

			if ( ! $this->is_queue_empty() ) {

				$batch = $this->get_batch();

				$this->delete( $batch->key );

				wp_clear_scheduled_hook( $this->cron_hook_identifier );

				delete_site_option( 'wpfb_status' );

				wpf_log( 'notice', 0, 'Batch operation cancelled', array( 'source' => 'batch-process' ) );

			}

		}

		/**
		 * Task
		 *
		 * Override this method to perform any actions required on each
		 * queue item. Return the modified item for further processing
		 * in the next pass through. Or, return false to remove the
		 * item from the queue.
		 *
		 * @param mixed $item Queue item to iterate over.
		 *
		 * @return mixed
		 */
		protected function task( $item ) {

			// Disable turbo for bulk processes
			wp_fusion()->crm = wp_fusion()->crm_base->crm_no_queue;

			if ( isset( $item['action'] ) ) {

				do_action_ref_array( $item['action'], $item['args'] );

			} else {

				// New minified method

				do_action_ref_array( 'wpf_batch_' . $item[0], $item[1] );

			}

			$sleep = apply_filters( 'wpf_batch_sleep_time', 0 );

			if( $sleep > 0 ) {
				sleep( $sleep );
			}

			return false;

		}

	}
}
