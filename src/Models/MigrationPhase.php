<?php
/**
 * MigrationPhase model — one row per entity phase of a migration run.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\MigrationPhaseDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * A migration run is a set of phase rows sharing a job_id, processed in
 * phase_order. The cursor (last_source_id) is the source of truth for
 * resuming a phase.
 */
class MigrationPhase extends Model {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';

	public const TYPE_MIGRATE  = 'migrate';
	public const TYPE_ROLLBACK = 'rollback';

	public string $job_id;
	public int $user_id;
	public string $source;
	public string $job_type;
	public string $entity;
	public int $phase_order;
	public string $status;
	public string $options;
	public int $total_items;
	public int $processed_items;
	public int $imported;
	public int $skipped;
	public int $errors;
	public string $error_details;
	public string $last_error;
	public int $last_source_id;
	public ?string $started_at;
	public ?string $completed_at;
	public string $date_created;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id              = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->job_id          = (string) ( $data['job_id'] ?? '' );
		$this->user_id         = (int) ( $data['user_id'] ?? 0 );
		$this->source          = (string) ( $data['source'] ?? '' );
		$this->job_type        = (string) ( $data['job_type'] ?? self::TYPE_MIGRATE );
		$this->entity          = (string) ( $data['entity'] ?? '' );
		$this->phase_order     = (int) ( $data['phase_order'] ?? 0 );
		$this->status          = (string) ( $data['status'] ?? self::STATUS_QUEUED );
		$this->options         = (string) ( $data['options'] ?? '' );
		$this->total_items     = (int) ( $data['total_items'] ?? 0 );
		$this->processed_items = (int) ( $data['processed_items'] ?? 0 );
		$this->imported        = (int) ( $data['imported'] ?? 0 );
		$this->skipped         = (int) ( $data['skipped'] ?? 0 );
		$this->errors          = (int) ( $data['errors'] ?? 0 );
		$this->error_details   = (string) ( $data['error_details'] ?? '' );
		$this->last_error      = (string) ( $data['last_error'] ?? '' );
		$this->last_source_id  = (int) ( $data['last_source_id'] ?? 0 );
		$this->started_at      = isset( $data['started_at'] ) ? (string) $data['started_at'] : null;
		$this->completed_at    = isset( $data['completed_at'] ) ? (string) $data['completed_at'] : null;
		$this->date_created    = (string) ( $data['date_created'] ?? current_time( 'mysql', true ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new MigrationPhaseDataStore();
	}

	/**
	 * Get all phase rows for a job, ordered by phase_order.
	 *
	 * @param string $job_id Public job token.
	 *
	 * @return self[]
	 */
	public static function find_for_job( string $job_id ): array {
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		return $store->find_for_job( $job_id );
	}

	/**
	 * Find the job_id of the currently active (queued or processing) run, if any.
	 */
	public static function find_active_job_id(): ?string {
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		return $store->find_active_job_id();
	}

	/**
	 * Find the job_id of the most recently created run, active or not.
	 */
	public static function find_latest_job_id(): ?string {
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		return $store->find_latest_job_id();
	}

	/**
	 * Decode the options JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function get_options(): array {
		if ( '' === $this->options ) {
			return [];
		}

		$decoded = json_decode( $this->options, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Atomically increment counters.
	 *
	 * Defensive against AS firing the same action twice in rare overlaps —
	 * we never read-modify-write counters from PHP.
	 *
	 * @param array<string, int> $deltas Map of column => delta (imported, skipped, errors, processed_items).
	 */
	public function increment_counts( array $deltas ): void {
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		$store->increment_counts( $this->id, $deltas );
	}

	/**
	 * Advance the source cursor. Guarded so a stale tick can never move it backwards.
	 *
	 * @param int $source_id Highest source ID processed in the batch.
	 */
	public function advance_cursor( int $source_id ): void {
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		$store->advance_cursor( $this->id, $source_id );
	}

	/**
	 * Append entries to error_details, capping at 25 total to avoid runaway payloads.
	 *
	 * @param array<int, array{source_id: int, message: string}> $new_entries Entries to append.
	 */
	public function append_error_details( array $new_entries ): void {
		if ( empty( $new_entries ) ) {
			return;
		}

		$existing = $this->decode_error_details();
		$combined = array_slice( array_merge( $existing, $new_entries ), 0, 25 );

		$this->error_details = wp_json_encode( $combined );
		/** @var MigrationPhaseDataStore $store */
		$store = static::store();
		$store->update_field( $this->id, 'error_details', $this->error_details );
	}

	/**
	 * Decode error_details into an array of {source_id, message}.
	 *
	 * @return array<int, array{source_id: int, message: string}>
	 */
	public function decode_error_details(): array {
		if ( '' === $this->error_details ) {
			return [];
		}

		$decoded = json_decode( $this->error_details, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Transition to `processing` and stamp started_at if not already set.
	 */
	public function mark_processing(): void {
		$this->status     = self::STATUS_PROCESSING;
		$this->started_at = $this->started_at ?: current_time( 'mysql', true );
		$this->save();
	}

	/**
	 * Mark as completed and stamp completed_at.
	 */
	public function mark_completed(): void {
		$this->status       = self::STATUS_COMPLETED;
		$this->completed_at = current_time( 'mysql', true );
		$this->save();
	}

	/**
	 * Mark as failed and store the reason.
	 *
	 * @param string $reason Short error message for the user.
	 */
	public function mark_failed( string $reason ): void {
		$this->status       = self::STATUS_FAILED;
		$this->last_error   = $reason;
		$this->completed_at = current_time( 'mysql', true );
		$this->save();
	}

	/**
	 * Mark as cancelled.
	 */
	public function mark_cancelled(): void {
		$this->status       = self::STATUS_CANCELLED;
		$this->completed_at = current_time( 'mysql', true );
		$this->save();
	}

	/**
	 * Is this phase in a terminal state?
	 */
	public function is_terminal(): bool {
		return in_array( $this->status, [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ], true );
	}

	/**
	 * Public-safe array for REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$total      = max( 1, $this->total_items );
		$percentage = $this->total_items > 0
			? (int) floor( ( $this->processed_items / $total ) * 100 )
			: ( self::STATUS_COMPLETED === $this->status ? 100 : 0 );

		return [
			'entity'          => $this->entity,
			'phase_order'     => $this->phase_order,
			'status'          => $this->status,
			'total_items'     => $this->total_items,
			'processed_items' => $this->processed_items,
			'percentage'      => min( 100, max( 0, $percentage ) ),
			'imported'        => $this->imported,
			'skipped'         => $this->skipped,
			'errors'          => $this->errors,
			'error_details'   => $this->decode_error_details(),
			'last_error'      => $this->last_error,
			'started_at'      => $this->started_at,
			'completed_at'    => $this->completed_at,
		];
	}
}
