<?php
/**
 * Tests for the FundraiserImageUploader class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Fundraiser;
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
	 * A 1x1 transparent GIF (real image bytes for the success/mime tests).
	 */
	private const GIF_BYTES = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

	/**
	 * Create tables once for the attachment-cleanup tests.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->uploader = new FundraiserImageUploader();
	}

	/**
	 * Clean up temp files and table rows.
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );

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
	 * The size check measures the file on disk, not the client-supplied field.
	 */
	public function test_rejects_oversize_file_by_real_size(): void {
		add_filter( 'mission_fundraiser_photo_max_size', static fn() => 10 );

		$path = $this->write_temp_file( 'image.gif', self::GIF_BYTES );

		// The client claims 1 byte; the real file is larger than the 10-byte cap.
		$result = $this->uploader->handle(
			[
				'name'     => 'image.gif',
				'type'     => 'image/gif',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => 1,
			]
		);

		remove_all_filters( 'mission_fundraiser_photo_max_size' );

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
	 * Text content dressed up with an image name and mime is rejected.
	 */
	public function test_rejects_mime_spoof(): void {
		$path = $this->write_temp_file( 'image.png', 'not really a png' );

		$result = $this->uploader->handle(
			[
				'name'     => 'image.png',
				'type'     => 'image/png',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => 16,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	/**
	 * A valid image becomes an attachment with metadata.
	 */
	public function test_accepts_valid_image_and_creates_attachment(): void {
		$path = $this->write_temp_file( 'photo.gif', self::GIF_BYTES );

		// Test files aren't PHP uploads, so the wp_handle_upload action would
		// refuse them; process this one as a sideload instead.
		add_filter(
			'mission_fundraiser_photo_upload_overrides',
			static fn( array $overrides ): array => array_merge( $overrides, [ 'action' => 'wp_handle_sideload' ] )
		);

		$result = $this->uploader->handle(
			[
				'name'     => 'photo.gif',
				'type'     => 'image/gif',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => strlen( self::GIF_BYTES ),
			]
		);

		remove_all_filters( 'mission_fundraiser_photo_upload_overrides' );

		$this->assertIsInt( $result );
		$this->assertTrue( wp_attachment_is_image( $result ) );
		$this->assertSame( 'image/gif', get_post_mime_type( $result ) );
		$this->assertNotEmpty( get_post_meta( $result, FundraiserImageUploader::UPLOAD_MARKER_META, true ) );

		wp_delete_attachment( $result, true );
	}

	/**
	 * A replaced plugin-uploaded attachment nothing references anymore is deleted.
	 */
	public function test_cleanup_deletes_unreferenced_attachment(): void {
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => 'old-cover.gif',
				'post_mime_type' => 'image/gif',
			]
		);
		update_post_meta( $attachment_id, FundraiserImageUploader::UPLOAD_MARKER_META, 1 );

		$this->uploader->cleanup_replaced_image( (string) $attachment_id );

		$this->assertNull( get_post( $attachment_id ) );
	}

	/**
	 * An attachment the uploader didn't create is never deleted, even unreferenced.
	 */
	public function test_cleanup_keeps_unmarked_library_attachment(): void {
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => 'library-image.gif',
				'post_mime_type' => 'image/gif',
			]
		);

		$this->uploader->cleanup_replaced_image( (string) $attachment_id );

		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * A replaced attachment still referenced by another record survives.
	 */
	public function test_cleanup_keeps_attachment_referenced_elsewhere(): void {
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => 'shared-cover.gif',
				'post_mime_type' => 'image/gif',
			]
		);
		update_post_meta( $attachment_id, FundraiserImageUploader::UPLOAD_MARKER_META, 1 );

		$other = new Fundraiser(
			[
				'campaign_id' => 1,
				'donor_id'    => 1,
				'cover_image' => (string) $attachment_id,
			]
		);
		$other->save();

		$this->uploader->cleanup_replaced_image( (string) $attachment_id );

		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * URL values are left alone (only attachment IDs are managed).
	 */
	public function test_cleanup_ignores_url_values(): void {
		// Must not throw or delete anything; just a smoke check.
		$this->uploader->cleanup_replaced_image( 'https://example.com/image.jpg' );
		$this->uploader->cleanup_replaced_image( '' );

		$this->assertTrue( true );
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
