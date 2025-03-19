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
	 * This contains the current user ID (or the user ID currently being acted on),
	 * for use with HTTP API logging.
	 *
	 * @since 3.40.29
	 *
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Last HTTP API error.
	 *
	 * @since 3.44.23
	 * @var array
	 */
	public $error_last = array();

	/**
	 * Constructor for the logger.
	 */
	public function __construct() {

		add_filter( 'validate_field_enable_logging', array( $this, 'validate_enable_logging' ), 10, 2 );

		if ( ! wpf_get_option( 'enable_logging' ) ) {
			return;
		}

		add_filter( 'wpf_configure_sections', array( $this, 'configure_sections' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'register_logger_subpage' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Clear the error count.
		add_action( 'load-tools_page_wpf-settings-logs', array( $this, 'clear_errors_count' ) );

		// Hide admin notices
		add_action( 'load-tools_page_wpf-settings-logs', array( $this, 'hide_notices' ) );

		// Track user IDs.
		add_action( 'wpf_get_contact_id_start', array( $this, 'set_log_source_user_id' ) );

		// Screen options.
		add_action( 'load-tools_page_wpf-settings-logs', array( $this, 'add_screen_options' ) );
		add_filter( 'set_screen_option_wpf_status_log_items_per_page', array( $this, 'set_screen_option' ), 10, 3 );

		// Export & Flush logs.
		add_action( 'admin_init', array( $this, 'export_logs' ) );
		add_action( 'admin_init', array( $this, 'flush_logs' ) );
		add_action( 'admin_init', array( $this, 'retry_api_call' ) );

		// Add log url redirect.
		add_action( 'load-tools_page_wpf-settings-logs', array( $this, 'log_url_redirect' ) );

		// HTTP API logging.
		if ( wpf_get_option( 'logging_http_api' ) ) {
			add_filter( 'http_request_args', array( $this, 'http_request_args' ), 10, 2 );
		}

		add_action( 'http_api_debug', array( $this, 'http_api_debug' ), 10, 5 );

		// Export & Flush logs.
		add_action( 'admin_init', array( $this, 'export_logs' ) );
		add_action( 'admin_init', array( $this, 'flush_logs' ) );

		// Error handling.
		add_action( 'shutdown', array( $this, 'shutdown' ) );

		// Create the table if it hasn't been created yet.
		if ( empty( wpf_get_option( 'log_table_version' ) ) ) {
			$this->create_update_table();
		}
	}


	/**
	 * Drops / re-creates the logging table when logging is disabled or re-enabled.
	 *
	 * @since 3.40.15
	 *
	 * @param bool  $input   The input.
	 * @param array $setting The setting.
	 */
	public function validate_enable_logging( $input, $setting ) {

		if ( $input ) {

			$this->create_update_table();

		} else {

			global $wpdb;
			$wpdb->query( "DROP TABLE {$wpdb->prefix}wpf_logging" );

		}

		return $input;
	}

	/**
	 * This allows us to manually prepend an event source to the log entry.
	 *
	 * @since 3.37.3
	 *
	 * @param string $source The source.
	 */

	public function add_source( $source ) {

		if ( ! in_array( $source, $this->event_sources, true ) ) {
			$this->event_sources[] = $source;
		}
	}

	/**
	 * This sets the user ID about to be queried, so it can be used with HTTP API
	 * logging.
	 *
	 * @since 3.40.29
	 *
	 * @param int $user_id The user ID.
	 */
	public function set_log_source_user_id( $user_id ) {
		$this->user_id = $user_id;
	}


	/**
	 * When HTTP API logging is enabled, send all event tracking API calls
	 * blocking, so we can read the responses.
	 *
	 * @since  3.38.28
	 *
	 * @param  array  $args   The HTTP request args.
	 * @param  string $url    The request URL.
	 * @return array  The request args.
	 */
	public function http_request_args( $args, $url ) {

		if ( 'WP Fusion; ' . home_url() === $args['user-agent'] ) {
			$args['blocking'] = true;
			$args['duration'] = microtime( true );
		}

		return $args;
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
		$message .= '<li><strong>Request URI:</strong> ' . sanitize_text_field( $url ) . '</li>';

		if ( ! is_wp_error( $response ) ) {
			unset( $response['http_response'] ); // This is redundant, we don't need to log it.

			if ( isset( $parsed_args['duration'] ) ) {
				$response['duration'] = microtime( true ) - $parsed_args['duration']; // Calcluate the duration.
				$message             .= '<li><strong>Duration:</strong> ' . round( $response['duration'], 2 ) . ' seconds</li>';
			}
		}

		if ( ! is_array( $parsed_args['body'] ) && ! empty( $parsed_args['body'] ) ) {
			$maybe_json = json_decode( $parsed_args['body'] );

			if ( ! is_null( $maybe_json ) ) {
				$message .= '<li><strong>Request (JSON Decoded):</strong><br /><pre>' . esc_html( wpf_print_r( $maybe_json, true ) ) . '</pre></li>';
			}
		}

		if ( ! is_wp_error( $response ) ) {
			$maybe_json = json_decode( $response['body'] );

			if ( ! is_null( $maybe_json ) ) {
				$message .= '<li><strong>Response (JSON Decoded):</strong><br /><pre>' . esc_html( wpf_print_r( $maybe_json, true ) ) . '</pre></li>';
			}
		}

		$message .= '<li><strong>Request:</strong> <pre>' . esc_html( wpf_print_r( array_filter( $parsed_args ), true ) ) . '</pre></li>';

		if ( ! is_wp_error( $response ) ) {
			$message .= '<li><strong>Response:</strong><br /><pre>' . esc_html( wpf_print_r( array_filter( $response ), true ) ) . '</pre></li>';
		} else {
			$message .= '<li><strong>Response:</strong><br /><pre>' . $response->get_error_message() . '</pre></li>';
		}

		$message .= '</ul>';

		$this->error_last = array(
			'message' => $message,
			'url'     => $url,
			'args'    => $parsed_args,
		);

		if ( wpf_get_option( 'logging_http_api' ) ) {
			$this->handle( 'http', $this->user_id, $message );
		}
	}

	/**
	 * Adds standalone log management page
	 *
	 * @access public
	 * @return void
	 */

	public function register_logger_subpage() {

		if ( ! wpf_get_option( 'connection_configured' ) ) {
			return;
		}

		$menu_title = __( 'WP Fusion Logs', 'wp-fusion-lite' );

		if ( wpf_get_option( 'logging_badge', true ) && ( ! isset( $_GET['page'] ) || 'wpf-settings-logs' !== $_GET['page'] ) ) {

			$errors_count = get_option( 'wpf_logs_unseen_errors', 0 );

			if ( $errors_count ) {

				// Add it to the WPF Logs menu item.
				$menu_title .= ' <span title="' . esc_attr( __( 'New WP Fusion API Errors', 'wp-fusion-lite' ) ) . '" class="awaiting-mod count-' . $errors_count . '">' . $errors_count . '</span>';

				// Add it to Tools.
				global $menu;

				$menu_item = wp_list_filter( $menu, array( 2 => 'tools.php' ) );

				if ( ! empty( $menu_item ) ) {
					$menu_item_position              = key( $menu_item ); // get the array key (position) of the element.
					$menu[ $menu_item_position ][0] .= ' <span title="' . esc_attr( __( 'New WP Fusion API Errors', 'wp-fusion-lite' ) ) . '" class="awaiting-mod count-' . $errors_count . '">' . $errors_count . '</span>';
				}
			}
		}

		$page = add_submenu_page(
			'tools.php',
			__( 'WP Fusion Activity Logs', 'wp-fusion-lite' ),
			$menu_title,
			'manage_options',
			'wpf-settings-logs',
			array( $this, 'show_logs_section' )
		);
	}


	/**
	 * Resets the errors count badge when the logs page is viewed
	 *
	 * @since 3.37.23
	 */
	public function clear_errors_count() {

		if ( wpf_get_option( 'logging_badge', true ) ) {
			delete_option( 'wpf_logs_unseen_errors' );
		}
	}

	/**
	 * Hides admin notices on the logs page.
	 *
	 * @since 3.42.6
	 */
	public function hide_notices() {

		remove_all_actions( 'admin_notices' );
	}


	/**
	 * Redirect to the correct page for log URL.
	 */
	public function log_url_redirect() {
		if ( isset( $_REQUEST['id'] ) && (int) $_REQUEST['id'] !== 0 && ! isset( $_REQUEST['paged'] ) ) {

			$log_id = intval( $_REQUEST['id'] );

			include_once WPF_DIR_PATH . 'includes/admin/logging/class-log-table-list.php';
			$log_table_list = new WPF_Log_Table_List();

			global $wpdb;
			$max_id   = $wpdb->get_var( "SELECT MAX(log_id) FROM `{$wpdb->prefix}wpf_logging`" );
			$per_page = $log_table_list->get_items_per_page( 'wpf_status_log_items_per_page', 20 );
			$paged    = ceil( ( (int) $max_id - (int) $log_id + 1 ) / $per_page );
			wp_safe_redirect( add_query_arg( array( 'paged' => $paged ), wp_unslash( esc_url_raw( $_SERVER['REQUEST_URI'] ) ) ) . '#' . $log_id );
			exit;
		}
	}

	/**
	 * Enqueues logger styles
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_scripts() {

		if ( 'tools_page_wpf-settings-logs' !== get_current_screen()->id ) {
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

		if ( 'wpf_status_log_items_per_page' === $option ) {
			return $value;
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

		$title = __( 'Logs', 'wp-fusion-lite' );

		if ( wpf_get_option( 'logging_badge', true ) ) {

			$errors_count = get_option( 'wpf_logs_unseen_errors', 0 );

			if ( $errors_count ) {

				// Add it to the WPF Logs menu item.
				$title .= ' <span title="' . esc_attr__( 'New WP Fusion API Errors', 'wp-fusion-lite' ) . '" class="awaiting-mod count-' . $errors_count . '">' . $errors_count . '</span>';

			}
		}

		$page['sections'] = wp_fusion()->settings->insert_setting_after(
			'advanced',
			$page['sections'],
			array(
				'logs' => array(
					'title' => $title . ' &rarr;',
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

		if ( $wpdb->get_var( "show tables like '$table_name'" ) !== $table_name ) {

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
	 * Flush all logs.
	 *
	 * @return void
	 */
	public function flush_logs() {

		// Flush logs.
		if ( ! empty( $_REQUEST['wpf-flush-logs'] ) ) { // phpcs:ignore

			if ( empty( $_REQUEST['wpf_logs_submit'] ) || ! wp_verify_nonce( $_REQUEST['wpf_logs_submit'], 'wp-fusion-status-logs' ) ) { // @codingStandardsIgnoreLine.
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-fusion-lite' ) );
			}

			self::flush();

			// Redirect to clear the URL.
			wp_redirect( esc_url_raw( admin_url( 'tools.php?page=wpf-settings-logs' ) ) );
			exit;
		}
	}


	/**
	 * Export logs from db to a csv file.
	 *
	 * @since 3.38.23
	 */
	public function export_logs() {

		if ( ! empty( $_REQUEST['export-logs'] ) ) {

			if ( empty( $_REQUEST['wpf_logs_submit'] ) || ! wp_verify_nonce( $_REQUEST['wpf_logs_submit'], 'wp-fusion-status-logs' ) ) { // @codingStandardsIgnoreLine.
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-fusion-lite' ) );
			}

			self::export();

		}
	}

	/**
	 * Retry a failed API call in the logs.
	 *
	 * @since 3.44.25
	 */
	public function retry_api_call() {

		if ( ! isset( $_GET['wpf-retry-api-call'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-fusion-lite' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wpf_retry_log' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'wp-fusion-lite' ) );
		}

		global $wpdb;

		$log_id = absint( $_GET['wpf-retry-api-call'] );
		$log    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpf_logging WHERE log_id = %d", $log_id ) );

		if ( ! $log ) {
			wp_die( esc_html__( 'Log entry not found.', 'wp-fusion-lite' ) );
		}

		$context = maybe_unserialize( $log->context );

		if ( empty( $context['request_uri'] ) || empty( $context['request_args'] ) ) {
			wp_die( esc_html__( 'No API request data found in log entry.', 'wp-fusion-lite' ) );
		}

		// Make sure we're using the latest authorization tokens / headers.
		$params = wp_fusion()->crm->get_params();

		if ( isset( $params['headers'] ) ) {
			$context['request_args']['headers'] = $params['headers'];
		}

		// Make the API call
		$response = wp_remote_request( $context['request_uri'], $context['request_args'] );

		// Log the response
		if ( is_wp_error( $response ) ) {
			wpf_log( 'error', $log->user, 'Error retrying API call: ' . $response->get_error_message() );
		} else {
			wpf_log( 'notice', $log->user, 'Successfully retried API call to ' . $context['request_uri'] );
		}

		// Redirect back to logs
		wp_safe_redirect( admin_url( 'tools.php?page=wpf-settings-logs' ) );
		exit;
	}


	/**
	 * Logging tab content
	 *
	 * @access public
	 * @return void
	 */

	public function show_logs_section() {

		include_once WPF_DIR_PATH . 'includes/admin/logging/class-log-table-list.php';

		// Bulk actions.
		if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['log'] ) ) { // @codingStandardsIgnoreLine.
			self::log_table_bulk_actions();
		}

		$log_table_list = new WPF_Log_Table_List();
		$log_table_list->prepare_items();

		?>

		<div class="wrap">

			<form method="get" id="mainform">

				<h1 class="wp-heading-inline"><?php esc_html_e( 'WP Fusion Activity Log', 'wp-fusion-lite' ); ?></h1>

				<?php wp_nonce_field( 'wp-fusion-status-logs', 'wpf_logs_submit', false ); ?>

				<input type="submit" id="search-submit" class="button" style="display:none;" value="Search logs"> <?php // This is here so that hitting enter on the pagination won't flush the logs. ?>

				<input style="vertical-align: baseline;" type="submit" name="wpf-flush-logs" id="flush-logs" class="button delete" value="<?php esc_attr_e( 'Flush all logs', 'wp-fusion-lite' ); ?>">

				<input style="vertical-align: baseline;" type="submit" name="export-logs" id="export-logs" class="button" value="<?php esc_attr_e( 'Export to .csv', 'wp-fusion-lite' ); ?>">

				<hr class="wp-header-end" />

				<span class="description" style="display: inline-block; padding: 5px 0;">
					<?php printf( esc_html__( 'For more information on the logs, %1$ssee our documentation%2$s.', 'wp-fusion-lite' ), '<a href="https://wpfusion.com/documentation/getting-started/activity-logs/" target="_blank">', '</a>' ); ?>
					<?php printf( esc_html__( 'To go back to the main settings page, %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings' ) ) . '">', '</a>' ); ?>
				</span>

				<?php if ( wpf_get_option( 'logging_errors_only' ) ) : ?>

					<div class="notice notice-warning">
						<p><?php echo wp_kses_post( __( '<strong>Note:</strong> The logs are currently set to record <strong>Only Errors</strong>, from the Advanced tab in the WP Fusion settings. Informational and debugging messages are not being recorded.', 'wp-fusion-lite' ) ); ?></p>
					</div>

				<?php endif; ?>

				<input type="hidden" name="page" value="wpf-settings-logs">

				<?php $log_table_list->display(); ?>

				<?php submit_button( esc_html__( 'Flush all logs', 'wp-fusion-lite' ), 'delete', 'wpf-flush-logs' ); ?>

				<?php if ( ! function_exists( 'fatal_error_notify' ) ) : ?>

					<div id="fen-pro">
						<div id="fen-pro-top">
							<img src="<?php echo esc_url( WPF_DIR_URL . 'assets/img/fen-pro-promo.png' ); ?>" />
						</div>
						<div class="fen-pro-center">

							<p><?php printf( esc_html__( '%1$sFatal Error Notify%2$s can send you an email, SMS, or Slack message when WP Fusion encounters an API error.', 'wp-fusion-lite' ), '<a href="https://fatalerrornotify.com/?utm_campaign=wpfusion&utm_source=wp-fusion-logs" target="_blank">', '</a>' ); ?></p>

							<p><?php esc_html_e( 'You can also set up notifications for other plugins, or WordPress itself.', 'wp-fusion-lite' ); ?></p>

							<p><?php printf( esc_html__( 'Take 50%% off your first year using coupon code %1$sWP-FUSION%2$s.', 'wp-fusion-lite' ), '<code>', '</code>' ); ?></p>
						
						</div>

						<a class="button-primary" href="https://fatalerrornotify.com/?utm_campaign=wpfusion&utm_source=wp-fusion-logs&discount=WP-FUSION" target="_blank">Learn More</a>

					</div>

				<?php endif; ?>

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
			$severity = 500; // unknown errors are 500.
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
	 * @param int    $timestamp Log timestamp.
	 *
	 * @see    WPF_Log_Handler::get_log_source() for default source.
	 * @return bool   False if value was not handled and true if value was handled.
	 */
	public function handle( $level, $user, $message, $context = array() ) {

		$timestamp = time();

		if ( ! empty( $context['source'] ) ) {
			$source = $context['source'];
		} else {
			$source = $this->get_log_source();
		}

		if ( 'http_request_failed' === $level ) {
			$level = 'error';
		}

		// Change "tags" to "lists" etc. in the message.
		$message = wp_fusion()->settings->set_tag_labels( $message, false, 'wp-fusion-lite' );

		// If a custom object type is in use, change it in the message.
		if ( ! empty( wp_fusion()->crm->object_type ) ) {

			$selected_type = strtolower( rtrim( wp_fusion()->crm->object_type, 's' ) ); // make singular.

			if ( 'contact' !== $selected_type ) {
				$message = str_replace( 'contact', $selected_type, $message );
			}
		}

		// At this point we've got enough info to pass it on to Fatal Error
		// Notify or other integrations via wpf_handle_log.

		do_action( 'wpf_handle_log', $timestamp, $level, $user, $message, $context );

		do_action( "wpf_handle_log_{$level}", $timestamp, $user, $message, $context );

		// If logging isn't enabled, nothing more to do.

		if ( ! wpf_get_option( 'enable_logging' ) ) {
			return;
		}

		if ( wpf_get_option( 'logging_errors_only' ) && 'error' !== $level ) {
			return;
		}

		// Filter out irrelevant meta fields and show any field format changes (don't do it when loading data).
		if ( ! empty( $context['meta_array'] ) ) {

			$contact_fields = wpf_get_option( 'contact_fields', array() );

			foreach ( $context['meta_array'] as $key => $data ) {

				if ( ! isset( $contact_fields[ $key ] ) || empty( $contact_fields[ $key ]['active'] ) ) {
					unset( $context['meta_array'][ $key ] );
					continue;
				}

				if ( ! did_action( 'wpf_pre_pull_user_meta' ) ) {

					// If we're sending data to the CRM, also log what might have changed.

					if ( ! isset( $contact_fields[ $key ]['type'] ) ) {
						$contact_fields[ $key ]['type'] = 'text';
					}

					$filtered_value = apply_filters( 'wpf_format_field_value', $data, $contact_fields[ $key ]['type'], $contact_fields[ $key ]['crm_field'] );

					if ( $data !== $filtered_value ) {

						// Store what happened to the data so we can show a little more context in the logs.
						$context['meta_array'][ $key ] = array(
							'original' => $data,
							'new'      => $filtered_value,
							'type'     => $contact_fields[ $key ]['type'],
						);

						if ( 'date' === $contact_fields[ $key ]['type'] && false === $filtered_value ) {
							wpf_log( 'notice', $user, 'Unable to convert date value <strong>' . $data . '</strong> into a timestamp (using <code>strtotime()</code>).', array( 'source' => wp_fusion()->crm->slug ) );
						}
					}
				}
			}
		} elseif ( ! empty( $context['meta_array'] ) && did_action( 'wpf_pre_pull_user_meta' ) ) {

			// When loading data, we'll include an indicator on any pseudo fields.
			foreach ( $context['meta_array'] as $key => $data ) {

				if ( wpf_is_pseudo_field( $key ) ) {

					$context['meta_array'][ $key ] = array(
						'original' => $data,
						'pseudo'   => true,
					);
				}
			}
		}

		if ( empty( $user ) ) {
			$user = 0;
		}

		// Don't log meta data pushes where no enabled fields are being synced.
		if ( 'info' === $level && isset( $context['meta_array'] ) && empty( $context['meta_array'] ) ) {
			return;
		}

		// Track errors.
		if ( 'error' === $level && wpf_get_option( 'logging_badge', true ) ) {

			$count = get_option( 'wpf_logs_unseen_errors', 0 );

			++$count;

			update_option( 'wpf_logs_unseen_errors', $count );

		}

		// Save it so it can be retried.
		if ( 'error' === $level && ! empty( $this->error_last ) ) {
			$context['request_uri']  = $this->error_last['url'];
			$context['request_args'] = $this->error_last['args'];

			$message .= '<hr>' . $this->error_last['message'];

			$this->error_last = array();

		}

		do_action( 'wpf_log_handled', $timestamp, $level, $user, $message, $source, $context );

		$result = $this->add( $timestamp, $level, $user, $message, $source, $context );

		return $result;
	}

	/**
	 * Add a log entry to chosen file.
	 *
	 * @since  3.3.0
	 *
	 * @param  int    $timestamp The timestamp.
	 * @param  string $level     emergency|alert|critical|error|warning|notice|info|debug.
	 * @param  int    $user      The user ID.
	 * @param  string $message   Log message.
	 * @param  string $source    Log source. Useful for filtering and sorting.
	 * @param  array  $context   { Context will be serialized and stored in
	 *                           database. }.
	 * @return bool   True if write was successful.
	 */
	protected static function add( $timestamp, $level, $user, $message, $source, $context = array() ) {
		global $wpdb;

		$insert = array(
			'timestamp' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'level'     => self::get_level_severity( $level ),
			'user'      => $user,
			'message'   => $message,
			'source'    => maybe_serialize( $source ),
		);

		$format = array(
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s', // possible serialized context.
		);

		if ( ! empty( $context ) ) {
			$insert['context'] = serialize( $context );
		}

		$result = $wpdb->insert( "{$wpdb->prefix}wpf_logging", $insert, $format );

		if ( false === $result ) {
			return false;
		}

		$rowcount = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpf_logging" );

		$max_log_size = apply_filters( 'wpf_log_max_entries', 10000 );

		if ( $rowcount > $max_log_size ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}wpf_logging ORDER BY log_id ASC LIMIT 100" ); // Delete 100 so we don't need to run this with every new entry.
		}

		return $result;
	}

	/**
	 * Export all db data to csv file.
	 *
	 * @since 3.38.23
	 */
	public static function export() {

		global $wpdb;

		// Build the WHERE clause based on filters.
		$where_clauses = array();
		$where_values  = array();

		// Filter by user.
		if ( ! empty( $_REQUEST['user'] ) ) {
			$where_clauses[] = 'user = %d';
			$where_values[]  = absint( $_REQUEST['user'] );
		}

		// Filter by level.
		if ( ! empty( $_REQUEST['level'] ) ) {
			$where_clauses[] = 'level = %d';
			$where_values[]  = self::get_level_severity( $_REQUEST['level'] );
		}

		// Filter by source.
		if ( ! empty( $_REQUEST['source'] ) ) {
			$where_clauses[] = 'source LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $_REQUEST['source'] ) . '%';
		}

		// Filter by date range.
		if ( ! empty( $_REQUEST['startdate'] ) ) {
			$where_clauses[] = 'timestamp >= %s';
			$where_values[]  = $_REQUEST['startdate'] . ' 00:00:00';
		}
		if ( ! empty( $_REQUEST['enddate'] ) ) {
			$where_clauses[] = 'timestamp <= %s';
			$where_values[]  = $_REQUEST['enddate'] . ' 23:59:59';
		}

		// Build the final query.
		$query = "SELECT * FROM {$wpdb->prefix}wpf_logging";
		if ( ! empty( $where_clauses ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where_clauses );
		}
		$query .= ' ORDER BY log_id DESC';

		$results = $wpdb->get_results( $wpdb->prepare( $query, $where_values ) );

		if ( ! empty( $results ) ) {
			$filename = 'wp-fusion-activity-logs-' . gmdate( 'Y-m-d' ) . '.csv';

			header( 'Pragma: public' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Cache-Control: private', false );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename . ';' );
			header( 'Content-Transfer-Encoding: binary' );

			$output = fopen( 'php://output', 'w' );
			$fields = array( 'ID', 'Time', 'Level', 'User', 'Source', 'Message', 'Context' );
			fputcsv( $output, $fields );

			// Output each row of the data, format line as csv and write to file pointer.
			foreach ( $results as $result ) {
				$line_data = array(
					$result->log_id,
					$result->timestamp,
					self::get_severity_level( $result->level ),
					$result->user,
					print_r( maybe_unserialize( $result->source ), true ),
					wp_strip_all_tags( htmlspecialchars_decode( $result->message ) ),
					print_r( maybe_unserialize( $result->context ), true ),
				);

				fputcsv( $output, $line_data );
			}

			fclose( $output ) or die( 'Can\'t close output file' );
			exit;
		}
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

		if ( empty( $_REQUEST['wpf_logs_submit'] ) || ! wp_verify_nonce( $_REQUEST['wpf_logs_submit'], 'wp-fusion-status-logs' ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-fusion-lite' ) );
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

		// Get the available files that are valid as a log source.

		$slugs = array( 'user-profile', 'api', 'access-control', 'auto-login', 'ajax', 'class-shortcodes' );

		foreach ( wp_fusion()->get_integrations() as $slug => $integration ) {
			$slugs[] = $slug;
		}

		$found_integrations = array();

		// If we're doing a batch operation it's good to know which one, we'll prepend that here.
		if ( defined( 'DOING_WPF_BATCH_TASK' ) ) {

			$found_integrations[] = 'batch-process';

			$found_integrations[] = str_replace( '_', '-', DOING_WPF_BATCH_TASK );

		}

		// This allows us to preprend a source manually

		if ( ! empty( $this->event_sources ) ) {
			$found_integrations  = array_merge( $found_integrations, $this->event_sources );
			$this->event_sources = array(); // clear it out.
		}

		$full_trace = array_reverse( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ) );

		foreach ( $full_trace as $trace ) {

			if ( isset( $trace['file'] ) ) {

				if ( 'login' === $trace['function'] ) {
					$found_integrations[] = 'user-login';
					continue;
				}

				if ( 'edit_user' === $trace['function'] ) {
					$found_integrations[] = 'user-profile';
					continue;
				}

				foreach ( $slugs as $slug ) {

					if ( empty( $slug ) ) {
						continue;
					}

					if ( strpos( $trace['file'], 'class-' . $slug ) !== false ) {

						// Remove the "class".

						$slug = str_replace( 'class-', '', $slug );

						$found_integrations[] = $slug;
					}
				}
			}
		}

		// Figure out most likely integration.
		if ( ! empty( $found_integrations ) ) {
			$source = array_unique( $found_integrations );
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