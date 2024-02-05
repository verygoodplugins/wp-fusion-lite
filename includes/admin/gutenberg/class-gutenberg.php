<?php
/**
 * WP Fusion's secure block forked from Secure Blocks for Gutenberg by Matt Watson
 *
 * @link https://github.com/mwtsn/secure-blocks-for-gutenberg
 */

namespace wp_fusion\secure_blocks_for_gutenberg;

// Abort if this file is called directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Main {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */

	public function __construct() {

		if ( ! wpf_get_option( 'restrict_content', true ) ) {
			return;
		}
		
		// Load Assets
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) ); // Load Editor Assets
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );        // Load Admin Assets

		// Load Classes
		add_action( 'init', array( $this, 'includes' ) );

	}

	/**
	 * Enqueue editor scripts and styles
	 *
	 * @access public
	 * @return void
	 */

	public function editor_assets() {

		$scripts = '/build/index.js';

		// Enqueue editor JS.
		wp_enqueue_script(
			'wpf-secure-blocks-for-gutenberg-editor-js',
			plugins_url( $scripts, __FILE__ ),
			array( 'wp-i18n', 'wp-element', 'wp-blocks', 'wp-components', 'wp-api', 'wp-editor' ),
			filemtime( plugin_dir_path( __FILE__ ) . $scripts )
		);

	}

	/**
	 * Enqueue admin styles
	 *
	 * @access public
	 * @return void
	 */

	public function admin_assets() {

		$styles = '/build/index.css';

		// Enqueue Styles.
		wp_enqueue_style(
			'wpf-secure-blocks-for-gutenberg-admin-css',
			plugins_url( $styles, __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . $styles )
		);
	}

	/**
	 * Include block classes
	 *
	 * @access public
	 * @return void
	 */

	public function includes() {

		// Load Classes
		require_once plugin_dir_path( __FILE__ ) . 'src/secure-block/php/class-secure-block.php';
		$secure_block = new Secure_Block();
		$secure_block->run();

	}

}

$main = new Main();
