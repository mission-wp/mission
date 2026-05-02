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
}
