<?php
/**
 * Transaction DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\Transaction;

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
		return $wpdb->prefix . 'missiondp_transactions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_transactionmeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_transaction';
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
		do_action( 'missiondp_transaction_created', $model );

		// Update donor/campaign aggregates if created with a completed status.
		if ( 'completed' === $model->status ) {
			$this->increment_aggregates( $model );
		}

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
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
		);

		if ( false === $result ) {
			return false;
		}

		// Handle refund aggregate adjustment before status transition to avoid double-decrement.
		if ( $model->amount_refunded > $old->amount_refunded ) {
			$refund_delta = $model->amount_refunded - $old->amount_refunded;
			$this->adjust_aggregates_for_refund( $model, $refund_delta );
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

		// Decrement aggregates before deleting if the transaction was completed.
		$transaction = $this->read( $id );
		if ( $transaction && 'completed' === $transaction->status ) {
			$this->decrement_aggregates( $transaction );
		}

		// Delete associated notes, history, and tribute first.
		( new NoteDataStore() )->delete_by_object( 'transaction', $id );
		( new TransactionHistoryDataStore() )->delete_by_transaction( $id );
		( new TributeDataStore() )->delete_by_transaction( $id );

		// Delete associated meta.
		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE missiondp_transaction_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query transactions.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Transaction[]
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
	 * Count transactions matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
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
	 * Build a query SQL template and the values that fill its placeholders.
	 *
	 * The returned SQL contains `%d` / `%s` placeholders for any user-supplied
	 * value; the caller is expected to pass it through `$wpdb->prepare()`
	 * before executing.
	 *
	 * @param string               $select The SELECT clause.
	 * @param array<string, mixed> $args   Query arguments.
	 *
	 * @return array{0: string, 1: array<int, mixed>} [ sql_template, values ]
	 */
	private function build_query_sql( string $select, array $args ): array {
		$table  = $this->get_table_name();
		$where  = [];
		$values = [];

		if ( ! empty( $args['id'] ) ) {
			$where[]  = 'id = %d';
			$values[] = $args['id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$values[] = $args['type'];
		}

		if ( ! empty( $args['type__not'] ) ) {
			$where[]  = 'type != %s';
			$values[] = $args['type__not'];
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

		if ( ! empty( $args['gateway_transaction_id'] ) ) {
			$where[]  = 'gateway_transaction_id = %s';
			$values[] = $args['gateway_transaction_id'];
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

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created', 'date_completed', 'date_modified', 'total_amount', 'status' ];
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
		do_action( 'missiondp_transaction_status_transition', $transaction, $old_status, $new_status );

		/**
		 * Fires on a specific transaction status transition.
		 *
		 * @param Transaction $transaction The transaction.
		 */
		do_action( "missiondp_transaction_status_{$old_status}_to_{$new_status}", $transaction );

		// Update donor and campaign aggregates.
		if ( 'completed' === $new_status ) {
			$this->increment_aggregates( $transaction );
		} elseif ( 'completed' === $old_status && in_array( $new_status, [ 'refunded', 'cancelled', 'failed' ], true ) ) {
			if ( 'refunded' === $new_status && $transaction->amount_refunded > 0 ) {
				// Dollar amounts already adjusted by adjust_aggregates_for_refund().
				// Only decrement counts.
				$this->decrement_counts( $transaction );
			} else {
				$this->decrement_aggregates( $transaction );
			}
		}
	}

	/**
	 * Increment donor and campaign aggregates when a transaction is completed.
	 *
	 * @param Transaction $transaction The completed transaction.
	 */
	private function increment_aggregates( Transaction $transaction ): void {
		global $wpdb;

		$now          = current_time( 'mysql', true );
		$completed_at = $transaction->date_completed ?? $now;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$donor_table}
						SET test_total_donated = test_total_donated + %d,
							test_total_tip = test_total_tip + %d,
							test_transaction_count = test_transaction_count + 1,
							test_first_transaction = COALESCE(NULLIF(test_first_transaction, '0000-00-00 00:00:00'), %s),
							test_last_transaction = %s,
							date_modified = %s
						WHERE id = %d",
						$transaction->amount,
						$transaction->tip_amount,
						$completed_at,
						$completed_at,
						$now,
						$transaction->donor_id
					)
				);
			} else {
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
						$completed_at,
						$completed_at,
						$now,
						$transaction->donor_id
					)
				);
			}
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'missiondp_campaigns';
			$raised_col     = $transaction->is_test ? 'test_total_raised' : 'total_raised';
			$count_col      = $transaction->is_test ? 'test_transaction_count' : 'transaction_count';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET {$raised_col} = {$raised_col} + %d,
						{$count_col} = {$count_col} + 1,
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$now,
					$transaction->campaign_id
				)
			);

			// Increment donor_count if this is the donor's first completed transaction for this campaign.
			if ( $transaction->donor_id ) {
				$txn_table       = $this->get_table_name();
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$has_previous = (bool) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$txn_table}
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d
						LIMIT 1",
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( ! $has_previous ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$campaign_table}
							SET {$donor_count_col} = {$donor_count_col} + 1, date_modified = %s
							WHERE id = %d",
							$now,
							$transaction->campaign_id
						)
					);
				}
			}

			/**
			 * @param int  $campaign_id The campaign ID.
			 * @param bool $is_test     Whether the triggering transaction is a test.
			 */
			do_action( 'missiondp_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
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
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$donor_table}
						SET test_total_donated = GREATEST(0, test_total_donated - %d),
							test_total_tip = GREATEST(0, test_total_tip - %d),
							test_transaction_count = GREATEST(0, CAST(test_transaction_count AS SIGNED) - 1),
							date_modified = %s
						WHERE id = %d",
						$transaction->amount,
						$transaction->tip_amount,
						$now,
						$transaction->donor_id
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$donor_table}
						SET total_donated = GREATEST(0, total_donated - %d),
							total_tip = GREATEST(0, total_tip - %d),
							transaction_count = GREATEST(0, CAST(transaction_count AS SIGNED) - 1),
							date_modified = %s
						WHERE id = %d",
						$transaction->amount,
						$transaction->tip_amount,
						$now,
						$transaction->donor_id
					)
				);
			}
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'missiondp_campaigns';
			$raised_col     = $transaction->is_test ? 'test_total_raised' : 'total_raised';
			$count_col      = $transaction->is_test ? 'test_transaction_count' : 'transaction_count';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET {$raised_col} = GREATEST(0, {$raised_col} - %d),
						{$count_col} = GREATEST(0, CAST({$count_col} AS SIGNED) - 1),
						date_modified = %s
					WHERE id = %d",
					$transaction->amount,
					$now,
					$transaction->campaign_id
				)
			);

			// Decrement donor_count if the donor has no remaining completed transactions for this campaign.
			if ( $transaction->donor_id ) {
				$txn_table       = $this->get_table_name();
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$remaining = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$txn_table}
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d",
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( 0 === $remaining ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$campaign_table}
							SET {$donor_count_col} = GREATEST(0, CAST({$donor_count_col} AS SIGNED) - 1),
								date_modified = %s
							WHERE id = %d",
							$now,
							$transaction->campaign_id
						)
					);
				}
			}

			/**
			 * @param int  $campaign_id The campaign ID.
			 * @param bool $is_test     Whether the triggering transaction is a test.
			 */
			do_action( 'missiondp_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Adjust donor and campaign dollar aggregates for a refund.
	 *
	 * Handles both partial and full refunds. Refund amounts are attributed
	 * to the donation first; any excess reduces the tip.
	 *
	 * @param Transaction $transaction  The transaction (with updated amount_refunded).
	 * @param int         $refund_delta Amount newly refunded in this event (minor units).
	 */
	private function adjust_aggregates_for_refund( Transaction $transaction, int $refund_delta ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Attribute the refund to the donation first, then any excess to the tip.
		$previous_refunded          = $transaction->amount_refunded - $refund_delta;
		$previous_donation_refunded = min( $previous_refunded, $transaction->amount );
		$current_donation_refunded  = min( $transaction->amount_refunded, $transaction->amount );
		$donation_delta             = $current_donation_refunded - $previous_donation_refunded;
		$tip_delta                  = $refund_delta - $donation_delta;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$donor_table}
						SET test_total_donated = GREATEST(0, test_total_donated - %d),
							test_total_tip = GREATEST(0, test_total_tip - %d),
							date_modified = %s
						WHERE id = %d",
						$donation_delta,
						$tip_delta,
						$now,
						$transaction->donor_id
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$donor_table}
						SET total_donated = GREATEST(0, total_donated - %d),
							total_tip = GREATEST(0, total_tip - %d),
							date_modified = %s
						WHERE id = %d",
						$donation_delta,
						$tip_delta,
						$now,
						$transaction->donor_id
					)
				);
			}
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'missiondp_campaigns';
			$raised_col     = $transaction->is_test ? 'test_total_raised' : 'total_raised';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET {$raised_col} = GREATEST(0, {$raised_col} - %d),
						date_modified = %s
					WHERE id = %d",
					$donation_delta,
					$now,
					$transaction->campaign_id
				)
			);

			/** @param int $campaign_id @param bool $is_test */
			do_action( 'missiondp_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * Fires after aggregates are adjusted for a refund.
		 *
		 * @since 1.0.0
		 *
		 * @param Transaction $transaction  The transaction.
		 * @param int         $refund_delta Amount refunded in this event (minor units).
		 */
		do_action( 'missiondp_transaction_refund_applied', $transaction, $refund_delta );
	}

	/**
	 * Decrement only transaction and donor counts (no dollar amounts).
	 *
	 * Used when a refund transitions a transaction to 'refunded' — the dollar
	 * amounts were already adjusted incrementally by adjust_aggregates_for_refund().
	 *
	 * @param Transaction $transaction The transaction.
	 */
	private function decrement_counts( Transaction $transaction ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';
			$count_col   = $transaction->is_test ? 'test_transaction_count' : 'transaction_count';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$donor_table}
					SET {$count_col} = GREATEST(0, CAST({$count_col} AS SIGNED) - 1),
						date_modified = %s
					WHERE id = %d",
					$now,
					$transaction->donor_id
				)
			);
		}

		if ( $transaction->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'missiondp_campaigns';
			$count_col      = $transaction->is_test ? 'test_transaction_count' : 'transaction_count';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET {$count_col} = GREATEST(0, CAST({$count_col} AS SIGNED) - 1),
						date_modified = %s
					WHERE id = %d",
					$now,
					$transaction->campaign_id
				)
			);

			// Decrement donor_count if the donor has no remaining completed transactions for this campaign.
			if ( $transaction->donor_id ) {
				$txn_table       = $this->get_table_name();
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$remaining = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$txn_table}
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d",
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( 0 === $remaining ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$campaign_table}
							SET {$donor_count_col} = GREATEST(0, CAST({$donor_count_col} AS SIGNED) - 1),
								date_modified = %s
							WHERE id = %d",
							$now,
							$transaction->campaign_id
						)
					);
				}
			}

			/** @param int $campaign_id @param bool $is_test */
			do_action( 'missiondp_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
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
		return [
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
			'amount_refunded'         => $model->amount_refunded,
			'currency'                => $model->currency,
			'payment_gateway'         => $model->payment_gateway,
			'gateway_transaction_id'  => $model->gateway_transaction_id,
			'gateway_subscription_id' => $model->gateway_subscription_id,
			'is_anonymous'            => (int) $model->is_anonymous,
			'is_test'                 => (int) $model->is_test,
			'donor_ip'                => $model->donor_ip,
			'date_created'            => $model->date_created,
			'date_completed'          => $model->date_completed,
			'date_refunded'           => $model->date_refunded,
			'date_modified'           => $model->date_modified,
		];
	}
}
