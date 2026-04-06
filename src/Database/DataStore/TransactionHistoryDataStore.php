<?php
/**
 * Transaction history DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\TransactionHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the transaction_history table.
 */
class TransactionHistoryDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_transaction_history';
	}

	/**
	 * Create a transaction history entry.
	 *
	 * @param object $model TransactionHistory model.
	 *
	 * @return int New entry ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['created_at'] = $row['created_at'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after a transaction history entry is created.
		 *
		 * @param TransactionHistory $model The history entry.
		 */
		do_action( 'mission_transaction_history_created', $model );

		return $model->id;
	}

	/**
	 * Read a transaction history entry by ID.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return TransactionHistory|null
	 */
	public function read( int $id ): ?TransactionHistory {
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
	 * Update is not supported for transaction history (immutable records).
	 *
	 * @param object $model The model.
	 * @return bool Always false.
	 */
	public function update( object $model ): bool {
		return false;
	}

	/**
	 * Delete a transaction history entry by ID.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Delete all history entries for a transaction.
	 *
	 * @param int $id Transaction ID.
	 *
	 * @return bool
	 */
	public function delete_by_transaction( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'transaction_id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query transaction history entries.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return TransactionHistory[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count transaction history entries matching filters.
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

		if ( ! empty( $args['transaction_id'] ) ) {
			$where[]  = 'transaction_id = %d';
			$values[] = $args['transaction_id'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$values[] = $args['event_type'];
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'created_at' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
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
	 * Map a database row to a TransactionHistory model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return TransactionHistory
	 */
	private function row_to_model( array $row ): TransactionHistory {
		return new TransactionHistory( $row );
	}

	/**
	 * Map a TransactionHistory model to a database row array.
	 *
	 * @param TransactionHistory $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( TransactionHistory $model ): array {
		return [
			'id'             => $model->id,
			'transaction_id' => $model->transaction_id,
			'event_type'     => $model->event_type,
			'actor_type'     => $model->actor_type,
			'actor_id'       => $model->actor_id,
			'context'        => $model->context,
			'created_at'     => $model->created_at,
		];
	}
}
