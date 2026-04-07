<?php
/**
 * WP Fusion - Tag ID Migration
 *
 * Provides runtime translation of legacy tag IDs to their current
 * equivalents. Hooks into core tag resolution and access control
 * so that stored settings with old IDs continue to work correctly
 * without requiring every storage location to be migrated at once.
 *
 * Currently used for HubSpot v1→v3 list ID migration. Designed to
 * also support future CRM-switch tag migration via label matching.
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since 3.47.8
 *
 * @see WPF_User::get_tag_id()    — wpf_get_tag_id filter
 * @see WPF_User::get_tag_label() — wpf_get_tag_label filter
 */
class WPF_Tag_Migration {

	/**
	 * The legacy-to-current tag ID mapping.
	 *
	 * @since 3.47.8
	 * @var array
	 */
	private $id_map;

	/**
	 * Snapshot of available_tags before migration, for label lookups.
	 *
	 * @since 3.47.8
	 * @var array
	 */
	private $prev_tags;

	/**
	 * Tag sub-keys that contain tag ID arrays in access settings.
	 *
	 * @since 3.47.8
	 * @var array
	 */
	private $access_tag_keys = array(
		'allow_tags',
		'allow_tags_all',
		'allow_tags_not',
		'apply_tags',
		'remove_tags',
	);

	/**
	 * Constructor. Attaches hooks only when a tag ID map is active.
	 *
	 * @since 3.47.8
	 */
	public function __construct() {

		$is_hubspot_crm = 'hubspot' === wpf_get_option( 'crm' );
		$has_map        = ! empty( wpf_get_option( 'wpf_tag_id_map', array() ) );
		$has_state      = get_option( 'wpf_hubspot_v3_migration_needed' ) || wpf_get_option( 'wpf_hubspot_v3_migrated' );

		if ( ! $is_hubspot_crm || ! $has_map || ! $has_state ) {
			return;
		}

		$this->id_map    = wpf_get_option( 'wpf_tag_id_map', array() );
		$this->prev_tags = wpf_get_option( 'wpf_available_tags_prev', array() );

		if ( empty( $this->id_map ) ) {
			return;
		}

		// Runtime tag ID translation.
		add_filter( 'wpf_get_tag_id', array( $this, 'translate_tag_id' ), 10, 2 );
		add_filter( 'wpf_get_tag_label', array( $this, 'translate_tag_label' ), 10, 2 );

		// Translate stored settings before access control comparisons.
		add_filter( 'wpf_post_access_meta', array( $this, 'translate_access_meta' ), 5, 2 );

		// Admin UI: surface legacy tags in the tag picker.
		if ( is_admin() ) {
			add_filter( 'wpf_render_tag_multiselect_args', array( $this, 'inject_legacy_tags' ) );
		}
	}


	/**
	 * Translates a legacy tag ID to its current equivalent.
	 *
	 * Hooked to wpf_get_tag_id. This covers has_tag(), Elementor,
	 * and every other integration that resolves tags through
	 * get_tag_id() or wpf_get_tag_id().
	 *
	 * @since 3.47.8
	 *
	 * @param string|int|false $tag_id   The resolved tag ID.
	 * @param string           $tag_name The original input.
	 * @return string|int|false The translated tag ID.
	 */
	public function translate_tag_id( $tag_id, $tag_name ) {

		if ( false === $tag_id || empty( $tag_id ) ) {
			return $tag_id;
		}

		$key = (string) $tag_id;

		if ( isset( $this->id_map[ $key ] ) ) {
			return $this->id_map[ $key ];
		}

		return $tag_id;
	}


	/**
	 * Resolves labels for legacy tag IDs that are no longer in
	 * available_tags.
	 *
	 * Hooked to wpf_get_tag_label. Falls back to the pre-migration
	 * snapshot so legacy IDs show a real name instead of "Unknown".
	 *
	 * @since 3.47.8
	 *
	 * @param string     $label  The resolved label.
	 * @param string|int $tag_id The tag ID.
	 * @return string The label.
	 */
	public function translate_tag_label( $label, $tag_id ) {

		if ( empty( $this->prev_tags ) ) {
			return $label;
		}

		// Only intervene when the core returned an "Unknown" label.
		if ( false === strpos( $label, 'Unknown' ) ) {
			return $label;
		}

		$key = (string) $tag_id;

		if ( isset( $this->prev_tags[ $key ] ) ) {
			$prev_label = is_array( $this->prev_tags[ $key ] ) ? $this->prev_tags[ $key ]['label'] : $this->prev_tags[ $key ];
			return $prev_label . ' (' . __( 'legacy', 'wp-fusion-lite' ) . ')';
		}

		return $label;
	}


	/**
	 * Translates legacy tag IDs in post access settings before
	 * the access control comparison.
	 *
	 * This catches the direct array_intersect() in
	 * WPF_Access_Control::user_can_access() which doesn't go
	 * through get_tag_id().
	 *
	 * @since 3.47.8
	 *
	 * @param array $settings The wpf-settings for the post.
	 * @param int   $post_id  The post ID.
	 * @return array The translated settings.
	 */
	public function translate_access_meta( $settings, $post_id ) {

		foreach ( $this->access_tag_keys as $key ) {

			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}

			$changed = false;

			foreach ( $settings[ $key ] as $i => $tag_id ) {
				$str_id = (string) $tag_id;

				if ( isset( $this->id_map[ $str_id ] ) ) {
					$settings[ $key ][ $i ] = $this->id_map[ $str_id ];
					$changed                = true;
				}
			}

			if ( $changed ) {
				$settings[ $key ] = array_values( $settings[ $key ] );
			}
		}

		return $settings;
	}


	/**
	 * Injects legacy tags into the tag picker so stored IDs show
	 * with labels instead of blank/unknown.
	 *
	 * Hooked to wpf_render_tag_multiselect_args. Only fires in
	 * admin context.
	 *
	 * @since 3.47.8
	 *
	 * @param array $args The tag multiselect arguments.
	 * @return array The modified arguments.
	 */
	public function inject_legacy_tags( $args ) {

		if ( empty( $args['setting'] ) || ! is_array( $args['setting'] ) || empty( $this->prev_tags ) ) {
			return $args;
		}

		$available_tags = (array) wpf_get_option( 'available_tags' );

		foreach ( $args['setting'] as $tag_id ) {

			$str_id = (string) $tag_id;

			// Only inject if the ID is in our map and not already in available tags.
			if ( ! isset( $this->id_map[ $str_id ] ) || isset( $available_tags[ $str_id ] ) ) {
				continue;
			}

			if ( ! isset( $this->prev_tags[ $str_id ] ) ) {
				continue;
			}

			$prev_label = is_array( $this->prev_tags[ $str_id ] ) ? $this->prev_tags[ $str_id ]['label'] : $this->prev_tags[ $str_id ];

			// Inject into available_tags so the <option> renders.
			// For CRMs with categories (like HubSpot), use the category format.
			if ( is_array( $this->prev_tags[ $str_id ] ) ) {
				$available_tags[ $str_id ] = array(
					'label'    => $prev_label . ' (' . __( 'legacy', 'wp-fusion-lite' ) . ')',
					'category' => __( 'Legacy (Needs Migration)', 'wp-fusion-lite' ),
				);
			} else {
				$available_tags[ $str_id ] = $prev_label . ' (' . __( 'legacy', 'wp-fusion-lite' ) . ')';
			}
		}

		// Write back to the option cache so the rendering loop picks it up.
		// This is a runtime-only change; it doesn't persist to the database.
		wp_fusion()->settings->options['available_tags'] = $available_tags;

		return $args;
	}


	/**
	 * Translates legacy IDs in a raw tag array.
	 *
	 * Used by wpf_clean_tags() to auto-upgrade IDs on save.
	 *
	 * @since 3.47.8
	 *
	 * @param array $tags The tag IDs.
	 * @return array The translated tag IDs.
	 */
	public function translate_tags( $tags ) {

		if ( empty( $this->id_map ) ) {
			return $tags;
		}

		foreach ( $tags as $i => $tag_id ) {
			$str_id = (string) $tag_id;

			if ( isset( $this->id_map[ $str_id ] ) ) {
				$tags[ $i ] = $this->id_map[ $str_id ];
			}
		}

		return $tags;
	}


	/**
	 * Gets the current tag ID map.
	 *
	 * @since 3.47.8
	 *
	 * @return array The ID map (old => new).
	 */
	public function get_id_map() {
		return $this->id_map;
	}


	/**
	 * Gets the previous available tags snapshot.
	 *
	 * @since 3.47.8
	 *
	 * @return array The previous available tags.
	 */
	public function get_prev_tags() {
		return $this->prev_tags;
	}
}
