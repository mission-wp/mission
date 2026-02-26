<?php
/**
 * Transaction DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the transactions table.
 */
class TransactionDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_transactions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_transaction_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_foreign_key(): string {
		return 'transaction_id';
	}

	/**
	 * Create a transaction.
	 *
	 * @param object $model Transaction model.
	 *
	 * @return int New transaction ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->model_to_row( $model );

		$data['date_created']  = $data['date_created'] ?: $now;
		$data['date_modified'] = $now;
		unset( $data['id'] );

		$wpdb->insert( $this->get_table_name(), $data );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after a transaction is created.
		 *
		 * @param Transaction $model The transaction.
		 */
		do_action( 'mission_transaction_created', $model );

		return $model->id;
	}

	/**
	 * Read a transaction by ID.
	 *
	 * @param int $id Transaction ID.
	 *
	 * @return Transaction|null
	 */
	public function read( int $id ): ?Transaction {
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
	 * Update a transaction.
	 *
	 * @param object $model Transaction model with updated values.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$old = $this->read( $model->id );
		if ( ! $old ) {
			return false;
		}

		$data                  = $this->model_to_row( $model );
		$data['date_modified'] = current_time( 'mysql', true );
		unset( $data['id'] );

		$result = $wpdb->update(
			$this->get_table_name(),
			$data,
			array( 'id' => $model->id ),
			null,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		// Fire status transition hooks and update aggregates.
		if ( $old->status !== $model->status ) {
			$this->handle_status_transition( $model, $old->status, $model->status );
		}

		return true;
	}

	/**
	 * Delete a transaction by ID.
	 *
	 * @param int $id Transaction ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// Delete associated meta first.
		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE transaction_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Query transactions.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Transaction[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'row_to_model' ), $rows ?: array() );
	}

	/**
	 * Count transactions matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = array() ): int {
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
		$where  = array();
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['donor_id'] ) ) {
			$where[]  = 'donor_id = %d';
			$values[] = $args['donor_id'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$values[] = $args['campaign_id'];
		}

		if ( ! empty( $args['subscription_id'] ) ) {
			$where[]  = 'subscription_id = %d';
			$values[] = $args['subscription_id'];
		}

		if ( ! empty( $args['source_post_id'] ) ) {
			$where[]  = 'source_post_id = %d';
			$values[] = $args['source_post_id'];
		}

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'date_created >= %s';
			$values[] = $args['date_after'];
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'date_created <= %s';
			$values[] = $args['date_before'];
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = array( 'id', 'date_created', 'date_modified', 'total_amount', 'status' );
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
	 * Handle transaction status transitions.
	 *
	 * @param Transaction $transaction The transaction.
	 * @param string      $old_status  Previous status.
	 * @param string      $new_status  New status.
	 */
	private function handle_status_transition( Transaction $transaction, string $old_status, string $new_status ): void {
		/**
		 * Fires on any transaction status change.
		 *
		 * @param Transaction $transaction The transaction.
		 * @param string      $old_status  Previous status.
		 * @param string      $new_status  New status.
		 */
		do_action( 'mission_transaction_status_transition', $transaction, $old_status, $new_status );

		/**
		 * Fires on a specific transaction status transition.
		 *
		 * @param Transaction $transaction The transaction.
		 */
		do_action( "mission_transaction_status_{$old_status}_to_{$new_status}", $transaction );

		// Update donor and campaign aggregates.
		if ( 'completed' === $new_status ) {
			$this->increment_aggregates( $transaction );
		} elseif ( 'completed' === $old_status && in_array( $new_status, array( 'refunded', 'cancelled', 'failed' ), true ) ) {
			$this->decrement_aggregates( $transaction );
		}
	}

	/**
	 * Increment donor and campaign aggregates when a transaction is completed.
	 *
	 * @param Transaction $transaction The completed transaction.
	 */
	private function increment_aggregates( Transaction $transaction ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'mission_donors';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$donor_table}
					SET total_donated = total_donated + %d,
						total_tip = total_tip + %d,
						transaction_count = transaction_count + 1,
						first_transaction = COALESCE(NULLIF(first_transaction, '0000-00-00 00:00:00'), %s),
						last_transaction = %s,
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$transaction->tip_amount,
					$now,
					$now,
					$now,
					$transaction->donor_id
				)
			);
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'mission_campaigns';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET total_raised = total_raised + %d,
						transaction_count = transaction_count + 1,
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$now,
					$transaction->campaign_id
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Decrement donor and campaign aggregates when a transaction leaves completed status.
	 *
	 * @param Transaction $transaction The transaction.
	 */
	private function decrement_aggregates( Transaction $transaction ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'mission_donors';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$donor_table}
					SET total_donated = GREATEST(0, total_donated - %d),
						total_tip = GREATEST(0, total_tip - %d),
						transaction_count = GREATEST(0, transaction_count - 1),
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$transaction->tip_amount,
					$now,
					$transaction->donor_id
				)
			);
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'mission_campaigns';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET total_raised = GREATEST(0, total_raised - %d),
						transaction_count = GREATEST(0, transaction_count - 1),
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$now,
					$transaction->campaign_id
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Map a database row to a Transaction model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Transaction
	 */
	private function row_to_model( array $row ): Transaction {
		return new Transaction( $row );
	}

	/**
	 * Map a Transaction model to a database row array.
	 *
	 * @param Transaction $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Transaction $model ): array {
		return array(
			'id'                      => $model->id,
			'status'                  => $model->status,
			'type'                    => $model->type,
			'donor_id'                => $model->donor_id,
			'subscription_id'         => $model->subscription_id,
			'parent_id'               => $model->parent_id,
			'source_post_id'          => $model->source_post_id,
			'campaign_id'             => $model->campaign_id,
			'amount'                  => $model->amount,
			'fee_amount'              => $model->fee_amount,
			'tip_amount'              => $model->tip_amount,
			'total_amount'            => $model->total_amount,
			'currency'                => $model->currency,
			'payment_gateway'         => $model->payment_gateway,
			'gateway_transaction_id'  => $model->gateway_transaction_id,
			'gateway_subscription_id' => $model->gateway_subscription_id,
			'is_anonymous'            => (int) $model->is_anonymous,
			'donor_ip'                => $model->donor_ip,
			'date_created'            => $model->date_created,
			'date_completed'          => $model->date_completed,
			'date_refunded'           => $model->date_refunded,
			'date_modified'           => $model->date_modified,
		);
	}
}
