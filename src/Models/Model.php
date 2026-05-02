<?php
/**
 * Abstract base model.
 *
 * @package MissionDP
 */

namespace MissionDP\Models;

use MissionDP\Database\DataStore\DataStoreInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all Mission models.
 *
 * Provides static convenience methods (find, query, count) and instance
 * persistence methods (save, delete) that delegate to the underlying DataStore.
 */
abstract class Model {

	/**
	 * Record ID. All models share this column.
	 */
	public ?int $id;

	/**
	 * Cached DataStore instances keyed by model class.
	 *
	 * @var array<class-string, DataStoreInterface>
	 */
	private static array $stores = [];

	/**
	 * Create the DataStore for this model.
	 *
	 * @return DataStoreInterface
	 */
	abstract protected static function new_store(): DataStoreInterface;

	/**
	 * Get the DataStore for this model (cached per class).
	 *
	 * @return DataStoreInterface
	 */
	public static function store(): DataStoreInterface {
		$class                           = static::class;
		return self::$stores[ $class ] ??= static::new_store();
	}

	/**
	 * Override the DataStore for testing.
	 *
	 * @param DataStoreInterface $store Replacement store.
	 */
	public static function set_store( DataStoreInterface $store ): void {
		self::$stores[ static::class ] = $store;
	}

	/**
	 * Reset the DataStore to the default (re-created on next access).
	 */
	public static function reset_store(): void {
		unset( self::$stores[ static::class ] );
	}

	/**
	 * Find a record by ID.
	 *
	 * @param int $id Record ID.
	 * @return static|null
	 */
	public static function find( int $id ): ?static {
		return static::store()->read( $id );
	}

	/**
	 * Find multiple records by ID.
	 *
	 * @param int[] $ids Record IDs.
	 * @return static[] Indexed by ID.
	 */
	public static function find_many( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$ids     = array_unique( array_map( 'intval', $ids ) );
		$results = static::query(
			[
				'id__in'   => $ids,
				'per_page' => count( $ids ),
			]
		);
		$keyed   = [];

		foreach ( $results as $model ) {
			$keyed[ $model->id ] = $model;
		}

		return $keyed;
	}

	/**
	 * Query records with filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return static[]
	 */
	public static function query( array $args = [] ): array {
		return static::store()->query( $args );
	}

	/**
	 * Count records matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		return static::store()->count( $args );
	}

	/**
	 * Save this model (insert or update).
	 *
	 * @return int|bool New ID on insert, true on update, false on failure.
	 */
	public function save(): int|bool {
		if ( $this->id ) {
			return static::store()->update( $this );
		}

		$this->id = static::store()->create( $this );

		return $this->id;
	}

	/**
	 * Delete this model's record.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		return static::store()->delete( $this->id );
	}

	/**
	 * Reload this model from the database.
	 *
	 * @return static|null
	 */
	public function fresh(): ?static {
		return static::find( $this->id );
	}
}
