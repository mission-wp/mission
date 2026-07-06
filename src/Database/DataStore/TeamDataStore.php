<?php
/**
 * Team DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\Team;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the teams table.
 *
 * A team groups peer-to-peer fundraisers under a captain. Teams have no stored
 * aggregate columns; amount_raised is summed live from member fundraisers'
 * aggregate columns plus direct team gifts via sum_amount_raised().
 */
class TeamDataStore implements DataStoreInterface {

	use CachesRows;
	use MetaTrait;

	/**
	 * Non-persistent cache group for memoized team rows.
	 */
	public const CACHE_GROUP = 'missiondp_teams';

	/**
	 * {@inheritDoc}
	 */
	protected function cache_group(): string {
		return self::CACHE_GROUP;
	}

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
	 * @return int New team ID, 0 when the insert failed.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->model_to_row( $model );

		$data['date_created']  = $data['date_created'] ?: $now;
		$data['date_modified'] = $now;
		unset( $data['id'] );

		$result    = $wpdb->insert( $this->get_table_name(), $data );
		$model->id = false === $result ? 0 : (int) $wpdb->insert_id;

		if ( $model->id ) {
			/**
			 * Fires after a team is created.
			 *
			 * @param Team $model The team.
			 */
			do_action( 'mission_team_created', $model );
		}

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

		$row = $this->cached_row( $id );

		if ( null === $row ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
				ARRAY_A
			);

			if ( $row ) {
				$this->prime_row_cache( $row );
			}
		}

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

		$row = $this->cached_row_by_post_id( $post_id );

		if ( null === $row ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $this->get_table_name(), $post_id ),
				ARRAY_A
			);

			if ( $row ) {
				$this->prime_row_cache( $row );
			}
		}

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

		$this->forget_cached_row( $model->id, $old->post_id );

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

		$detached_members = $wpdb->update(
			$wpdb->prefix . 'missiondp_fundraisers',
			[ 'team_id' => null ],
			[ 'team_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $detached_members ) {
			wp_cache_flush_group( FundraiserDataStore::CACHE_GROUP );
		}
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

		$this->forget_cached_row( $id );

		return false !== $result;
	}

	/**
	 * Sum the amount raised by a team: member fundraisers plus direct team gifts.
	 *
	 * The roll-up rule (stored member aggregates plus refund-netted direct
	 * gifts) is defined once in ReportingService::team_raised_sql().
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

		[ $raised_sql, $raised_args ] = ReportingService::team_raised_sql( $team_id, $is_test );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the fragment is literal SQL whose %i/%d placeholders are matched by $raised_args.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT {$raised_sql}", $raised_args ) );
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

		// Bounded by default; -1 means "all" for full-set consumers (cascades, flushes).
		$per_page = (int) ( $args['per_page'] ?? 100 );
		$per_page = $per_page < 1 ? PHP_INT_MAX : $per_page;
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

		if ( ! empty( $args['id__in'] ) && is_array( $args['id__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['id__in'] ), '%d' ) );
			$clauses[]    = "id IN ( {$placeholders} )";
			$values       = array_merge( $values, array_map( 'intval', $args['id__in'] ) );
		}

		if ( ! empty( $args['cover_image'] ) ) {
			$clauses[] = 'cover_image = %s';
			$values[]  = (string) $args['cover_image'];
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
