<?php
/**
 * Tribute DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\Tribute;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the tributes table.
 */
class TributeDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_tributes';
	}

	/**
	 * Create a tribute.
	 *
	 * @param Tribute $model Tribute model.
	 *
	 * @return int New tribute ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['date_created'] = $row['date_created'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after a tribute is created.
		 *
		 * @param Tribute $model The tribute.
		 */
		do_action( 'missiondp_tribute_created', $model );

		return $model->id;
	}

	/**
	 * Read a tribute by ID.
	 *
	 * @param int $id Tribute ID.
	 *
	 * @return Tribute|null
	 */
	public function read( int $id ): ?Tribute {
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
	 * Update a tribute.
	 *
	 * @param Tribute $model Tribute model with updated values.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$data = $this->model_to_row( $model );
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

		/**
		 * Fires after a tribute is updated.
		 *
		 * @param Tribute $model The tribute.
		 */
		do_action( 'missiondp_tribute_updated', $model );

		return true;
	}

	/**
	 * Delete a tribute by ID.
	 *
	 * @param int $id Tribute ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$tribute = $this->read( $id );

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		if ( false !== $result && $tribute ) {
			/**
			 * Fires after a tribute is deleted.
			 *
			 * @param Tribute $tribute The deleted tribute.
			 */
			do_action( 'missiondp_tribute_deleted', $tribute );
		}

		return false !== $result;
	}

	/**
	 * Delete the tribute for a transaction (cascade cleanup).
	 *
	 * @param int $transaction_id Transaction ID.
	 *
	 * @return bool
	 */
	public function delete_by_transaction( int $transaction_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$this->get_table_name(),
			[ 'transaction_id' => $transaction_id ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Query tributes.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Tribute[]
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
	 * Count tributes matching filters.
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
		$table  = $this->get_table_name();
		$where  = [];
		$values = [];

		if ( ! empty( $args['transaction_id'] ) ) {
			$where[]  = 'transaction_id = %d';
			$values[] = $args['transaction_id'];
		}

		if ( ! empty( $args['tribute_type'] ) ) {
			$where[]  = 'tribute_type = %s';
			$values[] = $args['tribute_type'];
		}

		if ( ! empty( $args['notify_method'] ) ) {
			$where[]  = 'notify_method = %s';
			$values[] = $args['notify_method'];
		}

		if ( isset( $args['notification_status'] ) ) {
			if ( 'pending' === $args['notification_status'] ) {
				$where[] = 'notification_sent_at IS NULL';
			} elseif ( 'sent' === $args['notification_status'] ) {
				$where[] = 'notification_sent_at IS NOT NULL';
			}
		}

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'date_created >= %s';
			$values[] = $args['date_after'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'date_created <= %s';
			$values[] = $args['date_before'] . ' 23:59:59';
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created' ];
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
	 * Map a database row to a Tribute model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Tribute
	 */
	private function row_to_model( array $row ): Tribute {
		return new Tribute( $row );
	}

	/**
	 * Map a Tribute model to a database row array.
	 *
	 * @param Tribute $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Tribute $model ): array {
		return [
			'id'                   => $model->id,
			'transaction_id'       => $model->transaction_id,
			'tribute_type'         => $model->tribute_type,
			'honoree_name'         => $model->honoree_name,
			'notify_name'          => $model->notify_name,
			'notify_email'         => $model->notify_email,
			'notify_address_1'     => $model->notify_address_1,
			'notify_address_2'     => $model->notify_address_2,
			'notify_city'          => $model->notify_city,
			'notify_state'         => $model->notify_state,
			'notify_zip'           => $model->notify_zip,
			'notify_country'       => $model->notify_country,
			'notify_method'        => $model->notify_method,
			'message'              => $model->message,
			'notification_sent_at' => $model->notification_sent_at,
			'date_created'         => $model->date_created,
		];
	}
}
