<?php
/**
 * WP Fusion - Tag Migration Handler
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since 3.47.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic tag ID migration engine.
 *
 * Applies a provided id_map across all known storage locations in the
 * database. Storage key registries are exposed as public static methods so
 * that CRM-specific scan phases and future tag-report tooling can share the
 * same definitions without instantiating the class.
 *
 * @package WP Fusion
 * @since   3.47.8
 */
class WPF_Admin_Tag_Migration {

	/**
	 * The id map from legacy tag IDs to new ones.
	 *
	 * @since 3.47.8
	 * @var array<string, string>
	 */
	private $id_map;

	/**
	 * Constructor.
	 *
	 * @since 3.47.8
	 *
	 * @param array $id_map Legacy to new tag ID mapping (old_id => new_id).
	 */
	public function __construct( array $id_map ) {
		$this->id_map = $id_map;
	}

	// -------------------------------------------------------------------------
	// Storage key registries (public static so scan phases can use them too).
	// -------------------------------------------------------------------------

	/**
	 * Returns all postmeta keys and their tag sub-keys for migration.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string, array<string>> Meta key => array of tag sub-keys.
	 */
	public static function get_postmeta_tag_keys() {

		$keys = array(
			'wpf-settings'              => array(
				'allow_tags',
				'allow_tags_all',
				'allow_tags_not',
				'apply_tags',
				'remove_tags',
				'apply_tags_suredash_complete',
			),
			'wpf-settings-learndash'    => array(
				'tag_link',
				'apply_tags_enrolled',
				'apply_tags_ld',
				'apply_tags_complete',
				'remove_tags',
				'leader_tag',
			),
			'wpf-settings-woo'          => array(
				'apply_tags',
				'remove_tags',
				'apply_tags_paid_in_full',
				'allow_tags',
				'apply_tags_variation',
				'allow_tags_variation',
				'allow_tags_not_variation',
				'tag_link',
				'apply_tags_active',
				'apply_tags_expired',
				'apply_tags_cancelled',
				'apply_tags_pending',
				'apply_tags_complimentary',
				'apply_tags_free_trial',
				'apply_tags_paused',
			),
			'wpf-settings-woo-memberships-teams' => array(
				'apply_tags_memberships_teams',
				'link_tag_memberships_teams',
			),
			'wpf-settings-memberpress'  => array(
				'apply_tags',
				'apply_tags_registration',
				'apply_tags_refunded',
				'tag_link',
				'remove_tags',
			),
			'wpf-settings-edd'          => array(
				'apply_tags',
				'apply_tags_price',
				'apply_tags_refunded',
				'apply_tags_refund_price',
				'allow_tags_price',
			),
			'wpf-settings-um'           => array(
				'apply_tags',
			),
			'wpf_settings_llms_voucher' => array(
				'apply_tags_voucher',
			),
			'wpf-settings-llms-plan'    => array(
				'apply_tags',
				'allow_tags',
			),
			'wpf_settings_llms_group'   => array(
				'apply_tags',
				'remove_tags',
			),
			'wpf-settings-badgeos'      => array(
				'tag_link',
			),
			'wpf_settings_suredash'     => array(
				'apply_tags_complete',
				'required_tags',
			),
			'wpf_block_settings'        => array(
				'apply_tags',
				'apply_tags_deleted',
				'apply_tags_checkin',
			),
			'wpf_settings'              => array(
				'apply_tags',
				'apply_tags_deleted',
				'apply_tags_checkin',
			),
		);

			$keys = apply_filters( 'wpf_hubspot_migration_postmeta_tag_keys', $keys );

			return apply_filters( 'wpf_migration_postmeta_tag_keys', $keys );
	}


	/**
	 * Returns the taxonomy rule sub-keys that contain tag IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string>
	 */
	public static function get_taxonomy_rule_tag_keys() {
		return array( 'allow_tags', 'allow_tags_all', 'apply_tags' );
	}


	/**
	 * Returns the term meta keys and their tag sub-keys for migration.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string, array<string>> Meta key => array of tag sub-keys.
	 */
	public static function get_termmeta_tag_keys() {

		$keys = array(
			'wpf-settings-woo'        => array( 'apply_tags' ),
			'wpf_settings_llms_track' => array( 'apply_tags_complete' ),
			'wpf_settings_event'      => array( 'apply_tags' ),
		);

		$keys = apply_filters( 'wpf_hubspot_migration_termmeta_tag_keys', $keys );

		return apply_filters( 'wpf_migration_termmeta_tag_keys', $keys );
	}


	/**
	 * Returns option name patterns and their tag sub-keys.
	 *
	 * @since 3.47.8
	 *
	 * @return array Each element describes one option or family of options.
	 */
	public static function get_option_tag_definitions() {

		$definitions = array(
			array(
				'option'   => 'wpf_pmp_%',
				'like'     => true,
				'nested'   => false,
				'tag_keys' => array(
					'apply_tags',
					'tag_link',
					'apply_tags_cancelled',
					'apply_tags_expired',
					'apply_tags_payment_failed',
					'apply_tags_pending_cancellation',
				),
			),
			array(
				'option'   => 'wpf_wpforo_settings',
				'like'     => false,
				'nested'   => true,
				'tag_keys' => array( 'required_tags' ),
			),
			array(
				'option'   => 'wpf_wpforo_settings_usergroups',
				'like'     => false,
				'nested'   => true,
				'tag_keys' => array( 'enrollment_tag' ),
			),
			array(
				'option'   => 'frm_wpf_settings_%',
				'like'     => true,
				'nested'   => false,
				'tag_keys' => array( 'apply_tags' ),
			),
		);

		$definitions = apply_filters( 'wpf_hubspot_migration_option_definitions', $definitions );

		return apply_filters( 'wpf_migration_option_definitions', $definitions );
	}


	/**
	 * Returns WPF global option keys that contain tag arrays.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string>
	 */
	public static function get_wpf_option_tag_keys() {

		$keys = array(
			'assign_tags',
			'email_optin_tags',
		);

		$keys = apply_filters( 'wpf_hubspot_migration_wpf_option_tag_keys', $keys );

		return apply_filters( 'wpf_migration_wpf_option_tag_keys', $keys );
	}


	/**
	 * Returns recursive Elementor setting keys that contain WP Fusion tag IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string>
	 */
	public static function get_elementor_tag_keys() {

		return array(
			'wpf_tags',
			'wpf_tags_all',
			'wpf_tags_not',
			'wp_fusion_popup_tags',
		);
	}


	// -------------------------------------------------------------------------
	// Update methods (instance — use $this->id_map).
	// -------------------------------------------------------------------------

	/**
	 * Updates all postmeta tag fields with the new IDs.
	 *
	 * Processes one batch of 200 rows per call. Returns the next cursor to
	 * resume from, or 0 when the batch is complete.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor The last meta_id processed (0 to start from the beginning).
	 * @return int Next cursor, or 0 when all rows have been processed.
	 */
	public function update_postmeta( $cursor ) {

		global $wpdb;

		$id_map = $this->id_map;

		if ( empty( $id_map ) ) {
			return 0;
		}

		$batch_size  = 200;
		$tag_key_map = self::get_postmeta_tag_keys();
		$meta_keys   = array_keys( $tag_key_map );

		if ( empty( $meta_keys ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value != '' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", array_merge( $meta_keys, array( $cursor, $batch_size ) ) ) );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$meta_value   = maybe_unserialize( $row->meta_value );
			$changed      = false;
			$tag_sub_keys = isset( $tag_key_map[ $row->meta_key ] ) ? $tag_key_map[ $row->meta_key ] : array();

			if ( is_array( $meta_value ) ) {
				foreach ( $tag_sub_keys as $tag_key ) {
					if ( empty( $meta_value[ $tag_key ] ) || ! is_array( $meta_value[ $tag_key ] ) ) {
						continue;
					}

					foreach ( $meta_value[ $tag_key ] as $i => $item ) {
						if ( is_array( $item ) ) {
							// Nested array (e.g. variation-level tags).
							foreach ( $item as $j => $sub_item ) {
								if ( isset( $id_map[ (string) $sub_item ] ) ) {
									$meta_value[ $tag_key ][ $i ][ $j ] = $id_map[ (string) $sub_item ];
									$changed                            = true;
								}
							}
						} elseif ( isset( $id_map[ (string) $item ] ) ) {
							$meta_value[ $tag_key ][ $i ] = $id_map[ (string) $item ];
							$changed                      = true;
						}
					}
				}
			}

			if ( $changed ) {
				update_post_meta( absint( $row->post_id ), $row->meta_key, $meta_value );
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->meta_id );
		$row_count   = count( $rows );

		return $row_count < $batch_size ? 0 : $next_cursor;
	}


	/**
	 * Updates all user tag meta with the new IDs.
	 *
	 * Processes one batch of 200 rows per call. Returns the next cursor to
	 * resume from, or 0 when the batch is complete.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor The last umeta_id processed (0 to start from the beginning).
	 * @return int Next cursor, or 0 when all rows have been processed.
	 */
	public function update_usermeta( $cursor ) {

		global $wpdb;

		$id_map = $this->id_map;

		if ( empty( $id_map ) ) {
			return 0;
		}

		$batch_size = 200;
		$meta_key   = WPF_TAGS_META_KEY;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != '' AND umeta_id > %d ORDER BY umeta_id ASC LIMIT %d", $meta_key, $cursor, $batch_size ) );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$meta_value = maybe_unserialize( $row->meta_value );
			$changed    = false;

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			foreach ( $meta_value as $i => $tag_id ) {
				if ( isset( $id_map[ (string) $tag_id ] ) ) {
					$meta_value[ $i ] = $id_map[ (string) $tag_id ];
					$changed          = true;
				}
			}

			if ( $changed ) {
				update_user_meta( absint( $row->user_id ), $meta_key, $meta_value );
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->umeta_id );
		$row_count   = count( $rows );

		return $row_count < $batch_size ? 0 : $next_cursor;
	}


	/**
	 * Determines if a tag ID looks like a legacy numeric HubSpot list ID.
	 *
	 * @since 3.47.8
	 *
	 * @param mixed $tag_id The candidate tag value.
	 * @return bool
	 */
	public static function is_legacy_tag_id( $tag_id ) {

		if ( ! is_scalar( $tag_id ) ) {
			return false;
		}

		return 1 === preg_match( '/^\d+$/', (string) $tag_id );
	}


	/**
	 * Scans post meta in batches and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed postmeta row ID.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_postmeta( $cursor ) {

		global $wpdb;

		$batch_size  = 200;
		$tag_key_map = self::get_postmeta_tag_keys();

		if ( empty( $tag_key_map ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$meta_keys    = array_keys( $tag_key_map );
		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value != '' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", array_merge( $meta_keys, array( $cursor, $batch_size ) ) ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$found_ids = array();

		foreach ( $rows as $row ) {
			$meta_value   = maybe_unserialize( $row->meta_value );
			$tag_sub_keys = isset( $tag_key_map[ $row->meta_key ] ) ? $tag_key_map[ $row->meta_key ] : array();

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			foreach ( $tag_sub_keys as $tag_key ) {
				if ( empty( $meta_value[ $tag_key ] ) || ! is_array( $meta_value[ $tag_key ] ) ) {
					continue;
				}

				foreach ( $meta_value[ $tag_key ] as $item ) {
					if ( is_array( $item ) ) {
						foreach ( $item as $sub_item ) {
							if ( self::is_legacy_tag_id( $sub_item ) ) {
								$found_ids[ (string) $sub_item ] = true;
							}
						}
					} elseif ( self::is_legacy_tag_id( $item ) ) {
						$found_ids[ (string) $item ] = true;
					}
				}
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->meta_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'  => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_scanned' => $row_count,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans user meta in batches and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed usermeta row ID.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_usermeta( $cursor ) {

		global $wpdb;

		$batch_size = 200;
		$meta_key   = WPF_TAGS_META_KEY;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != '' AND umeta_id > %d ORDER BY umeta_id ASC LIMIT %d", $meta_key, $cursor, $batch_size ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$found_ids = array();

		foreach ( $rows as $row ) {
			$meta_value = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			foreach ( $meta_value as $tag_id ) {
				if ( self::is_legacy_tag_id( $tag_id ) ) {
					$found_ids[ (string) $tag_id ] = true;
				}
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->umeta_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'  => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_scanned' => $row_count,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans taxonomy rules and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return array{
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_taxonomy_rules() {

		$taxonomy_rules = get_option( 'wpf_taxonomy_rules', array() );

		if ( empty( $taxonomy_rules ) || ! is_array( $taxonomy_rules ) ) {
			return array(
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$found_ids    = array();
		$tag_sub_keys = self::get_taxonomy_rule_tag_keys();

		foreach ( $taxonomy_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			foreach ( $tag_sub_keys as $tag_key ) {
				if ( empty( $rule[ $tag_key ] ) || ! is_array( $rule[ $tag_key ] ) ) {
					continue;
				}

				foreach ( $rule[ $tag_key ] as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		}

		return array(
			'rows_scanned' => count( $taxonomy_rules ),
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans term meta in batches and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed termmeta row ID.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_termmeta( $cursor ) {

		global $wpdb;

		$batch_size    = 200;
		$termmeta_keys = self::get_termmeta_tag_keys();

		if ( empty( $termmeta_keys ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$meta_keys    = array_keys( $termmeta_keys );
		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ({$placeholders}) AND meta_value != '' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", array_merge( $meta_keys, array( $cursor, $batch_size ) ) ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$found_ids = array();

		foreach ( $rows as $row ) {
			$meta_value   = maybe_unserialize( $row->meta_value );
			$tag_sub_keys = isset( $termmeta_keys[ $row->meta_key ] ) ? $termmeta_keys[ $row->meta_key ] : array();

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			foreach ( $tag_sub_keys as $tag_key ) {
				if ( empty( $meta_value[ $tag_key ] ) || ! is_array( $meta_value[ $tag_key ] ) ) {
					continue;
				}

				foreach ( $meta_value[ $tag_key ] as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->meta_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'  => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_scanned' => $row_count,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans options in batches and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed options row ID.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_options( $cursor ) {

		global $wpdb;

		$batch_size  = 200;
		$definitions = self::get_option_tag_definitions();

		if ( empty( $definitions ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		list( $where_sql, $where_params ) = self::get_option_query_parts( $definitions );

		if ( empty( $where_sql ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$params = array_merge( $where_params, array( $cursor, $batch_size ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE ({$where_sql}) AND option_value != '' AND option_id > %d ORDER BY option_id ASC LIMIT %d", $params ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$found_ids = array();

		foreach ( $rows as $row ) {
			$definition = self::find_option_definition( $definitions, $row->option_name );

			if ( empty( $definition ) ) {
				continue;
			}

			$value = maybe_unserialize( $row->option_value );

			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( ! empty( $definition['nested'] ) ) {
				foreach ( $value as $sub_value ) {
					if ( ! is_array( $sub_value ) ) {
						continue;
					}

					foreach ( $definition['tag_keys'] as $tag_key ) {
						if ( empty( $sub_value[ $tag_key ] ) || ! is_array( $sub_value[ $tag_key ] ) ) {
							continue;
						}

						foreach ( $sub_value[ $tag_key ] as $tag_id ) {
							if ( self::is_legacy_tag_id( $tag_id ) ) {
								$found_ids[ (string) $tag_id ] = true;
							}
						}
					}
				}
			} else {
				foreach ( $definition['tag_keys'] as $tag_key ) {
					if ( empty( $value[ $tag_key ] ) || ! is_array( $value[ $tag_key ] ) ) {
						continue;
					}

					foreach ( $value[ $tag_key ] as $tag_id ) {
						if ( self::is_legacy_tag_id( $tag_id ) ) {
							$found_ids[ (string) $tag_id ] = true;
						}
					}
				}
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->option_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'  => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_scanned' => $row_count,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans WPF options and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return array{
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_wpf_options() {

		$found_ids = array();
		$rows      = 0;
		$tag_keys  = self::get_wpf_option_tag_keys();

		foreach ( $tag_keys as $key ) {
			$value = wpf_get_option( $key, array() );
			++$rows;

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $tag_id ) {
				if ( self::is_legacy_tag_id( $tag_id ) ) {
					$found_ids[ (string) $tag_id ] = true;
				}
			}
		}

		$all_options = wp_fusion()->settings->get_all();

		if ( is_array( $all_options ) ) {
			foreach ( $all_options as $key => $value ) {
				if ( 0 !== strpos( $key, 'woo_status_tagging_' ) || ! is_array( $value ) ) {
					continue;
				}

				++$rows;

				foreach ( $value as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		}

		return array(
			'rows_scanned' => $rows,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Returns supported custom table scan/update sources.
	 *
	 * @since 3.47.8
	 *
	 * @return array<string>
	 */
	public static function get_custom_table_sources() {
		return array(
			'fluent_forms',
			'bookingpress',
			'amelia_services',
			'amelia_events',
			'wpf_meta',
			'rcp_groupmeta',
		);
	}


	/**
	 * Scans one custom table source in batches and collects legacy IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param string $source Source key from get_custom_table_sources().
	 * @param int    $cursor Last processed source row ID.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>
	 * }
	 */
	public function scan_custom_table( $source, $cursor ) {

		global $wpdb;

		$batch_size = 200;
		$found_ids  = array();
		$rows       = array();
		$id_column  = '';

		if ( 'fluent_forms' === $source ) {
			$table = $wpdb->prefix . 'fluentform_form_meta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'  => 0,
					'rows_scanned' => 0,
					'ids_found'    => 0,
					'found_ids'    => array(),
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, value FROM {$table} WHERE meta_key = %s AND id > %d ORDER BY id ASC LIMIT %d", 'fluentform_wpfusion_feed', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$feeds     = json_decode( $row->value, true );
				$found_ids = self::collect_legacy_ids_from_nested_data( $feeds, array( 'tag_ids', 'tags' ), $found_ids );
			}
		} elseif ( 'bookingpress' === $source ) {
			$table = $wpdb->prefix . 'bookingpress_servicesmeta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'  => 0,
					'rows_scanned' => 0,
					'ids_found'    => 0,
					'found_ids'    => array(),
				);
			}

			$id_column = 'bookingpress_servicemeta_id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bookingpress_servicemeta_id, bookingpress_servicemeta_value FROM {$table} WHERE bookingpress_servicemeta_name = %s AND bookingpress_servicemeta_id > %d ORDER BY bookingpress_servicemeta_id ASC LIMIT %d", 'wpf_apply_tags', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$tags = maybe_unserialize( $row->bookingpress_servicemeta_value );

				if ( ! is_array( $tags ) ) {
					continue;
				}

				foreach ( $tags as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		} elseif ( 'amelia_services' === $source || 'amelia_events' === $source ) {
			$table = $wpdb->prefix . $source;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'  => 0,
					'rows_scanned' => 0,
					'ids_found'    => 0,
					'found_ids'    => array(),
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, settings FROM {$table} WHERE settings IS NOT NULL AND settings != '' AND id > %d ORDER BY id ASC LIMIT %d", $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$settings = json_decode( $row->settings, true );

				if ( ! is_array( $settings ) || empty( $settings['apply_tags'] ) ) {
					continue;
				}

				foreach ( (array) $settings['apply_tags'] as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		} elseif ( 'wpf_meta' === $source ) {
			$table = $wpdb->prefix . 'wpf_meta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'  => 0,
					'rows_scanned' => 0,
					'ids_found'    => 0,
					'found_ids'    => array(),
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, meta_value FROM {$table} WHERE meta_key = %s AND id > %d ORDER BY id ASC LIMIT %d", 'wppayform_wpfusion_feed', $cursor, $batch_size ) );

			$payform_tag_keys = array(
				'apply_tags_form_submission',
				'apply_tags_payment_received',
				'apply_tags_payment_failed',
				'apply_tags_subscription_cancelled',
			);

			foreach ( $rows as $row ) {
				$feeds     = json_decode( $row->meta_value, true );
				$found_ids = self::collect_legacy_ids_from_nested_data( $feeds, $payform_tag_keys, $found_ids );
			}
		} elseif ( 'rcp_groupmeta' === $source ) {
			$table = $wpdb->prefix . 'rcp_groupmeta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'  => 0,
					'rows_scanned' => 0,
					'ids_found'    => 0,
					'found_ids'    => array(),
				);
			}

			$id_column = 'meta_id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$table} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", 'tag_link', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$tags = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $tags ) ) {
					continue;
				}

				foreach ( $tags as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			}
		}

		if ( empty( $rows ) || empty( $id_column ) ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
			);
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->{$id_column} );
		$row_count   = count( $rows );

		return array(
			'next_cursor'  => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_scanned' => $row_count,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
		);
	}


	/**
	 * Scans integrations in batches and collects legacy list IDs.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Zero-based integration offset.
	 * @return array{
	 *     next_cursor:int,
	 *     rows_scanned:int,
	 *     ids_found:int,
	 *     found_ids:array<string, bool>,
	 *     location:string
	 * }
	 */
	public function scan_integrations( $cursor ) {

		$integrations = self::get_active_integrations();
		$total        = count( $integrations );

		if ( $cursor >= $total ) {
			return array(
				'next_cursor'  => 0,
				'rows_scanned' => 0,
				'ids_found'    => 0,
				'found_ids'    => array(),
				'location'     => '',
			);
		}

		$integration = $integrations[ $cursor ];
		$location    = 'integration_' . $integration->slug;
		$found_ids   = array();

		foreach ( (array) $integration->get_configured_tag_ids() as $tag_id ) {
			if ( self::is_legacy_tag_id( $tag_id ) ) {
				$found_ids[ (string) $tag_id ] = true;
			}
		}

		$next_cursor = ( $cursor + 1 ) < $total ? ( $cursor + 1 ) : 0;

		return array(
			'next_cursor'  => $next_cursor,
			'rows_scanned' => 1,
			'ids_found'    => count( $found_ids ),
			'found_ids'    => $found_ids,
			'location'     => $location,
		);
	}


	/**
	 * Updates term meta in a single batch.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed termmeta row ID.
	 * @return array{next_cursor:int, rows_processed:int, rows_updated:int}
	 */
	public function update_termmeta_batch( $cursor ) {

		global $wpdb;

		$id_map        = $this->id_map;
		$batch_size    = 200;
		$rows_updated  = 0;
		$termmeta_keys = self::get_termmeta_tag_keys();

		if ( empty( $id_map ) || empty( $termmeta_keys ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		$meta_keys    = array_keys( $termmeta_keys );
		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ({$placeholders}) AND meta_value != '' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", array_merge( $meta_keys, array( $cursor, $batch_size ) ) ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		foreach ( $rows as $row ) {
			$meta_value   = maybe_unserialize( $row->meta_value );
			$tag_sub_keys = isset( $termmeta_keys[ $row->meta_key ] ) ? $termmeta_keys[ $row->meta_key ] : array();
			$changed      = false;

			if ( ! is_array( $meta_value ) ) {
				continue;
			}

			foreach ( $tag_sub_keys as $tag_key ) {
				if ( empty( $meta_value[ $tag_key ] ) || ! is_array( $meta_value[ $tag_key ] ) ) {
					continue;
				}

				foreach ( $meta_value[ $tag_key ] as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$meta_value[ $tag_key ][ $i ] = $id_map[ (string) $tag_id ];
						$changed                      = true;
					}
				}
			}

			if ( $changed ) {
				update_term_meta( absint( $row->term_id ), $row->meta_key, $meta_value );
				++$rows_updated;
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->meta_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'    => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_processed' => $row_count,
			'rows_updated'   => $rows_updated,
		);
	}


	/**
	 * Updates options in a single batch.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Last processed options row ID.
	 * @return array{next_cursor:int, rows_processed:int, rows_updated:int}
	 */
	public function update_options_batch( $cursor ) {

		global $wpdb;

		$id_map       = $this->id_map;
		$batch_size   = 200;
		$rows_updated = 0;
		$definitions  = self::get_option_tag_definitions();

		if ( empty( $id_map ) || empty( $definitions ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		list( $where_sql, $where_params ) = self::get_option_query_parts( $definitions );

		if ( empty( $where_sql ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		$params = array_merge( $where_params, array( $cursor, $batch_size ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE ({$where_sql}) AND option_value != '' AND option_id > %d ORDER BY option_id ASC LIMIT %d", $params ) );

		if ( empty( $rows ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		foreach ( $rows as $row ) {
			$definition = self::find_option_definition( $definitions, $row->option_name );

			if ( empty( $definition ) ) {
				continue;
			}

			$value = maybe_unserialize( $row->option_value );

			if ( ! is_array( $value ) ) {
				continue;
			}

			$changed = false;

			if ( ! empty( $definition['nested'] ) ) {
				foreach ( $value as $sub_key => $sub_value ) {
					if ( ! is_array( $sub_value ) ) {
						continue;
					}

					foreach ( $definition['tag_keys'] as $tag_key ) {
						if ( empty( $sub_value[ $tag_key ] ) || ! is_array( $sub_value[ $tag_key ] ) ) {
							continue;
						}

						foreach ( $sub_value[ $tag_key ] as $i => $tag_id ) {
							if ( isset( $id_map[ (string) $tag_id ] ) ) {
								$value[ $sub_key ][ $tag_key ][ $i ] = $id_map[ (string) $tag_id ];
								$changed                             = true;
							}
						}
					}
				}
			} else {
				foreach ( $definition['tag_keys'] as $tag_key ) {
					if ( empty( $value[ $tag_key ] ) || ! is_array( $value[ $tag_key ] ) ) {
						continue;
					}

					foreach ( $value[ $tag_key ] as $i => $tag_id ) {
						if ( isset( $id_map[ (string) $tag_id ] ) ) {
							$value[ $tag_key ][ $i ] = $id_map[ (string) $tag_id ];
							$changed                 = true;
						}
					}
				}
			}

			if ( $changed ) {
				update_option( $row->option_name, $value );
				++$rows_updated;
			}
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->option_id );
		$row_count   = count( $rows );

		return array(
			'next_cursor'    => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_processed' => $row_count,
			'rows_updated'   => $rows_updated,
		);
	}


	/**
	 * Updates one custom table source in a single batch.
	 *
	 * @since 3.47.8
	 *
	 * @param string $source Source key from get_custom_table_sources().
	 * @param int    $cursor Last processed source row ID.
	 * @return array{next_cursor:int, rows_processed:int, rows_updated:int}
	 */
	public function update_custom_table_batch( $source, $cursor ) {

		global $wpdb;

		$id_map       = $this->id_map;
		$batch_size   = 200;
		$rows         = array();
		$rows_updated = 0;
		$id_column    = '';

		if ( empty( $id_map ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		if ( 'fluent_forms' === $source ) {
			$table = $wpdb->prefix . 'fluentform_form_meta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'    => 0,
					'rows_processed' => 0,
					'rows_updated'   => 0,
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, value FROM {$table} WHERE meta_key = %s AND id > %d ORDER BY id ASC LIMIT %d", 'fluentform_wpfusion_feed', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$feeds = json_decode( $row->value, true );

				if ( ! is_array( $feeds ) ) {
					continue;
				}

				list( $updated, $changed ) = self::replace_ids_in_nested_data( $feeds, array( 'tag_ids', 'tags' ), $id_map );

				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'value' => wp_json_encode( $updated ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
					++$rows_updated;
				}
			}
		} elseif ( 'bookingpress' === $source ) {
			$table = $wpdb->prefix . 'bookingpress_servicesmeta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'    => 0,
					'rows_processed' => 0,
					'rows_updated'   => 0,
				);
			}

			$id_column = 'bookingpress_servicemeta_id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bookingpress_servicemeta_id, bookingpress_servicemeta_value FROM {$table} WHERE bookingpress_servicemeta_name = %s AND bookingpress_servicemeta_id > %d ORDER BY bookingpress_servicemeta_id ASC LIMIT %d", 'wpf_apply_tags', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$tags = maybe_unserialize( $row->bookingpress_servicemeta_value );

				if ( ! is_array( $tags ) ) {
					continue;
				}

				$changed = false;

				foreach ( $tags as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$tags[ $i ] = $id_map[ (string) $tag_id ];
						$changed    = true;
					}
				}

				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'bookingpress_servicemeta_value' => maybe_serialize( $tags ) ), array( 'bookingpress_servicemeta_id' => $row->bookingpress_servicemeta_id ), array( '%s' ), array( '%d' ) );
					++$rows_updated;
				}
			}
		} elseif ( 'amelia_services' === $source || 'amelia_events' === $source ) {
			$table = $wpdb->prefix . $source;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'    => 0,
					'rows_processed' => 0,
					'rows_updated'   => 0,
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, settings FROM {$table} WHERE settings IS NOT NULL AND settings != '' AND id > %d ORDER BY id ASC LIMIT %d", $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$settings = json_decode( $row->settings, true );

				if ( ! is_array( $settings ) || empty( $settings['apply_tags'] ) || ! is_array( $settings['apply_tags'] ) ) {
					continue;
				}

				$changed = false;

				foreach ( $settings['apply_tags'] as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$settings['apply_tags'][ $i ] = $id_map[ (string) $tag_id ];
						$changed                      = true;
					}
				}

				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
					++$rows_updated;
				}
			}
		} elseif ( 'wpf_meta' === $source ) {
			$table = $wpdb->prefix . 'wpf_meta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'    => 0,
					'rows_processed' => 0,
					'rows_updated'   => 0,
				);
			}

			$id_column = 'id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, meta_value FROM {$table} WHERE meta_key = %s AND id > %d ORDER BY id ASC LIMIT %d", 'wppayform_wpfusion_feed', $cursor, $batch_size ) );

			$payform_tag_keys = array(
				'apply_tags_form_submission',
				'apply_tags_payment_received',
				'apply_tags_payment_failed',
				'apply_tags_subscription_cancelled',
			);

			foreach ( $rows as $row ) {
				$feeds = json_decode( $row->meta_value, true );

				if ( ! is_array( $feeds ) ) {
					continue;
				}

				list( $updated, $changed ) = self::replace_ids_in_nested_data( $feeds, $payform_tag_keys, $id_map );

				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'meta_value' => wp_json_encode( $updated ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
					++$rows_updated;
				}
			}
		} elseif ( 'rcp_groupmeta' === $source ) {
			$table = $wpdb->prefix . 'rcp_groupmeta';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array(
					'next_cursor'    => 0,
					'rows_processed' => 0,
					'rows_updated'   => 0,
				);
			}

			$id_column = 'meta_id';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$table} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", 'tag_link', $cursor, $batch_size ) );

			foreach ( $rows as $row ) {
				$tags = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $tags ) ) {
					continue;
				}

				$changed = false;

				foreach ( $tags as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$tags[ $i ] = $id_map[ (string) $tag_id ];
						$changed    = true;
					}
				}

				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $table, array( 'meta_value' => maybe_serialize( $tags ) ), array( 'meta_id' => $row->meta_id ), array( '%s' ), array( '%d' ) );
					++$rows_updated;
				}
			}
		}

		if ( empty( $rows ) || empty( $id_column ) ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
			);
		}

		$last_row    = end( $rows );
		$next_cursor = absint( $last_row->{$id_column} );
		$row_count   = count( $rows );

		return array(
			'next_cursor'    => $row_count < $batch_size ? 0 : $next_cursor,
			'rows_processed' => $row_count,
			'rows_updated'   => $rows_updated,
		);
	}


	/**
	 * Updates integrations in batches.
	 *
	 * @since 3.47.8
	 *
	 * @param int $cursor Zero-based integration offset.
	 * @return array{next_cursor:int, rows_processed:int, rows_updated:int, location:string}
	 */
	public function update_integrations_batch( $cursor ) {

		$integrations = self::get_active_integrations();
		$total        = count( $integrations );

		if ( $cursor >= $total ) {
			return array(
				'next_cursor'    => 0,
				'rows_processed' => 0,
				'rows_updated'   => 0,
				'location'       => '',
			);
		}

		$integration = $integrations[ $cursor ];
		$updated     = 0;
		$location    = 'integration_' . $integration->slug;
		$updated     = $integration->migrate_tag_ids( $this->id_map );

		if ( 0 < $updated ) {
			wpf_log(
				'notice',
				0,
				// translators: %1$s is the integration name, %2$d is the number of updated settings.
				sprintf( __( 'WPF migration: %1$s updated %2$d tag settings.', 'wp-fusion-lite' ), $integration->name, $updated )
			);
		}

		$next_cursor = ( $cursor + 1 ) < $total ? ( $cursor + 1 ) : 0;

		return array(
			'next_cursor'    => $next_cursor,
			'rows_processed' => 1,
			'rows_updated'   => absint( $updated ),
			'location'       => $location,
		);
	}


	/**
	 * Collects legacy IDs from a nested array by matching known tag keys.
	 *
	 * @since 3.47.8
	 *
	 * @param mixed               $data       Nested data array.
	 * @param array<int, string>  $target_keys Keys that contain tag arrays.
	 * @param array<string, bool> $found_ids  Existing set of found IDs.
	 * @return array<string, bool>
	 */
	public static function collect_legacy_ids_from_nested_data( $data, $target_keys, $found_ids = array() ) {

		if ( ! is_array( $data ) ) {
			return $found_ids;
		}

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $target_keys, true ) && is_array( $value ) ) {
				foreach ( $value as $tag_id ) {
					if ( self::is_legacy_tag_id( $tag_id ) ) {
						$found_ids[ (string) $tag_id ] = true;
					}
				}
			} elseif ( is_array( $value ) ) {
				$found_ids = self::collect_legacy_ids_from_nested_data( $value, $target_keys, $found_ids );
			}
		}

		return $found_ids;
	}


	/**
	 * Builds SQL query parts for option scan/update definitions.
	 *
	 * @since 3.47.8
	 *
	 * @param array $definitions Option definitions.
	 * @return array{0:string,1:array<int, string>}
	 */
	private static function get_option_query_parts( $definitions ) {

		$conditions = array();
		$params     = array();

		foreach ( $definitions as $definition ) {
			if ( empty( $definition['option'] ) ) {
				continue;
			}

			if ( ! empty( $definition['like'] ) ) {
				$conditions[] = 'option_name LIKE %s';
			} else {
				$conditions[] = 'option_name = %s';
			}

			$params[] = $definition['option'];
		}

		return array( implode( ' OR ', $conditions ), $params );
	}


	/**
	 * Returns active integration instances for scan/update phases.
	 *
	 * @since 3.47.8
	 *
	 * @return array<int, WPF_Integrations_Base>
	 */
	private static function get_active_integrations() {

		$active = array();

		foreach ( (array) wp_fusion()->integrations as $integration ) {
			if ( ! $integration instanceof WPF_Integrations_Base ) {
				continue;
			}

			if ( ! $integration->is_integration_active() ) {
				continue;
			}

			$active[] = $integration;
		}

		return $active;
	}


	/**
	 * Finds the matching option definition for an option name.
	 *
	 * @since 3.47.8
	 *
	 * @param array  $definitions Option definitions.
	 * @param string $option_name Option name from the database.
	 * @return array|false
	 */
	private static function find_option_definition( $definitions, $option_name ) {

		foreach ( $definitions as $definition ) {
			if ( empty( $definition['option'] ) ) {
				continue;
			}

			if ( ! empty( $definition['like'] ) ) {
				$pattern = '/^' . str_replace( '\%', '.*', preg_quote( $definition['option'], '/' ) ) . '$/';

				if ( 1 === preg_match( $pattern, $option_name ) ) {
					return $definition;
				}
			} elseif ( $option_name === $definition['option'] ) {
				return $definition;
			}
		}

		return false;
	}


	/**
	 * Updates term meta with the new IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function update_termmeta() {

		$cursor = 0;

		do {
			$result = $this->update_termmeta_batch( $cursor );
			$cursor = $result['next_cursor'];
		} while ( 0 !== $cursor );
	}


	/**
	 * Updates WordPress options with the new IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function update_options() {

		$cursor = 0;

		do {
			$result = $this->update_options_batch( $cursor );
			$cursor = $result['next_cursor'];
		} while ( 0 !== $cursor );
	}


	/**
	 * Updates WPF global options with the new IDs.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function update_wpf_options() {

		$id_map = $this->id_map;

		if ( empty( $id_map ) ) {
			return;
		}

		// Named tag keys.
		$tag_keys = self::get_wpf_option_tag_keys();

		foreach ( $tag_keys as $key ) {
			$value = wpf_get_option( $key, array() );

			if ( ! is_array( $value ) ) {
				continue;
			}

			$changed = false;

			foreach ( $value as $i => $tag_id ) {
				if ( isset( $id_map[ (string) $tag_id ] ) ) {
					$value[ $i ] = $id_map[ (string) $tag_id ];
					$changed     = true;
				}
			}

			if ( $changed ) {
				wpf_update_option( $key, $value );
			}
		}

		// woo_status_tagging_* keys.
		$all_options = wp_fusion()->settings->get_all();

		if ( is_array( $all_options ) ) {
			foreach ( $all_options as $key => $value ) {
				if ( 0 !== strpos( $key, 'woo_status_tagging_' ) || ! is_array( $value ) ) {
					continue;
				}

				$changed = false;

				foreach ( $value as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$value[ $i ] = $id_map[ (string) $tag_id ];
						$changed     = true;
					}
				}

				if ( $changed ) {
					wpf_update_option( $key, $value );
				}
			}
		}
	}


	/**
	 * Updates custom database tables with the new IDs.
	 *
	 * Covers Fluent Forms, BookingPress, Amelia, WP PayForm, and
	 * Restrict Content Pro.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function update_custom_tables() {

		foreach ( self::get_custom_table_sources() as $source ) {
			$cursor = 0;

			do {
				$result = $this->update_custom_table_batch( $source, $cursor );
				$cursor = $result['next_cursor'];
			} while ( 0 !== $cursor );
		}
	}


	/**
	 * Runs integration-specific tag ID migration via the registry.
	 *
	 * Each integration that implements migrate_tag_ids() will be called to
	 * handle its own complex storage.
	 *
	 * @since 3.47.8
	 *
	 * @return void
	 */
	public function update_integrations() {

		$cursor = 0;

		do {
			$result = $this->update_integrations_batch( $cursor );
			$cursor = $result['next_cursor'];
		} while ( 0 !== $cursor );
	}


	// -------------------------------------------------------------------------
	// Static helpers.
	// -------------------------------------------------------------------------

	/**
	 * Recursively replaces legacy tag IDs in nested arrays.
	 *
	 * Shared by the update phase (postmeta, custom tables) and integration
	 * classes such as WPF_Elementor that store tags in JSON structures.
	 *
	 * @since 3.47.8
	 *
	 * @param array $data        Nested data array.
	 * @param array $target_keys Keys that contain tag arrays.
	 * @param array $id_map      Legacy to new map.
	 * @return array{0: array, 1: bool} Updated array and whether any change was made.
	 */
	public static function replace_ids_in_nested_data( $data, $target_keys, $id_map ) {

		$changed = false;

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $target_keys, true ) && is_array( $value ) ) {
				foreach ( $value as $i => $tag_id ) {
					if ( isset( $id_map[ (string) $tag_id ] ) ) {
						$data[ $key ][ $i ] = $id_map[ (string) $tag_id ];
						$changed            = true;
					}
				}
			} elseif ( is_array( $value ) ) {
				list( $new_value, $nested_changed ) = self::replace_ids_in_nested_data( $value, $target_keys, $id_map );

				if ( $nested_changed ) {
					$data[ $key ] = $new_value;
					$changed      = true;
				}
			}
		}

		return array( $data, $changed );
	}
}
