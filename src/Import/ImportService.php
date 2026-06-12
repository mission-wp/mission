<?php
/**
 * Import service. Parses uploaded files, runs validation previews, and
 * orchestrates background donor imports via Action Scheduler.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Export\ExportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central import coordinator.
 */
class ImportService {

	private const TYPES         = [ 'donors', 'transactions', 'campaigns', 'subscriptions', 'tributes' ];
	private const PREVIEW_ROWS  = 5;
	public const MAX_BYTES      = 10 * 1024 * 1024;
	private const BATCH_DEFAULT = 200;

	/**
	 * Value coercion for raw cell values (amounts, dates, booleans).
	 */
	private RowValueParser $values;

	/**
	 * ID resolution with per-batch lookup caches.
	 */
	private EntityResolver $resolver;

	/**
	 * CSV/JSON parsing and slicing.
	 */
	private ImportFileReader $reader;

	/**
	 * Upload file storage lifecycle.
	 */
	private ImportFileStorage $storage;

	/**
	 * Touched-entity accumulation for the end-of-job aggregate recompute.
	 */
	private TouchedEntityTracker $touched;

	/**
	 * Per-type row importers, keyed by import type.
	 *
	 * @var array<string, Importers\RowImporter>
	 */
	private array $importers;

	/**
	 * Constructor.
	 *
	 * @param ExportService $export    Export service (for column definitions).
	 * @param ColumnMapper  $mapper    Column mapper.
	 * @param RowValidator  $validator Row validator.
	 */
	public function __construct(
		private ExportService $export,
		private ColumnMapper $mapper,
		private RowValidator $validator,
	) {
		$this->values   = new RowValueParser();
		$this->resolver = new EntityResolver();
		$this->reader   = new ImportFileReader();
		$this->storage  = new ImportFileStorage();
		$this->touched  = new TouchedEntityTracker();

		$this->importers = [
			'donors'        => new Importers\DonorImporter( $validator, $this->values, $this->resolver ),
			'transactions'  => new Importers\TransactionImporter( $validator, $this->values, $this->resolver, $this->touched ),
			'campaigns'     => new Importers\CampaignImporter( $validator, $this->values, $this->resolver ),
			'subscriptions' => new Importers\SubscriptionImporter( $validator, $this->values, $this->resolver ),
			'tributes'      => new Importers\TributeImporter( $validator, $this->values, $this->resolver ),
		];
	}

	/**
	 * Whether an import type string is supported.
	 *
	 * @param string $type Type to test.
	 */
	public function is_valid_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Get supported import types.
	 *
	 * @return string[]
	 */
	public function get_types(): array {
		return self::TYPES;
	}

	/**
	 * Get the expected (canonical) column keys for a type.
	 *
	 * @param string $type Data type.
	 * @return string[]
	 */
	public function get_expected_columns( string $type ): array {
		return $this->mapper->get_known_fields( $type );
	}

	/**
	 * Get the columns the user must include in their file for a given type.
	 *
	 * Broader than RowValidator::required_fields() — it also lists columns that
	 * are required by downstream resolution (e.g. transactions need a donor
	 * reference, which is enforced after validation in resolve_donor_id()).
	 *
	 * @param string $type Data type.
	 * @return string[]
	 */
	public function get_required_columns( string $type ): array {
		return match ( $type ) {
			'donors'        => [ 'email' ],
			'transactions'  => [ 'amount', 'donor_email' ],
			'campaigns'     => [ 'title' ],
			'subscriptions' => [ 'amount', 'donor_email', 'status' ],
			'tributes'      => [ 'transaction_id' ],
			default         => [],
		};
	}

	/**
	 * Validate an uploaded file and return a preview payload.
	 *
	 * @param array{tmp_name: string, name: string, size: int, type: string, error: int} $file PHP $_FILES-style array.
	 * @param string                                                                     $type Data type.
	 *
	 * @return array|WP_Error Preview payload or error.
	 */
	public function validate_file( array $file, string $type ): array|WP_Error {
		if ( ! $this->is_valid_type( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unsupported import type.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_failed', __( 'File upload failed.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) && ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_failed', __( 'Uploaded file is not readable.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds the 10 MB limit.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		$parsed = match ( $extension ) {
			'csv'   => $this->reader->parse_csv( $file['tmp_name'] ),
			'json'  => $this->reader->parse_json( $file['tmp_name'] ),
			default => new WP_Error( 'invalid_extension', __( 'Only .csv and .json files are supported.', 'mission-donation-platform' ), [ 'status' => 400 ] ),
		};

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$headers = $parsed['headers'];
		$rows    = $parsed['rows'];

		if ( empty( $headers ) ) {
			return new WP_Error( 'no_headers', __( 'No header row was detected in the file.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$resolved = $this->mapper->resolve_headers( $headers, $type );
		$matched  = array_values( array_filter( $resolved['matched'] ) );

		$mapped_rows  = $this->reader->map_rows( $rows, $resolved['matched'] );
		$warnings     = [];
		$skipped_rows = [];

		foreach ( $mapped_rows as $i => $row ) {
			foreach ( $this->validator->validate( $row, $type, $i + 1 ) as $warning ) {
				$warnings[] = $warning;

				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					$skipped_rows[ $warning['row'] ] = true;
				}
			}
		}

		$skipped_row_numbers = array_keys( $skipped_rows );
		$importable_rows     = count( $rows ) - count( $skipped_row_numbers );

		$duplicates    = $this->count_duplicates( $type, $mapped_rows, $skipped_rows );
		$gateway_field = match ( $type ) {
			'transactions'  => 'gateway_transaction_id',
			'subscriptions' => 'gateway_subscription_id',
			default         => '',
		};
		$rows_no_gateway = '' !== $gateway_field
			? $this->count_rows_without_gateway_id( $mapped_rows, $gateway_field, $skipped_rows )
			: 0;

		$preview_rows = array_map(
			static fn( array $row ) => array_values( $row ),
			array_slice( $rows, 0, self::PREVIEW_ROWS )
		);

		$file_id = $this->storage->store_file( $file['tmp_name'], $file['name'], $type );

		return [
			'file_id'                 => $file_id,
			'filename'                => $file['name'],
			'filesize'                => (int) $file['size'],
			'rows_detected'           => count( $rows ),
			'rows_importable'         => $importable_rows,
			'rows_skipped'            => count( $skipped_row_numbers ),
			'columns'                 => $headers,
			'columns_matched'         => count( array_unique( $matched ) ),
			'columns_unmatched'       => $resolved['unmatched'],
			'warnings'                => $warnings,
			'duplicates'              => $duplicates,
			'rows_without_gateway_id' => $rows_no_gateway,
			'preview_headers'         => $headers,
			'preview_rows'            => $preview_rows,
			'warning_rows'            => array_values( array_unique( array_column( $warnings, 'row' ) ) ),
			'skipped_row_numbers'     => $skipped_row_numbers,
		];
	}

	/**
	 * Start a background import from a validated upload. Creates an ImportJob
	 * row, moves the file to stable storage, and enqueues the first Action
	 * Scheduler tick. Does not process any rows itself.
	 *
	 * @param string $file_id            Transient key from validate_file().
	 * @param string $duplicate_strategy 'skip' | 'update'.
	 * @param int    $user_id            Current WP user (jobs are scoped per user).
	 *
	 * @return array|WP_Error Job payload, or WP_Error on validation/conflict.
	 */
	public function start_import( string $file_id, string $duplicate_strategy, int $user_id ): array|WP_Error {
		if ( ! in_array( $duplicate_strategy, [ 'skip', 'update' ], true ) ) {
			return new WP_Error( 'invalid_strategy', __( 'Invalid duplicate strategy.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( $user_id <= 0 ) {
			return new WP_Error( 'no_user', __( 'You must be signed in to start an import.', 'mission-donation-platform' ), [ 'status' => 401 ] );
		}

		$stored = get_transient( $file_id );

		if ( ! is_array( $stored ) || empty( $stored['path'] ) || empty( $stored['type'] ) ) {
			return new WP_Error( 'file_expired', __( 'The uploaded file has expired. Please upload it again.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$source_path = (string) $stored['path'];
		$type        = (string) $stored['type'];
		$filename    = (string) ( $stored['filename'] ?? '' );

		if ( ! is_readable( $source_path ) ) {
			return new WP_Error( 'file_missing', __( 'The uploaded file could not be read.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( ! in_array( $type, [ 'donors', 'transactions', 'campaigns', 'subscriptions', 'tributes' ], true ) ) {
			return new WP_Error( 'unsupported_type', __( 'This import type is not yet supported.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		// Concurrency guard: refuse a second import while one is in flight.
		$active = ImportJob::find_active_for_user( $user_id, $type );

		if ( $active ) {
			return new WP_Error(
				'import_in_progress',
				__( 'An import is already running for this data type. Resume or cancel it before starting another.', 'mission-donation-platform' ),
				[
					'status' => 409,
					'job'    => $active->to_array(),
				]
			);
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$job_id = $this->generate_job_id();
		$stable = $this->storage->move_to_storage( $source_path, $job_id, $extension );

		if ( is_wp_error( $stable ) ) {
			return $stable;
		}

		// Single pass to count rows so the UI can show real percentages.
		$total_rows = $this->reader->count_rows( $stable, $extension );

		if ( is_wp_error( $total_rows ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable during REST.
			@unlink( $stable );
			return $total_rows;
		}

		$job = new ImportJob(
			[
				'job_id'             => $job_id,
				'user_id'            => $user_id,
				'type'               => $type,
				'duplicate_strategy' => $duplicate_strategy,
				'status'             => ImportJob::STATUS_QUEUED,
				'file_path'          => $stable,
				'original_filename'  => $filename,
				'total_rows'         => $total_rows,
			]
		);
		$job->save();

		// Drop the upload transient now that we own the file at a stable path.
		delete_transient( $file_id );

		/**
		 * Fires before the first tick of an import is scheduled.
		 *
		 * @param string $type      Data type.
		 * @param int    $row_count Total rows.
		 * @param string $strategy  Duplicate strategy.
		 * @param string $job_id    Public job token.
		 */
		do_action( "mission_import_{$type}_before", $type, $total_rows, $duplicate_strategy, $job_id );

		// Enqueue the first tick. Each tick reschedules itself until done.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'missiondp_import_tick', [ $job_id ], 'mission-import' );
			$this->kick_queue_runner();
		} else {
			// Fallback for environments without Action Scheduler (e.g. tests).
			do_action( 'missiondp_import_tick', $job_id );
		}

		return $job->to_array();
	}

	/**
	 * Run AS's queue runner on shutdown so ticks start processing immediately
	 * in environments where WP-Cron / loopback dispatch is unreliable (wp-env,
	 * low-traffic sites). On PHP-FPM the response is flushed first so the
	 * caller isn't blocked.
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
					\ActionScheduler::runner()->run( 'Mission Import' );
				} catch ( \Throwable $e ) {
					// Don't let runner failures surface — AS records them itself.
					unset( $e );
				}
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Read the next batch of rows from the job's file (skipping past
	 * processed_rows), map columns, and run the donor write logic. Returns
	 * the delta to apply to the job's counters.
	 *
	 * Used by ImportJobHandler. Public so the handler in another namespace can call it.
	 *
	 * @param ImportJob $job        The job.
	 * @param int       $batch_size Rows to process this call.
	 *
	 * @return array{processed: int, imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>, eof: bool}
	 */
	public function process_batch( ImportJob $job, int $batch_size ): array {
		$batch = $this->read_batch( $job, $batch_size );

		if ( is_wp_error( $batch ) ) {
			throw new \RuntimeException( esc_html( $batch->get_error_message() ) );
		}

		if ( empty( $batch['rows'] ) ) {
			return [
				'processed'     => 0,
				'imported'      => 0,
				'skipped'       => 0,
				'updated'       => 0,
				'errors'        => 0,
				'error_details' => [],
				'eof'           => true,
			];
		}

		$importer = $this->importers[ $job->type ] ?? null;

		if ( ! $importer ) {
			throw new \RuntimeException( esc_html( "Unsupported import type: {$job->type}" ) );
		}

		$deltas = $importer->process( $batch['rows'], $job->duplicate_strategy, $batch['start_row'], $job );

		$deltas['processed'] = count( $batch['rows'] );
		$deltas['eof']       = $batch['eof'];

		return $deltas;
	}

	/**
	 * Return the public-safe payload for a job, scoped to the requesting user.
	 *
	 * @param string $job_id  Public token.
	 * @param int    $user_id Current user.
	 */
	public function get_job_status( string $job_id, int $user_id ): array|WP_Error {
		$job = ImportJob::find_by_job_id( $job_id );

		if ( ! $job || $job->user_id !== $user_id ) {
			return new WP_Error( 'job_not_found', __( 'Import job not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		return $job->to_array();
	}

	/**
	 * Cancel a queued/processing job. The next tick observes the flag and bails.
	 *
	 * @param string $job_id  Public token.
	 * @param int    $user_id Current user.
	 */
	public function cancel_job( string $job_id, int $user_id ): array|WP_Error {
		$job = ImportJob::find_by_job_id( $job_id );

		if ( ! $job || $job->user_id !== $user_id ) {
			return new WP_Error( 'job_not_found', __( 'Import job not found.', 'mission-donation-platform' ), [ 'status' => 404 ] );
		}

		if ( $job->is_terminal() ) {
			return $job->to_array();
		}

		$job->mark_cancelled();

		return $job->to_array();
	}

	/**
	 * Get the user's currently in-flight job for a type (queued or processing).
	 *
	 * @param int    $user_id User.
	 * @param string $type    Type.
	 */
	public function get_active_job( int $user_id, string $type ): ?array {
		$job = ImportJob::find_active_for_user( $user_id, $type );

		return $job ? $job->to_array() : null;
	}

	/**
	 * Configured batch size, filterable.
	 */
	public function get_batch_size(): int {
		/**
		 * Filter the number of rows processed per import tick.
		 *
		 * @param int $batch_size Default batch size.
		 */
		return max( 1, (int) apply_filters( 'mission_import_batch_size', self::BATCH_DEFAULT ) );
	}

	/**
	 * Remove a job's stored upload file. Called on cancel/complete/failure
	 * cleanup. Safe to call repeatedly.
	 *
	 * @param ImportJob $job Job.
	 */
	public function delete_job_file( ImportJob $job ): void {
		$this->storage->delete_job_file( $job );
	}

	// ------------------------------------------------------------------
	// Batch reading & touched-entity tracking
	// ------------------------------------------------------------------

	/**
	 * Read the next batch of rows from a job's file, starting at processed_rows.
	 * Returns mapped (canonical-keyed) rows along with the 1-based row number of
	 * the first row in the batch, so error reports can use the user's row numbers.
	 *
	 * @param ImportJob $job        Job.
	 * @param int       $batch_size Max rows to return.
	 *
	 * @return array{rows: array<int, array<string, string>>, start_row: int, eof: bool}|WP_Error
	 */
	private function read_batch( ImportJob $job, int $batch_size ): array|WP_Error {
		if ( ! is_readable( $job->file_path ) ) {
			return new WP_Error( 'file_missing', __( 'Import file is no longer available.', 'mission-donation-platform' ) );
		}

		$extension = strtolower( pathinfo( $job->original_filename, PATHINFO_EXTENSION ) );

		$parsed = match ( $extension ) {
			'csv'   => $this->reader->read_csv_slice( $job->file_path, $job->processed_rows, $batch_size ),
			'json'  => $this->reader->read_json_slice( $job->file_path, $job->processed_rows, $batch_size ),
			default => new WP_Error( 'invalid_extension', __( 'Unknown file format.', 'mission-donation-platform' ) ),
		};

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$resolved = $this->mapper->resolve_headers( $parsed['headers'], $job->type );
		$mapped   = $this->reader->map_rows( $parsed['rows'], $resolved['matched'] );

		return [
			'rows'      => $mapped,
			'start_row' => $job->processed_rows + 1,
			'eof'       => $parsed['eof'],
		];
	}

	/**
	 * Accumulate distinct touched IDs in a per-job transient.
	 *
	 * @param string $job_id Public job token.
	 * @param string $bucket 'donors' or 'campaigns'.
	 * @param int    $id     Donor or campaign ID.
	 */
	public function track_touched_entity( string $job_id, string $bucket, int $id ): void {
		$this->touched->track( $job_id, $bucket, $id );
	}

	/**
	 * Rebuild aggregates for all donors and campaigns touched by this job.
	 *
	 * Called from ImportJobHandler when the job transitions to completed, before
	 * mark_completed(). Idempotent — running it twice produces the same result.
	 *
	 * @param ImportJob $job The completed job.
	 */
	public function run_post_import_recompute( ImportJob $job ): void {
		$this->touched->recompute_and_clear( $job->job_id );
	}

	// ------------------------------------------------------------------
	// Templates & preview analysis
	// ------------------------------------------------------------------

	/**
	 * Generate the blank CSV template for a type.
	 *
	 * @param string $type Data type.
	 */
	public function build_template( string $type ): string {
		$columns = $this->export->get_columns( $type );
		$headers = array_column( $columns, 'label' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $headers );
		rewind( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$content = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return (string) $content;
	}

	/**
	 * Count rows that look like duplicates of existing records, used by the preview.
	 *
	 * @param string                              $type                Data type.
	 * @param array<int, array<string, string>>   $rows                Mapped rows.
	 * @param array<int, bool>                    $skipped_rows_lookup Rows already flagged for skipping.
	 */
	private function count_duplicates( string $type, array $rows, array $skipped_rows_lookup = [] ): int {
		if ( 'donors' === $type ) {
			$count = 0;
			$seen  = [];

			foreach ( $rows as $i => $row ) {
				if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
					continue;
				}

				$email = strtolower( trim( $row['email'] ?? '' ) );

				if ( '' === $email || ! is_email( $email ) || isset( $seen[ $email ] ) ) {
					continue;
				}

				$seen[ $email ] = true;

				if ( Donor::find_by_email( $email ) ) {
					++$count;
				}
			}

			return $count;
		}

		if ( 'transactions' === $type ) {
			$count = 0;
			$seen  = [];

			foreach ( $rows as $i => $row ) {
				if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
					continue;
				}

				$gateway_id = trim( (string) ( $row['gateway_transaction_id'] ?? '' ) );

				if ( '' === $gateway_id || isset( $seen[ $gateway_id ] ) ) {
					continue;
				}

				$seen[ $gateway_id ] = true;

				if ( Transaction::find_by_gateway_transaction_id( $gateway_id ) ) {
					++$count;
				}
			}

			return $count;
		}

		if ( 'campaigns' === $type ) {
			$count = 0;
			$seen  = [];

			foreach ( $rows as $i => $row ) {
				if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
					continue;
				}

				$title = trim( (string) ( $row['title'] ?? '' ) );
				$key   = ColumnMapper::normalize( $title );

				if ( '' === $title || isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;

				if ( Campaign::find_by_title( $title ) ) {
					++$count;
				}
			}

			return $count;
		}

		if ( 'subscriptions' === $type ) {
			$count = 0;
			$seen  = [];

			foreach ( $rows as $i => $row ) {
				if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
					continue;
				}

				$gateway_id = trim( (string) ( $row['gateway_subscription_id'] ?? '' ) );

				if ( '' === $gateway_id || isset( $seen[ $gateway_id ] ) ) {
					continue;
				}

				$seen[ $gateway_id ] = true;

				if ( Subscription::find_by_gateway_subscription_id( $gateway_id ) ) {
					++$count;
				}
			}

			return $count;
		}

		if ( 'tributes' === $type ) {
			$count = 0;
			$seen  = [];

			foreach ( $rows as $i => $row ) {
				if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
					continue;
				}

				$transaction_id = $this->resolver->resolve_transaction_id( $row );

				if ( is_wp_error( $transaction_id ) || isset( $seen[ $transaction_id ] ) ) {
					continue;
				}

				$seen[ $transaction_id ] = true;

				if ( Tribute::find_by_transaction_id( $transaction_id ) ) {
					++$count;
				}
			}

			return $count;
		}

		return 0;
	}

	/**
	 * Count rows with no gateway ID in the given field. These rows have no stable
	 * key to deduplicate against, so each import creates a new record for them.
	 * The validation preview surfaces this so the user knows what happens on a
	 * re-run.
	 *
	 * @param array<int, array<string, string>> $rows                Mapped rows.
	 * @param string                            $field               Gateway field key (e.g. gateway_transaction_id).
	 * @param array<int, bool>                  $skipped_rows_lookup Rows already flagged for skipping.
	 */
	private function count_rows_without_gateway_id( array $rows, string $field, array $skipped_rows_lookup = [] ): int {
		$count = 0;

		foreach ( $rows as $i => $row ) {
			if ( isset( $skipped_rows_lookup[ $i + 1 ] ) ) {
				continue;
			}

			if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Generate an unguessable job token.
	 */
	private function generate_job_id(): string {
		return 'mdp_job_' . wp_generate_password( 24, false );
	}
}
