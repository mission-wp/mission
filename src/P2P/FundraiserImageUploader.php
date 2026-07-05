<?php
/**
 * Server-side image upload handling for fundraiser pages.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and stores a fundraiser cover photo uploaded from the frontend.
 *
 * The donor role has no upload_files capability by design, so this never relies
 * on the current user's caps: it validates the file (image mime + size) and
 * calls the WordPress upload primitives directly to create an attachment.
 */
class FundraiserImageUploader {

	/**
	 * Meta key marking attachments created by this uploader.
	 *
	 * Only marked attachments are eligible for cleanup_replaced_image(), so
	 * admin-assigned media library images are never deleted by the plugin.
	 */
	public const UPLOAD_MARKER_META = '_missiondp_p2p_upload';

	/**
	 * Image mime types accepted for fundraiser photos.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	];

	/**
	 * Validate an uploaded file and create a WordPress attachment from it.
	 *
	 * @param array<string, mixed> $file A single entry from WP_REST_Request::get_file_params().
	 * @return int|WP_Error Attachment ID on success.
	 */
	public function handle( array $file ): int|WP_Error {
		$validation = $this->validate( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = [
			'test_form' => false,
			'mimes'     => self::ALLOWED_MIMES,
		];

		/**
		 * Filters the wp_handle_upload() overrides for fundraiser photo uploads.
		 *
		 * @param array<string, mixed> $overrides Upload overrides.
		 */
		$overrides = (array) apply_filters( 'mission_fundraiser_photo_upload_overrides', $overrides );

		$uploaded = wp_handle_upload( $file, $overrides );

		if ( ! is_array( $uploaded ) || isset( $uploaded['error'] ) ) {
			return new WP_Error(
				'upload_failed',
				$uploaded['error'] ?? __( 'The image could not be uploaded.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $uploaded['type'],
				'post_title'     => sanitize_file_name( wp_basename( $uploaded['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$uploaded['file']
		);

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			return new WP_Error(
				'attachment_failed',
				__( 'The image could not be saved.', 'mission-donation-platform' ),
				[ 'status' => 500 ]
			);
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		update_post_meta( $attachment_id, self::UPLOAD_MARKER_META, 1 );

		return (int) $attachment_id;
	}

	/**
	 * Delete a replaced cover photo unless another record still references it.
	 *
	 * Only attachments this uploader created (carrying the marker meta) are
	 * eligible: an admin can point a record at any media-library image, and
	 * those may be used in posts or elsewhere, so the plugin never deletes
	 * them. Marked attachments are removed once no fundraiser or team
	 * references them.
	 *
	 * @param string $old_value Previous cover_image value (attachment ID or URL).
	 */
	public function cleanup_replaced_image( string $old_value ): void {
		// URLs and empty values aren't attachments this plugin manages.
		if ( '' === $old_value || ! ctype_digit( $old_value ) ) {
			return;
		}

		$attachment_id = (int) $old_value;

		if ( ! get_post_meta( $attachment_id, self::UPLOAD_MARKER_META, true ) ) {
			return;
		}

		$referenced = Fundraiser::count( [ 'cover_image' => $old_value ] ) > 0
			|| Fundraiser::count( [ 'profile_image' => $old_value ] ) > 0
			|| Team::count( [ 'cover_image' => $old_value ] ) > 0;

		if ( ! $referenced && wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Maximum upload size in bytes.
	 *
	 * Public so the dashboard can validate client-side before the upload.
	 *
	 * @return int
	 */
	public static function max_size(): int {
		// wp_max_upload_size() can be 0 when the server limits are unparseable.
		$server_max = wp_max_upload_size();
		$default    = $server_max > 0 ? min( 5 * MB_IN_BYTES, $server_max ) : 5 * MB_IN_BYTES;

		/**
		 * Filters the maximum fundraiser photo size in bytes.
		 *
		 * @param int $bytes Default 5 MB, capped at the server upload limit.
		 */
		return (int) apply_filters( 'mission_fundraiser_photo_max_size', $default );
	}

	/**
	 * Validate the uploaded file is a present, well-formed image within limits.
	 *
	 * @param array<string, mixed> $file Uploaded file entry.
	 * @return bool|WP_Error True when valid.
	 */
	private function validate( array $file ): bool|WP_Error {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'no_file', __( 'No image was uploaded.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		// Measure the file on disk; the request's size field is client-supplied.
		$size = file_exists( $file['tmp_name'] ) ? (int) filesize( $file['tmp_name'] ) : 0;
		$max  = self::max_size();

		if ( $size > $max ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: maximum allowed file size, e.g. "5 MB". */
					__( 'The image is too large. Please upload a file under %s.', 'mission-donation-platform' ),
					size_format( $max )
				),
				[ 'status' => 400 ]
			);
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] ?? '', self::ALLOWED_MIMES );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::ALLOWED_MIMES, true ) ) {
			return new WP_Error(
				'invalid_image',
				__( 'Please upload a JPEG, PNG, GIF, or WebP image.', 'mission-donation-platform' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
