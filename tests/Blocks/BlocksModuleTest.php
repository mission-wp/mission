<?php
/**
 * Tests for BlocksModule.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Blocks;

use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * BlocksModule tests.
 */
class BlocksModuleTest extends WP_UnitTestCase {

	/**
	 * Registered block styles carry the plugin version, not block.json's.
	 *
	 * Scripts are already cache-busted by webpack's asset hash.
	 */
	public function test_block_assets_are_versioned_with_plugin_version(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'mission-donation-platform/donation-form' );
		$this->assertNotNull( $block, 'Donation form block should be registered.' );

		$style_handle = $block->style_handles[0] ?? '';
		$this->assertNotEmpty( $style_handle );
		$this->assertSame( MISSIONDP_VERSION, wp_styles()->registered[ $style_handle ]->ver );
	}

	/**
	 * Third-party block metadata is left alone.
	 */
	public function test_other_blocks_keep_their_own_version(): void {
		$metadata = apply_filters( 'block_type_metadata', [ 'name' => 'core/paragraph', 'version' => '9.9.9' ] );
		$this->assertSame( '9.9.9', $metadata['version'] );
	}
}
