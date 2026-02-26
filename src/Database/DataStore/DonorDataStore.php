<?php
/**
 * Donor DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\Donor;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the donors table.
 */
class DonorDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_donors';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_donor_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_foreign_key(): string {
		return 'donor_id';
	}

	/**
	 * Create a donor.
	 *
	 * @param object $model Donor model.
	 *
	 * @return int New donor ID.
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

		/** @param Donor $model The donor. */
		do_action( 'mission_donor_created', $model );

		return $model->id;
	}

	/**
	 * Read a donor by ID.
	 *
	 * @param int $id Donor ID.
	 *
	 * @return Donor|null
	 */
	public function read( int $id ): ?Donor {
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
	 * Find a donor by email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return Donor|null
	 */
	public function find_by_email( string $email ): ?Donor {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update a donor.
	 *
	 * @param object $model Donor model.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

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

		return false !== $result;
	}

	/**
	 * Delete a donor by ID.
	 *
	 * @param int $id Donor ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE donor_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Query donors.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Donor[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'row_to_model' ), $rows ?: array() );
	}

	/**
	 * Count donors matching filters.
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

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = $args['user_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
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

		$allowed_orderby = array( 'id', 'date_created', 'total_donated', 'donation_count', 'last_donation' );
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
	 * Map a database row to a Donor model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Donor
	 */
	private function row_to_model( array $row ): Donor {
		return new Donor( $row );
	}

	/**
	 * Map a Donor model to a database row array.
	 *
	 * @param Donor $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Donor $model ): array {
		return array(
			'id'             => $model->id,
			'user_id'        => $model->user_id,
			'email'          => $model->email,
			'first_name'     => $model->first_name,
			'last_name'      => $model->last_name,
			'name_prefix'    => $model->name_prefix,
			'phone'          => $model->phone,
			'total_donated'  => $model->total_donated,
			'total_tip'      => $model->total_tip,
			'donation_count' => $model->donation_count,
			'first_donation' => $model->first_donation,
			'last_donation'  => $model->last_donation,
			'date_created'   => $model->date_created,
			'date_modified'  => $model->date_modified,
		);
	}
}
