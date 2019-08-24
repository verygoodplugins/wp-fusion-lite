<?php
namespace wp_fusion\secure_blocks_for_gutenberg;

// Abort if this file is called directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Secure_Block
 *
 * The main loader for the secure block
 *
 * @package wp_fusion\secure_blocks_for_gutenberg
 */
class Secure_Block {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {}

	/**
	 * Run all of the plugin functions.
	 *
	 * @since 1.0.0
	 */
	public function run() {

		// Register the Block
		$this->register_dynamic_block();

		// Load Classes
		$this->includes();
	}

	/**
	 * Register the dynamic block.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_dynamic_block() {

		// Only load if Gutenberg is available.
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Hook server side rendering into render callback
		register_block_type( 'wp-fusion/secure-block', [
			'render_callback' => 'wp_fusion\secure_blocks_for_gutenberg\wp_fusion_secure_blocks_for_gutenberg_render',
		] );
	}

	/**
	 * Include Classes
	 */
	public function includes() {

		// Load Classes
		require_once 'class-api.php';
		$api = new API();
		$api->run();

	}

}

function wp_fusion_secure_blocks_for_gutenberg_render( $attributes, $content ) {

	if ( is_admin() ) {
		return $content;
	}

	$can_access = false;

	$restricted_tags = array();

	if ( wp_fusion()->settings->get( 'exclude_admins' ) == true && current_user_can( 'manage_options' ) ) {

		$can_access = true;

	} else {

		if ( isset( $attributes['tag'] ) ) {

			$user_tags = wp_fusion()->user->get_tags();

			$decoded_tags = json_decode( $attributes['tag'] );

			if ( ! empty( $decoded_tags ) ) {

				foreach ( $decoded_tags as $tag ) {
					$restricted_tags[] = $tag->value;
				}

				$result = array_intersect( $restricted_tags, $user_tags );

				if( ! empty( $result ) ) {
					$can_access = true;
				}
			}
		}

	}

	$dom = new \DomDocument();
	$dom->loadXML( $content );

	$finder               = new \DomXPath( $dom );
	$secure_class         = 'wp-block-wp-fusion-secure-block-inner-secure';
	$secure_content       = $finder->query( "//div[contains(@class, '$secure_class')]" );
	$unsecure_class       = 'wp-block-wp-fusion-secure-block-inner-unsecure';
	$unsecure_content     = $finder->query( "//div[contains(@class, '$unsecure_class')]" );
	$secure_content_dom   = new \DOMDocument();
	$unsecure_content_dom = new \DOMDocument();

	foreach ( $secure_content as $node ) {
		$secure_content_dom->appendChild( $secure_content_dom->importNode( $node, true ) );
	}

	foreach ( $unsecure_content as $node ) {
		$unsecure_content_dom->appendChild( $unsecure_content_dom->importNode( $node, true ) );
	}

	$secure_content   = trim( $secure_content_dom->saveHTML() );
	$unsecure_content = trim( $unsecure_content_dom->saveHTML() );

	global $post;
	$can_access = apply_filters( 'wpf_user_can_access', $can_access, get_current_user_id(), $post->ID, $restricted_tags );

	if ( is_user_logged_in() && $can_access ) {
		return $secure_content;
	} else {
		return $unsecure_content;
	}
}
