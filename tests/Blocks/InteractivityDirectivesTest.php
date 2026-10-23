<?php
/**
 * Tests for Interactivity API directive conventions in block templates.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Blocks;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_UnitTestCase;

/**
 * Guards directive patterns that newer Interactivity API runtimes reject.
 */
class InteractivityDirectivesTest extends WP_UnitTestCase {

	/**
	 * Negating a callback reference evaluates to undefined in Gutenberg's runtime,
	 * which strips bound attributes such as hidden. Derived booleans belong in state getters.
	 */
	public function test_templates_never_negate_callbacks(): void {
		$templates = $this->block_templates();
		$this->assertNotEmpty( $templates, 'No block templates found to scan.' );

		$offenders = [];
		foreach ( $templates as $path ) {
			if ( preg_match_all( '/data-wp-[a-z-]+="!callbacks\.[^"]+"/', (string) file_get_contents( $path ), $matches ) ) {
				$offenders[] = basename( dirname( $path ) ) . '/' . basename( $path ) . ': ' . implode( ', ', $matches[0] );
			}
		}

		$this->assertSame( [], $offenders, 'Negated callbacks found; move the boolean into a state getter instead.' );
	}

	/**
	 * Every PHP template under blocks/src.
	 *
	 * @return string[]
	 */
	private function block_templates(): array {
		$files    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/blocks/src', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
