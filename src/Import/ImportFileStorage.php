<?php
/**
 * Stable file storage for import uploads.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Models\ImportJob;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Moves validated uploads through their storage lifecycle: a short-lived
 * transient-keyed temp file, then a stable per-job location under uploads,
 * then deletion on job cleanup.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class ImportFileStorage {

	public const FILE_ID_TTL = HOUR_IN_SECONDS;

	private const STORAGE_DIR = 'mission-imports';

	/**
	 * Copy the validated upload file into a tempnam location keyed by a
	 * short-lived transient. Used by validate_file().
	 *
	 * @param string $tmp_path Source path.
	 * @param string $filename Original filename.
	 * @param string $type     Data type.
	 */
	public function store_file( string $tmp_path, string $filename, string $type ): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$dest = wp_tempnam( 'mission-import-' . $type );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy,WordPress.PHP.NoSilencedErrors.Discouraged
		@copy( $tmp_path, $dest );

		$file_id = 'mdp_imp_' . wp_generate_password( 16, false );

		set_transient(
			$file_id,
			[
				'path'     => $dest,
				'filename' => $filename,
				'type'     => $type,
			],
			self::FILE_ID_TTL
		);

		return $file_id;
	}

	/**
	 * Copy the temp-uploaded file into a stable location keyed by job_id.
	 *
	 * @param string $source_path Original upload temp path.
	 * @param string $job_id      Public token.
	 * @param string $extension   csv|json.
	 */
	public function move_to_storage( string $source_path, string $job_id, string $extension ): string|WP_Error {
		$base = $this->ensure_storage_dir();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$dest = trailingslashit( $base ) . $job_id . '.' . $extension;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @copy( $source_path, $dest ) ) {
			return new WP_Error( 'file_copy_failed', __( 'Could not store the import file.', 'mission-donation-platform' ), [ 'status' => 500 ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $source_path );

		return $dest;
	}

	/**
	 * Remove a job's stored upload file. Called on cancel/complete/failure
	 * cleanup. Safe to call repeatedly.
	 *
	 * @param ImportJob $job Job.
	 */
	public function delete_job_file( ImportJob $job ): void {
		if ( '' === $job->file_path ) {
			return;
		}

		$base = $this->storage_basedir();

		if ( null === $base ) {
			return;
		}

		// Defensive: only ever delete files inside our managed dir.
		if ( ! str_starts_with( $job->file_path, $base ) ) {
			return;
		}

		if ( file_exists( $job->file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable in AS workers.
			@unlink( $job->file_path );
		}
	}

	/**
	 * Ensure the mission-imports upload dir exists and is locked down.
	 */
	private function ensure_storage_dir(): string|WP_Error {
		$base = $this->storage_basedir();

		if ( null === $base ) {
			return new WP_Error( 'upload_dir', __( 'Uploads directory is not writable.', 'mission-donation-platform' ), [ 'status' => 500 ] );
		}

		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}

		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create the import storage directory.', 'mission-donation-platform' ), [ 'status' => 500 ] );
		}

		$htaccess = trailingslashit( $base ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable here; lockdown file is best-effort.
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}

		$index = trailingslashit( $base ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable here; lockdown file is best-effort.
			@file_put_contents( $index, '' );
		}

		return $base;
	}

	/**
	 * Compute the base storage directory under uploads.
	 */
	private function storage_basedir(): ?string {
		$upload = wp_upload_dir( null, false );

		if ( empty( $upload['basedir'] ) ) {
			return null;
		}

		return trailingslashit( $upload['basedir'] ) . self::STORAGE_DIR;
	}
}
