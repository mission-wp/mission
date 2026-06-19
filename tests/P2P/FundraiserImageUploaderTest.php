<?php
/**
 * Tests for the FundraiserImageUploader class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\P2P\FundraiserImageUploader;
use WP_UnitTestCase;

/**
 * FundraiserImageUploader test class.
 */
class FundraiserImageUploaderTest extends WP_UnitTestCase {

	private FundraiserImageUploader $uploader;

	/**
	 * Temp files to clean up.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->uploader = new FundraiserImageUploader();
	}

	/**
	 * Clean up temp files.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = [];

		parent::tear_down();
	}

	/**
	 * A missing file is rejected.
	 */
	public function test_rejects_missing_file(): void {
		$result = $this->uploader->handle( [ 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE ] );

		$this->assertWPError( $result );
		$this->assertSame( 'no_file', $result->get_error_code() );
	}

	/**
	 * A file over the size limit is rejected.
	 */
	public function test_rejects_oversize_file(): void {
		$path = $this->write_temp_file( 'image.png', 'data' );

		$result = $this->uploader->handle(
			[
				'name'     => 'image.png',
				'type'     => 'image/png',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => 6 * MB_IN_BYTES,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
	}

	/**
	 * A non-image file is rejected.
	 */
	public function test_rejects_non_image(): void {
		$path = $this->write_temp_file( 'notes.txt', 'just some text' );

		$result = $this->uploader->handle(
			[
				'name'     => 'notes.txt',
				'type'     => 'text/plain',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => 14,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	/**
	 * Write a temp file and track it for cleanup.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @return string Path.
	 */
	private function write_temp_file( string $name, string $content ): string {
		$path = trailingslashit( sys_get_temp_dir() ) . uniqid( 'mdp', true ) . '-' . $name;
		file_put_contents( $path, $content );
		$this->temp_files[] = $path;

		return $path;
	}
}
