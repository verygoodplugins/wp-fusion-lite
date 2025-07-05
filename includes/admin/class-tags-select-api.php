<?php
/**
 * Holds the WPF_Tags_Select_API class
 *
 * @package WP Fusion
 */

namespace WP_Fusion\Includes\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WPF_Tags_Select_API' ) ) {
	/**
	 * Handles the API and helper methods for the WPFSelect package
	 *
	 * @since 3.44.23
	 */
	class WPF_Tags_Select_API {
		/**
		 * The version of the API
		 *
		 * @since 3.44.23
		 *
		 * @var string
		 */
		private string $version = '1';

		/**
		 * The namespace for the API
		 *
		 * @since 3.44.23
		 *
		 * @var string
		 */
		private string $namespace;

		/**
		 * Fetch and format the available tags
		 *
		 * @since 3.44.23
		 * @access public
		 *
		 * @return array
		 */
		public static function get_formatted_tags_array(): array {
			$available_tags  = wpf_get_option( 'available_tags', array() );
			$tags_for_select = array();
			$grouped_tags    = array();

			foreach ( $available_tags as $tag_id => $tag ) {
				if ( is_array( $tag ) ) {
					if ( isset( $tag['category'] ) ) {
						$grouped_tags[ $tag['category'] ][] = array(
							'value' => $tag_id,
							'label' => $tag['label'],
						);
					} else {
						$tags_for_select[] = array(
							'value' => $tag_id,
							'label' => $tag['label'],
						);

					}
				} else {
					$tags_for_select[] = array(
						'value' => $tag_id,
						'label' => $tag,
					);
				}
			}

			if ( ! empty( $grouped_tags ) ) {
				foreach ( $grouped_tags as $label => $group ) {
					if ( str_contains( strtolower( $label ), '(read only)' ) ) {
						foreach ( $group as $key => $option ) {
							$group[ $key ]['label'] = $option['label'] . ' (Read Only)';
						}
					}

					$tags_for_select[] = array(
						'label'   => $label,
						'options' => $group,
					);
				}
			}

			return $tags_for_select;
		}

		/**
		 * Format tags to props
		 *
		 * @since 3.44.23
		 *
		 * @param mixed $tags The tags to format.
		 * @access public
		 *
		 * @return array
		 */
		public static function format_tags_to_props( $tags ): array {
			$props = array();

			if ( ! is_array( $tags ) ) {
				return $props;
			}

			foreach ( $tags as $tag ) {
				$props[] = array(
					'label' => wpf_get_tag_label( $tag ),
					'value' => $tag,
				);
			}

			return $props;
		}

		/**
		 * Format tags to string
		 *
		 * @since 3.44.23
		 *
		 * @param mixed $tags The tags to format.
		 * @access public
		 *
		 * @return string
		 */
		public static function format_tags_to_string( $tags ): string {
			if ( ! is_array( $tags ) ) {
				return '';
			}

			return implode( ',', $tags );
		}

		/**
		 * Get tag values
		 *
		 * @since 3.45.2
		 *
		 * @param mixed $tags The tags to get the values of.
		 * @access public
		 *
		 * @return array
		 */
		public static function select_get_tag_values( $tags ): array {
			return array_map(
				function ( $tag ) {
					if ( empty( $tag ) || empty( $tag['value'] ) ) {
						return;
					}

					return $tag['value'];
				},
				$tags
			);
		}

		/**
		 * Constructor
		 *
		 * @since 3.44.23
		 *
		 * @return void
		 */
		public function __construct() {
			$this->namespace = 'wp-fusion/v' . $this->version;

			add_action( 'rest_api_init', array( $this, 'routes' ) );
		}

		/**
		 * Register the API routes
		 *
		 * @since 3.44.23
		 *
		 * @return void
		 */
		public function routes(): void {
			// Available tags endpoint.
			register_rest_route(
				$this->namespace,
				'/available-tags',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_available_tags' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);

			// Update available tags route.
			register_rest_route(
				$this->namespace,
				'/update-available-tags',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_available_tags' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);

			// Sync available tags route.
			register_rest_route(
				$this->namespace,
				'/sync-tags',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'sync_tags' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		/**
		 * Callback for the available tags endpoint
		 *
		 * @since 3.44.23
		 *
		 * @return array
		 */
		public function get_available_tags(): array {
			return self::get_formatted_tags_array();
		}

		/**
		 * Updates the wpf_available_tags option with the given value
		 *
		 * @since 3.44.23
		 *
		 * @param \WP_REST_Request $request The REST request object.
		 *
		 * @return void
		 */
		public function update_available_tags( \WP_REST_Request $request ): void {
			$tag = $request->get_param( 'tag_name' );

			if ( empty( $tag ) ) {
				wp_send_json_error(
					new \WP_Error( 'error', __( 'Tag name is empty!.', 'wp-fusion-lite' ) )
				);
			}

			if ( in_array( 'add_tags_api', wp_fusion()->crm->supports, true ) ) {
				$tag_id = wp_fusion()->crm->add_tag( $tag );

				if ( is_wp_error( $tag_id ) ) {
					wp_send_json_error( $tag_id );
				}

				wpf_log( 'info', wpf_get_current_user_id(), 'Created new tag <strong>' . $tag . '</strong> with ID <code>' . $tag_id . '</code>' );
			}

			$available_tags = wpf_get_option( 'available_tags', array() );

			if ( ! in_array( $tag, $available_tags, true ) ) {
				$available_tags[] = $tag;
				asort( $available_tags );

				wp_fusion()->settings->set( 'available_tags', $available_tags );

				if ( in_array( 'add_tags', wp_fusion()->crm->supports, true ) ) {
					wpf_log( 'info', wpf_get_current_user_id(), 'Created new tag <strong>' . $tag . '</strong></code>' );
				}
			}

			wp_send_json_success();
		}

		/**
		 * Syncs the tags and returns the new list of available tags
		 *
		 * @since 3.44.23
		 *
		 * @return void
		 */
		public function sync_tags(): void {
			wp_fusion()->crm->sync_tags();

			wp_send_json_success( self::get_formatted_tags_array() );
		}
	}

	new WPF_Tags_Select_API();
}
