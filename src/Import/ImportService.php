<?php
/**
 * Import service. Parses uploaded files, runs validation previews, and
 * orchestrates background donor imports via Action Scheduler.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Currency\Currency;
use MissionDP\Database\DataStore\CampaignDataStore;
use MissionDP\Database\DataStore\DonorDataStore;
use MissionDP\Database\DataStore\SubscriptionDataStore;
use MissionDP\Database\DataStore\TransactionDataStore;
use MissionDP\Database\DataStore\TributeDataStore;
use MissionDP\Export\ExportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\ImportJob;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central import coordinator.
 */
class ImportService {

	private const TYPES         = [ 'donors', 'transactions', 'campaigns', 'subscriptions', 'tributes' ];
	private const PREVIEW_ROWS  = 5;
	private const MAX_BYTES     = 10 * 1024 * 1024;
	private const FILE_ID_TTL   = HOUR_IN_SECONDS;
	private const BATCH_DEFAULT = 200;
	private const STORAGE_DIR   = 'mission-imports';

	/**
	 * Constructor.
	 *
	 * @param ExportService $export    Export service (for column definitions).
	 * @param ColumnMapper  $mapper    Column mapper.
	 * @param RowValidator  $validator Row validator.
	 */
	public function __construct(
		private readonly ExportService $export,
		private readonly ColumnMapper $mapper,
		private readonly RowValidator $validator,
	) {}

	/**
	 * Per-batch caches for donor and campaign lookups. Keyed by lookup-string
	 * (email or title), value is int donor/campaign id, or 0 for "we already
	 * looked and found nothing" so we don't re-query.
	 *
	 * @var array<string, int>
	 */
	private array $donor_lookup_cache = [];

	/**
	 * @var array<string, int>
	 */
	private array $campaign_lookup_cache = [];

	/**
	 * @var array<string, int>
	 */
	private array $transaction_lookup_cache = [];

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
			'csv'   => $this->parse_csv( $file['tmp_name'] ),
			'json'  => $this->parse_json( $file['tmp_name'] ),
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

		$mapped_rows  = $this->map_rows( $rows, $resolved['matched'] );
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

		$file_id = $this->store_file( $file['tmp_name'], $file['name'], $type );

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
		$stable = $this->move_to_storage( $source_path, $job_id, $extension );

		if ( is_wp_error( $stable ) ) {
			return $stable;
		}

		// Single pass to count rows so the UI can show real percentages.
		$total_rows = $this->count_rows( $stable, $extension );

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
		do_action( "missiondp_import_{$type}_before", $type, $total_rows, $duplicate_strategy, $job_id );

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

		$deltas = match ( $job->type ) {
			'donors'       => $this->process_donor_rows( $batch['rows'], $job->duplicate_strategy, $batch['start_row'] ),
			'transactions' => $this->process_transaction_rows( $batch['rows'], $job->duplicate_strategy, $batch['start_row'], $job->job_id ),
			'campaigns'    => $this->process_campaign_rows( $batch['rows'], $job->duplicate_strategy, $batch['start_row'] ),
			'subscriptions' => $this->process_subscription_rows( $batch['rows'], $job->duplicate_strategy, $batch['start_row'] ),
			'tributes'     => $this->process_tribute_rows( $batch['rows'], $job->duplicate_strategy, $batch['start_row'] ),
			default        => throw new \RuntimeException( esc_html( "Unsupported import type: {$job->type}" ) ),
		};

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
		return max( 1, (int) apply_filters( 'missiondp_import_batch_size', self::BATCH_DEFAULT ) );
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
	 * Log a completed import to the activity feed. Used by ImportJobHandler.
	 *
	 * @param ImportJob $job Completed job.
	 */
	public function log_completed_activity( ImportJob $job ): void {
		if ( ! class_exists( Plugin::class ) ) {
			return;
		}

		// Nothing was written (e.g. an update run where every row was unchanged or
		// skipped) — don't record an activity entry; nothing happened.
		if ( 0 === $job->imported && 0 === $job->updated ) {
			return;
		}

		$module = Plugin::instance()->get_activity_feed_module();

		if ( ! $module ) {
			return;
		}

		// The import runs in a background job, so there's no current user to
		// attribute it to — resolve the importer from the job and store the name.
		$user       = get_userdata( $job->user_id );
		$actor_name = $user ? ( $user->display_name ?: $user->user_login ) : '';

		$module->log(
			'data_imported',
			$job->type,
			0,
			[
				'type'       => $job->type,
				'strategy'   => $job->duplicate_strategy,
				'imported'   => $job->imported,
				'skipped'    => $job->skipped,
				'updated'    => $job->updated,
				'errors'     => $job->errors,
				'job_id'     => $job->job_id,
				'actor_name' => $actor_name,
			],
			false,
			$job->errors > 0 ? 'warning' : 'info',
		);
	}

	// ------------------------------------------------------------------
	// Internal: row reading & processing
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
			'csv'   => $this->read_csv_slice( $job->file_path, $job->processed_rows, $batch_size ),
			'json'  => $this->read_json_slice( $job->file_path, $job->processed_rows, $batch_size ),
			default => new WP_Error( 'invalid_extension', __( 'Unknown file format.', 'mission-donation-platform' ) ),
		};

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$resolved = $this->mapper->resolve_headers( $parsed['headers'], $job->type );
		$mapped   = $this->map_rows( $parsed['rows'], $resolved['matched'] );

		return [
			'rows'      => $mapped,
			'start_row' => $job->processed_rows + 1,
			'eof'       => $parsed['eof'],
		];
	}

	/**
	 * Apply a batch of donor rows. Same logic as the pre-background flow:
	 * re-run validator (skip on errors), respect duplicate strategy, save via
	 * Model layer so hooks fire.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows.
	 * @param string                            $strategy  Duplicate strategy.
	 * @param int                               $start_row 1-based row number of the first row.
	 *
	 * @return array{imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>}
	 */
	private function process_donor_rows( array $rows, string $strategy, int $start_row ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'donors', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare_donor_row( $row );

			/**
			 * Filter a donor row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'missiondp_import_donors_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$email    = (string) ( $prepared['email'] ?? '' );
				$existing = '' !== $email ? Donor::find_by_email( $email ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							if ( 'email' === $field ) {
								continue;
							}
							$existing->{$field} = $value;
						}
						$existing->save();
						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$donor = new Donor( $prepared );
				$donor->save();
				$this->apply_meta( $donor, $meta_pairs );
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	// ------------------------------------------------------------------
	// Transactions
	// ------------------------------------------------------------------

	/**
	 * Process a batch of mapped transaction rows.
	 *
	 * Inserts go through TransactionDataStore::create_silent() so we don't fire
	 * missiondp_transaction_created or per-row aggregate updates. Touched donor
	 * and campaign IDs are accumulated in the job's transient so the end-of-job
	 * recompute can rebuild aggregates in a single pass.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows from read_batch().
	 * @param string                            $strategy  Duplicate strategy (skip/update/create).
	 * @param int                               $start_row Row number of the first row in this batch.
	 * @param string                            $job_id    Public job token.
	 *
	 * @return array{imported:int, skipped:int, updated:int, errors:int, error_details: array<int, array{row:int, message:string}>}
	 */
	private function process_transaction_rows( array $rows, string $strategy, int $start_row, string $job_id ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		$this->donor_lookup_cache    = [];
		$this->campaign_lookup_cache = [];

		$transaction_store = new TransactionDataStore();

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'transactions', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare_transaction_row( $row );

			if ( is_wp_error( $prepared ) ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $prepared->get_error_message(),
				];
				continue;
			}

			/**
			 * Filter a transaction row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'missiondp_import_transactions_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$gateway_id = (string) ( $prepared['gateway_transaction_id'] ?? '' );
				$existing   = '' !== $gateway_id ? Transaction::find_by_gateway_transaction_id( $gateway_id ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$transaction_store->update_silent( $existing );
						$this->apply_meta( $existing, $meta_pairs );
						$this->mark_touched_from_transaction( $existing, $job_id );
						++$updated;
						continue;
					}
				}

				// Stamp provenance on created rows only — an import that updates
				// an existing transaction must not claim it.
				$prepared['import_job_id'] = $job_id;

				$transaction = new Transaction( $prepared );
				$transaction_store->create_silent( $transaction );
				$this->apply_meta( $transaction, $meta_pairs );
				$this->mark_touched_from_transaction( $transaction, $job_id );
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	/**
	 * Convert a mapped transaction row into the data array for the Transaction constructor.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_transaction_row( array $row ): array|WP_Error {
		$donor_id = $this->resolve_donor_id( $row );
		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		$campaign_id = $this->resolve_campaign_id( $row );
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$amount          = $this->parse_amount( (string) ( $row['amount'] ?? '0' ) );
		$fee_amount      = $this->parse_amount( (string) ( $row['fee_amount'] ?? '0' ) );
		$tip_amount      = $this->parse_amount( (string) ( $row['tip_amount'] ?? '0' ) );
		$amount_refunded = $this->parse_amount( (string) ( $row['amount_refunded'] ?? '0' ) );

		$total_amount = isset( $row['total_amount'] ) && '' !== trim( (string) $row['total_amount'] )
			? $this->parse_amount( (string) $row['total_amount'] )
			: $amount + $fee_amount + $tip_amount;

		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
		if ( ! in_array( $status, [ 'pending', 'completed', 'refunded', 'cancelled', 'failed' ], true ) ) {
			$status = 'completed';
		}

		$date_created   = $this->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_completed = $this->parse_date( (string) ( $row['date_completed'] ?? '' ) );
		$date_refunded  = $this->parse_date( (string) ( $row['date_refunded'] ?? '' ) );

		if ( 'completed' === $status && null === $date_completed ) {
			$date_completed = $date_created ?? current_time( 'mysql', true );
		}

		$prepared = [
			'status'                  => $status,
			'donor_id'                => $donor_id,
			'campaign_id'             => $campaign_id,
			'amount'                  => $amount,
			'fee_amount'              => $fee_amount,
			'tip_amount'              => $tip_amount,
			'total_amount'            => $total_amount,
			'amount_refunded'         => $amount_refunded,
			'currency'                => strtolower( trim( (string) ( $row['currency'] ?? 'usd' ) ) ),
			'payment_gateway'         => trim( (string) ( $row['payment_gateway'] ?? '' ) ),
			'gateway_transaction_id'  => trim( (string) ( $row['gateway_transaction_id'] ?? '' ) ) ?: null,
			'gateway_subscription_id' => trim( (string) ( $row['gateway_subscription_id'] ?? '' ) ) ?: null,
			'gateway_customer_id'     => trim( (string) ( $row['gateway_customer_id'] ?? '' ) ),
			'is_anonymous'            => $this->parse_bool( $row['is_anonymous'] ?? '' ),
			'is_test'                 => $this->parse_bool( $row['is_test'] ?? '' ),
			'donor_ip'                => trim( (string) ( $row['donor_ip'] ?? '' ) ),
		];

		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_completed ) {
			$prepared['date_completed'] = $date_completed;
		}
		if ( null !== $date_refunded ) {
			$prepared['date_refunded'] = $date_refunded;
		}

		if ( isset( $row['type'] ) && '' !== trim( (string) $row['type'] ) ) {
			$prepared['type'] = trim( (string) $row['type'] );
		}

		return $prepared;
	}

	/**
	 * Resolve a transaction row's donor reference to a donor ID.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|WP_Error
	 */
	private function resolve_donor_id( array $row ): int|WP_Error {
		if ( isset( $row['donor_id'] ) && '' !== trim( (string) $row['donor_id'] ) ) {
			$id = (int) trim( (string) $row['donor_id'] );

			if ( $id > 0 ) {
				/* translators: %d: donor ID */
				$not_found_message = __( 'No donor with ID %d.', 'mission-donation-platform' );

				$cache_key = "id:{$id}";
				if ( isset( $this->donor_lookup_cache[ $cache_key ] ) ) {
					if ( 0 === $this->donor_lookup_cache[ $cache_key ] ) {
						return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $id ) );
					}
					return $this->donor_lookup_cache[ $cache_key ];
				}

				$donor                                  = Donor::find( $id );
				$this->donor_lookup_cache[ $cache_key ] = $donor ? $donor->id : 0;

				if ( $donor ) {
					return $donor->id;
				}

				return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $id ) );
			}
		}

		$email = strtolower( trim( (string) ( $row['donor_email'] ?? '' ) ) );

		if ( '' === $email ) {
			return new WP_Error( 'donor_missing', __( 'Row has no donor_email or donor_id.', 'mission-donation-platform' ) );
		}

		/* translators: %s: donor email */
		$not_found_message = __( 'No donor found for email "%s". Import donors first.', 'mission-donation-platform' );

		$cache_key = "email:{$email}";
		if ( isset( $this->donor_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->donor_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $email ) );
			}
			return $this->donor_lookup_cache[ $cache_key ];
		}

		$donor                                  = Donor::find_by_email( $email );
		$this->donor_lookup_cache[ $cache_key ] = $donor ? $donor->id : 0;

		if ( $donor ) {
			return $donor->id;
		}

		return new WP_Error( 'donor_not_found', sprintf( $not_found_message, $email ) );
	}

	/**
	 * Resolve a transaction row's campaign reference to a campaign ID, or null
	 * if no campaign is referenced (a valid case).
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|null|WP_Error
	 */
	private function resolve_campaign_id( array $row ): int|null|WP_Error {
		if ( isset( $row['campaign_id'] ) && '' !== trim( (string) $row['campaign_id'] ) ) {
			$id = (int) trim( (string) $row['campaign_id'] );

			if ( $id > 0 ) {
				/* translators: %d: campaign ID */
				$not_found_message = __( 'No campaign with ID %d.', 'mission-donation-platform' );

				$cache_key = "id:{$id}";
				if ( isset( $this->campaign_lookup_cache[ $cache_key ] ) ) {
					if ( 0 === $this->campaign_lookup_cache[ $cache_key ] ) {
						return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $id ) );
					}
					return $this->campaign_lookup_cache[ $cache_key ];
				}

				$campaign                                  = Campaign::find( $id );
				$this->campaign_lookup_cache[ $cache_key ] = $campaign ? $campaign->id : 0;

				if ( $campaign ) {
					return $campaign->id;
				}

				return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $id ) );
			}
		}

		$title = trim( (string) ( $row['campaign_title'] ?? '' ) );

		if ( '' === $title ) {
			return null;
		}

		/* translators: %s: campaign title */
		$not_found_message = __( 'No campaign found with title "%s".', 'mission-donation-platform' );

		$cache_key = 'title:' . strtolower( $title );
		if ( isset( $this->campaign_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->campaign_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $title ) );
			}
			return $this->campaign_lookup_cache[ $cache_key ];
		}

		$matches                                   = Campaign::find_all_by_title( $title );
		$this->campaign_lookup_cache[ $cache_key ] = $matches ? $matches[0]->id : 0;

		if ( $matches ) {
			return $matches[0]->id;
		}

		return new WP_Error( 'campaign_not_found', sprintf( $not_found_message, $title ) );
	}

	/**
	 * Coerce a CSV truthy/falsy string to bool.
	 *
	 * @param mixed $value Raw value.
	 */
	private function parse_bool( $value ): bool {
		$normalized = strtolower( trim( (string) $value ) );

		return in_array( $normalized, [ '1', 'true', 'yes', 'y', 'on' ], true );
	}

	/**
	 * Mark a transaction's donor and campaign as touched if the row was committed
	 * with completed status. Touched entities get a recompute at job end.
	 *
	 * @param Transaction $transaction Inserted/updated transaction.
	 * @param string      $job_id      Public job token.
	 */
	private function mark_touched_from_transaction( Transaction $transaction, string $job_id ): void {
		if ( 'completed' !== $transaction->status ) {
			// We still want refunds tracked, since the refund path can change donor totals.
			if ( 'refunded' !== $transaction->status ) {
				return;
			}
		}

		if ( $transaction->donor_id ) {
			$this->track_touched_entity( $job_id, 'donors', $transaction->donor_id );
		}

		if ( $transaction->campaign_id ) {
			$this->track_touched_entity( $job_id, 'campaigns', (int) $transaction->campaign_id );
		}
	}

	/**
	 * Accumulate distinct touched IDs in a per-job transient.
	 *
	 * @param string $job_id Public job token.
	 * @param string $bucket 'donors' or 'campaigns'.
	 * @param int    $id     Donor or campaign ID.
	 */
	public function track_touched_entity( string $job_id, string $bucket, int $id ): void {
		if ( $id <= 0 || ! in_array( $bucket, [ 'donors', 'campaigns' ], true ) ) {
			return;
		}

		$key      = "mission_import_touched_{$job_id}";
		$existing = get_transient( $key );

		if ( ! is_array( $existing ) ) {
			$existing = [
				'donors'    => [],
				'campaigns' => [],
			];
		}

		$existing[ $bucket ][] = $id;
		$existing[ $bucket ]   = array_values( array_unique( $existing[ $bucket ] ) );

		set_transient( $key, $existing, DAY_IN_SECONDS );
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
		$key     = "mission_import_touched_{$job->job_id}";
		$touched = get_transient( $key );

		if ( ! is_array( $touched ) ) {
			return;
		}

		$donor_store    = new DonorDataStore();
		$campaign_store = new CampaignDataStore();

		foreach ( $touched['donors'] ?? [] as $donor_id ) {
			$donor_store->recompute_aggregates( (int) $donor_id );
		}

		foreach ( $touched['campaigns'] ?? [] as $campaign_id ) {
			$campaign_store->recompute_aggregates( (int) $campaign_id );
		}

		delete_transient( $key );
	}

	// ------------------------------------------------------------------
	// Campaigns
	// ------------------------------------------------------------------

	/**
	 * Process a batch of mapped campaign rows.
	 *
	 * Each new campaign is created through the Campaign model so its linked WP
	 * post is created too. Aggregate fields are never imported — they recompute
	 * from real transactions — so prepare_campaign_row() omits them. Duplicates
	 * are matched by title.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows from read_batch().
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row Row number of the first row in this batch.
	 *
	 * @return array{imported:int, skipped:int, updated:int, errors:int, error_details: array<int, array{row:int, message:string}>}
	 */
	private function process_campaign_rows( array $rows, string $strategy, int $start_row ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'campaigns', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare_campaign_row( $row );

			/**
			 * Filter a campaign row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'missiondp_import_campaigns_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$title    = (string) ( $prepared['title'] ?? '' );
				$existing = '' !== $title ? Campaign::find_by_title( $title ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$existing->save();

						// Campaign::save() only writes the linked post on creation, so
						// sync title/description here to keep the post from going stale.
						wp_update_post(
							[
								'ID'           => $existing->post_id,
								'post_title'   => $existing->title,
								'post_excerpt' => $existing->description,
							]
						);

						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$campaign = new Campaign( $prepared );
				$campaign->save();
				$this->apply_meta( $campaign, $meta_pairs );
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	/**
	 * Convert a mapped row into the data array expected by the Campaign constructor.
	 *
	 * Excludes id, post_id, and aggregate fields (total_raised, transaction_count,
	 * donor_count, and their test_* variants) so they default to zero and recompute
	 * from real transactions. Status is derived from the date window when not given
	 * as a valid value.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>
	 */
	private function prepare_campaign_row( array $row ): array {
		$prepared = [];

		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $title ) {
			$prepared['title'] = $title;
		}

		$description = trim( (string) ( $row['description'] ?? '' ) );
		if ( '' !== $description ) {
			$prepared['description'] = $description;
		}

		if ( isset( $row['goal_amount'] ) && '' !== trim( (string) $row['goal_amount'] ) ) {
			$prepared['goal_amount'] = $this->parse_amount( (string) $row['goal_amount'] );
		}

		$goal_type = strtolower( trim( (string) ( $row['goal_type'] ?? '' ) ) );
		if ( in_array( $goal_type, [ 'amount', 'donations', 'donors' ], true ) ) {
			$prepared['goal_type'] = $goal_type;
		}

		$currency = trim( (string) ( $row['currency'] ?? '' ) );
		if ( '' !== $currency ) {
			$prepared['currency'] = strtolower( $currency );
		}

		// Only honor show_in_listings when a value is present; an empty cell must
		// not force it false (the model defaults to true).
		if ( isset( $row['show_in_listings'] ) && '' !== trim( (string) $row['show_in_listings'] ) ) {
			$prepared['show_in_listings'] = $this->parse_bool( $row['show_in_listings'] );
		}

		$date_start    = $this->parse_date( (string) ( $row['date_start'] ?? '' ) );
		$date_end      = $this->parse_date( (string) ( $row['date_end'] ?? '' ) );
		$date_created  = $this->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_modified = $this->parse_date( (string) ( $row['date_modified'] ?? '' ) );

		if ( null !== $date_start ) {
			$prepared['date_start'] = $date_start;
		}
		if ( null !== $date_end ) {
			$prepared['date_end'] = $date_end;
		}
		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_modified ) {
			$prepared['date_modified'] = $date_modified;
		}

		$prepared['status'] = $this->resolve_campaign_status( $row['status'] ?? '', $date_start, $date_end );

		return $prepared;
	}

	/**
	 * Resolve a campaign status: honor a valid given value, otherwise derive it
	 * from the date window.
	 *
	 * @param string      $raw        Raw status value from the row.
	 * @param string|null $date_start Parsed start datetime (MySQL, UTC) or null.
	 * @param string|null $date_end   Parsed end datetime (MySQL, UTC) or null.
	 */
	private function resolve_campaign_status( string $raw, ?string $date_start, ?string $date_end ): string {
		$status = strtolower( trim( $raw ) );

		if ( in_array( $status, [ 'active', 'scheduled', 'ended' ], true ) ) {
			return $status;
		}

		$now = current_time( 'mysql', true );

		if ( null !== $date_start && $date_start > $now ) {
			return 'scheduled';
		}

		if ( null !== $date_end && $date_end < $now ) {
			return 'ended';
		}

		return 'active';
	}

	// ------------------------------------------------------------------
	// Subscriptions
	// ------------------------------------------------------------------

	/**
	 * Process a batch of mapped subscription rows.
	 *
	 * Writes go through SubscriptionDataStore::create_silent() / update_silent()
	 * so the activity feed and status-transition emails don't fire for historical
	 * rows being backfilled. Subscriptions don't touch donor/campaign aggregates,
	 * so there's no end-of-job recompute. Duplicates are matched by
	 * gateway_subscription_id.
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows from read_batch().
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row Row number of the first row in this batch.
	 *
	 * @return array{imported:int, skipped:int, updated:int, errors:int, error_details: array<int, array{row:int, message:string}>}
	 */
	private function process_subscription_rows( array $rows, string $strategy, int $start_row ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		$this->donor_lookup_cache    = [];
		$this->campaign_lookup_cache = [];

		$subscription_store = new SubscriptionDataStore();

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'subscriptions', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare_subscription_row( $row );

			if ( is_wp_error( $prepared ) ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $prepared->get_error_message(),
				];
				continue;
			}

			/**
			 * Filter a subscription row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'missiondp_import_subscriptions_row', $prepared, $row, $strategy );

			$meta_pairs = $this->extract_meta_pairs( $row );

			try {
				$gateway_id = (string) ( $prepared['gateway_subscription_id'] ?? '' );
				$existing   = '' !== $gateway_id ? Subscription::find_by_gateway_subscription_id( $gateway_id ) : null;

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$subscription_store->update_silent( $existing );
						$this->apply_meta( $existing, $meta_pairs );
						++$updated;
						continue;
					}
				}

				$subscription = new Subscription( $prepared );
				$subscription_store->create_silent( $subscription );
				$this->apply_meta( $subscription, $meta_pairs );
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	/**
	 * Convert a mapped row into the data array expected by the Subscription constructor.
	 *
	 * Resolves donor (required) and campaign (optional). Zeroes out renewal_count
	 * and total_renewed, and omits id, source_post_id, and initial_transaction_id
	 * (local references that won't survive an import). Status is assumed valid —
	 * rows with a missing/invalid status are flagged as errors and skipped before
	 * this runs.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_subscription_row( array $row ): array|WP_Error {
		$donor_id = $this->resolve_donor_id( $row );
		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		$campaign_id = $this->resolve_campaign_id( $row );
		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$amount     = $this->parse_amount( (string) ( $row['amount'] ?? '0' ) );
		$fee_amount = $this->parse_amount( (string) ( $row['fee_amount'] ?? '0' ) );
		$tip_amount = $this->parse_amount( (string) ( $row['tip_amount'] ?? '0' ) );

		$total_amount = isset( $row['total_amount'] ) && '' !== trim( (string) $row['total_amount'] )
			? $this->parse_amount( (string) $row['total_amount'] )
			: $amount + $fee_amount + $tip_amount;

		$status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );

		$frequency = strtolower( trim( (string) ( $row['frequency'] ?? '' ) ) );
		if ( ! in_array( $frequency, [ 'weekly', 'monthly', 'quarterly', 'annually' ], true ) ) {
			$frequency = 'monthly';
		}

		$date_created      = $this->parse_date( (string) ( $row['date_created'] ?? '' ) );
		$date_next_renewal = $this->parse_date( (string) ( $row['date_next_renewal'] ?? '' ) );
		$date_cancelled    = $this->parse_date( (string) ( $row['date_cancelled'] ?? '' ) );
		$date_modified     = $this->parse_date( (string) ( $row['date_modified'] ?? '' ) );

		if ( 'cancelled' === $status && null === $date_cancelled ) {
			$date_cancelled = $date_created ?? current_time( 'mysql', true );
		}

		$prepared = [
			'status'                  => $status,
			'donor_id'                => $donor_id,
			'campaign_id'             => $campaign_id,
			'amount'                  => $amount,
			'fee_amount'              => $fee_amount,
			'tip_amount'              => $tip_amount,
			'total_amount'            => $total_amount,
			'currency'                => strtolower( trim( (string) ( $row['currency'] ?? 'usd' ) ) ),
			'frequency'               => $frequency,
			'payment_gateway'         => trim( (string) ( $row['payment_gateway'] ?? '' ) ),
			'gateway_subscription_id' => trim( (string) ( $row['gateway_subscription_id'] ?? '' ) ) ?: null,
			'gateway_customer_id'     => trim( (string) ( $row['gateway_customer_id'] ?? '' ) ) ?: null,
			'is_test'                 => $this->parse_bool( $row['is_test'] ?? '' ),
		];

		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}
		if ( null !== $date_next_renewal ) {
			$prepared['date_next_renewal'] = $date_next_renewal;
		}
		if ( null !== $date_cancelled ) {
			$prepared['date_cancelled'] = $date_cancelled;
		}
		if ( null !== $date_modified ) {
			$prepared['date_modified'] = $date_modified;
		}

		return $prepared;
	}

	// ------------------------------------------------------------------
	// Tributes (Dedications)
	// ------------------------------------------------------------------

	/**
	 * Process a batch of mapped dedication (tribute) rows.
	 *
	 * Each dedication attaches to one existing transaction (resolved by Charge ID
	 * or transaction_id). Writes go through TributeDataStore::create_silent() /
	 * update_silent() so the honoree-notification and admin mail-dedication emails
	 * don't fire for historical rows. Tributes have no meta and touch no
	 * aggregates. Duplicates are matched by transaction (one tribute per
	 * transaction).
	 *
	 * @param array<int, array<string, string>> $rows      Mapped rows from read_batch().
	 * @param string                            $strategy  Duplicate strategy (skip/update).
	 * @param int                               $start_row Row number of the first row in this batch.
	 *
	 * @return array{imported:int, skipped:int, updated:int, errors:int, error_details: array<int, array{row:int, message:string}>}
	 */
	private function process_tribute_rows( array $rows, string $strategy, int $start_row ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];

		$this->transaction_lookup_cache = [];

		$tribute_store = new TributeDataStore();

		foreach ( $rows as $i => $row ) {
			$row_number = $start_row + $i;

			$row_warnings = $this->validator->validate( $row, 'tributes', $row_number );

			foreach ( $row_warnings as $warning ) {
				if ( 'error' === ( $warning['severity'] ?? 'warning' ) ) {
					++$skipped;
					continue 2;
				}
			}

			$prepared = $this->prepare_tribute_row( $row );

			if ( is_wp_error( $prepared ) ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $prepared->get_error_message(),
				];
				continue;
			}

			/**
			 * Filter a dedication row right before it is saved.
			 *
			 * @param array  $prepared Prepared row data.
			 * @param array  $row      Original mapped row.
			 * @param string $strategy Duplicate strategy.
			 */
			$prepared = apply_filters( 'missiondp_import_tributes_row', $prepared, $row, $strategy );

			try {
				$existing = Tribute::find_by_transaction_id( (int) $prepared['transaction_id'] );

				if ( $existing ) {
					if ( 'skip' === $strategy ) {
						++$skipped;
						continue;
					}

					if ( 'update' === $strategy ) {
						foreach ( $prepared as $field => $value ) {
							$existing->{$field} = $value;
						}
						$tribute_store->update_silent( $existing );
						++$updated;
						continue;
					}
				}

				$tribute = new Tribute( $prepared );
				$tribute_store->create_silent( $tribute );
				++$imported;
			} catch ( \Throwable $e ) {
				++$errors;
				$error_details[] = [
					'row'     => $row_number,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'imported'      => $imported,
			'skipped'       => $skipped,
			'updated'       => $updated,
			'errors'        => $errors,
			'error_details' => $error_details,
		];
	}

	/**
	 * Convert a mapped row into the data array expected by the Tribute constructor.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_tribute_row( array $row ): array|WP_Error {
		$transaction_id = $this->resolve_transaction_id( $row );
		if ( is_wp_error( $transaction_id ) ) {
			return $transaction_id;
		}

		$tribute_type = strtolower( trim( (string) ( $row['tribute_type'] ?? '' ) ) );
		if ( ! in_array( $tribute_type, [ 'in_honor', 'in_memory' ], true ) ) {
			$tribute_type = 'in_honor';
		}

		$notify_method = strtolower( trim( (string) ( $row['notify_method'] ?? '' ) ) );
		if ( ! in_array( $notify_method, [ 'email', 'mail' ], true ) ) {
			$notify_method = '';
		}

		$prepared = [
			'transaction_id'   => $transaction_id,
			'tribute_type'     => $tribute_type,
			'honoree_name'     => trim( (string) ( $row['honoree_name'] ?? '' ) ),
			'message'          => trim( (string) ( $row['message'] ?? '' ) ),
			'notify_method'    => $notify_method,
			'notify_name'      => trim( (string) ( $row['notify_name'] ?? '' ) ),
			'notify_email'     => trim( (string) ( $row['notify_email'] ?? '' ) ),
			'notify_address_1' => trim( (string) ( $row['notify_address_1'] ?? '' ) ),
			'notify_address_2' => trim( (string) ( $row['notify_address_2'] ?? '' ) ),
			'notify_city'      => trim( (string) ( $row['notify_city'] ?? '' ) ),
			'notify_state'     => trim( (string) ( $row['notify_state'] ?? '' ) ),
			'notify_zip'       => trim( (string) ( $row['notify_zip'] ?? '' ) ),
			'notify_country'   => trim( (string) ( $row['notify_country'] ?? '' ) ),
		];

		$notification_sent_at = $this->parse_date( (string) ( $row['notification_sent_at'] ?? '' ) );
		$date_created         = $this->parse_date( (string) ( $row['date_created'] ?? '' ) );

		if ( null !== $notification_sent_at ) {
			$prepared['notification_sent_at'] = $notification_sent_at;
		}
		if ( null !== $date_created ) {
			$prepared['date_created'] = $date_created;
		}

		return $prepared;
	}

	/**
	 * Resolve a dedication row's transaction, by Charge ID (gateway_transaction_id)
	 * first, then transaction_id. Caches lookups per batch.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return int|WP_Error Transaction ID, or error if the row references none / an unknown one.
	 */
	private function resolve_transaction_id( array $row ): int|WP_Error {
		$charge_id = trim( (string) ( $row['gateway_transaction_id'] ?? '' ) );

		if ( '' !== $charge_id ) {
			/* translators: %s: Charge ID (gateway transaction id) */
			$not_found_message = __( 'No transaction found for Charge ID "%s". Import transactions first.', 'mission-donation-platform' );
			$cache_key         = "charge:{$charge_id}";

			if ( isset( $this->transaction_lookup_cache[ $cache_key ] ) ) {
				if ( 0 === $this->transaction_lookup_cache[ $cache_key ] ) {
					return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $charge_id ) );
				}
				return $this->transaction_lookup_cache[ $cache_key ];
			}

			$transaction                                  = Transaction::find_by_gateway_transaction_id( $charge_id );
			$this->transaction_lookup_cache[ $cache_key ] = $transaction ? $transaction->id : 0;

			if ( $transaction ) {
				return $transaction->id;
			}

			return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $charge_id ) );
		}

		$id = (int) trim( (string) ( $row['transaction_id'] ?? '' ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'transaction_missing', __( 'Row has no transaction_id or Charge ID.', 'mission-donation-platform' ) );
		}

		/* translators: %d: transaction ID */
		$not_found_message = __( 'No transaction with ID %d.', 'mission-donation-platform' );
		$cache_key         = "id:{$id}";

		if ( isset( $this->transaction_lookup_cache[ $cache_key ] ) ) {
			if ( 0 === $this->transaction_lookup_cache[ $cache_key ] ) {
				return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $id ) );
			}
			return $this->transaction_lookup_cache[ $cache_key ];
		}

		$transaction                                  = Transaction::find( $id );
		$this->transaction_lookup_cache[ $cache_key ] = $transaction ? $transaction->id : 0;

		if ( $transaction ) {
			return $transaction->id;
		}

		return new WP_Error( 'transaction_not_found', sprintf( $not_found_message, $id ) );
	}

	// ------------------------------------------------------------------
	// Internal: file IO
	// ------------------------------------------------------------------

	/**
	 * Read a slice of a CSV file starting at `$offset` data rows past the header.
	 *
	 * @param string $path       File path.
	 * @param int    $offset     Number of data rows to skip.
	 * @param int    $batch_size Max rows to return.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>, eof: bool}|WP_Error
	 */
	private function read_csv_slice( string $path, int $offset, int $batch_size ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return new WP_Error( 'parse_failed', __( 'Could not open the uploaded file.', 'mission-donation-platform' ) );
		}

		$headers = fgetcsv( $handle );

		if ( false === $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'parse_failed', __( 'The file appears to be empty.', 'mission-donation-platform' ) );
		}

		$headers = array_map( static fn( $h ) => is_string( $h ) ? trim( $h ) : '', $headers );

		// Skip past previously processed rows.
		for ( $i = 0; $i < $offset; $i++ ) {
			$skip = fgetcsv( $handle );
			if ( false === $skip ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return [
					'headers' => $headers,
					'rows'    => [],
					'eof'     => true,
				];
			}
		}

		$rows      = [];
		$row_count = 0;
		while ( $row_count < $batch_size ) {
			$row = fgetcsv( $handle );
			if ( false === $row ) {
				break;
			}
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue;
			}
			$rows[] = array_map( static fn( $v ) => is_string( $v ) ? $v : (string) $v, $row );
			++$row_count;
		}

		$eof = feof( $handle ) || $row_count < $batch_size;

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [
			'headers' => $headers,
			'rows'    => $rows,
			'eof'     => $eof,
		];
	}

	/**
	 * Read a slice of a JSON file. JSON is loaded whole (file size capped at 10 MB)
	 * and sliced in-memory — simpler than streaming a JSON array.
	 *
	 * @param string $path       File path.
	 * @param int    $offset     Rows to skip.
	 * @param int    $batch_size Max rows.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>, eof: bool}|WP_Error
	 */
	private function read_json_slice( string $path, int $offset, int $batch_size ): array|WP_Error {
		$parsed = $this->parse_json( $path );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$total = count( $parsed['rows'] );
		$slice = array_slice( $parsed['rows'], $offset, $batch_size );
		$eof   = ( $offset + count( $slice ) ) >= $total;

		return [
			'headers' => $parsed['headers'],
			'rows'    => array_values( $slice ),
			'eof'     => $eof,
		];
	}

	/**
	 * Count total data rows in the file (header excluded).
	 *
	 * @param string $path      File path.
	 * @param string $extension csv|json.
	 */
	private function count_rows( string $path, string $extension ): int|WP_Error {
		if ( 'csv' === $extension ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $path, 'r' );
			if ( false === $handle ) {
				return new WP_Error( 'parse_failed', __( 'Could not open the uploaded file.', 'mission-donation-platform' ) );
			}

			$first = fgetcsv( $handle );
			if ( false === $first ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 0;
			}

			$count = 0;
			while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,WordPress.CodeAnalysis.AssignmentInCondition
				if ( 1 === count( $row ) && null === $row[0] ) {
					continue;
				}
				++$count;
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $count;
		}

		if ( 'json' === $extension ) {
			$parsed = $this->parse_json( $path );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			return count( $parsed['rows'] );
		}

		return new WP_Error( 'invalid_extension', __( 'Only .csv and .json files are supported.', 'mission-donation-platform' ) );
	}

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
	 * Parse a CSV file fully into headers + rows. Used by validate_file and
	 * by the row counter for very small files.
	 *
	 * @param string $path Path.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>}|WP_Error
	 */
	private function parse_csv( string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return new WP_Error( 'parse_failed', __( 'Could not open the uploaded file.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$headers = fgetcsv( $handle );

		if ( false === $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'parse_failed', __( 'The file appears to be empty.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$headers = array_map( static fn( $h ) => is_string( $h ) ? trim( $h ) : '', $headers );

		$rows = [];

		while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,WordPress.CodeAnalysis.AssignmentInCondition
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue;
			}

			$rows[] = array_map( static fn( $v ) => is_string( $v ) ? $v : (string) $v, $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [
			'headers' => $headers,
			'rows'    => $rows,
		];
	}

	/**
	 * Parse a JSON file into headers + rows. Used for validation and small-batch reads.
	 *
	 * @param string $path Path.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>}|WP_Error
	 */
	private function parse_json( string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			return new WP_Error( 'parse_failed', __( 'Could not read the uploaded file.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error( 'parse_failed', __( 'The JSON file is empty or malformed.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$headers = array_keys( $data[0] );
		$rows    = [];

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$row = [];

			foreach ( $headers as $key ) {
				$value = $entry[ $key ] ?? '';
				$row[] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}

			$rows[] = $row;
		}

		return [
			'headers' => array_map( 'strval', $headers ),
			'rows'    => $rows,
		];
	}

	/**
	 * Translate raw rows into canonical-keyed arrays based on header mapping.
	 *
	 * @param array<int, string[]>      $rows         Raw rows.
	 * @param array<int, string|null>   $matched_keys Mapping of column index => canonical key.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function map_rows( array $rows, array $matched_keys ): array {
		$mapped = [];

		foreach ( $rows as $row ) {
			$entry = [];

			foreach ( $matched_keys as $i => $key ) {
				if ( null === $key ) {
					continue;
				}

				$entry[ $key ] = $row[ $i ] ?? '';
			}

			$mapped[] = $entry;
		}

		return $mapped;
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

				$transaction_id = $this->resolve_transaction_id( $row );

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
	 * Convert a mapped row into the data array expected by the Donor constructor.
	 *
	 * @param array<string, string> $row Mapped row keyed by canonical field.
	 * @return array<string, mixed>
	 */
	private function prepare_donor_row( array $row ): array {
		$known = [
			'email',
			'first_name',
			'last_name',
			'phone',
			'address_1',
			'address_2',
			'city',
			'state',
			'zip',
			'country',
			'total_donated',
			'total_tip',
			'transaction_count',
			'first_transaction',
			'last_transaction',
			'date_created',
			'date_modified',
		];

		$amount_fields = [ 'total_donated', 'total_tip' ];
		$date_fields   = [ 'first_transaction', 'last_transaction', 'date_created', 'date_modified' ];
		$int_fields    = [ 'transaction_count' ];

		$prepared = [];

		foreach ( $known as $field ) {
			if ( ! array_key_exists( $field, $row ) ) {
				continue;
			}

			$value = is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : $row[ $field ];

			if ( '' === $value ) {
				continue;
			}

			if ( in_array( $field, $amount_fields, true ) ) {
				$prepared[ $field ] = $this->parse_amount( (string) $value );
			} elseif ( in_array( $field, $int_fields, true ) ) {
				$prepared[ $field ] = (int) preg_replace( '/[^0-9\-]/', '', (string) $value );
			} elseif ( in_array( $field, $date_fields, true ) ) {
				$parsed = $this->parse_date( (string) $value );
				if ( null !== $parsed ) {
					$prepared[ $field ] = $parsed;
				}
			} else {
				$prepared[ $field ] = (string) $value;
			}
		}

		if ( isset( $prepared['email'] ) ) {
			$prepared['email'] = strtolower( $prepared['email'] );
		}

		return $prepared;
	}

	/**
	 * Pull `meta:*` columns out of a row.
	 *
	 * @param array<string, string> $row Mapped row.
	 * @return array<string, string>
	 */
	private function extract_meta_pairs( array $row ): array {
		$pairs = [];

		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'meta:' ) ) {
				continue;
			}

			$meta_key = substr( $key, 5 );

			if ( '' === $meta_key ) {
				continue;
			}

			$pairs[ $meta_key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $pairs;
	}

	/**
	 * Apply meta:* pairs to any model that uses HasMeta (Donor, Transaction).
	 *
	 * @param object              $model Model with HasMeta trait.
	 * @param array<string, string> $pairs Meta key => value pairs.
	 */
	private function apply_meta( object $model, array $pairs ): void {
		foreach ( $pairs as $key => $value ) {
			$model->update_meta( $key, $value );
		}
	}

	/**
	 * Convert a major-unit currency string into minor units.
	 *
	 * @param string $value Raw amount string.
	 */
	private function parse_amount( string $value ): int {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		if ( null === $cleaned || '' === $cleaned || ! is_numeric( $cleaned ) ) {
			return 0;
		}

		$major      = (float) $cleaned;
		$multiplier = 10 ** Currency::get_decimals( 'USD' );

		return (int) round( $major * $multiplier );
	}

	/**
	 * Convert a free-form date string into MySQL datetime.
	 *
	 * @param string $value Date input.
	 */
	private function parse_date( string $value ): ?string {
		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	// ------------------------------------------------------------------
	// Internal: storage
	// ------------------------------------------------------------------

	/**
	 * Copy the temp-uploaded file into a stable location keyed by job_id.
	 *
	 * @param string $source_path Original upload temp path.
	 * @param string $job_id      Public token.
	 * @param string $extension   csv|json.
	 */
	private function move_to_storage( string $source_path, string $job_id, string $extension ): string|WP_Error {
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

	/**
	 * Generate an unguessable job token.
	 */
	private function generate_job_id(): string {
		return 'mdp_job_' . wp_generate_password( 24, false );
	}

	/**
	 * Copy the validated upload file into a tempnam location keyed by a
	 * short-lived transient. Used by validate_file().
	 *
	 * @param string $tmp_path Source path.
	 * @param string $filename Original filename.
	 * @param string $type     Data type.
	 */
	private function store_file( string $tmp_path, string $filename, string $type ): string {
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
}
