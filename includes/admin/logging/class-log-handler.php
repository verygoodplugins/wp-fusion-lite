<?php

/**
 * Handles log entries by writing to database.
 *
 * @class          WPF_Log_Handler
 */

class WPF_Log_Handler {

	/**
	 * Log Levels
	 *
	 * Description of levels:.
	 *     'error': Error conditions.
	 *     'warning': Warning conditions.
	 *     'notice': Normal but significant condition.
	 *     'info': Informational messages.
	 *
	 * @see @link {https://tools.ietf.org/html/rfc5424}
	 */
	const ERROR   = 'error';
	const WARNING = 'warning';
	const NOTICE  = 'notice';
	const INFO    = 'info';
	const HTTP    = 'http';

	/**
	 * Level strings mapped to integer severity.
	 *
	 * @var array
	 */
	protected static $level_to_severity = array(
		self::ERROR   => 500,
		self::WARNING => 400,
		self::NOTICE  => 300,
		self::INFO    => 200,
		self::HTTP    => 100,
	);

	/**
	 * Severity integers mapped to level strings.
	 *
	 * This is the inverse of $level_severity.
	 *
	 * @var array
	 */
	protected static $severity_to_level = array(
		500 => self::ERROR,
		400 => self::WARNING,
		300 => self::NOTICE,
		200 => self::INFO,
		100 => self::HTTP,
	);

	/**
	 * This allows other classes to manually prepend a source
	 * for an event that's about to be logged.
	 *
	 * @var array
	 */

	public $event_sources = array();

	/**
	 * Constructor for the logger.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'init' ) );

	}

	/**
	 * Prepares logging functionalty if enabled
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		if ( wp_fusion()->settings->get( 'enable_logging' ) != true ) {
			return;
		}

		add_filter( 'wpf_configure_sections', array( $this, 'configure_sections' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'register_logger_subpage' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Screen options
		add_action( 'load-tools_page_wpf-settings-logs', array( $this, 'add_screen_options' ) );
		add_filter( 'set_screen_option_wpf_status_log_items_per_page', array( $this, 'set_screen_option' ), 10, 3 );

		// HTTP API logging
		if ( wp_fusion()->settings->get( 'logging_http_api' ) ) {
			add_action( 'http_api_debug', array( $this, 'http_api_debug' ), 10, 5 );
		}

		// Error handling
		add_action( 'shutdown', array( $this, 'shutdown' ) );

		// Create the table if it hasn't been created yet

		if ( empty( wp_fusion()->settings->get( 'log_table_version' ) ) ) {

			$this->create_update_table();

		}

	}


	/**
	 * This allows us to manually prepend an event source to the log entry.
	 *
	 * @since 3.37.3
	 *
	 * @param string $source The source.
	 */

	public function add_source( $source ) {

		if ( ! in_array( $source, $this->event_sources ) ) {
			$this->event_sources[] = $source;
		}

	}

	/**
	 * Log HTTP API calls
	 *
	 * @access public
	 * @return void
	 */

	public function http_api_debug( $response, $context, $class, $parsed_args, $url ) {

		if ( 'WP Fusion; ' . home_url() !== $parsed_args['user-agent'] ) {
			return;
		}

		$message  = '<ul>';
		$message .= '<li><strong>Request:</strong> ' . $url . '</li>';
		$message .= '<li><strong>Params:</strong> <pre>' . print_r( $parsed_args, true ) . '</pre></li>';
		$message .= '<li><strong>Response:</strong><br /><pre>' .  print_r( $response, true ) . '</pre></li>';
		$message .= '</ul>';

		$this->handle( 'http', 0, $message );

	}

	/**
	 * Adds standalone log management page
	 *
	 * @access public
	 * @return void
	 */

	public function register_logger_subpage() {

		$page = add_submenu_page(
			'tools.php',
			'WP Fusion Activity Logs',
			'WP Fusion Logs',
			'manage_options',
			'wpf-settings-logs',
			array( $this, 'show_logs_section' )
		);

	}

	/**
	 * Enqueues logger styles
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_scripts() {

		$screen = get_current_screen();

		if ( 'tools_page_wpf-settings-logs' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'options-css', WPF_DIR_URL . 'includes/admin/options/css/options.css', array(), WP_FUSION_VERSION );
		wp_enqueue_style( 'wpf-options', WPF_DIR_URL . 'assets/css/wpf-options.css', array(), WP_FUSION_VERSION );
		wp_enqueue_style( 'wpf-admin', WPF_DIR_URL . 'assets/css/wpf-admin.css', array(), WP_FUSION_VERSION );

	}

	/**
	 * Adds per-page screen option
	 *
	 * @access public
	 * @return void
	 */

	public function add_screen_options() {

		$args = array(
			'label'   => __( 'Entries per page', 'wp-fusion-lite' ),
			'default' => 20,
			'option'  => 'wpf_status_log_items_per_page',
		);

		add_screen_option( 'per_page', $args );

	}

	/**
	 * Save screen options
	 *
	 * @access public
	 * @return int Value
	 */

	public function set_screen_option( $status, $option, $value ) {

		if ( 'wpf_status_log_items_per_page' == $option && isset( $_POST['wp_screen_options'] ) ) {
			return $_POST['wp_screen_options']['value'];
		}

		return $status;

	}

	/**
	 * Adds logging tab to main settings for access
	 *
	 * @access public
	 * @return array Page
	 */

	public function configure_sections( $page, $options ) {

		$page['sections'] = wp_fusion()->settings->insert_setting_after(
			'advanced', $page['sections'], array(
				'logs' => array(
					'title' => __( 'Logs', 'wp-fusion-lite' ) . ' &rarr;',
					'slug'  => 'wpf-settings-logs',
				),
			)
		);

		return $page;

	}

	/**
	 * Creates logging table if logging enabled
	 *
	 * @access public
	 * @return void
	 */

	public function create_update_table() {

		global $wpdb;
		$table_name = $wpdb->prefix . 'wpf_logging';

		if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$collate = '';

			if ( $wpdb->has_cap( 'collation' ) ) {
				$collate = $wpdb->get_charset_collate();
			}

			$sql = 'CREATE TABLE ' . $table_name . " (
				log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				timestamp datetime NOT NULL,
				level smallint(4) NOT NULL,
				user bigint(8) NOT NULL,
				source varchar(200) NOT NULL,
				message longtext NOT NULL,
				context longtext NULL,
				PRIMARY KEY (log_id),
				KEY level (level)
			) $collate;";

			dbDelta( $sql );

		}

		wp_fusion()->settings->set( 'log_table_version', WP_FUSION_VERSION );

	}

	/**
	 * Logging tab content
	 *
	 * @access public
	 * @return void
	 */

	public function show_logs_section() {

		include_once WPF_DIR_PATH . 'includes/admin/logging/class-log-table-list.php';

		// Flush
		if ( ! empty( $_REQUEST['flush-logs'] ) ) {

			self::flush();

			// Redirect to clear the URL
			wp_redirect( admin_url( 'tools.php?page=wpf-settings-logs' ) );
			exit;
		}

		// Bulk actions
		if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['log'] ) ) {
			self::log_table_bulk_actions();
		}

		$log_table_list = new WPF_Log_Table_List();
		$log_table_list->prepare_items();

		?>

		<div class="wrap">

			<form method="get" id="mainform">

				<h1 class="wp-heading-inline"><?php _e( 'WP Fusion Activity Log', 'wp-fusion-lite' ); ?></h1>
				<a style="vertical-align: middle;" href="<?php echo admin_url( 'tools.php?page=wpf-settings-logs&flush-logs=true' ) ?>" name="flush-logs" class="button page-title-action"><?php _e( 'Flush all logs', 'wp-fusion-lite' ); ?></a>

				<?php wp_nonce_field( 'wp-fusion-status-logs' ); ?>

				<hr class="wp-header-end" />

				<span class="description" style="display: inline-block; padding: 5px 0;">
					<?php printf( __( 'For more information on the logs, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/activity-logs/" target="_blank">', '</a>' ); ?>
					<?php printf( __( 'To go back to the main settings page, %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . admin_url( 'options-general.php?page=wpf-settings' ) . '">', '</a>' ); ?>
				</span>

				<?php if ( wp_fusion()->settings->get( 'logging_errors_only' ) ) : ?>

					<div class="notice notice-warning">
						<p><?php _e( '<strong>Note:</strong> The logs are currently set to record <strong>Only Errors</strong>, from the Advanced tab in the WP Fusion settings. Informational and debugging messages are not being recorded.', 'wp-fusion-lite' ); ?></p>
					</div>

				<?php endif; ?>

				<input type="hidden" name="page" value="wpf-settings-logs">

				<?php $log_table_list->display(); ?>

				<?php submit_button( __( 'Flush all logs', 'wp-fusion-lite' ), 'delete', 'flush-logs' ); ?>

			</form>
		</div>

		<?php

	}


	/**
	 * Validate a level string.
	 *
	 * @param string $level
	 * @return bool True if $level is a valid level.
	 */
	public static function is_valid_level( $level ) {
		return isset( self::$level_to_severity[ strtolower( $level ) ] );
	}

	/**
	 * Translate level string to integer.
	 *
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @return int 100 (debug) - 800 (emergency) or 0 if not recognized
	 */
	public static function get_level_severity( $level ) {
		if ( self::is_valid_level( $level ) ) {
			$severity = self::$level_to_severity[ strtolower( $level ) ];
		} else {
			$severity = 0;
		}
		return $severity;
	}

	/**
	 * Translate severity integer to level string.
	 *
	 * @param int $severity
	 * @return bool|string False if not recognized. Otherwise string representation of level.
	 */
	public static function get_severity_level( $severity ) {
		if ( isset( self::$severity_to_level[ $severity ] ) ) {
			return self::$severity_to_level[ $severity ];
		} else {
			return false;
		}
	}

	/**
	 * Handle a log entry.
	 *
	 * @since  3.3.0
	 *
	 * @param  string $level   emergency|alert|critical|error|warning|notice|info|debug
	 * @param  int    $user    The user
	 * @param  string $message Log message.
	 * @param  array  $context { Additional information for log handlers.
	 *
	 * @type string $source Optional. Source will be available in log table. If
	 * no source is provided, attempt to provide sensible default. }
	 * @param int   $timestamp Log timestamp.
	 *
	 * @see    WPF_Log_Handler::get_log_source() for default source.
	 * @return bool   False if value was not handled and true if value was handled.
	 */
	public function handle( $level, $user, $message, $context = array() ) {

		$timestamp = current_time( 'timestamp' );

		do_action( 'wpf_handle_log', $timestamp, $level, $user, $message, $context );

		if ( wp_fusion()->settings->get( 'enable_logging' ) != true ) {
			return;
		}

		if ( wp_fusion()->settings->get( 'logging_errors_only' ) == true && $level != 'error' ) {
			return;
		}

		if ( isset( $context['source'] ) && $context['source'] ) {
			$source = $context['source'];
		} else {
			$source = $this->get_log_source();
		}

		// Change "tags" to "lists" etc. in the message

		$message = wp_fusion()->settings->set_tag_labels( $message, false, 'wp-fusion-lite' );

		// If a custom object type is in use, change it in the message

		if ( ! empty( wp_fusion()->crm->object_type ) ) {

			$selected_type = strtolower( rtrim( wp_fusion()->crm->object_type, 's' ) ); // make singular

			if ( 'contact' != $selected_type ) {
				$message = str_replace( 'contact', $selected_type, $message );
			}
		}

		// Filter out irrelevant meta fields and show any field format changes (don't do it when loading data)
		if ( ! empty( $context['meta_array'] ) && ! did_action( 'wpf_pre_pull_user_meta' ) ) {

			$contact_fields = wp_fusion()->settings->get( 'contact_fields' );

			foreach ( $context['meta_array'] as $key => $data ) {

				if ( ! isset( $contact_fields[ $key ] ) || empty( $contact_fields[ $key ]['active'] ) ) {
					unset( $context['meta_array'][ $key ] );
					continue;
				}

				if ( ! isset( $contact_fields[ $key ]['type'] ) ) {
					$contact_fields[ $key ]['type'] = 'text';
				}

				$filtered_value = apply_filters( 'wpf_format_field_value', $data, $contact_fields[ $key ]['type'], $contact_fields[ $key ]['crm_field'] );

				if ( $data != $filtered_value ) {

					// Store what happened to the data so we can show a little more context in the logs

					$context['meta_array'][ $key ] = array(
						'original' => $data,
						'new'      => $filtered_value,
						'type'     => $contact_fields[ $key ]['type'],
					);
				}
			}
		}

		if ( empty( $user ) ) {
			$user = 0;
		}

		// Don't log meta data pushes where no enabled fields are being synced
		if ( isset( $context['meta_array'] ) && empty( $context['meta_array'] ) ) {
			return;
		}

		do_action( 'wpf_log_handled', $timestamp, $level, $user, $message, $source, $context );

		return $this->add( $timestamp, $level, $user, $message, $source, $context );
	}

	/**
	 * Add a log entry to chosen file.
	 *
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 * @param string $source Log source. Useful for filtering and sorting.
	 * @param array  $context {
	 *      Context will be serialized and stored in database.
	 *  }
	 *
	 * @return bool True if write was successful.
	 */
	protected static function add( $timestamp, $level, $user, $message, $source, $context ) {
		global $wpdb;

		$insert = array(
			'timestamp' => date( 'Y-m-d H:i:s', $timestamp ),
			'level'     => self::get_level_severity( $level ),
			'user'      => $user,
			'message'   => $message,
			'source'    => $source,
		);

		$format = array(
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s', // possible serialized context
		);

		if ( ! empty( $context ) ) {
			$insert['context'] = serialize( $context );
		}

		$result = $wpdb->insert( "{$wpdb->prefix}wpf_logging", $insert, $format );

		if ( $result === false ) {
			return false;
		}

		$rowcount = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpf_logging" );

		$max_log_size = apply_filters( 'wpf_log_max_entries', 10000 );

		if ( $rowcount > $max_log_size ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}wpf_logging ORDER BY log_id ASC LIMIT 100" ); // Delete 100 so we don't need to run this with every new entry
		}

		return $result;

	}

	/**
	 * Clear all logs from the DB.
	 *
	 * @return bool True if flush was successful.
	 */
	public static function flush() {

		global $wpdb;

		return $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpf_logging" );
	}

	/**
	 * Bulk DB log table actions.
	 *
	 * @since 3.0.0
	 */
	private function log_table_bulk_actions() {

		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp-fusion-status-logs' ) ) {
			wp_die( __( 'Action failed. Please refresh the page and retry.', 'wp-fusion-lite' ) );
		}

		$log_ids = array_map( 'absint', (array) $_REQUEST['log'] );

		if ( 'delete' === $_REQUEST['action'] || 'delete' === $_REQUEST['action2'] ) {
			self::delete( $log_ids );
		}
	}

	/**
	 * Delete selected logs from DB.
	 *
	 * @param int|string|array Log ID or array of Log IDs to be deleted.
	 *
	 * @return bool
	 */
	public static function delete( $log_ids ) {
		global $wpdb;

		if ( ! is_array( $log_ids ) ) {
			$log_ids = array( $log_ids );
		}

		$format = array_fill( 0, count( $log_ids ), '%d' );

		$query_in = '(' . implode( ',', $format ) . ')';

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wpf_logging WHERE log_id IN {$query_in}",
			$log_ids
		);

		return $wpdb->query( $query );
	}


	/**
	 * Get appropriate source based on file name.
	 *
	 * Try to provide an appropriate source in case none is provided.
	 *
	 * @return string Text to use as log source. "" (empty string) if none is found.
	 */

	public function get_log_source() {

		/**
		 * PHP < 5.3.6 correct behavior
		 *
		 * @see http://php.net/manual/en/function.debug-backtrace.php#refsect1-function.debug-backtrace-parameters
		 */

		if ( defined( 'DEBUG_BACKTRACE_IGNORE_ARGS' ) ) {
			$debug_backtrace_arg = DEBUG_BACKTRACE_IGNORE_ARGS;
		} else {
			$debug_backtrace_arg = false;
		}

		// Get the available files that are valid as a log source

		$slugs = array( 'user-profile', 'api', 'access-control', 'auto-login', 'ajax', 'shortcodes' );

		foreach ( wp_fusion()->get_integrations() as $slug => $integration ) {
			$slugs[] = $slug;
		}

		$found_integrations = array();

		// If we're doing a batch operation it's good to know which one,
		// we'll prepend that here

		if ( defined( 'DOING_WPF_BATCH_TASK' ) ) {

			$found_integrations[] = 'batch-process';

			$found_integrations[] = str_replace( '_', '-', DOING_WPF_BATCH_TASK );

		}

		// This allows us to preprend a source manually

		if ( ! empty( $this->event_sources ) ) {
			$found_integrations = array_merge( $found_integrations, $this->event_sources );
		}

		$full_trace = array_reverse( debug_backtrace( $debug_backtrace_arg ) );

		foreach ( $full_trace as $i => $trace ) {

			if ( isset( $trace['file'] ) ) {

				foreach ( $slugs as $slug ) {

					if ( empty( $slug ) ) {
						continue;
					}

					if ( strpos( $trace['file'], 'class-' . $slug ) !== false ) {

						// Remove the "class"

						$slug = str_replace( 'class-', '', $slug );

						$found_integrations[] = $slug;
					}
				}
			}
		}

		// Figure out most likely integration
		if ( ! empty( $found_integrations ) ) {

			$source = serialize( array_unique( $found_integrations ) );

		} else {
			$source = 'unknown';
		}

		return $source;
	}


	/**
	 * Check for PHP errors on shutdown and log them
	 *
	 * @access public
	 * @return void
	 */
	public function shutdown() {

		$error = error_get_last();

		if ( is_null( $error ) ) {
			return;
		}

		if ( false !== strpos( $error['message'], 'Allowed memory size' ) ) {

			// Out of memory

			$this->handle( 'error', wpf_get_current_user_id(), '<strong>PHP out of memory error.</strong> This may have affected WP Fusion\'s functionality. Consider increasing the available memory on your site or deactivating some plugins. ' . nl2br( $error['message'] ) . '<br /><br />' . $error['file'] . ':' . $error['line'] );

		} elseif ( false !== strpos( $error['message'], 'Maximum execution time' ) ) {

			// Max execution time

			$this->handle( 'error', wpf_get_current_user_id(), '<strong>PHP fatal error: ' . $error['message'] . '.</strong> This may have affected WP Fusion\'s functionality. Consider increasing the available memory on your site or deactivating some plugins.<br /><br />' . $error['file'] . ':' . $error['line'] );

		} elseif ( false !== strpos( $error['file'], 'wp-fusion-lite' ) || false !== strpos( $error['message'], 'wp-fusion-lite' ) ) {

			// WPF errors

			if ( E_ERROR == $error['type'] || E_WARNING == $error['type'] ) {

				// Get the source

				$source = 'unknown';

				$slugs = array( 'user-profile', 'api', 'access-control', 'class-auto-login', 'class-ajax', 'class-user' );

				foreach ( wp_fusion()->get_integrations() as $slug => $integration ) {
					$slugs[] = $slug;
				}

				foreach ( $slugs as $slug ) {

					if ( empty( $slug ) ) {
						continue;
					}

					if ( strpos( $error['file'], $slug ) !== false ) {

						$source = $slug;
						break;

					}
				}

				if ( E_ERROR == $error['type'] ) {
					$level = 'error';
				} elseif ( E_WARNING == $error['type'] ) {
					$level = 'warning';
				}

				$this->handle( $level, wpf_get_current_user_id(), '<strong>PHP ' . $level . ':</strong> ' . nl2br( $error['message'] ) . '<br /><br />' . $error['file'] . ':' . $error['line'], array( 'source' => $source ) );

			}

		}

	}

}
