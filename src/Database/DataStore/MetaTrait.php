<?php
/**
 * Shared meta CRUD trait for DataStore classes.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Provides add/get/update/delete meta methods for a meta table.
 *
 * Classes using this trait must implement get_meta_table_name() and
 * get_meta_foreign_key().
 */
trait MetaTrait {

	/**
	 * Get the fully-prefixed meta table name.
	 *
	 * @return string
	 */
	abstract protected function get_meta_table_name(): string;

	/**
	 * Get the foreign key column name in the meta table (e.g. 'donation_id').
	 *
	 * @return string
	 */
	abstract protected function get_meta_foreign_key(): string;

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
		global $wpdb;

		$result = $wpdb->insert(
			$this->get_meta_table_name(),
			array(
				$this->get_meta_foreign_key() => $object_id,
				'meta_key'                    => $meta_key,
				'meta_value'                  => maybe_serialize( $meta_value ),
			),
			array( '%d', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
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
		global $wpdb;

		$table       = $this->get_meta_table_name();
		$foreign_key = $this->get_meta_foreign_key();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are internal.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE {$foreign_key} = %d AND meta_key = %s",
				$object_id,
				$meta_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $results ) ) {
			return $single ? '' : array();
		}

		$results = array_map( 'maybe_unserialize', $results );

		return $single ? $results[0] : $results;
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
		global $wpdb;

		$table       = $this->get_meta_table_name();
		$foreign_key = $this->get_meta_foreign_key();

		// Check if the meta key exists for this object.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$table} WHERE {$foreign_key} = %d AND meta_key = %s LIMIT 1",
				$object_id,
				$meta_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $exists ) {
			$result = $wpdb->update(
				$table,
				array( 'meta_value' => maybe_serialize( $meta_value ) ),
				array(
					$foreign_key => $object_id,
					'meta_key'   => $meta_key,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);

			return false !== $result;
		}

		return false !== $this->add_meta( $object_id, $meta_key, $meta_value );
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
		global $wpdb;

		$result = $wpdb->delete(
			$this->get_meta_table_name(),
			array(
				$this->get_meta_foreign_key() => $object_id,
				'meta_key'                    => $meta_key,
			),
			array( '%d', '%s' )
		);

		return false !== $result;
	}
}
