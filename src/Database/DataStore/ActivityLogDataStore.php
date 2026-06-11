<?php
/**
 * Activity log DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

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
		do_action( 'mission_activity_log_created', $model );

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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

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

		$object_type     = ! empty( $args['object_type'] ) ? (string) $args['object_type'] : '';
		$has_object_type = '' !== $object_type ? 1 : 0;

		$object_id     = ! empty( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		$has_object_id = $object_id > 0 ? 1 : 0;

		$event     = ! empty( $args['event'] ) ? (string) $args['event'] : '';
		$has_event = '' !== $event ? 1 : 0;

		$has_is_test = isset( $args['is_test'] ) ? 1 : 0;
		$is_test     = $has_is_test ? (int) $args['is_test'] : 0;

		$date_after     = ! empty( $args['date_after'] ) ? (string) $args['date_after'] : '';
		$has_date_after = '' !== $date_after ? 1 : 0;

		$level     = ! empty( $args['level'] ) ? (string) $args['level'] : '';
		$has_level = '' !== $level ? 1 : 0;

		$category     = ! empty( $args['category'] ) ? (string) $args['category'] : '';
		$has_category = '' !== $category ? 1 : 0;

		$search_like = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';
		$has_search  = '' !== $search_like ? 1 : 0;

		$allowed_orderby = [ 'id', 'date_created', 'event', 'object_type', 'level', 'category' ];
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
					   AND ( %d = 0 OR event = %s )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR date_created >= %s )
					   AND ( %d = 0 OR level = %s )
					   AND ( %d = 0 OR category = %s )
					   AND ( %d = 0 OR event LIKE %s OR data LIKE %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_object_type,
					$object_type,
					$has_object_id,
					$object_id,
					$has_event,
					$event,
					$has_is_test,
					$is_test,
					$has_date_after,
					$date_after,
					$has_level,
					$level,
					$has_category,
					$category,
					$has_search,
					$search_like,
					$search_like,
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
					   AND ( %d = 0 OR event = %s )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR date_created >= %s )
					   AND ( %d = 0 OR level = %s )
					   AND ( %d = 0 OR category = %s )
					   AND ( %d = 0 OR event LIKE %s OR data LIKE %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_object_type,
					$object_type,
					$has_object_id,
					$object_id,
					$has_event,
					$event,
					$has_is_test,
					$is_test,
					$has_date_after,
					$date_after,
					$has_level,
					$level,
					$has_category,
					$category,
					$has_search,
					$search_like,
					$search_like,
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
	 * Count activity log entries matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$object_type     = ! empty( $args['object_type'] ) ? (string) $args['object_type'] : '';
		$has_object_type = '' !== $object_type ? 1 : 0;

		$object_id     = ! empty( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		$has_object_id = $object_id > 0 ? 1 : 0;

		$event     = ! empty( $args['event'] ) ? (string) $args['event'] : '';
		$has_event = '' !== $event ? 1 : 0;

		$has_is_test = isset( $args['is_test'] ) ? 1 : 0;
		$is_test     = $has_is_test ? (int) $args['is_test'] : 0;

		$date_after     = ! empty( $args['date_after'] ) ? (string) $args['date_after'] : '';
		$has_date_after = '' !== $date_after ? 1 : 0;

		$level     = ! empty( $args['level'] ) ? (string) $args['level'] : '';
		$has_level = '' !== $level ? 1 : 0;

		$category     = ! empty( $args['category'] ) ? (string) $args['category'] : '';
		$has_category = '' !== $category ? 1 : 0;

		$search_like = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';
		$has_search  = '' !== $search_like ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR object_type = %s )
				   AND ( %d = 0 OR object_id = %d )
				   AND ( %d = 0 OR event = %s )
				   AND ( %d = 0 OR is_test = %d )
				   AND ( %d = 0 OR date_created >= %s )
				   AND ( %d = 0 OR level = %s )
				   AND ( %d = 0 OR category = %s )
				   AND ( %d = 0 OR event LIKE %s OR data LIKE %s )',
				$this->get_table_name(),
				$has_object_type,
				$object_type,
				$has_object_id,
				$object_id,
				$has_event,
				$event,
				$has_is_test,
				$is_test,
				$has_date_after,
				$date_after,
				$has_level,
				$level,
				$has_category,
				$category,
				$has_search,
				$search_like,
				$search_like
			)
		);
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

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE date_created < %s',
				$this->get_table_name(),
				$cutoff
			)
		);

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

		if ( isset( $args['is_test'] ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE is_test = %d',
					$this->get_table_name(),
					(int) $args['is_test']
				)
			);
		} else {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $this->get_table_name() ) );
		}

		return (int) $wpdb->rows_affected;
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
