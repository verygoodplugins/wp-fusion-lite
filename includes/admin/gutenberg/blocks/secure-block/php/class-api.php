<?php
namespace wp_fusion\secure_blocks_for_gutenberg;

// Abort if this file is called directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

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
		add_action( 'rest_api_init', array( $this, 'available_tags' ) );
	}

	/**
	 * Register REST API
	 */
	public function available_tags() {

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
	}

	/**
	 * Get the available tags
	 *
	 * @return $available_tags JSON feed of returned objects
	 */
	
	public function get_available_tags() {

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );
		$tags_for_select = array();

		foreach ( $available_tags as $tag_id => $tag ) {

			if( is_array( $tag ) ) {

				$tags_for_select[] = array(
					'value' => $tag_id,
					'label' => $tag['label'],
				);

			} else {

				$tags_for_select[] = array(
					'value' => $tag_id,
					'label' => $tag,
				);

			}
		}

		return $tags_for_select;

	}
}
