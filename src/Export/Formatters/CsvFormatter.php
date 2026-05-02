<?php
/**
 * CSV export formatter.
 *
 * @package MissionDP
 */

namespace MissionDP\Export\Formatters;

use MissionDP\Currency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Formats export data as CSV.
 */
class CsvFormatter implements FormatterInterface {

	/**
	 * {@inheritDoc}
	 */
	public function format( array $columns, array $rows, string $type ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory temp stream.
		$handle = fopen( 'php://temp', 'r+' );

		// Header row.
		fputcsv( $handle, array_column( $columns, 'label' ) );

		// Data rows.
		foreach ( $rows as $row ) {
			$csv_row = [];

			foreach ( $columns as $col ) {
				$value     = $row[ $col['key'] ] ?? '';
				$csv_row[] = $this->format_value( $value, $col['type'], $row['_currency'] ?? 'USD' );
			}

			fputcsv( $handle, $csv_row );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory temp stream.
		fclose( $handle );

		/**
		 * Filter the final CSV output for an export type.
		 *
		 * @param string $csv     The CSV string.
		 * @param array  $columns Column definitions.
		 * @param array  $rows    Row data.
		 */
		return apply_filters( "missiondp_export_{$type}_csv", $csv, $columns, $rows );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_type(): string {
		return 'text/csv; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_extension(): string {
		return 'csv';
	}

	/**
	 * Format a single value for CSV output.
	 *
	 * @param mixed  $value    The raw value.
	 * @param string $col_type Column type (string, int, bool, amount, date).
	 * @param string $currency Currency code for amount formatting.
	 *
	 * @return string
	 */
	private function format_value( mixed $value, string $col_type, string $currency ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}

		return match ( $col_type ) {
			'amount' => number_format( Currency::minor_to_major( (int) $value, strtoupper( $currency ) ), 2, '.', '' ),
			'bool'   => $value ? '1' : '0',
			default  => (string) $value,
		};
	}
}
