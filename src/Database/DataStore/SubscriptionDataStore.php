<?php
/**
 * Subscription DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the subscriptions table.
 */
class SubscriptionDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_subscriptions';
	}

	/**
	 * Create a subscription.
	 *
	 * @param object $model Subscription model.
	 *
	 * @return int New subscription ID.
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

		/** @param Subscription $model The subscription. */
		do_action( 'mission_subscription_created', $model );

		return $model->id;
	}

	/**
	 * Read a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return Subscription|null
	 */
	public function read( int $id ): ?Subscription {
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
	 * Update a subscription.
	 *
	 * @param object $model Subscription model.
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

		if ( $old->status !== $model->status ) {
			/**
			 * Fires on any subscription status change.
			 *
			 * @param Subscription $model      The subscription.
			 * @param string       $old_status Previous status.
			 * @param string       $new_status New status.
			 */
			do_action( 'mission_subscription_status_transition', $model, $old->status, $model->status );

			/**
			 * Fires on a specific subscription status transition.
			 *
			 * @param Subscription $model The subscription.
			 */
			do_action( "mission_subscription_status_{$old->status}_to_{$model->status}", $model );
		}

		return true;
	}

	/**
	 * Delete a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Query subscriptions.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Subscription[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'row_to_model' ), $rows ?: array() );
	}

	/**
	 * Count subscriptions matching filters.
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

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'date_created >= %s';
			$values[] = $args['date_after'];
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'date_created <= %s';
			$values[] = $args['date_before'];
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = array( 'id', 'date_created', 'date_modified', 'date_next_renewal', 'total_amount', 'status' );
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
	 * Map a database row to a Subscription model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Subscription
	 */
	private function row_to_model( array $row ): Subscription {
		return new Subscription( $row );
	}

	/**
	 * Map a Subscription model to a database row array.
	 *
	 * @param Subscription $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Subscription $model ): array {
		return array(
			'id'                      => $model->id,
			'status'                  => $model->status,
			'donor_id'                => $model->donor_id,
			'source_post_id'          => $model->source_post_id,
			'campaign_id'             => $model->campaign_id,
			'initial_transaction_id'  => $model->initial_transaction_id,
			'amount'                  => $model->amount,
			'fee_amount'              => $model->fee_amount,
			'tip_amount'              => $model->tip_amount,
			'total_amount'            => $model->total_amount,
			'currency'                => $model->currency,
			'frequency'               => $model->frequency,
			'payment_gateway'         => $model->payment_gateway,
			'gateway_subscription_id' => $model->gateway_subscription_id,
			'gateway_customer_id'     => $model->gateway_customer_id,
			'renewal_count'           => $model->renewal_count,
			'total_renewed'           => $model->total_renewed,
			'date_created'            => $model->date_created,
			'date_next_renewal'       => $model->date_next_renewal,
			'date_cancelled'          => $model->date_cancelled,
			'date_expired'            => $model->date_expired,
			'date_modified'           => $model->date_modified,
		);
	}
}
