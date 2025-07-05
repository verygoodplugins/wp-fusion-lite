<?php
namespace wp_fusion\secure_blocks_for_gutenberg;

// Abort if this file is called directly.

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Deprecated file
 *
 * @deprecated 3.44.23 Use wp-fusion/includes/admin/class-tags-select-api.php instead.
 */
// _deprecated_file( __FILE__, '3.44.23', 'wp-fusion/includes/admin/class-tags-select-api.php' );

/**
 * Class API
 *
 * WP REST API Custom Methods
 *
 * @package wp_fusion\secure_blocks_for_gutenberg
 */
class API {

	private $version;
	private $namespace;

	/**
	 * Formats the wpf
	 *
	 * @return array
	 */
	public static function get_formatted_tags_array() {
		$available_tags  = wpf_get_option( 'available_tags', array() );
		$tags_for_select = array();
		$groupped_tags   = array();

		foreach ( $available_tags as $tag_id => $tag ) {
			if ( is_array( $tag ) ) {
				if ( isset( $tag['category'] ) ) {
					$groupped_tags[ $tag['category'] ][] = array(
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

		if ( ! empty( $groupped_tags ) ) {
			foreach ( $groupped_tags as $label => $group ) {
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
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->version   = '1';
		$this->namespace = 'wp-fusion/secure-blocks/v' . $this->version;
	}

	/**
	 * Run all of the plugin functions.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		add_action( 'rest_api_init', array( $this, 'api_routes' ) );
	}

	/**
	 * Register REST API
	 */
	public function api_routes() {

		// Council
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

		// Update available tags route
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

		// Sync available tags route
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
	 * Get the available tags
	 *
	 * @return array
	 */
	public function get_available_tags() {
		return self::get_formatted_tags_array();
	}

	/**
	 * Updates the wpf_available_tags option with the given value
	 *
	 * @return void
	 */
	public function update_available_tags( \WP_REST_Request $request ) {

		$tag = $request->get_param( 'tag_name' );

		if ( empty( $tag ) ) {
			wp_send_json_error( new \WP_Error( 'error', __( 'Tag name is empty!.', 'wp-fusion-lite' ) ) );
		}

		if ( in_array( 'add_tags_api', wp_fusion()->crm->supports ) ) {
			$tag_id = wp_fusion()->crm->add_tag( $tag );

			if ( is_wp_error( $tag_id ) ) {
				wp_send_json_error( $tag_id );
			}

			wpf_log( 'info', wpf_get_current_user_id(), 'Created new tag <strong>' . $tag . '</strong> with ID <code>' . $tag_id . '</code>' );
		}

		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( ! in_array( $tag, $available_tags ) ) {
			$available_tags[] = $tag;
			asort( $available_tags );

			wp_fusion()->settings->set( 'available_tags', $available_tags );

			if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {
				wpf_log( 'info', wpf_get_current_user_id(), 'Created new tag <strong>' . $tag . '</strong></code>' );
			}
		}

		wp_send_json_success();
	}

	/**
	 * Syncs the tags and returns the new list of available tags
	 *
	 * @return void
	 */
	public function sync_tags() {
		wp_fusion()->crm->sync_tags();

		wp_send_json_success( self::get_formatted_tags_array() );
	}
}
