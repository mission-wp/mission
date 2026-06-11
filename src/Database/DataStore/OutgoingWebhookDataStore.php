<?php
/**
 * Outgoing webhook DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\OutgoingWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the outgoing_webhooks table.
 */
class OutgoingWebhookDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_outgoing_webhooks';
	}

	/**
	 * Create an outgoing webhook.
	 *
	 * @param object $model OutgoingWebhook model.
	 *
	 * @return int New webhook ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$now                  = current_time( 'mysql', true );
		$row['date_created']  = $row['date_created'] ?: $now;
		$row['date_modified'] = $now;
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after an outgoing webhook is created.
		 *
		 * @param OutgoingWebhook $model The webhook.
		 */
		do_action( 'mission_outgoing_webhook_created', $model );

		return $model->id;
	}

	/**
	 * Read an outgoing webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 *
	 * @return OutgoingWebhook|null
	 */
	public function read( int $id ): ?OutgoingWebhook {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update an outgoing webhook.
	 *
	 * @param object $model OutgoingWebhook model.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$row                  = $this->model_to_row( $model );
		$row['date_modified'] = current_time( 'mysql', true );
		unset( $row['id'] );

		$result = $wpdb->update(
			$this->get_table_name(),
			$row,
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
		);

		if ( false !== $result ) {
			/**
			 * Fires after an outgoing webhook is updated.
			 *
			 * @param OutgoingWebhook $model The webhook.
			 */
			do_action( 'mission_outgoing_webhook_updated', $model );
		}

		return false !== $result;
	}

	/**
	 * Delete an outgoing webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		if ( false !== $result ) {
			// Clean up associated deliveries.
			( new WebhookDeliveryDataStore() )->delete_by_webhook( $id );

			/**
			 * Fires after an outgoing webhook is deleted.
			 *
			 * @param int $id The deleted webhook ID.
			 */
			do_action( 'mission_outgoing_webhook_deleted', $id );
		}

		return false !== $result;
	}

	/**
	 * Query outgoing webhooks.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return OutgoingWebhook[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$status     = ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status = '' !== $status ? 1 : 0;

		$health     = ! empty( $args['health'] ) ? (string) $args['health'] : '';
		$has_health = '' !== $health ? 1 : 0;

		$search_like = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';
		$has_search  = '' !== $search_like ? 1 : 0;

		$allowed_orderby = [ 'id', 'name', 'status', 'health', 'date_created', 'last_delivery_at' ];
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
					   AND ( %d = 0 OR health = %s )
					   AND ( %d = 0 OR name LIKE %s OR url LIKE %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status,
					$status,
					$has_health,
					$health,
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
					 WHERE ( %d = 0 OR status = %s )
					   AND ( %d = 0 OR health = %s )
					   AND ( %d = 0 OR name LIKE %s OR url LIKE %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_status,
					$status,
					$has_health,
					$health,
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
	 * Count outgoing webhooks matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$status     = ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status = '' !== $status ? 1 : 0;

		$health     = ! empty( $args['health'] ) ? (string) $args['health'] : '';
		$has_health = '' !== $health ? 1 : 0;

		$search_like = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';
		$has_search  = '' !== $search_like ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR status = %s )
				   AND ( %d = 0 OR health = %s )
				   AND ( %d = 0 OR name LIKE %s OR url LIKE %s )',
				$this->get_table_name(),
				$has_status,
				$status,
				$has_health,
				$health,
				$has_search,
				$search_like,
				$search_like
			)
		);
	}

	/**
	 * Map a database row to an OutgoingWebhook model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return OutgoingWebhook
	 */
	private function row_to_model( array $row ): OutgoingWebhook {
		$row['events'] = json_decode( $row['events'] ?? '[]', true ) ?: [];

		return new OutgoingWebhook( $row );
	}

	/**
	 * Map an OutgoingWebhook model to a database row array.
	 *
	 * @param OutgoingWebhook $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( OutgoingWebhook $model ): array {
		return [
			'id'                 => $model->id,
			'name'               => $model->name,
			'url'                => $model->url,
			'secret'             => $model->secret,
			'events'             => wp_json_encode( $model->events ),
			'status'             => $model->status,
			'health'             => $model->health,
			'failure_count'      => $model->failure_count,
			'failing_since'      => $model->failing_since,
			'last_delivery_at'   => $model->last_delivery_at,
			'last_response_code' => $model->last_response_code,
			'date_created'       => $model->date_created,
			'date_modified'      => $model->date_modified,
		];
	}
}
