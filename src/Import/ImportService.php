<?php
/**
 * Import service. Parses uploaded files and produces a validation preview.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Export\ExportService;
use MissionDP\Import\Validators\RowValidator;
use MissionDP\Models\Donor;
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
