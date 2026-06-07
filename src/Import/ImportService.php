<?php
/**
 * Import service. Parses uploaded files and produces a validation preview.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Currency\Currency;
use MissionDP\Export\ExportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Donor;
use MissionDP\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central import coordinator (Phase 1: validation only).
 */
class ImportService {

	private const TYPES        = [ 'donors', 'transactions', 'campaigns', 'subscriptions' ];
	private const PREVIEW_ROWS = 5;
	private const MAX_BYTES    = 10 * 1024 * 1024;
	private const FILE_ID_TTL  = HOUR_IN_SECONDS;

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

		$duplicates = $this->count_duplicates( $type, $mapped_rows, $skipped_rows );

		$preview_rows = array_map(
			static fn( array $row ) => array_values( $row ),
			array_slice( $rows, 0, self::PREVIEW_ROWS )
		);

		$file_id = $this->store_file( $file['tmp_name'], $file['name'], $type );

		return [
			'file_id'             => $file_id,
			'filename'            => $file['name'],
			'filesize'            => (int) $file['size'],
			'rows_detected'       => count( $rows ),
			'rows_importable'     => $importable_rows,
			'rows_skipped'        => count( $skipped_row_numbers ),
			'columns'             => $headers,
			'columns_matched'     => count( array_unique( $matched ) ),
			'columns_unmatched'   => $resolved['unmatched'],
			'warnings'            => $warnings,
			'duplicates'          => $duplicates,
			'preview_headers'     => $headers,
			'preview_rows'        => $preview_rows,
			'warning_rows'        => array_values( array_unique( array_column( $warnings, 'row' ) ) ),
			'skipped_row_numbers' => $skipped_row_numbers,
		];
	}

	/**
	 * Execute a validated import. Re-parses the previously uploaded file from the
	 * transient-referenced temp path, applies the duplicate strategy, and writes
	 * records through the relevant model layer.
	 *
	 * @param string $file_id            Transient key returned by validate_file().
	 * @param string $duplicate_strategy 'skip' | 'update' | 'create'.
	 *
	 * @return array|WP_Error Summary array or error.
	 */
	public function execute_import( string $file_id, string $duplicate_strategy ): array|WP_Error {
		if ( ! in_array( $duplicate_strategy, [ 'skip', 'update', 'create' ], true ) ) {
			return new WP_Error( 'invalid_strategy', __( 'Invalid duplicate strategy.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$stored = get_transient( $file_id );

		if ( ! is_array( $stored ) || empty( $stored['path'] ) || empty( $stored['type'] ) ) {
			return new WP_Error( 'file_expired', __( 'The uploaded file has expired. Please upload it again.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		$path     = (string) $stored['path'];
		$type     = (string) $stored['type'];
		$filename = (string) ( $stored['filename'] ?? '' );

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'file_missing', __( 'The uploaded file could not be read.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		if ( 'donors' !== $type ) {
			return new WP_Error( 'unsupported_type', __( 'Only donor imports are supported right now.', 'mission-donation-platform' ), [ 'status' => 400 ] );
		}

		// The stored temp file has a `.tmp` extension (from wp_tempnam), so derive
		// the format from the original upload filename instead.
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$parsed = match ( $extension ) {
			'csv'   => $this->parse_csv( $path ),
			'json'  => $this->parse_json( $path ),
			default => new WP_Error( 'invalid_extension', __( 'Only .csv and .json files are supported.', 'mission-donation-platform' ), [ 'status' => 400 ] ),
		};

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$resolved    = $this->mapper->resolve_headers( $parsed['headers'], $type );
		$mapped_rows = $this->map_rows( $parsed['rows'], $resolved['matched'] );

		/**
		 * Fires before an import starts.
		 *
		 * @param string $type      Data type being imported.
		 * @param int    $row_count Number of rows about to be processed.
		 * @param string $strategy  Duplicate strategy.
		 */
		do_action( "missiondp_import_{$type}_before", $type, count( $mapped_rows ), $duplicate_strategy );

		$results = $this->import_donors( $mapped_rows, $duplicate_strategy );

		$this->cleanup_file( $file_id, $path );

		$this->log_activity( $type, $duplicate_strategy, $results );

		/**
		 * Fires after an import completes.
		 *
		 * @param string $type     Data type imported.
		 * @param array  $results  Result summary.
		 * @param string $strategy Duplicate strategy.
		 */
		do_action( "missiondp_import_{$type}_after", $type, $results, $duplicate_strategy );

		return $results;
	}

	/**
	 * Generate a blank CSV template for the given type.
	 *
	 * @param string $type Data type.
	 */
	public function build_template( string $type ): string {
		$columns = $this->export->get_columns( $type );
		$headers = array_column( $columns, 'label' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory temp stream.
		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $headers );
		rewind( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- In-memory stream.
		$content = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem can't read php://temp.

		return (string) $content;
	}

	/**
	 * Parse a CSV file into headers + rows.
	 *
	 * @param string $path Path to the uploaded temp file.
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
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem can't read php://temp.
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

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem can't read php://temp.

		return [
			'headers' => $headers,
			'rows'    => $rows,
		];
	}

	/**
	 * Parse a JSON file into headers + rows.
	 *
	 * @param string $path Path to the uploaded temp file.
	 * @return array{headers: string[], rows: array<int, string[]>}|WP_Error
	 */
	private function parse_json( string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file.
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
	 * @param array<int, string[]>      $rows           Raw rows.
	 * @param array<int, string|null>   $matched_keys   Mapping of column index => canonical key (null for unmatched).
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
	 * Count rows that look like duplicates of existing records.
	 *
	 * Phase 1 only implements donor email matching. Other types return 0. Rows
	 * already marked for skipping are excluded so the duplicate count reflects
	 * only the records the importer would actually attempt to write.
	 *
	 * @param string                              $type                Data type.
	 * @param array<int, array<string, string>>   $rows                Mapped rows.
	 * @param array<int, bool>                    $skipped_rows_lookup Map of (1-based row number) to true for rows being skipped.
	 */
	private function count_duplicates( string $type, array $rows, array $skipped_rows_lookup = [] ): int {
		if ( 'donors' !== $type ) {
			return 0;
		}

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

	/**
	 * Import a batch of donor rows. Each row is run through the validator a
	 * second time so any error rows are skipped, mirroring the preview UI.
	 *
	 * @param array<int, array<string, string>> $rows     Mapped rows (canonical keys).
	 * @param string                            $strategy 'skip' | 'update' | 'create'.
	 *
	 * @return array{imported: int, skipped: int, updated: int, errors: int, error_details: array<int, array{row: int, message: string}>}
	 */
	private function import_donors( array $rows, string $strategy ): array {
		$imported      = 0;
		$skipped       = 0;
		$updated       = 0;
		$errors        = 0;
		$error_details = [];
		$batch_size    = 200;

		foreach ( $rows as $i => $row ) {
			$row_number = $i + 1;

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
				if ( count( $error_details ) < 25 ) {
					$error_details[] = [
						'row'     => $row_number,
						'message' => $e->getMessage(),
					];
				}
			}

			if ( 0 === $row_number % $batch_size ) {
				wp_cache_flush();
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
	 * Convert a mapped row into the data array expected by the Donor constructor.
	 * Strips out unknown keys, normalizes amounts (major -> minor units), and
	 * parses dates into MySQL format.
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
	 * Apply collected meta pairs to a donor.
	 *
	 * @param Donor                 $donor Donor model.
	 * @param array<string, string> $pairs Meta key => value pairs.
	 */
	private function apply_meta( Donor $donor, array $pairs ): void {
		foreach ( $pairs as $key => $value ) {
			$donor->update_meta( $key, $value );
		}
	}

	/**
	 * Convert a major-unit currency string ("$1,820.00" / "1820" / "18.20") into
	 * minor units. Exports use major units with two decimals, so values without a
	 * decimal point are assumed to be major as well.
	 *
	 * @param string $value Raw amount string.
	 */
	private function parse_amount( string $value ): int {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		if ( null === $cleaned || '' === $cleaned || ! is_numeric( $cleaned ) ) {
			return 0;
		}

		$major = (float) $cleaned;
		// Default currency: USD has 2 decimals. Donor-level totals aren't tied to a
		// specific transaction's currency so site default is the safest assumption.
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

	/**
	 * Remove the temp file and its transient after an import completes.
	 *
	 * @param string $file_id Transient key.
	 * @param string $path    Filesystem path.
	 */
	private function cleanup_file( string $file_id, string $path ): void {
		delete_transient( $file_id );

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable during REST.
			@unlink( $path );
		}
	}

	/**
	 * Log a completed import to the activity feed.
	 *
	 * @param string                                                                              $type     Data type.
	 * @param string                                                                              $strategy Duplicate strategy.
	 * @param array{imported: int, skipped: int, updated: int, errors: int, error_details: array} $results  Result counts.
	 */
	private function log_activity( string $type, string $strategy, array $results ): void {
		if ( ! class_exists( Plugin::class ) ) {
			return;
		}

		$module = Plugin::instance()->get_activity_feed_module();

		if ( ! $module ) {
			return;
		}

		$module->log(
			'data_imported',
			$type,
			0,
			[
				'type'     => $type,
				'strategy' => $strategy,
				'imported' => $results['imported'],
				'skipped'  => $results['skipped'],
				'updated'  => $results['updated'],
				'errors'   => $results['errors'],
			],
			false,
			$results['errors'] > 0 ? 'warning' : 'info',
		);
	}

	/**
	 * Copy the uploaded temp file into a stable location and return a transient-keyed ID.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy,WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem unavailable during REST; failure handled via missing transient downstream.
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
