<?php
/**
 * Daily cleanup for import job records and orphaned upload files.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the shared daily cleanup event.
 */
class ImportCleanup {

	private const FILE_TTL_HOURS = 24;
	private const ROW_TTL_DAYS   = 30;

	/**
	 * Constructor.
	 *
	 * @param ImportService $import Import service (for file deletion).
	 */
	public function __construct(
		private ImportService $import,
	) {}

	/**
	 * Register on the shared daily cleanup hook.
	 */
	public function register(): void {
		add_action( 'missiondp_daily_cleanup', [ $this, 'run' ] );
	}

	/**
	 * Daily cleanup pass.
	 */
	public function run(): void {
		$this->delete_old_files();
		$this->delete_old_rows();
	}

	/**
	 * Delete upload files for terminal jobs older than the file TTL.
	 */
	private function delete_old_files(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::file_ttl_seconds() );

		$jobs = ImportJob::query(
			[
				'status'     => [ ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED, ImportJob::STATUS_CANCELLED ],
				'older_than' => $cutoff,
				'per_page'   => 200,
			]
		);

		foreach ( $jobs as $job ) {
			if ( '' === $job->file_path ) {
				continue;
			}

			$this->import->delete_job_file( $job );

			// Clear the path so we don't repeatedly try to delete it.
			$job->file_path = '';
			$job->save();
		}
	}

	/**
	 * Delete job rows past the retention window.
	 */
	private function delete_old_rows(): void {
		/**
		 * Filter how long import job records are retained, in days.
		 *
		 * @param int $days Retention window.
		 */
		$days   = max( 1, (int) apply_filters( 'mission_import_job_retention_days', self::ROW_TTL_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$jobs = ImportJob::query(
			[
				'older_than' => $cutoff,
				'per_page'   => 500,
			]
		);

		foreach ( $jobs as $job ) {
			if ( '' !== $job->file_path ) {
				$this->import->delete_job_file( $job );
			}
			$job->delete();
		}
	}

	/**
	 * File TTL in seconds, filterable.
	 */
	private static function file_ttl_seconds(): int {
		/**
		 * Filter how long import upload files are kept after the job reaches a terminal state.
		 *
		 * @param int $hours Hours.
		 */
		return max( 1, (int) apply_filters( 'mission_import_file_retention_hours', self::FILE_TTL_HOURS ) ) * HOUR_IN_SECONDS;
	}
}
