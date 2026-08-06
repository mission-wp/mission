<?php
/**
 * Action Scheduler callback that drives one batch of an import forward.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * One tick = one batch of rows + reschedule until done.
 *
 * AS may re-fire on rare worker overlaps, so every operation is written to be
 * idempotent: counters use atomic UPDATEs, the offset (`processed_rows`) is the
 * source of truth for where to resume, and terminal states short-circuit.
 */
class ImportJobHandler {

	/**
	 * Action hook this handler is registered against.
	 */
	public const HOOK = 'missiondp_import_tick';

	/**
	 * Constructor.
	 *
	 * @param ImportService $import Import service.
	 */
	public function __construct(
		private ImportService $import,
	) {}

	/**
	 * Register the AS callback. Called from the plugin bootstrap so it runs on
	 * every request (including AS worker requests), not just rest_api_init.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handle' ], 10, 1 );
	}

	/**
	 * Process one tick of the named job.
	 *
	 * @param string $job_id Public job token (we look the job up by it).
	 */
	public function handle( string $job_id ): void {
		$job = ImportJob::find_by_job_id( $job_id );

		if ( ! $job ) {
			return;
		}

		if ( $job->is_terminal() ) {
			$this->import->delete_job_file( $job );
			return;
		}

		if ( ImportJob::STATUS_CANCELLED === $job->status ) {
			$this->import->delete_job_file( $job );
			return;
		}

		if ( ImportJob::STATUS_QUEUED === $job->status ) {
			$job->mark_processing();
		}

		try {
			$result = $this->import->process_batch( $job, $this->import->get_batch_size() );
		} catch ( \Throwable $e ) {
			// Do NOT rethrow — AS would retry, and a retry would re-process rows
			// we already committed. Mark the job failed and let the user re-upload.
			$job->mark_failed( $e->getMessage() );
			$this->import->delete_job_file( $job );

			/**
			 * Fires when an import job fails.
			 *
			 * @param ImportJob $job    The failed job.
			 * @param string    $reason Failure reason.
			 */
			do_action( "mission_import_{$job->type}_failed", $job, $e->getMessage() );

			return;
		}

		$job->increment_counts(
			[
				'processed_rows' => $result['processed'],
				'imported'       => $result['imported'],
				'skipped'        => $result['skipped'],
				'updated'        => $result['updated'],
				'errors'         => $result['errors'],
			]
		);

		if ( ! empty( $result['error_details'] ) ) {
			// Re-read so error_details merges with anything written by an overlapping tick.
			$fresh = $job->fresh();
			if ( $fresh ) {
				$fresh->append_error_details( $result['error_details'] );
			}
		}

		$fresh = $job->fresh();

		if ( ! $fresh ) {
			return;
		}

		if ( ImportJob::STATUS_CANCELLED === $fresh->status ) {
			$this->import->delete_job_file( $fresh );
			return;
		}

		$done = $result['eof'] || $fresh->processed_rows >= $fresh->total_rows;

		if ( $done ) {
			$this->import->run_post_import_recompute( $fresh );
			$fresh->mark_completed();

			/**
			 * Fires when an import run completes.
			 *
			 * @param ImportJob $job Completed job.
			 */
			do_action( 'mission_import_completed', $fresh );

			$this->import->delete_job_file( $fresh );

			/**
			 * Fires after an import completes.
			 *
			 * @param ImportJob $job Completed job.
			 */
			do_action( "mission_import_{$fresh->type}_after", $fresh );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ $job_id ], 'mission-import' );
			// Kick in case this tick fired from a /status poll rather than from
			// the runner itself — otherwise the chain stalls here.
			$this->import->kick_queue_runner();
		}
	}
}
