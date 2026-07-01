<?php
/**
 * Team DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the teams table.
 *
 * A team groups peer-to-peer fundraisers under a captain. Teams have no stored
 * aggregate columns; amount_raised is summed live from member fundraisers'
 * aggregate columns plus direct team gifts via sum_amount_raised().
 */
class TeamDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_teams';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_teammeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_team';
	}

	/**
	 * Create a team.
	 *
	 * @param object $model Team model.
	 *
	 * @return int New team ID.
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

		/**
		 * Fires after a team is created.
		 *
		 * @param Team $model The team.
		 */
		do_action( 'mission_team_created', $model );

		return $model->id;
	}

	/**
	 * Read a team by ID.
	 *
	 * @param int $id Team ID.
	 *
	 * @return Team|null
	 */
	public function read( int $id ): ?Team {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find a team by its associated post ID.
	 *
	 * @param int $post_id The WP post ID.
	 *
	 * @return Team|null
	 */
	public function find_by_post_id( int $post_id ): ?Team {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $this->get_table_name(), $post_id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update a team.
	 *
	 * @param object $model Team model.
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

		return false !== $result;
	}

	/**
	 * Delete a team by ID, including its meta and invitations.
	 *
	 * Detaches references first so nothing points at the deleted row: members
	 * become solo fundraisers, direct team gifts become plain campaign donations
	 * (their campaign_id stays), and outstanding invitation tokens are destroyed.
	 *
	 * @param int $id Team ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'missiondp_fundraisers',
			[
				'team_id'         => null,
				'is_team_captain' => 0,
			],
			[ 'team_id' => $id ],
			[ '%d', '%d' ],
			[ '%d' ]
		);
		$wpdb->update(
			$wpdb->prefix . 'missiondp_transactions',
			[ 'team_id' => null ],
			[ 'team_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		$wpdb->delete(
			$wpdb->prefix . 'missiondp_team_invitations',
			[ 'team_id' => $id ],
			[ '%d' ]
		);

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE missiondp_team_id = %d',
				$this->get_meta_table_name(),
				$id
			)
		);

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Sum the amount raised by a team: member fundraisers plus direct team gifts.
	 *
	 * Members are read from their stored aggregate columns (consistent with what
	 * each fundraiser page displays); direct gifts are completed transactions with
	 * this team_id, netted for refunds, matching ReportingService::team_totals().
	 *
	 * @param int  $team_id Team ID.
	 * @param bool $is_test Whether to sum test-mode amounts.
	 *
	 * @return int Total raised in minor units.
	 */
	public function sum_amount_raised( int $team_id, bool $is_test = false ): int {
		global $wpdb;

		if ( $team_id <= 0 ) {
			return 0;
		}

		$fundraisers_table  = $wpdb->prefix . 'missiondp_fundraisers';
		$transactions_table = $wpdb->prefix . 'missiondp_transactions';
		$column             = $is_test ? 'test_total_raised' : 'total_raised';

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ( SELECT COALESCE(SUM(%i), 0) FROM %i WHERE team_id = %d )
					+ ( SELECT COALESCE(SUM(amount - LEAST(amount_refunded, amount)), 0)
						FROM %i WHERE team_id = %d AND status = 'completed' AND is_test = %d )",
				$column,
				$fundraisers_table,
				$team_id,
				$transactions_table,
				$team_id,
				$is_test ? 1 : 0
			)
		);

		return (int) $total;
	}

	/**
	 * Query teams.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Team[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->build_where_clause( $args );

		$allowed_orderby = [ 'id', 'name', 'status', 'goal', 'date_created', 'date_modified' ];
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
	 * Count teams matching filters.
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
	 * Build a WHERE clause and its placeholder values from query args.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array{string, array<int, string|int>} WHERE fragment and prepare values.
	 */
	private function build_where_clause( array $args ): array {
		global $wpdb;

		$clauses = [];
		$values  = [];

		if ( ! empty( $args['search'] ) ) {
			$clauses[] = 'name LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$clauses[] = 'campaign_id = %d';
			$values[]  = (int) $args['campaign_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = (string) $args['status'];
		}

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['status__in'] ), '%s' ) );
			$clauses[]    = "status IN ( {$placeholders} )";
			$values       = array_merge( $values, array_map( 'strval', $args['status__in'] ) );
		}

		if ( ! empty( $args['access'] ) ) {
			$clauses[] = 'access = %s';
			$values[]  = (string) $args['access'];
		}

		return [ $clauses ? implode( ' AND ', $clauses ) : '1 = 1', $values ];
	}

	/**
	 * Map a database row to a Team model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Team
	 */
	private function row_to_model( array $row ): Team {
		return new Team( $row );
	}

	/**
	 * Map a Team model to a database row array.
	 *
	 * @param Team $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Team $model ): array {
		return [
			'id'            => $model->id,
			'campaign_id'   => $model->campaign_id,
			'captain_id'    => $model->captain_id,
			'post_id'       => $model->post_id,
			'name'          => $model->name,
			'description'   => $model->description,
			'goal'          => $model->goal,
			'cover_image'   => $model->cover_image,
			'status'        => $model->status,
			'access'        => $model->access,
			'date_created'  => $model->date_created,
			'date_modified' => $model->date_modified,
		];
	}
}
