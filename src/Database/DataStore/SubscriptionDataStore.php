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

		$status_in_csv  = ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ? implode( ',', $args['status__in'] ) : '';
		$has_status_in  = '' !== $status_in_csv ? 1 : 0;
		$status         = ! $has_status_in && ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status     = '' !== $status ? 1 : 0;
		$donor_id       = (int) ( $args['donor_id'] ?? 0 );
		$campaign_id    = (int) ( $args['campaign_id'] ?? 0 );
		$has_is_test    = isset( $args['is_test'] ) ? 1 : 0;
		$is_test        = isset( $args['is_test'] ) ? (int) (bool) $args['is_test'] : 0;
		$gateway_sub_id = (string) ( $args['gateway_subscription_id'] ?? '' );
		$has_gateway    = '' !== $gateway_sub_id ? 1 : 0;
		$renewal_before = (string) ( $args['date_next_renewal_before'] ?? '' );
		$has_renewal    = '' !== $renewal_before ? 1 : 0;

		$allowed_orderby = [ 'id', 'date_created', 'date_modified', 'date_next_renewal', 'total_amount', 'status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_asc       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' );

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR FIND_IN_SET( status, %s ) )
					   AND ( %d = 0 OR status = %s )
					   AND ( %d = 0 OR donor_id = %d )
					   AND ( %d = 0 OR campaign_id = %d )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR gateway_subscription_id = %s )
					   AND ( %d = 0 OR date_next_renewal < %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status_in,
					$status_in_csv,
					$has_status,
					$status,
					$donor_id,
					$donor_id,
					$campaign_id,
					$campaign_id,
					$has_is_test,
					$is_test,
					$has_gateway,
					$gateway_sub_id,
					$has_renewal,
					$renewal_before,
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
					 WHERE ( %d = 0 OR FIND_IN_SET( status, %s ) )
					   AND ( %d = 0 OR status = %s )
					   AND ( %d = 0 OR donor_id = %d )
					   AND ( %d = 0 OR campaign_id = %d )
					   AND ( %d = 0 OR is_test = %d )
					   AND ( %d = 0 OR gateway_subscription_id = %s )
					   AND ( %d = 0 OR date_next_renewal < %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status_in,
					$status_in_csv,
					$has_status,
					$status,
					$donor_id,
					$donor_id,
					$campaign_id,
					$campaign_id,
					$has_is_test,
					$is_test,
					$has_gateway,
					$gateway_sub_id,
					$has_renewal,
					$renewal_before,
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
	 * Count subscriptions matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$status_in_csv  = ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ? implode( ',', $args['status__in'] ) : '';
		$has_status_in  = '' !== $status_in_csv ? 1 : 0;
		$status         = ! $has_status_in && ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status     = '' !== $status ? 1 : 0;
		$donor_id       = (int) ( $args['donor_id'] ?? 0 );
		$campaign_id    = (int) ( $args['campaign_id'] ?? 0 );
		$has_is_test    = isset( $args['is_test'] ) ? 1 : 0;
		$is_test        = isset( $args['is_test'] ) ? (int) (bool) $args['is_test'] : 0;
		$gateway_sub_id = (string) ( $args['gateway_subscription_id'] ?? '' );
		$has_gateway    = '' !== $gateway_sub_id ? 1 : 0;
		$renewal_before = (string) ( $args['date_next_renewal_before'] ?? '' );
		$has_renewal    = '' !== $renewal_before ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR FIND_IN_SET( status, %s ) )
				   AND ( %d = 0 OR status = %s )
				   AND ( %d = 0 OR donor_id = %d )
				   AND ( %d = 0 OR campaign_id = %d )
				   AND ( %d = 0 OR is_test = %d )
				   AND ( %d = 0 OR gateway_subscription_id = %s )
				   AND ( %d = 0 OR date_next_renewal < %s )',
				$this->get_table_name(),
				$has_status_in,
				$status_in_csv,
				$has_status,
				$status,
				$donor_id,
				$donor_id,
				$campaign_id,
				$campaign_id,
				$has_is_test,
				$is_test,
				$has_gateway,
				$gateway_sub_id,
				$has_renewal,
				$renewal_before
			)
		);
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
