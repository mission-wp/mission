<?php
/**
 * Shared meta CRUD trait for DataStore classes.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

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
	 * Get the WP meta type (e.g. 'mission_campaign').
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
}
