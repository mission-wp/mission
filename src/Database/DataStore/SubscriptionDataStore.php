<?php
/**
 * Subscription DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

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
		$this->insert_row( $model );

		/** @param Subscription $model The subscription. */
		do_action( 'mission_subscription_created', $model );

		return $model->id;
	}

	/**
	 * Create a subscription without firing the created hook.
	 *
	 * Used by the data importer: listeners (activity feed, notifications) should
	 * not fire for historical rows being backfilled.
	 *
	 * Internal — consumer code should use Subscription::save_silent().
	 *
	 * @param Subscription $model Subscription model.
	 * @return int New subscription ID.
	 */
	public function create_silent( Subscription $model ): int {
		$this->insert_row( $model );
		return $model->id;
	}

	/**
	 * Raw insert path shared by create() and create_silent().
	 *
	 * @param object $model Subscription model.
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
	 * Read a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return Subscription|null
	 */
	public function read( int $id ): ?Subscription {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Read a subscription by its gateway subscription ID.
	 *
	 * @param string $gateway_subscription_id Gateway subscription identifier.
	 *
	 * @return Subscription|null
	 */
	public function read_by_gateway_subscription_id( string $gateway_subscription_id ): ?Subscription {
		global $wpdb;

		if ( '' === trim( $gateway_subscription_id ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE gateway_subscription_id = %s ORDER BY id DESC LIMIT 1',
				$this->get_table_name(),
				$gateway_subscription_id
			),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Map gateway subscription IDs to subscription IDs in one query.
	 *
	 * Used by batch consumers (e.g. migration) to detect records that already
	 * exist for the same gateway subscription. Empty values never match; when
	 * multiple rows share a value, the lowest ID wins.
	 *
	 * @param string[] $gateway_ids Gateway subscription identifiers.
	 *
	 * @return array<string, int> Map of gateway_subscription_id => subscription ID.
	 */
	public function read_ids_by_gateway_subscription_ids( array $gateway_ids ): array {
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
		$sql          = "SELECT id, gateway_subscription_id FROM %i WHERE gateway_subscription_id IN ( {$placeholders} ) ORDER BY id ASC";
		$prepare_args = array_merge( [ $this->get_table_name() ], $gateway_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, values via %s placeholders built from a counted array.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (string) $row['gateway_subscription_id'] ] ??= (int) $row['id'];
		}

		return $map;
	}

	/**
	 * Update a subscription.
	 *
	 * @param object $model Subscription model.
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
	 * Update a subscription without firing status-transition hooks. Used by the
	 * data importer (see create_silent).
	 *
	 * Internal — consumer code should use Subscription::save_silent().
	 *
	 * @param Subscription $model Subscription model with updated values.
	 * @return bool
	 */
	public function update_silent( Subscription $model ): bool {
		if ( ! $this->read( $model->id ) ) {
			return false;
		}

		return $this->update_row( $model );
	}

	/**
	 * Raw UPDATE path shared by update() and update_silent().
	 *
	 * @param object $model Subscription model.
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
	 * Delete a subscription by ID.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE missiondp_subscription_id = %d',
				$this->get_meta_table_name(),
				$id
			)
		);

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

		[ $where, $values ] = $this->build_where_clause( $args );

		$allowed_orderby = [ 'id', 'date_created', 'date_modified', 'date_next_renewal', 'total_amount', 'status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql          = "SELECT * FROM %i WHERE {$where} ORDER BY %i {$order} LIMIT %d OFFSET %d";
		$prepare_args = array_merge( [ $this->get_table_name() ], $values, [ $orderby, $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/orderby via %i, filters via placeholders built from counted arrays, direction whitelisted.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Build a WHERE clause and its placeholder values from query args.
	 *
	 * Clauses are added only when the corresponding filter is present, keeping
	 * the SQL portable (no MySQL-only functions, works on SQLite installs).
	 * status__in takes precedence over status when both are passed.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array{string, array<int, string|int>} WHERE fragment and prepare values.
	 */
	private function build_where_clause( array $args ): array {
		$clauses = [];
		$values  = [];

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['status__in'] ), '%s' ) );
			$clauses[]    = "status IN ( {$placeholders} )";
			$values       = array_merge( $values, array_map( 'strval', $args['status__in'] ) );
		} elseif ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = (string) $args['status'];
		}

		if ( ! empty( $args['donor_id'] ) ) {
			$clauses[] = 'donor_id = %d';
			$values[]  = (int) $args['donor_id'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$clauses[] = 'campaign_id = %d';
			$values[]  = (int) $args['campaign_id'];
		}

		if ( isset( $args['is_test'] ) ) {
			$clauses[] = 'is_test = %d';
			$values[]  = (int) (bool) $args['is_test'];
		}

		if ( ! empty( $args['gateway_subscription_id'] ) ) {
			$clauses[] = 'gateway_subscription_id = %s';
			$values[]  = (string) $args['gateway_subscription_id'];
		}

		if ( ! empty( $args['date_next_renewal_before'] ) ) {
			$clauses[] = 'date_next_renewal < %s';
			$values[]  = (string) $args['date_next_renewal_before'];
		}

		return [ $clauses ? implode( ' AND ', $clauses ) : '1 = 1', $values ];
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

		[ $where, $values ] = $this->build_where_clause( $args );

		$sql          = "SELECT COUNT(*) FROM %i WHERE {$where}";
		$prepare_args = array_merge( [ $this->get_table_name() ], $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, filters via placeholders built from counted arrays.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $prepare_args ) );
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
