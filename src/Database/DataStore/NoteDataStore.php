<?php
/**
 * Unified note DataStore for transactions, donors, and subscriptions.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\Note;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the notes table.
 */
class NoteDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_notes';
	}

	/**
	 * Create a note.
	 *
	 * @param Note $model Note model.
	 * @return int New note ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['date_created'] = $row['date_created'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after a note is created.
		 *
		 * @param Note $model The note.
		 */
		do_action( 'missiondp_note_created', $model );

		return $model->id;
	}

	/**
	 * Read a note by ID.
	 *
	 * @param int $id Note ID.
	 * @return Note|null
	 */
	public function read( int $id ): ?Note {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update is not supported for notes (they are immutable).
	 *
	 * @param object $model The model.
	 * @return bool Always false.
	 */
	public function update( object $model ): bool {
		return false;
	}

	/**
	 * Delete a note by ID.
	 *
	 * @param int $id Note ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$note = $this->read( $id );

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		if ( false !== $result && $note ) {
			/**
			 * Fires after a note is deleted.
			 *
			 * @param Note $note The deleted note.
			 */
			do_action( 'missiondp_note_deleted', $note );
		}

		return false !== $result;
	}

	/**
	 * Delete all notes for a given object (cascade cleanup).
	 *
	 * @param string $object_type Object type (e.g. 'transaction', 'donor', 'subscription').
	 * @param int    $object_id   Object ID.
	 * @return bool
	 */
	public function delete_by_object( string $object_type, int $object_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$this->get_table_name(),
			[
				'object_type' => $object_type,
				'object_id'   => $object_id,
			],
			[ '%s', '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Query notes.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return Note[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$object_type     = ! empty( $args['object_type'] ) ? (string) $args['object_type'] : '';
		$has_object_type = '' !== $object_type ? 1 : 0;

		$object_id     = ! empty( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		$has_object_id = $object_id > 0 ? 1 : 0;

		$type     = ! empty( $args['type'] ) ? (string) $args['type'] : '';
		$has_type = '' !== $type ? 1 : 0;

		$allowed_orderby = [ 'id', 'date_created' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_asc       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' );

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR object_type = %s )
					   AND ( %d = 0 OR object_id = %d )
					   AND ( %d = 0 OR type = %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_object_type,
					$object_type,
					$has_object_id,
					$object_id,
					$has_type,
					$type,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR object_type = %s )
					   AND ( %d = 0 OR object_id = %d )
					   AND ( %d = 0 OR type = %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_object_type,
					$object_type,
					$has_object_id,
					$object_id,
					$has_type,
					$type,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count notes matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$object_type     = ! empty( $args['object_type'] ) ? (string) $args['object_type'] : '';
		$has_object_type = '' !== $object_type ? 1 : 0;

		$object_id     = ! empty( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		$has_object_id = $object_id > 0 ? 1 : 0;

		$type     = ! empty( $args['type'] ) ? (string) $args['type'] : '';
		$has_type = '' !== $type ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR object_type = %s )
				   AND ( %d = 0 OR object_id = %d )
				   AND ( %d = 0 OR type = %s )',
				$this->get_table_name(),
				$has_object_type,
				$object_type,
				$has_object_id,
				$object_id,
				$has_type,
				$type
			)
		);
	}

	/**
	 * Map a database row to a Note model.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return Note
	 */
	private function row_to_model( array $row ): Note {
		return new Note( $row );
	}

	/**
	 * Map a Note model to a database row array.
	 *
	 * @param Note $model The model.
	 * @return array<string, mixed>
	 */
	private function model_to_row( Note $model ): array {
		return [
			'id'           => $model->id,
			'object_type'  => $model->object_type,
			'object_id'    => $model->object_id,
			'type'         => $model->type,
			'content'      => $model->content,
			'author_id'    => $model->author_id,
			'date_created' => $model->date_created,
		];
	}
}
