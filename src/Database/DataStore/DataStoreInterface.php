<?php
/**
 * DataStore interface for CRUD operations.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for all DataStore implementations.
 */
interface DataStoreInterface {

	/**
	 * Create a new record and return its ID.
	 *
	 * @param object $model The model to persist.
	 *
	 * @return int The new record ID.
	 */
	public function create( object $model ): int;

	/**
	 * Read a single record by ID.
	 *
	 * @param int $id Record ID.
	 *
	 * @return object|null The model, or null if not found.
	 */
	public function read( int $id ): ?object;

	/**
	 * Update an existing record.
	 *
	 * @param object $model The model with updated values.
	 *
	 * @return bool True on success.
	 */
	public function update( object $model ): bool;

	/**
	 * Delete a record by ID.
	 *
	 * @param int $id Record ID.
	 *
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool;

	/**
	 * Query records with filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return object[] Array of models.
	 */
	public function query( array $args = array() ): array;

	/**
	 * Count records matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments (same as query()).
	 *
	 * @return int
	 */
	public function count( array $args = array() ): int;
}
