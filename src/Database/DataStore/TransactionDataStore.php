<?php
/**
 * Transaction DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

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
		$this->insert_row( $model );

		/**
		 * Fires after a transaction is created.
		 *
		 * @param Transaction $model The transaction.
		 */
		do_action( 'mission_transaction_created', $model );

		// Update donor/campaign aggregates if created with a completed status.
		if ( Transaction::STATUS_COMPLETED === $model->status ) {
			$this->increment_aggregates( $model );
		}

		return $model->id;
	}

	/**
	 * Create a transaction without firing side-effect hooks or touching donor /
	 * campaign aggregates.
	 *
	 * Used by the data importer: aggregates are recomputed once at the end of the
	 * job, and we don't want listeners (Slack pings, thank-you emails, webhook
	 * posts) firing for historical rows being backfilled.
	 *
	 * Internal — consumer code should use Transaction::save_silent().
	 *
	 * @param Transaction $model Transaction model.
	 * @return int New transaction ID.
	 */
	public function create_silent( Transaction $model ): int {
		$this->insert_row( $model );
		return $model->id;
	}

	/**
	 * Raw insert path shared by create() and create_silent().
	 *
	 * @param object $model Transaction model.
	 */
	private function insert_row( object $model ): void {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->model_to_row( $model );

		$data['date_created']  = $data['date_created'] ?: $now;
		$data['date_modified'] = $now;
		unset( $data['id'] );

		$wpdb->insert( $this->get_table_name(), $data );
		$model->id = (int) $wpdb->insert_id;
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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Read a transaction by its gateway transaction ID.
	 *
	 * @param string $gateway_transaction_id Gateway transaction identifier.
	 *
	 * @return Transaction|null
	 */
	public function read_by_gateway_transaction_id( string $gateway_transaction_id ): ?Transaction {
		global $wpdb;

		if ( '' === trim( $gateway_transaction_id ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE gateway_transaction_id = %s ORDER BY id DESC LIMIT 1',
				$this->get_table_name(),
				$gateway_transaction_id
			),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Map a set of transaction IDs to their gateway transaction IDs.
	 *
	 * @param int[] $ids Transaction IDs.
	 * @return array<int, string> transaction_id => gateway_transaction_id (non-empty only).
	 */
	public function read_gateway_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT id, gateway_transaction_id FROM %i WHERE id IN ( {$placeholders} ) AND gateway_transaction_id <> ''";
		$prepare_args = array_merge( [ $this->get_table_name() ], $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['id'] ] = (string) $row['gateway_transaction_id'];
		}

		return $map;
	}

	/**
	 * Map gateway transaction IDs to transaction IDs in one query.
	 *
	 * Used by batch consumers (e.g. migration) to detect records that already
	 * exist for the same gateway charge. Empty values never match; when
	 * multiple rows share a value, the lowest ID wins.
	 *
	 * @param string[] $gateway_ids Gateway transaction identifiers.
	 *
	 * @return array<string, int> Map of gateway_transaction_id => transaction ID.
	 */
	public function read_ids_by_gateway_transaction_ids( array $gateway_ids ): array {
		global $wpdb;

		$gateway_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $gateway_ids ),
					static fn( string $value ): bool => '' !== trim( $value )
				)
			)
		);

		if ( empty( $gateway_ids ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $gateway_ids ), '%s' ) );
		$sql          = "SELECT id, gateway_transaction_id FROM %i WHERE gateway_transaction_id IN ( {$placeholders} ) ORDER BY id ASC";
		$prepare_args = array_merge( [ $this->get_table_name() ], $gateway_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, values via %s placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (string) $row['gateway_transaction_id'] ] ??= (int) $row['id'];
		}

		return $map;
	}

	/**
	 * Update a transaction.
	 *
	 * @param object $model Transaction model with updated values.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		$old = $this->read( $model->id );
		if ( ! $old ) {
			return false;
		}

		if ( ! $this->update_row( $model ) ) {
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
	 * Update a transaction without firing status-transition hooks or touching
	 * donor / campaign aggregates. Used by the data importer (see create_silent).
	 *
	 * Internal — consumer code should use Transaction::save_silent().
	 *
	 * @param Transaction $model Transaction model with updated values.
	 * @return bool
	 */
	public function update_silent( Transaction $model ): bool {
		if ( ! $this->read( $model->id ) ) {
			return false;
		}

		return $this->update_row( $model );
	}

	/**
	 * Raw UPDATE path shared by update() and update_silent().
	 *
	 * @param object $model Transaction model.
	 */
	private function update_row( object $model ): bool {
		global $wpdb;

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

		return false !== $result;
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
		if ( $transaction && Transaction::STATUS_COMPLETED === $transaction->status ) {
			$this->decrement_aggregates( $transaction );
		}

		// Delete associated notes, history, and tribute first.
		( new NoteDataStore() )->delete_by_object( 'transaction', $id );
		( new TransactionHistoryDataStore() )->delete_by_transaction( $id );
		( new TributeDataStore() )->delete_by_transaction( $id );

		// Delete associated meta.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE missiondp_transaction_id = %d',
				$this->get_meta_table_name(),
				$id
			)
		);

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query transactions.
	 *
	 * Supported filters: status, type, type__not, donor_id, campaign_id,
	 * fundraiser_id, team_id, subscription_id, gateway_transaction_id, is_test,
	 * date_after, date_before.
	 * Pagination/order: orderby, order, per_page, page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Transaction[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$status     = (string) ( $args['status'] ?? '' );
		$has_status = '' !== $status ? 1 : 0;

		$type     = (string) ( $args['type'] ?? '' );
		$has_type = '' !== $type ? 1 : 0;

		$type_not     = (string) ( $args['type__not'] ?? '' );
		$has_type_not = '' !== $type_not ? 1 : 0;

		$donor_id  = (int) ( $args['donor_id'] ?? 0 );
		$has_donor = $donor_id > 0 ? 1 : 0;

		$campaign_id  = (int) ( $args['campaign_id'] ?? 0 );
		$has_campaign = $campaign_id > 0 ? 1 : 0;

		$fundraiser_id  = (int) ( $args['fundraiser_id'] ?? 0 );
		$has_fundraiser = $fundraiser_id > 0 ? 1 : 0;

		$team_id  = (int) ( $args['team_id'] ?? 0 );
		$has_team = $team_id > 0 ? 1 : 0;

		$subscription_id  = (int) ( $args['subscription_id'] ?? 0 );
		$has_subscription = $subscription_id > 0 ? 1 : 0;

		$gateway_txn_id     = (string) ( $args['gateway_transaction_id'] ?? '' );
		$has_gateway_txn_id = '' !== $gateway_txn_id ? 1 : 0;

		$is_test_val = isset( $args['is_test'] ) ? (int) (bool) $args['is_test'] : 0;
		$has_is_test = isset( $args['is_test'] ) ? 1 : 0;

		$date_after     = (string) ( $args['date_after'] ?? '' );
		$has_date_after = '' !== $date_after ? 1 : 0;

		$date_before     = (string) ( $args['date_before'] ?? '' );
		$has_date_before = '' !== $date_before ? 1 : 0;

		$allowed_orderby = [ 'id', 'date_created', 'date_completed', 'date_modified', 'total_amount', 'status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_asc       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' );

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR status = %s )
					   AND ( %d = 0 OR type = %s )
					   AND ( %d = 0 OR type != %s )
					   AND ( %d = 0 OR donor_id = %d )
					   AND ( %d = 0 OR campaign_id = %d )
					   AND ( %d = 0 OR fundraiser_id = %d )
					   AND ( %d = 0 OR team_id = %d )
					   AND ( %d = 0 OR subscription_id = %d )
					   AND ( %d = 0 OR gateway_transaction_id = %s )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR date_created >= %s )
					   AND ( %d = 0 OR date_created <= %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status,
					$status,
					$has_type,
					$type,
					$has_type_not,
					$type_not,
					$has_donor,
					$donor_id,
					$has_campaign,
					$campaign_id,
					$has_fundraiser,
					$fundraiser_id,
					$has_team,
					$team_id,
					$has_subscription,
					$subscription_id,
					$has_gateway_txn_id,
					$gateway_txn_id,
					$has_is_test,
					$is_test_val,
					$has_date_after,
					$date_after,
					$has_date_before,
					$date_before,
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
					 WHERE ( %d = 0 OR status = %s )
					   AND ( %d = 0 OR type = %s )
					   AND ( %d = 0 OR type != %s )
					   AND ( %d = 0 OR donor_id = %d )
					   AND ( %d = 0 OR campaign_id = %d )
					   AND ( %d = 0 OR fundraiser_id = %d )
					   AND ( %d = 0 OR team_id = %d )
					   AND ( %d = 0 OR subscription_id = %d )
					   AND ( %d = 0 OR gateway_transaction_id = %s )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR date_created >= %s )
					   AND ( %d = 0 OR date_created <= %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status,
					$status,
					$has_type,
					$type,
					$has_type_not,
					$type_not,
					$has_donor,
					$donor_id,
					$has_campaign,
					$campaign_id,
					$has_fundraiser,
					$fundraiser_id,
					$has_team,
					$team_id,
					$has_subscription,
					$subscription_id,
					$has_gateway_txn_id,
					$gateway_txn_id,
					$has_is_test,
					$is_test_val,
					$has_date_after,
					$date_after,
					$has_date_before,
					$date_before,
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
	 * Count transactions matching filters.
	 *
	 * Supported filters mirror query() (excluding pagination/order).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$status     = (string) ( $args['status'] ?? '' );
		$has_status = '' !== $status ? 1 : 0;

		$type     = (string) ( $args['type'] ?? '' );
		$has_type = '' !== $type ? 1 : 0;

		$type_not     = (string) ( $args['type__not'] ?? '' );
		$has_type_not = '' !== $type_not ? 1 : 0;

		$donor_id  = (int) ( $args['donor_id'] ?? 0 );
		$has_donor = $donor_id > 0 ? 1 : 0;

		$campaign_id  = (int) ( $args['campaign_id'] ?? 0 );
		$has_campaign = $campaign_id > 0 ? 1 : 0;

		$fundraiser_id  = (int) ( $args['fundraiser_id'] ?? 0 );
		$has_fundraiser = $fundraiser_id > 0 ? 1 : 0;

		$team_id  = (int) ( $args['team_id'] ?? 0 );
		$has_team = $team_id > 0 ? 1 : 0;

		$subscription_id  = (int) ( $args['subscription_id'] ?? 0 );
		$has_subscription = $subscription_id > 0 ? 1 : 0;

		$gateway_txn_id     = (string) ( $args['gateway_transaction_id'] ?? '' );
		$has_gateway_txn_id = '' !== $gateway_txn_id ? 1 : 0;

		$is_test_val = isset( $args['is_test'] ) ? (int) (bool) $args['is_test'] : 0;
		$has_is_test = isset( $args['is_test'] ) ? 1 : 0;

		$date_after     = (string) ( $args['date_after'] ?? '' );
		$has_date_after = '' !== $date_after ? 1 : 0;

		$date_before     = (string) ( $args['date_before'] ?? '' );
		$has_date_before = '' !== $date_before ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR status = %s )
				   AND ( %d = 0 OR type = %s )
				   AND ( %d = 0 OR type != %s )
				   AND ( %d = 0 OR donor_id = %d )
				   AND ( %d = 0 OR campaign_id = %d )
				   AND ( %d = 0 OR fundraiser_id = %d )
				   AND ( %d = 0 OR team_id = %d )
				   AND ( %d = 0 OR subscription_id = %d )
				   AND ( %d = 0 OR gateway_transaction_id = %s )
				   AND ( %d = 0 OR is_test = %d )
				   AND ( %d = 0 OR date_created >= %s )
				   AND ( %d = 0 OR date_created <= %s )',
				$this->get_table_name(),
				$has_status,
				$status,
				$has_type,
				$type,
				$has_type_not,
				$type_not,
				$has_donor,
				$donor_id,
				$has_campaign,
				$campaign_id,
				$has_fundraiser,
				$fundraiser_id,
				$has_team,
				$team_id,
				$has_subscription,
				$subscription_id,
				$has_gateway_txn_id,
				$gateway_txn_id,
				$has_is_test,
				$is_test_val,
				$has_date_after,
				$date_after,
				$has_date_before,
				$date_before
			)
		);
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
		if ( Transaction::STATUS_COMPLETED === $new_status ) {
			$this->increment_aggregates( $transaction );
		} elseif ( Transaction::STATUS_COMPLETED === $old_status && in_array( $new_status, [ Transaction::STATUS_REFUNDED, Transaction::STATUS_CANCELLED, Transaction::STATUS_FAILED ], true ) ) {
			if ( Transaction::STATUS_REFUNDED === $new_status && $transaction->amount_refunded > 0 ) {
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

		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i
						SET test_total_donated = test_total_donated + %d,
							test_total_tip = test_total_tip + %d,
							test_transaction_count = test_transaction_count + 1,
							test_first_transaction = COALESCE(NULLIF(test_first_transaction, '0000-00-00 00:00:00'), %s),
							test_last_transaction = %s,
							date_modified = %s
						WHERE id = %d",
						$donor_table,
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
						"UPDATE %i
						SET total_donated = total_donated + %d,
							total_tip = total_tip + %d,
							transaction_count = transaction_count + 1,
							first_transaction = COALESCE(NULLIF(first_transaction, '0000-00-00 00:00:00'), %s),
							last_transaction = %s,
							date_modified = %s
						WHERE id = %d",
						$donor_table,
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
					'UPDATE %i SET %i = %i + %d, %i = %i + 1, date_modified = %s WHERE id = %d',
					$campaign_table,
					$raised_col,
					$raised_col,
					$transaction->amount,
					$count_col,
					$count_col,
					$now,
					$transaction->campaign_id
				)
			);

			// Increment donor_count if this is the donor's first completed transaction for this campaign.
			if ( $transaction->donor_id ) {
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$has_previous = (bool) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM %i
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d
						LIMIT 1",
						$this->get_table_name(),
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( ! $has_previous ) {
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET %i = %i + 1, date_modified = %s WHERE id = %d',
							$campaign_table,
							$donor_count_col,
							$donor_count_col,
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
			do_action( 'mission_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}
	}

	/**
	 * Decrement donor and campaign aggregates when a transaction leaves completed status.
	 *
	 * @param Transaction $transaction The transaction.
	 */
	private function decrement_aggregates( Transaction $transaction ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET test_total_donated = GREATEST(0, test_total_donated - %d),
							test_total_tip = GREATEST(0, test_total_tip - %d),
							test_transaction_count = GREATEST(0, CAST(test_transaction_count AS SIGNED) - 1),
							date_modified = %s
						WHERE id = %d',
						$donor_table,
						$transaction->amount,
						$transaction->tip_amount,
						$now,
						$transaction->donor_id
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET total_donated = GREATEST(0, total_donated - %d),
							total_tip = GREATEST(0, total_tip - %d),
							transaction_count = GREATEST(0, CAST(transaction_count AS SIGNED) - 1),
							date_modified = %s
						WHERE id = %d',
						$donor_table,
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
					'UPDATE %i SET %i = GREATEST(0, %i - %d), %i = GREATEST(0, CAST(%i AS SIGNED) - 1), date_modified = %s WHERE id = %d',
					$campaign_table,
					$raised_col,
					$raised_col,
					$transaction->amount,
					$count_col,
					$count_col,
					$now,
					$transaction->campaign_id
				)
			);

			// Decrement donor_count if the donor has no remaining completed transactions for this campaign.
			if ( $transaction->donor_id ) {
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$remaining = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d",
						$this->get_table_name(),
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( 0 === $remaining ) {
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET %i = GREATEST(0, CAST(%i AS SIGNED) - 1), date_modified = %s WHERE id = %d',
							$campaign_table,
							$donor_count_col,
							$donor_count_col,
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
			do_action( 'mission_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}
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

		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';

			if ( $transaction->is_test ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET test_total_donated = GREATEST(0, test_total_donated - %d),
							test_total_tip = GREATEST(0, test_total_tip - %d),
							date_modified = %s
						WHERE id = %d',
						$donor_table,
						$donation_delta,
						$tip_delta,
						$now,
						$transaction->donor_id
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET total_donated = GREATEST(0, total_donated - %d),
							total_tip = GREATEST(0, total_tip - %d),
							date_modified = %s
						WHERE id = %d',
						$donor_table,
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
					'UPDATE %i SET %i = GREATEST(0, %i - %d), date_modified = %s WHERE id = %d',
					$campaign_table,
					$raised_col,
					$raised_col,
					$donation_delta,
					$now,
					$transaction->campaign_id
				)
			);

			/** @param int $campaign_id @param bool $is_test */
			do_action( 'mission_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}

		/**
		 * Fires after aggregates are adjusted for a refund.
		 *
		 * @since 1.0.0
		 *
		 * @param Transaction $transaction  The transaction.
		 * @param int         $refund_delta Amount refunded in this event (minor units).
		 */
		do_action( 'mission_transaction_refund_applied', $transaction, $refund_delta );
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

		if ( $transaction->donor_id ) {
			$donor_table = $wpdb->prefix . 'missiondp_donors';
			$count_col   = $transaction->is_test ? 'test_transaction_count' : 'transaction_count';

			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET %i = GREATEST(0, CAST(%i AS SIGNED) - 1), date_modified = %s WHERE id = %d',
					$donor_table,
					$count_col,
					$count_col,
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
					'UPDATE %i SET %i = GREATEST(0, CAST(%i AS SIGNED) - 1), date_modified = %s WHERE id = %d',
					$campaign_table,
					$count_col,
					$count_col,
					$now,
					$transaction->campaign_id
				)
			);

			// Decrement donor_count if the donor has no remaining completed transactions for this campaign.
			if ( $transaction->donor_id ) {
				$donor_count_col = $transaction->is_test ? 'test_donor_count' : 'donor_count';
				$is_test_val     = (int) $transaction->is_test;

				$remaining = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i
						WHERE campaign_id = %d AND donor_id = %d AND status = 'completed'
							AND is_test = %d AND id != %d",
						$this->get_table_name(),
						$transaction->campaign_id,
						$transaction->donor_id,
						$is_test_val,
						$transaction->id
					)
				);

				if ( 0 === $remaining ) {
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET %i = GREATEST(0, CAST(%i AS SIGNED) - 1), date_modified = %s WHERE id = %d',
							$campaign_table,
							$donor_count_col,
							$donor_count_col,
							$now,
							$transaction->campaign_id
						)
					);
				}
			}

			/** @param int $campaign_id @param bool $is_test */
			do_action( 'mission_campaign_aggregates_updated', $transaction->campaign_id, (bool) $transaction->is_test );
		}
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
			'fundraiser_id'           => $model->fundraiser_id,
			'team_id'                 => $model->team_id,
			'amount'                  => $model->amount,
			'fee_amount'              => $model->fee_amount,
			'tip_amount'              => $model->tip_amount,
			'total_amount'            => $model->total_amount,
			'amount_refunded'         => $model->amount_refunded,
			'currency'                => $model->currency,
			'payment_gateway'         => $model->payment_gateway,
			'gateway_transaction_id'  => $model->gateway_transaction_id,
			'gateway_subscription_id' => $model->gateway_subscription_id,
			'gateway_customer_id'     => $model->gateway_customer_id,
			'is_anonymous'            => (int) $model->is_anonymous,
			'is_test'                 => (int) $model->is_test,
			'import_job_id'           => $model->import_job_id,
			'donor_ip'                => $model->donor_ip,
			'date_created'            => $model->date_created,
			'date_completed'          => $model->date_completed,
			'date_refunded'           => $model->date_refunded,
			'date_modified'           => $model->date_modified,
		];
	}
}
