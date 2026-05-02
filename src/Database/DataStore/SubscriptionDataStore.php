<?php
/**
 * Subscription DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the subscriptions table.
 */
class SubscriptionDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_subscriptions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_subscriptionmeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_subscription';
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
		do_action( 'missiondp_subscription_created', $model );

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
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
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
			do_action( 'missiondp_subscription_status_transition', $model, $old->status, $model->status );

			/**
			 * Fires on a specific subscription status transition.
			 *
			 * @param Subscription $model The subscription.
			 */
			do_action( "missiondp_subscription_status_{$old->status}_to_{$model->status}", $model );
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

		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE missiondp_subscription_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query subscriptions.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Subscription[]
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
	 * Count subscriptions matching filters.
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
	 * Build a query SQL string from arguments.
	 *
	 * @param string               $select The SELECT clause.
	 * @param array<string, mixed> $args   Query arguments.
	 *
	 * @return string
	 */
	/**
	 * Returns [ sql_template, values ] — caller passes through wpdb::prepare().
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_query_sql( string $select, array $args ): array {
		global $wpdb;

		$table  = $this->get_table_name();
		$where  = [];
		$values = [];

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['status__in'] ), '%s' ) );
			$where[]      = "status IN ($placeholders)";
			array_push( $values, ...$args['status__in'] );
		} elseif ( ! empty( $args['status'] ) ) {
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

		if ( isset( $args['is_test'] ) ) {
			$where[]  = 'is_test = %d';
			$values[] = (int) $args['is_test'];
		}

		if ( ! empty( $args['gateway_subscription_id'] ) ) {
			$where[]  = 'gateway_subscription_id = %s';
			$values[] = $args['gateway_subscription_id'];
		}

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'date_created >= %s';
			$values[] = $args['date_after'];
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'date_created <= %s';
			$values[] = $args['date_before'];
		}

		if ( ! empty( $args['date_next_renewal_before'] ) ) {
			$where[]  = 'date_next_renewal < %s';
			$values[] = $args['date_next_renewal_before'];
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( gateway_subscription_id LIKE %s )';
			$values[] = $search;
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created', 'date_modified', 'date_next_renewal', 'total_amount', 'status' ];
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
		return [
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
			'is_test'                 => (int) $model->is_test,
			'date_created'            => $model->date_created,
			'date_next_renewal'       => $model->date_next_renewal,
			'date_cancelled'          => $model->date_cancelled,
			'date_modified'           => $model->date_modified,
		];
	}
}
