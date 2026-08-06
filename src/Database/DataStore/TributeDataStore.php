<?php
/**
 * Tribute DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

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
		$this->insert_row( $model );

		/**
		 * Fires after a tribute is created.
		 *
		 * @param Tribute $model The tribute.
		 */
		do_action( 'mission_tribute_created', $model );

		return $model->id;
	}

	/**
	 * Create a tribute without firing the created hook.
	 *
	 * Used by the data importer: the tribute hooks send honoree notification and
	 * admin mail-dedication emails, which must not fire for historical rows being
	 * backfilled.
	 *
	 * Internal — consumer code should use Tribute::save_silent().
	 *
	 * @param Tribute $model Tribute model.
	 * @return int New tribute ID.
	 */
	public function create_silent( Tribute $model ): int {
		$this->insert_row( $model );
		return $model->id;
	}

	/**
	 * Raw insert path shared by create() and create_silent().
	 *
	 * @param object $model Tribute model.
	 */
	private function insert_row( object $model ): void {
		global $wpdb;

		$row = $this->model_to_row( $model );

		$row['date_created'] = $row['date_created'] ?: current_time( 'mysql', true );
		unset( $row['id'] );

		$wpdb->insert( $this->get_table_name(), $row );
		$model->id = (int) $wpdb->insert_id;
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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

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
		if ( ! $this->update_row( $model ) ) {
			return false;
		}

		/**
		 * Fires after a tribute is updated.
		 *
		 * @param Tribute $model The tribute.
		 */
		do_action( 'mission_tribute_updated', $model );

		return true;
	}

	/**
	 * Update a tribute without firing the updated hook. Used by the data importer
	 * (see create_silent).
	 *
	 * Internal — consumer code should use Tribute::save_silent().
	 *
	 * @param Tribute $model Tribute model with updated values.
	 * @return bool
	 */
	public function update_silent( Tribute $model ): bool {
		return $this->update_row( $model );
	}

	/**
	 * Raw UPDATE path shared by update() and update_silent().
	 *
	 * @param object $model Tribute model.
	 */
	private function update_row( object $model ): bool {
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

		return false !== $result;
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
			do_action( 'mission_tribute_deleted', $tribute );
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
	 * Supported args: `transaction_id` (int filter), `per_page`, `page`.
	 * Results are ordered by `date_created DESC`.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Tribute[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$transaction_id     = (int) ( $args['transaction_id'] ?? 0 );
		$has_transaction_id = $transaction_id > 0 ? 1 : 0;

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE ( %d = 0 OR transaction_id = %d )
				 ORDER BY date_created DESC, id DESC
				 LIMIT %d OFFSET %d',
				$this->get_table_name(),
				$has_transaction_id,
				$transaction_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

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

		$transaction_id     = (int) ( $args['transaction_id'] ?? 0 );
		$has_transaction_id = $transaction_id > 0 ? 1 : 0;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE ( %d = 0 OR transaction_id = %d )',
				$this->get_table_name(),
				$has_transaction_id,
				$transaction_id
			)
		);
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
