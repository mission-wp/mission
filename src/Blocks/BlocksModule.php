<?php
/**
 * Blocks module - registers custom blocks.
 *
 * @package Mission
 */

namespace Mission\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks module class.
 */
class BlocksModule {

	/**
	 * Initialize the blocks module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register blocks.
	 *
	 * Loops through all the blocks in the build directory and registers them.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$plugin_dir = dirname( dirname( __DIR__ ) );
		$blocks_dir = $plugin_dir . '/blocks/build';

		if ( ! is_dir( $blocks_dir ) ) {
			return;
		}

		$blocks = glob( $blocks_dir . '/*', GLOB_ONLYDIR );

		foreach ( $blocks as $block ) {
			register_block_type_from_metadata( $block );
		}
	}
}
