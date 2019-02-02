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

		$scripts = '/assets/js/editor.js';
		$styles  = '/assets/css/editor.css';

		// Enqueue editor JS.
		wp_enqueue_script(
			'wpf-secure-blocks-for-gutenberg-editor-js',
			plugins_url( $scripts, __FILE__ ),
			[ 'wp-i18n', 'wp-element', 'wp-blocks', 'wp-components', 'wp-api', 'wp-editor' ],
			filemtime( plugin_dir_path( __FILE__ ) . $scripts )
		);

		// Enqueue edtior Styles.
		wp_enqueue_style(
			'wpf-secure-blocks-for-gutenberg-editor-css',
			plugins_url( $styles, __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . $styles )
		);

	}

	/**
	 * Enqueue admin styles
	 *
	 * @access public
	 * @return void
	 */

	public function admin_assets() {

		$styles = '/assets/css/admin.css';

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
		require_once 'blocks/secure-block/php/class-secure-block.php';
		$secure_block = new Secure_Block();
		$secure_block->run();

	}

}

$main = new Main();
