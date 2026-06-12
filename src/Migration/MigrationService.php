<?php
/**
 * Migration orchestrator.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Migration\Writers\CampaignWriter;
use MissionDP\Migration\Writers\DonorWriter;
use MissionDP\Migration\Writers\FinalizeWriter;
use MissionDP\Migration\Writers\RollbackWriter;
use MissionDP\Migration\Writers\SubscriptionWriter;
use MissionDP\Migration\Writers\TransactionWriter;
use MissionDP\Models\MigrationPhase;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates migration runs: pre-flight scans, phase row creation, batch
 * dispatch to readers/writers, status payloads, cancellation, and rollback.
 * The Action Scheduler tick (MigrationJobHandler) drives the actual work.
 */
class MigrationService {

	/**
	 * Option holding the active job token. add_option() is atomic on the
	 * option name, which makes it a cheap site-wide lock.
	 */
	public const LOCK_OPTION = 'missiondp_migration_lock';

	/**
	 * Default records per tick.
	 */
	public const BATCH_DEFAULT = 100;

	/**
	 * Entity name of the synthetic last phase.
	 */
	public const FINALIZE_PHASE = 'finalize';

	/**
	 * Constructor.
	 *
	 * @param MigratorRegistry $registry Migrator registry.
	 */
	public function __construct( private MigratorRegistry $registry ) {}

	/**
	 * Get the registry (used by the endpoint for the sources payload).
	 */
	public function get_registry(): MigratorRegistry {
		return $this->registry;
	}

	/**
	 * Batch size for one tick.
	 */
	public function get_batch_size(): int {
		/**
		 * Filters how many records a migration processes per tick.
		 *
		 * @param int $batch_size Records per batch. Default 100.
		 */
		return max( 1, (int) apply_filters( 'mission_migration_batch_size', self::BATCH_DEFAULT ) );
	}

	/**
	 * Run a pre-flight scan for a source.
	 *
	 * @param string $source Source slug.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function scan( string $source ): array|WP_Error {
		$migrator = $this->registry->get( $source );

		if ( ! $migrator ) {
			return new WP_Error(
				'unknown_source',
				__( 'Unknown migration source.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $migrator->is_available() ) {
			return new WP_Error(
				'source_unavailable',
				/* translators: %s: source plugin name. */
				sprintf( __( 'No %s data was found on this site.', 'mission-donation-platform' ), $migrator->get_name() ),
				[ 'status' => 409 ]
			);
		}

		return $migrator->scan( [] );
	}

	/**
	 * Start a migration run.
	 *
	 * @param string               $source  Source slug.
	 * @param array<string, mixed> $options Migration options (include_test).
	 * @param int                  $user_id Acting user.
	 *
	 * @return array<string, mixed>|WP_Error Status payload.
	 */
	public function start( string $source, array $options, int $user_id ): array|WP_Error {
		$migrator = $this->registry->get( $source );

		if ( ! $migrator || ! $migrator->is_available() ) {
			return new WP_Error(
				'source_unavailable',
				__( 'This migration source is not available.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$options = [ 'include_test' => ! empty( $options['include_test'] ) ];
		$job_id  = wp_generate_uuid4();

		if ( ! $this->acquire_lock( $job_id ) ) {
			return new WP_Error(
				'migration_in_progress',
				__( 'A migration is already running.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$order = 0;
		foreach ( $migrator->get_phases() as $entity ) {
			$this->create_phase(
				[
					'job_id'      => $job_id,
					'user_id'     => $user_id,
					'source'      => $source,
					'job_type'    => MigrationPhase::TYPE_MIGRATE,
					'entity'      => $entity,
					'phase_order' => $order++,
					'options'     => (string) wp_json_encode( $options ),
					'total_items' => $migrator->count_items( $entity, $options ),
				]
			);
		}

		// Synthetic last phase: link subscriptions to transactions, recompute aggregates.
		$this->create_phase(
			[
				'job_id'      => $job_id,
				'user_id'     => $user_id,
				'source'      => $source,
				'job_type'    => MigrationPhase::TYPE_MIGRATE,
				'entity'      => self::FINALIZE_PHASE,
				'phase_order' => $order,
				'options'     => (string) wp_json_encode( $options ),
			]
		);

		$this->enqueue_tick( $job_id );

		return $this->get_status( $job_id );
	}

	/**
	 * Build the status payload for a job.
	 *
	 * @param string|null $job_id Job token; defaults to the active or latest job.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_status( ?string $job_id = null ): array|WP_Error {
		$job_id = $job_id ?: ( MigrationPhase::find_active_job_id() ?? MigrationPhase::find_latest_job_id() );

		if ( ! $job_id ) {
			return new WP_Error(
				'no_migration',
				__( 'No migration has been run yet.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$phases = MigrationPhase::find_for_job( $job_id );

		if ( empty( $phases ) ) {
			return new WP_Error(
				'no_migration',
				__( 'Migration not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		$status = $this->job_status( $phases );

		if ( ! in_array( $status, [ MigrationPhase::STATUS_COMPLETED, MigrationPhase::STATUS_FAILED, MigrationPhase::STATUS_CANCELLED ], true ) ) {
			// Safety net: if the AS chain stalled (e.g. worker died), restart it
			// from this poll.
			$this->enqueue_tick( $job_id );
		}

		$totals = [
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		];
		foreach ( $phases as $phase ) {
			$totals['imported'] += $phase->imported;
			$totals['skipped']  += $phase->skipped;
			$totals['errors']   += $phase->errors;
		}

		$first = $phases[0];

		return [
			'job_id'       => $job_id,
			'source'       => $first->source,
			'job_type'     => $first->job_type,
			'status'       => $status,
			'options'      => $first->get_options(),
			'phases'       => array_map( static fn( MigrationPhase $phase ): array => $phase->to_array(), $phases ),
			'totals'       => $totals,
			'started_at'   => $first->started_at,
			'completed_at' => end( $phases )->completed_at,
		];
	}

	/**
	 * Cancel a running job.
	 *
	 * @param string $job_id Job token.
	 *
	 * @return array<string, mixed>|WP_Error Status payload.
	 */
	public function cancel( string $job_id ): array|WP_Error {
		$phases = MigrationPhase::find_for_job( $job_id );

		if ( empty( $phases ) ) {
			return new WP_Error(
				'no_migration',
				__( 'Migration not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		foreach ( $phases as $phase ) {
			if ( ! $phase->is_terminal() ) {
				$phase->mark_cancelled();
			}
		}

		$this->release_lock( $job_id );

		return $this->get_status( $job_id );
	}

	/**
	 * Start a rollback of a finished migration run.
	 *
	 * Creates a new rollback job whose phases delete, in reverse dependency
	 * order, every record the target run created. Driven by the same tick.
	 *
	 * @param string $target_job_id Job token of the migration to roll back.
	 * @param int    $user_id       Acting user.
	 *
	 * @return array<string, mixed>|WP_Error Status payload for the rollback job.
	 */
	public function start_rollback( string $target_job_id, int $user_id ): array|WP_Error {
		$target_phases = MigrationPhase::find_for_job( $target_job_id );

		if ( empty( $target_phases ) || MigrationPhase::TYPE_MIGRATE !== $target_phases[0]->job_type ) {
			return new WP_Error(
				'no_migration',
				__( 'Migration not found.', 'mission-donation-platform' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! in_array( $this->job_status( $target_phases ), [ MigrationPhase::STATUS_COMPLETED, MigrationPhase::STATUS_FAILED, MigrationPhase::STATUS_CANCELLED ], true ) ) {
			return new WP_Error(
				'migration_in_progress',
				__( 'The migration is still running. Cancel it before resetting.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$source  = $target_phases[0]->source;
		$job_id  = wp_generate_uuid4();
		$options = (string) wp_json_encode( [ 'target_job_id' => $target_job_id ] );

		if ( ! $this->acquire_lock( $job_id ) ) {
			return new WP_Error(
				'migration_in_progress',
				__( 'A migration is already running.', 'mission-donation-platform' ),
				[ 'status' => 409 ]
			);
		}

		$writer = new RollbackWriter( $this->key_prefix( $source ), $target_job_id );

		$order = 0;
		foreach ( RollbackWriter::get_phases() as $entity ) {
			$this->create_phase(
				[
					'job_id'      => $job_id,
					'user_id'     => $user_id,
					'source'      => $source,
					'job_type'    => MigrationPhase::TYPE_ROLLBACK,
					'entity'      => $entity,
					'phase_order' => $order++,
					'options'     => $options,
					'total_items' => $writer->count( $entity ),
				]
			);
		}

		$this->enqueue_tick( $job_id );

		return $this->get_status( $job_id );
	}

	/**
	 * Delete a finished job's phase rows. Used after a rollback completes so
	 * the panel returns to the source selection state.
	 *
	 * @param string $job_id Job token.
	 */
	public function delete_job( string $job_id ): void {
		/** @var \MissionDP\Database\DataStore\MigrationPhaseDataStore $store */
		$store = MigrationPhase::store();
		$store->delete_for_job( $job_id );
	}

	/**
	 * Process one batch for a phase. Called by MigrationJobHandler.
	 *
	 * @param MigrationPhase $phase      Phase being processed.
	 * @param int            $batch_size Max records this tick.
	 *
	 * @return array{processed: int, imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>, max_source_id: int, done: bool}
	 */
	public function process_phase_batch( MigrationPhase $phase, int $batch_size ): array {
		if ( MigrationPhase::TYPE_ROLLBACK === $phase->job_type ) {
			return $this->process_rollback_batch( $phase, $batch_size );
		}

		if ( self::FINALIZE_PHASE === $phase->entity ) {
			$writer = new FinalizeWriter( $this->key_prefix( $phase->source ), $phase->job_id );
			$result = $writer->process_batch( $phase->last_source_id, $batch_size );

			return [
				'processed'     => $result['processed'],
				'imported'      => 0,
				'skipped'       => 0,
				'errors'        => $result['errors'],
				'error_details' => $result['error_details'],
				'max_source_id' => $result['max_id'],
				'done'          => $result['done'],
			];
		}

		$migrator = $this->registry->get( $phase->source );

		if ( ! $migrator ) {
			throw new \RuntimeException( esc_html__( 'The migration source is no longer registered.', 'mission-donation-platform' ) );
		}

		$records = $migrator->read_batch( $phase->entity, $phase->last_source_id, $batch_size, $phase->get_options() );

		if ( empty( $records ) ) {
			return [
				'processed'     => 0,
				'imported'      => 0,
				'skipped'       => 0,
				'errors'        => 0,
				'error_details' => [],
				'max_source_id' => $phase->last_source_id,
				'done'          => true,
			];
		}

		$writer = $this->writer_for( $phase );
		$result = $writer->write_batch( $records );

		return [
			'processed'     => count( $records ),
			'imported'      => $result['imported'],
			'skipped'       => $result['skipped'],
			'errors'        => $result['errors'],
			'error_details' => $result['error_details'],
			'max_source_id' => max( array_column( $records, 'source_id' ) ),
			'done'          => false,
		];
	}

	/**
	 * Process one rollback deletion batch.
	 *
	 * @param MigrationPhase $phase      Rollback phase.
	 * @param int            $batch_size Max records this tick.
	 *
	 * @return array{processed: int, imported: int, skipped: int, errors: int, error_details: array<int, array{source_id: int, message: string}>, max_source_id: int, done: bool}
	 */
	private function process_rollback_batch( MigrationPhase $phase, int $batch_size ): array {
		$target_job_id = (string) ( $phase->get_options()['target_job_id'] ?? '' );

		$writer = new RollbackWriter( $this->key_prefix( $phase->source ), $target_job_id );
		$result = $writer->delete_batch( $phase->entity, $phase->last_source_id, $batch_size );

		return [
			'processed'     => $result['processed'],
			'imported'      => 0,
			'skipped'       => 0,
			'errors'        => $result['errors'],
			'error_details' => $result['error_details'],
			'max_source_id' => $result['max_id'],
			'done'          => $result['done'],
		];
	}

	/**
	 * Run the rollback epilogue: recompute surviving aggregates and remove the
	 * target job's phase rows. Called by the handler when the last rollback
	 * phase completes.
	 *
	 * @param MigrationPhase $phase Any phase row of the rollback job.
	 */
	public function finish_rollback( MigrationPhase $phase ): void {
		$target_job_id = (string) ( $phase->get_options()['target_job_id'] ?? '' );

		( new RollbackWriter( $this->key_prefix( $phase->source ), $target_job_id ) )->finish();

		if ( '' !== $target_job_id ) {
			$this->delete_job( $target_job_id );
		}
	}

	/**
	 * Enqueue the next tick and kick the queue runner.
	 *
	 * @param string $job_id Job token.
	 */
	public function enqueue_tick( string $job_id ): void {
		// Without Action Scheduler (tests) ticks are fired manually.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action( MigrationJobHandler::HOOK, [ $job_id ], 'mission-migration' );
		$this->kick_queue_runner();
	}

	/**
	 * Release the site-wide lock if this job holds it.
	 *
	 * @param string $job_id Job token.
	 */
	public function release_lock( string $job_id ): void {
		if ( get_option( self::LOCK_OPTION ) === $job_id ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Source meta key prefix for a source slug (e.g. 'givewp' => '_givewp').
	 *
	 * @param string $source Source slug.
	 */
	private function key_prefix( string $source ): string {
		return '_' . sanitize_key( $source );
	}

	/**
	 * Instantiate the writer for a migrate phase entity.
	 *
	 * @param MigrationPhase $phase Phase row.
	 *
	 * @return CampaignWriter|DonorWriter|SubscriptionWriter|TransactionWriter
	 */
	private function writer_for( MigrationPhase $phase ): CampaignWriter|DonorWriter|SubscriptionWriter|TransactionWriter {
		$prefix = $this->key_prefix( $phase->source );

		return match ( $phase->entity ) {
			'campaigns'     => new CampaignWriter( $prefix, $phase->job_id ),
			'donors'        => new DonorWriter( $prefix, $phase->job_id ),
			'subscriptions' => new SubscriptionWriter( $prefix, $phase->job_id ),
			'transactions'  => new TransactionWriter( $prefix, $phase->job_id ),
			default         => throw new \RuntimeException( 'Unknown migration phase: ' . esc_html( $phase->entity ) ),
		};
	}

	/**
	 * Derive the job-level status from its phase rows.
	 *
	 * @param MigrationPhase[] $phases Phase rows in order.
	 */
	public function job_status( array $phases ): string {
		$all_completed = true;
		$any_started   = false;

		foreach ( $phases as $phase ) {
			if ( MigrationPhase::STATUS_FAILED === $phase->status ) {
				return MigrationPhase::STATUS_FAILED;
			}

			if ( MigrationPhase::STATUS_CANCELLED === $phase->status ) {
				return MigrationPhase::STATUS_CANCELLED;
			}

			if ( MigrationPhase::STATUS_COMPLETED !== $phase->status ) {
				$all_completed = false;
			}

			if ( MigrationPhase::STATUS_QUEUED !== $phase->status ) {
				$any_started = true;
			}
		}

		if ( $all_completed ) {
			return MigrationPhase::STATUS_COMPLETED;
		}

		return $any_started ? MigrationPhase::STATUS_PROCESSING : MigrationPhase::STATUS_QUEUED;
	}

	/**
	 * Acquire the site-wide migration lock.
	 *
	 * add_option() is atomic on the option name. A stale lock (holder has no
	 * active phase rows, e.g. after a crash mid-start) is broken and retried.
	 *
	 * @param string $job_id Job token that wants the lock.
	 */
	private function acquire_lock( string $job_id ): bool {
		if ( add_option( self::LOCK_OPTION, $job_id, '', false ) ) {
			return true;
		}

		if ( null === MigrationPhase::find_active_job_id() ) {
			delete_option( self::LOCK_OPTION );

			return (bool) add_option( self::LOCK_OPTION, $job_id, '', false );
		}

		return false;
	}

	/**
	 * Create one phase row.
	 *
	 * @param array<string, mixed> $data Column values.
	 */
	private function create_phase( array $data ): void {
		( new MigrationPhase( $data ) )->save();
	}

	/**
	 * Flush the response and run the Action Scheduler queue synchronously on
	 * shutdown, so migrations progress immediately on low-traffic sites.
	 * Same approach as ImportService::kick_queue_runner().
	 */
	public function kick_queue_runner(): void {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		add_action(
			'shutdown',
			static function () {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; not all SAPIs support this.
					@fastcgi_finish_request();
				}

				if ( ! class_exists( '\ActionScheduler' ) ) {
					return;
				}

				try {
					\ActionScheduler::runner()->run( 'Mission Migration' );
				} catch ( \Throwable $e ) {
					// Don't let runner failures surface — AS records them itself.
					unset( $e );
				}
			},
			PHP_INT_MAX
		);
	}
}
