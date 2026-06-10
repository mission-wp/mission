<?php
/**
 * Donor DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- query()/count() assemble SQL from literal fragments + the SearchClauseBuilder helper (whose placeholders are matched in $prepare_args) and a whitelisted ASC/DESC direction.

use MissionDP\Database\SearchClauseBuilder;
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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', $this->get_table_name(), $email ),
			ARRAY_A
		);

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

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $this->get_table_name(), $user_id ),
			ARRAY_A
		);

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
	 * Recompute a donor's lifetime aggregates from the transactions table.
	 *
	 * Rebuilds total_donated / total_tip / transaction_count / first_transaction /
	 * last_transaction and the four test_* mirrors. No-op if the donor row doesn't
	 * exist. Used by the import flow's deferred-recompute pass and by future admin
	 * "recalculate aggregates" tooling.
	 *
	 * @param int $donor_id Donor ID.
	 */
	public function recompute_aggregates( int $donor_id ): void {
		global $wpdb;

		if ( $donor_id <= 0 ) {
			return;
		}

		$transactions_table = $wpdb->prefix . 'missiondp_transactions';
		$donors_table       = $this->get_table_name();

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN is_test = 0 THEN amount END), 0)        AS total_donated,
					COALESCE(SUM(CASE WHEN is_test = 0 THEN tip_amount END), 0)    AS total_tip,
					COALESCE(SUM(CASE WHEN is_test = 0 THEN 1 END), 0)             AS transaction_count,
					MIN(CASE WHEN is_test = 0 THEN date_completed END)             AS first_transaction,
					MAX(CASE WHEN is_test = 0 THEN date_completed END)             AS last_transaction,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN amount END), 0)        AS test_total_donated,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN tip_amount END), 0)    AS test_total_tip,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN 1 END), 0)             AS test_transaction_count,
					MIN(CASE WHEN is_test = 1 THEN date_completed END)             AS test_first_transaction,
					MAX(CASE WHEN is_test = 1 THEN date_completed END)             AS test_last_transaction
				FROM %i
				WHERE donor_id = %d AND status = 'completed'",
				$transactions_table,
				$donor_id
			),
			ARRAY_A
		);

		if ( ! $stats ) {
			return;
		}

		$wpdb->update(
			$donors_table,
			[
				'total_donated'          => (int) $stats['total_donated'],
				'total_tip'              => (int) $stats['total_tip'],
				'transaction_count'      => (int) $stats['transaction_count'],
				'first_transaction'      => $stats['first_transaction'],
				'last_transaction'       => $stats['last_transaction'],
				'test_total_donated'     => (int) $stats['test_total_donated'],
				'test_total_tip'         => (int) $stats['test_total_tip'],
				'test_transaction_count' => (int) $stats['test_transaction_count'],
				'test_first_transaction' => $stats['test_first_transaction'],
				'test_last_transaction'  => $stats['test_last_transaction'],
				'date_modified'          => current_time( 'mysql', true ),
			],
			[ 'id' => $donor_id ],
			null,
			[ '%d' ]
		);
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

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE missiondp_donor_id = %d',
				$this->get_meta_table_name(),
				$id
			)
		);

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

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 'email', 'first_name', 'last_name' ]
		);
		$where_sql     = $search_clause ? ' WHERE ' . $search_clause['sql'] : '';
		$search_params = $search_clause ? $search_clause['params'] : [];

		$allowed_orderby = [ 'id', 'date_created', 'total_donated', 'transaction_count', 'last_transaction', 'test_total_donated', 'test_transaction_count', 'test_last_transaction' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$direction       = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? PHP_INT_MAX ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = 'SELECT * FROM %i' . $where_sql . " ORDER BY %i {$direction} LIMIT %d OFFSET %d";

		$prepare_args = array_merge(
			[ $this->get_table_name() ],
			$search_params,
			[ $orderby, $per_page, $offset ]
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $prepare_args ),
			ARRAY_A
		);

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

		$search_clause = SearchClauseBuilder::build_like_clause(
			(string) ( $args['search'] ?? '' ),
			[ 'email', 'first_name', 'last_name' ]
		);
		$where_sql     = $search_clause ? ' WHERE ' . $search_clause['sql'] : '';
		$search_params = $search_clause ? $search_clause['params'] : [];

		$sql          = 'SELECT COUNT(*) FROM %i' . $where_sql;
		$prepare_args = array_merge( [ $this->get_table_name() ], $search_params );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $prepare_args ) );
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
