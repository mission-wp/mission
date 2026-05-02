<?php
/**
 * Unified note DataStore for transactions, donors, and subscriptions.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

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

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		[ $sql, $values ] = $this->build_query_sql( 'SELECT *', $args );

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains placeholders for $values built in build_query_sql.
			$sql = $wpdb->prepare( $sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; values prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

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

		unset( $args['per_page'], $args['page'], $args['orderby'], $args['order'] );
		[ $sql, $values ] = $this->build_query_sql( 'SELECT COUNT(*)', $args );

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains placeholders for $values built in build_query_sql.
			$sql = $wpdb->prepare( $sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; values prepared above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build a query SQL string from arguments.
	 *
	 * @param string               $select The SELECT clause.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return string
	 */
	/**
	 * Returns [ sql_template, values ] — caller passes through wpdb::prepare().
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_query_sql( string $select, array $args ): array {
		$table  = $this->get_table_name();
		$where  = [];
		$values = [];

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $args['object_type'];
		}

		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$values[] = $args['object_id'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$values[] = $args['type'];
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "{$select} FROM {$table} {$where_clause} ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$per_page = max( 1, (int) $args['per_page'] );
			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$values[] = $per_page;
			$values[] = ( $page - 1 ) * $per_page;
		}

		return [ $sql, $values ];
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
