<?php
/**
 * Action Scheduler callback that drives one batch of a migration forward.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Models\MigrationPhase;

defined( 'ABSPATH' ) || exit;

/**
 * One tick = one batch of the current phase + reschedule until every phase is
 * done. Mirrors ImportJobHandler's idempotency rules: counters use atomic
 * UPDATEs, the cursor (last_source_id) is guarded against moving backwards,
 * source-ID meta stamps make re-processing a batch produce skips instead of
 * duplicates, and terminal states short-circuit.
 */
class MigrationJobHandler {

	/**
	 * Action hook this handler is registered against.
	 */
	public const HOOK = 'missiondp_migration_tick';

	/**
	 * Constructor.
	 *
	 * @param MigrationService $service Migration service.
	 */
	public function __construct(
		private MigrationService $service,
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
	 * @param string $job_id Public job token.
	 */
	public function handle( string $job_id ): void {
		$phases = MigrationPhase::find_for_job( $job_id );

		if ( empty( $phases ) ) {
			return;
		}

		$status = $this->service->job_status( $phases );

		if ( in_array( $status, [ MigrationPhase::STATUS_FAILED, MigrationPhase::STATUS_CANCELLED ], true ) ) {
			foreach ( $phases as $phase ) {
				if ( ! $phase->is_terminal() ) {
					$phase->mark_cancelled();
				}
			}
			$this->service->release_lock( $job_id );
			return;
		}

		if ( MigrationPhase::STATUS_COMPLETED === $status ) {
			$this->service->release_lock( $job_id );
			return;
		}

		$current = null;
		foreach ( $phases as $phase ) {
			if ( ! $phase->is_terminal() ) {
				$current = $phase;
				break;
			}
		}

		if ( ! $current ) {
			return;
		}

		if ( MigrationPhase::STATUS_QUEUED === $current->status ) {
			$current->mark_processing();
		}

		try {
			$result = $this->service->process_phase_batch( $current, $this->service->get_batch_size() );
		} catch ( \Throwable $e ) {
			// Do NOT rethrow: Action Scheduler would retry and the failure would just repeat.
			$current->mark_failed( $e->getMessage() );

			foreach ( $phases as $phase ) {
				if ( $phase->id !== $current->id && ! $phase->is_terminal() ) {
					$phase->mark_cancelled();
				}
			}

			$this->service->release_lock( $job_id );

			/**
			 * Fires when a migration run fails.
			 *
			 * @param string         $job_id Job token.
			 * @param MigrationPhase $phase  The failed phase.
			 * @param string         $reason Failure reason.
			 */
			do_action( 'mission_migration_failed', $job_id, $current, $e->getMessage() );

			return;
		}

		$current->increment_counts(
			[
				'processed_items' => $result['processed'],
				'imported'        => $result['imported'],
				'skipped'         => $result['skipped'],
				'errors'          => $result['errors'],
			]
		);

		if ( $result['max_source_id'] > $current->last_source_id ) {
			$current->advance_cursor( $result['max_source_id'] );
		}

		if ( ! empty( $result['error_details'] ) ) {
			// Re-read so error_details merges with anything written by an overlapping tick.
			$fresh = $current->fresh();
			if ( $fresh ) {
				$fresh->append_error_details( $result['error_details'] );
			}
		}

		$fresh = $current->fresh();
		if ( ! $fresh || MigrationPhase::STATUS_CANCELLED === $fresh->status ) {
			$this->service->release_lock( $job_id );
			return;
		}

		if ( $result['done'] ) {
			$fresh->mark_completed();

			$remaining = array_filter(
				MigrationPhase::find_for_job( $job_id ),
				static fn( MigrationPhase $phase ): bool => ! $phase->is_terminal()
			);

			if ( ! empty( $remaining ) ) {
				$this->service->enqueue_tick( $job_id );
				return;
			}

			$this->complete_job( $job_id, $fresh );
			return;
		}

		$this->service->enqueue_tick( $job_id );
	}

	/**
	 * Run job-level completion: rollback epilogue or completion hook, then
	 * release the lock.
	 *
	 * @param string         $job_id Job token.
	 * @param MigrationPhase $last   The phase that just completed.
	 */
	private function complete_job( string $job_id, MigrationPhase $last ): void {
		if ( MigrationPhase::TYPE_ROLLBACK === $last->job_type ) {
			$this->service->finish_rollback( $last );

			/**
			 * Fires after a migration rollback completes.
			 *
			 * @param string         $job_id Rollback job token.
			 * @param MigrationPhase $last   The final rollback phase.
			 */
			do_action( 'mission_migration_rolled_back', $job_id, $last );
		} else {
			/**
			 * Fires after a migration run completes.
			 *
			 * @param string           $job_id Job token.
			 * @param MigrationPhase[] $phases All phase rows.
			 */
			do_action( 'mission_migration_completed', $job_id, MigrationPhase::find_for_job( $job_id ) );
		}

		$this->service->release_lock( $job_id );
	}
}
