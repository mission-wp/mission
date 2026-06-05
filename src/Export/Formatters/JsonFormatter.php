<?php
/**
 * JSON export formatter.
 *
 * @package MissionDP
 */

namespace MissionDP\Export\Formatters;

defined( 'ABSPATH' ) || exit;

/**
 * Formats export data as JSON.
 */
class JsonFormatter implements FormatterInterface {

	/**
	 * {@inheritDoc}
	 */
	public function format( array $columns, array $rows, string $type ): string {
		$keys = array_column( $columns, 'key' );
		$data = [];

		foreach ( $rows as $row ) {
			$item = [];

			foreach ( $columns as $col ) {
				$value               = $row[ $col['key'] ] ?? null;
				$item[ $col['key'] ] = $this->cast_value( $value, $col['type'] );
			}

			$data[] = $item;
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		/**
		 * Filter the final JSON output for an export type.
		 *
		 * @param string $json    The JSON string.
		 * @param array  $columns Column definitions.
		 * @param array  $rows    Row data.
		 */
		return apply_filters( "missiondp_export_{$type}_json", $json, $columns, $rows );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_type(): string {
		return 'application/json; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_extension(): string {
		return 'json';
	}

	/**
	 * Cast a value to the appropriate JSON type.
	 *
	 * @param mixed  $value    The raw value.
	 * @param string $col_type Column type.
	 *
	 * @return mixed
	 */
	private function cast_value( mixed $value, string $col_type ): mixed {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		return match ( $col_type ) {
			'int', 'amount' => (int) $value,
			'bool'          => (bool) $value,
			default         => (string) $value,
		};
	}
}
