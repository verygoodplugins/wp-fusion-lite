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
	 * The array with the asset map.
	 *
	 * @since 3.44.23
	 *
	 * @var array The array map.
	 */
	private $asset_map = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		if ( ! wpf_get_option( 'restrict_content', true ) ) {
			return;
		}

		// Register the asset map.
		$this->asset_map = wpf_get_asset_meta( WPF_DIR_PATH . 'build/secure-block.asset.php' );

		// Load Assets
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) ); // Load Editor Assets

		// Load Classes
		add_action( 'init', array( $this, 'includes' ) );
	}

	/**
	 * Enqueue editor scripts and styles
	 *
	 * @since unknown
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function editor_assets() {
		// Enqueue editor JS.
		wp_enqueue_script(
			'wpf-secure-blocks-for-gutenberg-editor-js',
			WPF_DIR_URL . 'build/secure-block.js',
			$this->asset_map['dependencies'],
			$this->asset_map['version'],
			true
		);

		wp_enqueue_style(
			'wpf-secure-blocks-for-gutenberg-admin-css',
			WPF_DIR_URL . 'build/secure-block.css',
			array(),
			$this->asset_map['version'],
		);
	}

	/**
	 * Enqueue admin styles
	 *
	 * @deprecated 3.44.25 Use editor_assets() instead.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_assets() {
		_deprecated_function( __METHOD__, '3.44.25', 'Main::editor_assets()' );
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
