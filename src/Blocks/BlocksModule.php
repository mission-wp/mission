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
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register a custom block category for Mission blocks.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public function register_block_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'mission',
				'title' => __( 'Mission', 'mission' ),
			)
		);

		return $categories;
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
