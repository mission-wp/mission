<?php
/**
 * CSV/JSON parsing for import files.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads import files: full parses for validation previews and offset-based
 * slices for batched background processing.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class ImportFileReader {

	/**
	 * Parse a CSV file fully into headers + rows. Used by validate_file and
	 * by the row counter for very small files.
	 *
	 * @param string $path Path.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>}|WP_Error
	 */
	public function parse_csv( string $path ): array|WP_Error {
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
	public function parse_json( string $path ): array|WP_Error {
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
	 * Read a slice of a CSV file starting at `$offset` data rows past the header.
	 *
	 * @param string $path       File path.
	 * @param int    $offset     Number of data rows to skip.
	 * @param int    $batch_size Max rows to return.
	 *
	 * @return array{headers: string[], rows: array<int, string[]>, eof: bool}|WP_Error
	 */
	public function read_csv_slice( string $path, int $offset, int $batch_size ): array|WP_Error {
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
	public function read_json_slice( string $path, int $offset, int $batch_size ): array|WP_Error {
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
	public function count_rows( string $path, string $extension ): int|WP_Error {
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
	 * Translate raw rows into canonical-keyed arrays based on header mapping.
	 *
	 * @param array<int, string[]>      $rows         Raw rows.
	 * @param array<int, string|null>   $matched_keys Mapping of column index => canonical key.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function map_rows( array $rows, array $matched_keys ): array {
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
}
