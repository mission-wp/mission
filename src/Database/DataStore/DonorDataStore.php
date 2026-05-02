<?php
/**
 * Donor DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

use MissionDP\Models\Donor;

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
		return $wpdb->prefix . 'missiondp_donors';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_donormeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_donor';
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
		do_action( 'missiondp_donor_created', $model );

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
	 * Find a donor by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return Donor|null
	 */
	public function find_by_user_id( int $user_id ): ?Donor {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ),
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
			[ 'id' => $model->id ],
			null,
			[ '%d' ]
		);

		if ( false !== $result ) {
			/** @param Donor $model The donor. */
			do_action( 'missiondp_donor_updated', $model );
		}

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
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE missiondp_donor_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query donors.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Donor[]
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
	 * Count donors matching filters.
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

		if ( ! empty( $args['has_transactions'] ) ) {
			$allowed_count_cols = [ 'transaction_count', 'test_transaction_count' ];
			$count_col          = in_array( $args['has_transactions'], $allowed_count_cols, true )
				? $args['has_transactions']
				: 'transaction_count';
			$where[]            = "{$count_col} > 0";
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'date_created', 'total_donated', 'transaction_count', 'last_transaction', 'test_total_donated', 'test_transaction_count', 'test_last_transaction' ];
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
		return [
			'id'                     => $model->id,
			'user_id'                => $model->user_id,
			'email'                  => $model->email,
			'first_name'             => $model->first_name,
			'last_name'              => $model->last_name,
			'phone'                  => $model->phone,
			'address_1'              => $model->address_1,
			'address_2'              => $model->address_2,
			'city'                   => $model->city,
			'state'                  => $model->state,
			'zip'                    => $model->zip,
			'country'                => $model->country,
			'total_donated'          => $model->total_donated,
			'total_tip'              => $model->total_tip,
			'transaction_count'      => $model->transaction_count,
			'first_transaction'      => $model->first_transaction,
			'last_transaction'       => $model->last_transaction,
			'test_total_donated'     => $model->test_total_donated,
			'test_total_tip'         => $model->test_total_tip,
			'test_transaction_count' => $model->test_transaction_count,
			'test_first_transaction' => $model->test_first_transaction,
			'test_last_transaction'  => $model->test_last_transaction,
			'date_created'           => $model->date_created,
			'date_modified'          => $model->date_modified,
		];
	}
}
