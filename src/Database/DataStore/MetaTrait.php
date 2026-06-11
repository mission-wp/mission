<?php
/**
 * Shared meta CRUD trait for DataStore classes.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

defined( 'ABSPATH' ) || exit;

/**
 * Provides add/get/update/delete meta methods using WordPress metadata API.
 *
 * Classes using this trait must implement get_meta_type() and
 * get_meta_table_name().
 */
trait MetaTrait {

	/**
	 * Get the fully-prefixed meta table name.
	 *
	 * @return string
	 */
	abstract protected function get_meta_table_name(): string;

	/**
	 * Get the WP meta type (e.g. 'missiondp_campaign').
	 *
	 * This is used with WordPress metadata API functions and must match
	 * the $wpdb property name without the 'meta' suffix.
	 *
	 * @return string
	 */
	abstract protected function get_meta_type(): string;

	/**
	 * Add a meta value.
	 *
	 * @param int    $object_id  The parent object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 *
	 * @return int|false The meta_id on success, false on failure.
	 */
	public function add_meta( int $object_id, string $meta_key, mixed $meta_value ): int|false {
		return add_metadata( $this->get_meta_type(), $object_id, $meta_key, $meta_value );
	}

	/**
	 * Get meta value(s).
	 *
	 * @param int    $object_id The parent object ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether to return a single value.
	 *
	 * @return mixed Single value, array of values, or empty string if not found.
	 */
	public function get_meta( int $object_id, string $meta_key, bool $single = true ): mixed {
		$value = get_metadata( $this->get_meta_type(), $object_id, $meta_key, $single );

		// WP returns '' for single when key doesn't exist, [] for multi — matches our previous behavior.
		return $value;
	}

	/**
	 * Update a meta value. Adds if it doesn't exist.
	 *
	 * @param int    $object_id  The parent object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 *
	 * @return bool True on success.
	 */
	public function update_meta( int $object_id, string $meta_key, mixed $meta_value ): bool {
		return (bool) update_metadata( $this->get_meta_type(), $object_id, $meta_key, $meta_value );
	}

	/**
	 * Get all meta for an object.
	 *
	 * Returns a key => value map. For duplicate keys, keeps the first value
	 * (matches WordPress single=true behavior).
	 *
	 * @param int $object_id The parent object ID.
	 *
	 * @return array<string, mixed> Associative array of meta_key => unserialized value.
	 */
	public function get_all_meta( int $object_id ): array {
		$raw = get_metadata( $this->get_meta_type(), $object_id );

		if ( ! $raw || ! is_array( $raw ) ) {
			return [];
		}

		$meta = [];
		foreach ( $raw as $key => $values ) {
			$meta[ $key ] = maybe_unserialize( $values[0] ?? '' );
		}

		return $meta;
	}

	/**
	 * Delete meta value(s).
	 *
	 * @param int    $object_id The parent object ID.
	 * @param string $meta_key  Meta key.
	 *
	 * @return bool True on success.
	 */
	public function delete_meta( int $object_id, string $meta_key ): bool {
		return delete_metadata( $this->get_meta_type(), $object_id, $meta_key );
	}

	/**
	 * Map meta values to object IDs in one query.
	 *
	 * Used by batch consumers (e.g. migration writers) to resolve many source
	 * IDs at once instead of one get_meta() per record. When multiple objects
	 * share a value, the lowest object ID wins.
	 *
	 * @param string                    $meta_key    Meta key to match.
	 * @param array<int|string>         $meta_values Values to look up.
	 *
	 * @return array<string, int> Map of meta_value => object ID.
	 */
	public function find_object_ids_by_meta( string $meta_key, array $meta_values ): array {
		global $wpdb;

		$meta_values = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $meta_values ),
					static fn( string $value ): bool => '' !== $value
				)
			)
		);

		if ( empty( $meta_values ) ) {
			return [];
		}

		$id_column    = $this->get_meta_type() . '_id';
		$placeholders = implode( ', ', array_fill( 0, count( $meta_values ), '%s' ) );

		$sql = "SELECT meta_value, %i AS object_id FROM %i WHERE meta_key = %s AND meta_value IN ( {$placeholders} ) ORDER BY %i ASC";

		$prepare_args = array_merge(
			[ $id_column, $this->get_meta_table_name(), $meta_key ],
			$meta_values,
			[ $id_column ]
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers use %i, values use counted %s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (string) $row['meta_value'] ] ??= (int) $row['object_id'];
		}

		return $map;
	}

	/**
	 * Get object IDs carrying a specific meta key/value pair, cursor-paginated.
	 *
	 * Used to walk large sets (e.g. migration rollback) in batches.
	 *
	 * @param string $meta_key   Meta key to match.
	 * @param string $meta_value Meta value to match.
	 * @param int    $limit      Max IDs to return.
	 * @param int    $after_id   Only return object IDs greater than this.
	 *
	 * @return int[] Ascending object IDs.
	 */
	public function find_object_ids_with_meta( string $meta_key, string $meta_value, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;

		$id_column = $this->get_meta_type() . '_id';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT %i FROM %i WHERE meta_key = %s AND meta_value = %s AND %i > %d ORDER BY %i ASC LIMIT %d',
				$id_column,
				$this->get_meta_table_name(),
				$meta_key,
				$meta_value,
				$id_column,
				$after_id,
				$id_column,
				$limit
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}
}
