<?php
/**
 * Transaction history DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\TransactionHistory;

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
		return $wpdb->prefix . 'missiondp_transaction_history';
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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

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

		$transaction_id     = (int) ( $args['transaction_id'] ?? 0 );
		$has_transaction_id = $transaction_id > 0 ? 1 : 0;

		$event_type     = (string) ( $args['event_type'] ?? '' );
		$has_event_type = '' !== $event_type ? 1 : 0;

		$order_asc = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) );

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR transaction_id = %d )
					   AND ( %d = 0 OR event_type = %s )
					 ORDER BY created_at ASC, id ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_transaction_id,
					$transaction_id,
					$has_event_type,
					$event_type,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR transaction_id = %d )
					   AND ( %d = 0 OR event_type = %s )
					 ORDER BY created_at DESC, id DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_transaction_id,
					$transaction_id,
					$has_event_type,
					$event_type,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

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

		$transaction_id     = (int) ( $args['transaction_id'] ?? 0 );
		$has_transaction_id = $transaction_id > 0 ? 1 : 0;

		$event_type     = (string) ( $args['event_type'] ?? '' );
		$has_event_type = '' !== $event_type ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR transaction_id = %d )
				   AND ( %d = 0 OR event_type = %s )',
				$this->get_table_name(),
				$has_transaction_id,
				$transaction_id,
				$has_event_type,
				$event_type
			)
		);
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
