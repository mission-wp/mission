<?php
/**
 * HasMeta trait for models with meta tables.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Provides meta access methods for models whose DataStore uses MetaTrait.
 */
trait HasMeta {

	/**
	 * Get a meta value.
	 *
	 * @param string $key    Meta key.
	 * @param bool   $single Whether to return a single value. Default true.
	 * @return mixed
	 */
	public function get_meta( string $key, bool $single = true ): mixed {
		return static::store()->get_meta( $this->id, $key, $single );
	}

	/**
	 * Update (or add) a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return bool
	 */
	public function update_meta( string $key, mixed $value ): bool {
		// WordPress update_metadata() stores false as '', which is
		// indistinguishable from "not set". Normalize booleans to '1'/'0'.
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		return static::store()->update_meta( $this->id, $key, $value );
	}

	/**
	 * Add a meta value (allows duplicates).
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return int|false Meta ID on success, false on failure.
	 */
	public function add_meta( string $key, mixed $value ): int|false {
		return static::store()->add_meta( $this->id, $key, $value );
	}

	/**
	 * Delete a meta value.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function delete_meta( string $key ): bool {
		return static::store()->delete_meta( $this->id, $key );
	}

	/**
	 * Get all meta as a key => value map.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all_meta(): array {
		return static::store()->get_all_meta( $this->id );
	}

	/**
	 * Map meta values to object IDs in one query.
	 *
	 * @param string            $key    Meta key to match.
	 * @param array<int|string> $values Values to look up.
	 * @return array<string, int> Map of meta_value => object ID.
	 */
	public static function find_ids_by_meta( string $key, array $values ): array {
		return static::store()->find_object_ids_by_meta( $key, $values );
	}

	/**
	 * Get every object's value for one meta key in a single query.
	 *
	 * @param string $key Meta key to fetch.
	 * @return array<int, mixed> Map of object ID => unserialized value.
	 */
	public static function get_meta_for_all( string $key ): array {
		return static::store()->get_meta_for_all_objects( $key );
	}

	/**
	 * Get object IDs carrying a meta key/value pair, cursor-paginated.
	 *
	 * @param string $key      Meta key to match.
	 * @param string $value    Meta value to match.
	 * @param int    $limit    Max IDs to return.
	 * @param int    $after_id Only return object IDs greater than this.
	 * @return int[] Ascending object IDs.
	 */
	public static function find_ids_with_meta( string $key, string $value, int $limit = 100, int $after_id = 0 ): array {
		return static::store()->find_object_ids_with_meta( $key, $value, $limit, $after_id );
	}
}
