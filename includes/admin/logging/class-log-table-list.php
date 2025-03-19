<?php

/**
 * WP Fusion Log Table List
 *
 * @author   WooThemes
 * @category Admin
 * @package  WP Fusion/Admin
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WPF_Log_Table_List extends WP_List_Table {

	/**
	 * Initialize the log table list.
	 */
	public function __construct() {

		parent::__construct(
			array(
				'singular' => 'wpf-log',
				'plural'   => 'wpf-logs',
				'ajax'     => false,
			)
		);
	}


	/**
	 * Display level dropdown
	 *
	 * @global wpdb $wpdb
	 */
	public function level_dropdown() {

		$levels = array(
			array(
				'value' => 'error',
				'label' => __( 'Error', 'wp-fusion-lite' ),
			),
			array(
				'value' => 'warning',
				'label' => __( 'Warning', 'wp-fusion-lite' ),
			),
			array(
				'value' => 'notice',
				'label' => __( 'Notice', 'wp-fusion-lite' ),
			),
			array(
				'value' => 'info',
				'label' => __( 'Info', 'wp-fusion-lite' ),
			),
			array(
				'value' => 'http',
				'label' => __( 'HTTP', 'wp-fusion-lite' ),
			),
		);

		$selected_level = isset( $_REQUEST['level'] ) ? esc_attr( $_REQUEST['level'] ) : '';
		?>
			<label for="filter-by-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'wp-fusion-lite' ); ?></label>
			<select name="level" id="filter-by-level">
				<option<?php selected( $selected_level, '' ); ?> value=""><?php esc_html_e( 'All levels', 'wp-fusion-lite' ); ?></option>
				<?php
				foreach ( $levels as $l ) {
					printf(
						'<option%1$s value="%2$s">%3$s</option>',
						selected( $selected_level, $l['value'], false ),
						esc_attr( $l['value'] ),
						esc_html( $l['label'] )
					);
				}
				?>
			</select>
		<?php
	}


	/**
	 * Generates content for a single row of the table.
	 *
	 * @param object|array $item The current item
	 */
	public function single_row( $item ) {
		echo '<tr';

		if ( isset( $item['log_id'] ) ) {
			echo ' id="' . esc_attr( $item['log_id'] ) . '"';

			if ( isset( $_GET['id'] ) && $_GET['id'] === $item['log_id'] ) {
				echo ' class="active"';
			}
		}

		echo '>';

		$this->single_row_columns( $item );
		echo '</tr>';
	}


	/**
	 * Get list columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'timestamp' => __( 'Timestamp', 'wp-fusion-lite' ),
			'level'     => __( 'Level', 'wp-fusion-lite' ),
			'user'      => __( 'User', 'wp-fusion-lite' ),
			'message'   => __( 'Message', 'wp-fusion-lite' ),
			'source'    => __( 'Source', 'wp-fusion-lite' ),
		);
	}

	/**
	 * Column cb.
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_cb( $log ) {
		return sprintf( '<input type="checkbox" name="log[]" value="%1$s" />', esc_attr( $log['log_id'] ) );
	}

	/**
	 * Timestamp column.
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_timestamp( $log ) {
		$output = esc_html(
			mysql2date(
				get_option( 'date_format' ) . ' H:i:s',
				$log['timestamp']
			)
		);

		$link    = admin_url( "tools.php?page=wpf-settings-logs&id={$log['log_id']}#{$log['log_id']}" );
		$output .= sprintf( __( '<a class="wpf-log-link" href="%s"><span class="dashicons dashicons-admin-links"></span></a>', 'wp-fusion-lite' ), $link );

		return $output;
	}

	/**
	 * Level column.
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_level( $log ) {
		$level_key = wp_fusion()->logger->get_severity_level( $log['level'] );
		$levels    = array(
			'error'   => __( 'Error', 'wp-fusion-lite' ),
			'warning' => __( 'Warning', 'wp-fusion-lite' ),
			'notice'  => __( 'Notice', 'wp-fusion-lite' ),
			'info'    => __( 'Info', 'wp-fusion-lite' ),
			'http'    => __( 'HTTP', 'wp-fusion-lite' ),
		);

		if ( isset( $levels[ $level_key ] ) ) {
			$level       = $levels[ $level_key ];
			$level_class = sanitize_html_class( 'log-level--' . $level_key );
			$return      = '<span class="log-level ' . $level_class . '">' . esc_html( $level ) . '</span>';

			if ( isset( $log['context'] ) ) {

				$context = maybe_unserialize( $log['context'] );

				if ( isset( $context['request_uri'] ) && isset( $context['request_args'] ) ) {

					$link    = admin_url( "tools.php?page=wpf-settings-logs&wpf-retry-api-call={$log['log_id']}&_wpnonce=" . wp_create_nonce( 'wpf_retry_log' ) );
					$return .= ' <a href="' . esc_url( $link ) . '" class="wpf-logs-retry-link dashicons dashicons-update wpf-tip wpf-tip-right" data-tip="' . esc_attr__( 'Retry API call', 'wp-fusion-lite' ) . '"></a>';
				}
			}

			return $return;

		} else {
			return '';
		}
	}

	/**
	 * User column
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_user( $log ) {

		if ( empty( $log['user'] ) || $log['user'] < 1 ) {
			return __( 'system', 'wp-fusion-lite' );
		}

		$userdata = get_userdata( $log['user'] );

		if ( false === $userdata && intval( $log['user'] ) >= 100000000 ) {
			// Auto-login users.
			/* translators: %d user ID */
			return sprintf( __( 'auto-login-%d', 'wp-fusion-lite' ), absint( $log['user'] ) );
		} elseif ( false === $userdata ) {
			// Deleted users.
			/* translators: %d user ID */
			return sprintf( __( '(deleted user %d)', 'wp-fusion-lite' ), absint( $log['user'] ) );
		}

		$ret = '<a href="' . esc_url( get_edit_user_link( $log['user'] ) ) . '">' . esc_html( $userdata->data->user_login ) . '</a>';

		$contact_id = wp_fusion()->user->get_contact_id( $log['user'] );
		$edit_url   = wp_fusion()->crm->get_contact_edit_url( $contact_id );

		if ( $edit_url ) {
			$ret .= ' (<a title="' . sprintf( esc_attr__( 'View in %s', 'wp-fusion-lite' ), wp_fusion()->crm->name ) . ' &raquo;" href="' . esc_url( $edit_url ) . '" target="_blank">#' . esc_html( $contact_id ) . '<span class="dashicons dashicons-external"></span></a>)';
		}

		return $ret;
	}

	/**
	 * Message column.
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_message( $log ) {

		$output = $log['message'];

		// Add links to edit contact in CRM, if supported.

		if ( isset( wp_fusion()->crm->object_type ) ) {
			$type = strtolower( rtrim( wp_fusion()->crm->object_type, 's' ) ); // make singular (for D365).
		} else {
			$type = 'contact';
		}

		if ( false !== strpos( $output, ' ' . $type . ' #' ) ) {

			preg_match( '/(?<=' . $type . ' #)[\w-]*/', $output, $contact_id );

			if ( ! empty( $contact_id ) ) {

				$contact_id = $contact_id[0];

				$url = wp_fusion()->crm->get_contact_edit_url( $contact_id );

				if ( false !== $url ) {
					$output = preg_replace( '/' . $type . '\ #[\w-]*/', $type . ' <a href="' . $url . '" target="_blank">#' . $contact_id . '<span class="dashicons dashicons-external"></span></a>', $output );
				}
			}
		}

		// Make the log context readable.

		if ( ! empty( $log['context'] ) ) {

			$context = maybe_unserialize( $log['context'] );

			if ( empty( $context['meta_array'] ) && ! empty( $context['meta_array_nofilter'] ) ) {
				$context['meta_array'] = $context['meta_array_nofilter'];
			}

			if ( ! empty( $context['meta_array'] ) ) {

				$output .= '<br /><ul class="log-table-meta-fields">';

				foreach ( $context['meta_array'] as $key => $value ) {

					$output .= '<li><strong>' . $key . '</strong>: ';

					if ( is_array( $value ) && isset( $value['pseudo'] ) ) {

						// Value is a pseudo field and won't be saved.
						$text = __( 'This field is a pseudo field or read-only field, and will not be saved to the database.', 'wp-fusion-lite' );

						// print_r arrays / original value.
						if ( is_array( $value['original'] ) || is_object( $value['original'] ) ) {
							// phpcs:ignore
							$value['original'] = '<pre>' . wpf_print_r( $value['original'], true ) . '</pre>';
						}

						$output .= '<strike>' . $value['original'] . '</strike> ';
						$output .= '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-right" data-tip="' . $text . '"></span>';

					} elseif ( is_array( $value ) && array_key_exists( 'original', $value ) ) {

						$text = sprintf(
							/* translators: %1$s crm name %2$s field format */
							__( 'This value was modified by the wpf_format_field_value filter before being sent to %1$s, using field format %2$s.', 'wp-fusion-lite' ),
							wp_fusion()->crm->name,
							$value['type']
						);

						// print_r arrays / original value.
						if ( is_array( $value['original'] ) || is_object( $value['original'] ) ) {
							$value['original'] = '<pre>' . wpf_print_r( $value['original'], true ) . '</pre>';
						} elseif ( empty( $value['original'] ) ) {
							$value['original'] = '<code>0</code>';
						}

						// print_r arrays / new value.
						if ( is_array( $value['new'] ) || is_object( $value['new'] ) ) {
							$value['new'] = '<pre>' . wpf_print_r( $value['new'], true ) . '</pre>';
						}

						$output .= $value['original'] . ' &rarr; <code>' . $value['new'] . '</code>';
						$output .= '<span class="dashicons dashicons-editor-help wpf-tip wpf-tip-right" data-tip="' . $text . '"></span>';

					} elseif ( is_array( $value ) || is_object( $value ) ) {
						$output .= '<pre>' . wpf_print_r( $value, true ) . '</pre>';
					} else {
						$output .= $value;
					}

					$output .= '</li>';
				}

				$output .= '</ul>';

			}

			if ( ! empty( $context['tag_array'] ) ) {

				$output .= '<br /><ul class="log-table-tag-list">';

				foreach ( $context['tag_array'] as $tag_id ) {
					$output .= '<li>' . wp_fusion()->user->get_tag_label( $tag_id ) . '</li>';
				}

				$output .= '</ul>';

			}

			if ( ! empty( $context['args'] ) ) {

				$output .= '<pre>' . wpf_print_r( $context['args'], true ) . '</pre>';

			}
		}

		$allowed_tags = array(
			'ul'     => array(),
			'li'     => array(),
			'hr'     => array(),
			'code'   => array(),
			'pre'    => array(),
			'strong' => array(),
			'span'   => array(
				'class'    => true,
				'data-tip' => true,
			),
			'strike' => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => true,
				'target' => true,
			),
		);

		return wp_kses( $output, $allowed_tags );
	}

	/**
	 * Source column.
	 *
	 * @param  array $log
	 * @return string
	 */
	public function column_source( $log ) {

		$log['source'] = maybe_unserialize( $log['source'] );

		if ( is_array( $log['source'] ) ) {
			$log['source'] = implode( ' &raquo; ', $log['source'] );
		}

		return esc_html( $log['source'] );
	}

	/**
	 * Search input field for searching logs.
	 *
	 * @since 3.44.2
	 */
	public function search_text() {
		echo '<input type="text" placeholder="' . esc_attr__( 'Search Logs', 'wp-fusion-lite' ) . '" name="s" value="' . ( isset( $_REQUEST['s'] ) ? esc_attr( $_REQUEST['s'] ) : '' ) . '" />';
		submit_button( esc_html__( 'Search Logs', 'wp-fusion-lite' ), '', 'search-action', false );
	}

	/**
	 * Generates the table navigation above or below the table
	 *
	 * @since 3.44.2
	 *
	 * @param string $which The location of the navigation: Either 'top' or 'bottom'.
	 */
	public function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			echo '<div class="alignright wpf-log-header">';
			$this->search_text();
			echo '</div>';
		}

		parent::display_tablenav( $which );
	}


	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' === $which ) {
			echo '<div class="alignleft actions">';
				$this->level_dropdown();
				$this->source_dropdown();
				$this->user_dropdown();
				$this->date_select();
				submit_button( esc_html__( 'Filter', 'wp-fusion-lite' ), '', 'filter-action', false );
			echo '</div>';
		}
	}

	/**
	 * Get a list of sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'timestamp' => array( 'timestamp', true ),
			'level'     => array( 'level', true ),
			'user'      => array( 'user', true ),
			'source'    => array( 'source', true ),
		);
	}

	/**
	 * Display source dropdown
	 *
	 * @global wpdb $wpdb
	 */
	protected function source_dropdown() {
		global $wpdb;

		$sources_db = $wpdb->get_col(
			"
			SELECT DISTINCT source
			FROM {$wpdb->prefix}wpf_logging
			WHERE source != ''
			ORDER BY source ASC
		"
		);

		if ( ! empty( $sources_db ) ) {

			$sources = array();

			foreach ( $sources_db as $source ) {

				$source = maybe_unserialize( $source );

				if ( is_array( $source ) ) {
					$sources = array_merge( $sources, $source );
				} else {
					$sources[] = $source;
				}
			}

			$sources = array_filter( array_unique( $sources ) );

			sort( $sources );

			$selected_source = isset( $_REQUEST['source'] ) ? esc_attr( $_REQUEST['source'] ) : '';
			?>
				<label for="filter-by-source" class="screen-reader-text"><?php esc_html_e( 'Filter by source', 'wp-fusion-lite' ); ?></label>
				<select name="source" id="filter-by-source">
					<option<?php selected( $selected_source, '' ); ?> value=""><?php esc_html_e( 'All sources', 'wp-fusion-lite' ); ?></option>
					<?php
					foreach ( $sources as $s ) {
						printf(
							'<option%1$s value="%2$s">%3$s</option>',
							selected( $selected_source, $s, false ),
							esc_attr( $s ),
							esc_html( $s )
						);
					}
					?>
				</select>
			<?php
		}
	}

	/**
	 * Display user dropdown
	 *
	 * @global wpdb $wpdb
	 */
	protected function user_dropdown() {

		$selected_user = isset( $_REQUEST['user'] ) ? absint( $_REQUEST['user'] ) : '';

		?>
			<label for="filter-by-user" class="screen-reader-text"><?php esc_html_e( 'Filter by user', 'wp-fusion-lite' ); ?></label>
			<select class="select4-users-log" name="user" id="filter-by-user">
				<option value=""><?php esc_html_e( 'All users', 'wp-fusion-lite' ); ?></option>
				<?php
				if ( ! empty( $selected_user ) ) {
					$user = get_user_by( 'id', $selected_user );
					if ( $user ) {
						echo '<option selected value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->user_login ) . '</option>';
					}
				}
				?>
			</select>

		<?php
	}


	/**
	 * Display date selector
	 *
	 * @since 3.35.17
	 *
	 * @return mixed HTML output
	 */
	protected function date_select() {

		?>
			<label for="start-date" class="screen-reader-text"><?php esc_html_e( 'Start date', 'wp-fusion-lite' ); ?></label>

			<?php if ( ! empty( $_GET['startdate'] ) ) : ?>
				<input placeholder="<?php esc_attr_e( 'Start date', 'wp-fusion-lite' ); ?>" name="startdate" id="start-date" type="date" value="<?php echo esc_attr( $_GET['startdate'] ); ?>" />
			<?php else : ?>
				<input placeholder="<?php esc_attr_e( 'Start date', 'wp-fusion-lite' ); ?>" name="startdate" id="start-date" type="text" onfocus="(this.type='date')" />
			<?php endif; ?>

			<label for="end-date" class="screen-reader-text"><?php esc_html_e( 'End date', 'wp-fusion-lite' ); ?></label>

			<?php if ( ! empty( $_GET['enddate'] ) ) : ?>
				<input placeholder="<?php esc_attr_e( 'End date', 'wp-fusion-lite' ); ?>" name="enddate" id="end-date" type="date" value="<?php echo esc_attr( $_GET['enddate'] ); ?>" />
			<?php else : ?>
				<input placeholder="<?php esc_attr_e( 'End date', 'wp-fusion-lite' ); ?>" name="enddate" id="end-date" type="text" onfocus="(this.type='date')" />
			<?php endif; ?>

		<?php
	}

	/**
	 * Prepare table list items.
	 *
	 * @global wpdb $wpdb
	 */
	public function prepare_items() {

		if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) {

			// Cleans up the logs URL when filtering entries to make it easier
			// to read / link to. Doing it here to avoid having to run the
			// queries twice.
			//
			// @since 3.38.45.

			$args = array(
				'_wp_http_referer',
				'_wpnonce',
				'filter-action',
				'wpf_logs_submit',
			);

			if ( isset( $_GET['paged'] ) && '1' === $_GET['paged'] ) {
				$args[] = 'paged'; // don't need this if it's 1.
			}

			foreach ( $_GET as $key => $val ) {
				if ( empty( $val ) ) {
					$args[] = $key;
				}
			}

			if ( ! headers_sent() ) {
				wp_safe_redirect( remove_query_arg( $args, wp_unslash( esc_url_raw( $_SERVER['REQUEST_URI'] ) ) ) );
				exit;
			}
		}

		global $wpdb;

		$this->prepare_column_headers();

		$per_page = $this->get_items_per_page( 'wpf_status_log_items_per_page', 20 );

		$where  = $this->get_items_query_where();
		$order  = $this->get_items_query_order();
		$limit  = $this->get_items_query_limit();
		$offset = $this->get_items_query_offset();

		$query_items = "
			SELECT log_id, timestamp, level, user, message, source, context
			FROM {$wpdb->prefix}wpf_logging
			{$where} {$order} {$limit} {$offset}
		";

		$this->items = $wpdb->get_results( $query_items, ARRAY_A );

		$query_count = "SELECT COUNT(log_id) FROM {$wpdb->prefix}wpf_logging {$where}";
		$total_items = $wpdb->get_var( $query_count );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Get prepared LIMIT clause for items query
	 *
	 * @global wpdb $wpdb
	 *
	 * @return string Prepared LIMIT clause for items query.
	 */
	protected function get_items_query_limit() {
		global $wpdb;

		$per_page = $this->get_items_per_page( 'wpf_status_log_items_per_page', 20 );
		return $wpdb->prepare( 'LIMIT %d', $per_page );
	}

	/**
	 * Get prepared OFFSET clause for items query
	 *
	 * @global wpdb $wpdb
	 *
	 * @return string Prepared OFFSET clause for items query.
	 */
	protected function get_items_query_offset() {
		global $wpdb;

		$per_page     = $this->get_items_per_page( 'wpf_status_log_items_per_page', 20 );
		$current_page = $this->get_pagenum();
		if ( 1 < $current_page ) {
			$offset = $per_page * ( $current_page - 1 );
		} else {
			$offset = 0;
		}

		return $wpdb->prepare( 'OFFSET %d', $offset );
	}

	/**
	 * Get prepared ORDER BY clause for items query
	 *
	 * @return string Prepared ORDER BY clause for items query.
	 */
	protected function get_items_query_order() {
		$valid_orders = array( 'level', 'source', 'timestamp', 'user' );
		if ( ! empty( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $valid_orders ) ) {
			// $by = wc_clean( $_REQUEST['orderby'] );
			$by = esc_attr( $_REQUEST['orderby'] );
		} else {
			$by = 'log_id';
		}
		$by = esc_sql( $by );

		if ( ! empty( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ) {
			$order = 'ASC';
		} else {
			$order = 'DESC';
		}

		return "ORDER BY {$by} {$order}, log_id {$order}";
	}

	/**
	 * Get prepared WHERE clause for items query
	 *
	 * @global wpdb $wpdb
	 *
	 * @return string Prepared WHERE clause for items query.
	 */
	protected function get_items_query_where() {

		global $wpdb;

		$where_conditions = array();
		$where_values     = array();
		if ( ! empty( $_REQUEST['level'] ) && wp_fusion()->logger->is_valid_level( $_REQUEST['level'] ) ) {
			$where_conditions[] = 'level = %d';
			$where_values[]     = wp_fusion()->logger->get_level_severity( $_REQUEST['level'] );
		}
		if ( ! empty( $_REQUEST['source'] ) ) {
			$where_conditions[] = 'source LIKE %s';
			$where_values[]     = '%' . $wpdb->esc_like( $_REQUEST['source'] ) . '%';
		}

		if ( ! empty( $_REQUEST['user'] ) ) {
			$where_conditions[] = 'user = %s';
			$where_values[]     = absint( $_REQUEST['user'] );
		}

		if ( ! empty( $_REQUEST['startdate'] ) ) {
			$where_conditions[] = 'timestamp > %s';
			$where_values[]     = sanitize_text_field( $_REQUEST['startdate'] );
		}

		if ( ! empty( $_REQUEST['enddate'] ) ) {
			$where_conditions[] = 'timestamp < %s';
			$where_values[]     = sanitize_text_field( $_REQUEST['enddate'] );
		}

		if ( ! empty( $_REQUEST['s'] ) ) {
			$search_term        = '%' . $wpdb->esc_like( $_REQUEST['s'] ) . '%';
			$where_conditions[] = '(message LIKE %s OR context LIKE %s OR source LIKE %s)';
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
			$where_values[]     = $search_term;
		}

		if ( ! empty( $where_conditions ) ) {
			return $wpdb->prepare( 'WHERE 1 = 1 AND ' . implode( ' AND ', $where_conditions ), $where_values );
		} else {
			return '';
		}
	}

	/**
	 * Set _column_headers property for table list
	 */
	protected function prepare_column_headers() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}
}
