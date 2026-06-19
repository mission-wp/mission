<?php
/**
 * Server-side image upload handling for fundraiser pages.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

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

		return (int) $attachment_id;
	}

	/**
	 * Maximum upload size in bytes.
	 *
	 * @return int
	 */
	private function max_size(): int {
		/**
		 * Filters the maximum fundraiser photo size in bytes.
		 *
		 * @param int $bytes Default 5 MB.
		 */
		return (int) apply_filters( 'mission_fundraiser_photo_max_size', 5 * MB_IN_BYTES );
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

		if ( (int) ( $file['size'] ?? 0 ) > $this->max_size() ) {
			return new WP_Error(
				'file_too_large',
				__( 'The image is too large. Please upload a file under 5 MB.', 'mission-donation-platform' ),
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
