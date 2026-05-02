<?php
/**
 * Activity log DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the activity_log table.
 */
class ActivityLogDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_activity_log';
	}

	/**
	 * Create an activity log entry.
	 *
	 * @param object $model ActivityLog model.
	 *
	 * @return int New entry ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['date_created'] = $row['date_created'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after an activity log entry is created.
		 *
		 * @param ActivityLog $model The activity log entry.
		 */
		do_action( 'missiondp_activity_log_created', $model );

		return $model->id;
	}

	/**
	 * Read an activity log entry by ID.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return ActivityLog|null
	 */
	public function read( int $id ): ?ActivityLog {
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
	 * Update an activity log entry.
	 *
	 * @param object $model ActivityLog model.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$row = $this->model_to_row( $model );
		unset( $row['id'] );

		$result = $wpdb->update(
			$this->get_table_name(),
			$row,
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete an activity log entry by ID.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$result = $this->get_wpdb()->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query activity log entries.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return ActivityLog[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count activity log entries matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		unset( $args['per_page'], $args['page'], $args['orderby'], $args['order'] );
		$sql = $this->build_query_sql( 'SELECT COUNT(*)', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Delete entries older than the given number of days.
	 *
	 * @param int $days Number of days to retain.
	 *
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date_created < DATE_SUB(%s, INTERVAL %d DAY)", current_time( 'mysql', true ), $days ) );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Delete all activity log entries, optionally filtered by test mode.
	 *
	 * @param array<string, mixed> $args Optional filters (supports 'is_test').
	 *
	 * @return int Number of rows deleted.
	 */
	public function delete_all( array $args = [] ): int {
		global $wpdb;

		$table = $this->get_table_name();

		if ( isset( $args['is_test'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE is_test = %d", (int) $args['is_test'] ) );
		} else {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Build a query SQL string from arguments.
	 *
	 * @param string               $select The SELECT clause.
	 * @param array<string, mixed> $args   Query arguments.
	 *
	 * @return string
	 */
	private function build_query_sql( string $select, array $args ): string {
		global $wpdb;

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

		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$values[] = $args['event'];
		}

		if ( isset( $args['is_test'] ) ) {
			$where[]  = 'is_test = %d';
			$values[] = (int) $args['is_test'];
		}

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'date_created >= %s';
			$values[] = $args['date_after'];
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'date_created <= %s';
			$values[] = $args['date_before'];
		}

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = $args['level'];
		}

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$values[] = $args['category'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(event LIKE %s OR data LIKE %s)';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created', 'event', 'object_type', 'level', 'category' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "{$select} FROM {$table} {$where_clause} ORDER BY {$orderby} {$order}";

		if ( isset( $args['per_page'] ) ) {
			$per_page = max( 1, (int) $args['per_page'] );
			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$offset   = ( $page - 1 ) * $per_page;

			$sql .= " LIMIT {$per_page} OFFSET {$offset}";
		}

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $values );
		}

		return $sql;
	}

	/**
	 * Map a database row to an ActivityLog model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return ActivityLog
	 */
	private function row_to_model( array $row ): ActivityLog {
		return new ActivityLog( $row );
	}

	/**
	 * Map an ActivityLog model to a database row array.
	 *
	 * @param ActivityLog $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( ActivityLog $model ): array {
		return [
			'id'           => $model->id,
			'object_type'  => $model->object_type,
			'object_id'    => $model->object_id,
			'event'        => $model->event,
			'actor_id'     => $model->actor_id,
			'data'         => $model->data,
			'is_test'      => (int) $model->is_test,
			'level'        => $model->level,
			'category'     => $model->category,
			'date_created' => $model->date_created,
		];
	}

	/**
	 * Get the wpdb instance.
	 *
	 * @return \wpdb
	 */
	private function get_wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
