<?php
/**
 * ImportJob model — tracks a background import as it progresses.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;
use MissionDP\Database\DataStore\ImportJobDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * One row per import attempt.
 */
class ImportJob extends Model {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';

	public string $job_id;
	public int $user_id;
	public string $type;
	public string $duplicate_strategy;
	public string $status;
	public string $file_path;
	public string $original_filename;
	public int $total_rows;
	public int $processed_rows;
	public int $imported;
	public int $skipped;
	public int $updated;
	public int $errors;
	public string $error_details;
	public string $last_error;
	public ?string $started_at;
	public ?string $completed_at;
	public string $date_created;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Column values keyed by property name.
	 */
	public function __construct( array $data = [] ) {
		$this->id                 = isset( $data['id'] ) ? (int) $data['id'] : null;
		$this->job_id             = (string) ( $data['job_id'] ?? '' );
		$this->user_id            = (int) ( $data['user_id'] ?? 0 );
		$this->type               = (string) ( $data['type'] ?? 'donors' );
		$this->duplicate_strategy = (string) ( $data['duplicate_strategy'] ?? 'skip' );
		$this->status             = (string) ( $data['status'] ?? self::STATUS_QUEUED );
		$this->file_path          = (string) ( $data['file_path'] ?? '' );
		$this->original_filename  = (string) ( $data['original_filename'] ?? '' );
		$this->total_rows         = (int) ( $data['total_rows'] ?? 0 );
		$this->processed_rows     = (int) ( $data['processed_rows'] ?? 0 );
		$this->imported           = (int) ( $data['imported'] ?? 0 );
		$this->skipped            = (int) ( $data['skipped'] ?? 0 );
		$this->updated            = (int) ( $data['updated'] ?? 0 );
		$this->errors             = (int) ( $data['errors'] ?? 0 );
		$this->error_details      = (string) ( $data['error_details'] ?? '' );
		$this->last_error         = (string) ( $data['last_error'] ?? '' );
		$this->started_at         = isset( $data['started_at'] ) ? (string) $data['started_at'] : null;
		$this->completed_at       = isset( $data['completed_at'] ) ? (string) $data['completed_at'] : null;
		$this->date_created       = (string) ( $data['date_created'] ?? current_time( 'mysql', true ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function new_store(): DataStoreInterface {
		return new ImportJobDataStore();
	}

	/**
	 * Find an import job by its public token.
	 *
	 * @param string $job_id Public token.
	 */
	public static function find_by_job_id( string $job_id ): ?self {
		/** @var ImportJobDataStore $store */
		$store = static::store();
		return $store->find_by_job_id( $job_id );
	}

	/**
	 * Find the user's currently active job (queued or processing) for a given type.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $type    Data type (e.g. 'donors').
	 */
	public static function find_active_for_user( int $user_id, string $type ): ?self {
		/** @var ImportJobDataStore $store */
		$store = static::store();
		return $store->find_active_for_user( $user_id, $type );
	}

	/**
	 * Atomically increment counters and processed_rows.
	 *
	 * Defensive against AS firing the same action twice in rare overlaps —
	 * we never read-modify-write counters from PHP.
	 *
	 * @param array<string, int> $deltas Map of column => delta (imported, skipped, updated, errors, processed_rows).
	 */
	public function increment_counts( array $deltas ): void {
		/** @var ImportJobDataStore $store */
		$store = static::store();
		$store->increment_counts( $this->id, $deltas );
	}

	/**
	 * Append entries to error_details, capping at 25 total to avoid runaway payloads.
	 *
	 * @param array<int, array{row: int, message: string}> $new_entries Entries to append.
	 */
	public function append_error_details( array $new_entries ): void {
		if ( empty( $new_entries ) ) {
			return;
		}

		$existing = $this->decode_error_details();
		$combined = array_slice( array_merge( $existing, $new_entries ), 0, 25 );

		$this->error_details = wp_json_encode( $combined );
		/** @var ImportJobDataStore $store */
		$store = static::store();
		$store->update_field( $this->id, 'error_details', $this->error_details );
	}

	/**
	 * Decode error_details into an array of {row, message}.
	 *
	 * @return array<int, array{row: int, message: string}>
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
	 * Is this job in a terminal state?
	 */
	public function is_terminal(): bool {
		return in_array( $this->status, [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ], true );
	}

	/**
	 * Public-safe array for REST responses. Never exposes file_path.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$total      = max( 1, $this->total_rows );
		$percentage = $this->total_rows > 0
			? (int) floor( ( $this->processed_rows / $total ) * 100 )
			: ( self::STATUS_COMPLETED === $this->status ? 100 : 0 );

		return [
			'job_id'             => $this->job_id,
			'type'               => $this->type,
			'duplicate_strategy' => $this->duplicate_strategy,
			'status'             => $this->status,
			'original_filename'  => $this->original_filename,
			'total_rows'         => $this->total_rows,
			'processed_rows'     => $this->processed_rows,
			'percentage'         => min( 100, max( 0, $percentage ) ),
			'imported'           => $this->imported,
			'skipped'            => $this->skipped,
			'updated'            => $this->updated,
			'errors'             => $this->errors,
			'error_details'      => $this->decode_error_details(),
			'last_error'         => $this->last_error,
			'started_at'         => $this->started_at,
			'completed_at'       => $this->completed_at,
			'date_created'       => $this->date_created,
		];
	}
}
