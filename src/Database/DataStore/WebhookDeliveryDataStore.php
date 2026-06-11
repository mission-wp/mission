<?php
/**
 * Webhook delivery DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\WebhookDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the webhook_deliveries table.
 */
class WebhookDeliveryDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_webhook_deliveries';
	}

	/**
	 * Create a webhook delivery record.
	 *
	 * @param object $model WebhookDelivery model.
	 *
	 * @return int New delivery ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['date_created'] = $row['date_created'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		return $model->id;
	}

	/**
	 * Read a webhook delivery by ID.
	 *
	 * @param int $id Delivery ID.
	 *
	 * @return WebhookDelivery|null
	 */
	public function read( int $id ): ?WebhookDelivery {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update a webhook delivery record.
	 *
	 * @param object $model WebhookDelivery model.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$row = $this->model_to_row( $model );
		unset( $row['id'] );

		$result = $wpdb->update(
			$this->get_table_name(),
			$row,
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete a webhook delivery by ID.
	 *
	 * @param int $id Delivery ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query webhook deliveries.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return WebhookDelivery[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$webhook_id     = ! empty( $args['webhook_id'] ) ? (int) $args['webhook_id'] : 0;
		$has_webhook_id = $webhook_id > 0 ? 1 : 0;

		$event     = ! empty( $args['event'] ) ? (string) $args['event'] : '';
		$has_event = '' !== $event ? 1 : 0;

		$event_id     = ! empty( $args['event_id'] ) ? (string) $args['event_id'] : '';
		$has_event_id = '' !== $event_id ? 1 : 0;

		$status     = ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status = '' !== $status ? 1 : 0;

		$allowed_orderby = [ 'id', 'event', 'status', 'response_code', 'attempt', 'date_created' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order_asc       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' );

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $order_asc ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
					 WHERE ( %d = 0 OR webhook_id = %d )
					   AND ( %d = 0 OR event = %s )
					   AND ( %d = 0 OR event_id = %s )
					   AND ( %d = 0 OR status = %s )
					 ORDER BY %i ASC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_webhook_id,
					$webhook_id,
					$has_event,
					$event,
					$has_event_id,
					$event_id,
					$has_status,
					$status,
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
					 WHERE ( %d = 0 OR webhook_id = %d )
					   AND ( %d = 0 OR event = %s )
					   AND ( %d = 0 OR event_id = %s )
					   AND ( %d = 0 OR status = %s )
					 ORDER BY %i DESC
					 LIMIT %d OFFSET %d',
					$this->get_table_name(),
					$has_webhook_id,
					$webhook_id,
					$has_event,
					$event,
					$has_event_id,
					$event_id,
					$has_status,
					$status,
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
	 * Count webhook deliveries matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		$webhook_id     = ! empty( $args['webhook_id'] ) ? (int) $args['webhook_id'] : 0;
		$has_webhook_id = $webhook_id > 0 ? 1 : 0;

		$event     = ! empty( $args['event'] ) ? (string) $args['event'] : '';
		$has_event = '' !== $event ? 1 : 0;

		$event_id     = ! empty( $args['event_id'] ) ? (string) $args['event_id'] : '';
		$has_event_id = '' !== $event_id ? 1 : 0;

		$status     = ! empty( $args['status'] ) ? (string) $args['status'] : '';
		$has_status = '' !== $status ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR webhook_id = %d )
				   AND ( %d = 0 OR event = %s )
				   AND ( %d = 0 OR event_id = %s )
				   AND ( %d = 0 OR status = %s )',
				$this->get_table_name(),
				$has_webhook_id,
				$webhook_id,
				$has_event,
				$event,
				$has_event_id,
				$event_id,
				$has_status,
				$status
			)
		);
	}

	/**
	 * Delete deliveries older than the given number of days.
	 *
	 * @param int $days Number of days to retain.
	 *
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE date_created < %s',
				$this->get_table_name(),
				$cutoff
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Delete all deliveries for a specific webhook.
	 *
	 * @param int $webhook_id Webhook ID.
	 *
	 * @return int Number of rows deleted.
	 */
	public function delete_by_webhook( int $webhook_id ): int {
		global $wpdb;

		$wpdb->delete( $this->get_table_name(), [ 'webhook_id' => $webhook_id ], [ '%d' ] );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Map a database row to a WebhookDelivery model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return WebhookDelivery
	 */
	private function row_to_model( array $row ): WebhookDelivery {
		return new WebhookDelivery( $row );
	}

	/**
	 * Map a WebhookDelivery model to a database row array.
	 *
	 * @param WebhookDelivery $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( WebhookDelivery $model ): array {
		return [
			'id'               => $model->id,
			'webhook_id'       => $model->webhook_id,
			'event'            => $model->event,
			'event_id'         => $model->event_id,
			'url'              => $model->url,
			'request_headers'  => $model->request_headers,
			'request_body'     => $model->request_body,
			'response_code'    => $model->response_code,
			'response_headers' => $model->response_headers,
			'response_body'    => $model->response_body,
			'duration_ms'      => $model->duration_ms,
			'attempt'          => $model->attempt,
			'status'           => $model->status,
			'error_message'    => $model->error_message,
			'date_created'     => $model->date_created,
		];
	}
}
