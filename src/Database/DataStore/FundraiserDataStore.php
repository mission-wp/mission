<?php
/**
 * Fundraiser DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\Fundraiser;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the fundraisers table.
 *
 * A fundraiser is one person's participation in one peer-to-peer campaign.
 * The table is the source of truth; aggregate columns mirror the campaign
 * pattern and are rebuilt from attributed transactions via recompute_aggregates().
 */
class FundraiserDataStore implements DataStoreInterface {

	use CachesRows;
	use MetaTrait;

	/**
	 * Non-persistent cache group for memoized fundraiser rows.
	 */
	public const CACHE_GROUP = 'missiondp_fundraisers';

	/**
	 * Aggregate columns owned by recompute_aggregates(). Excluded from update()
	 * so a stale in-memory model can't overwrite a concurrent recompute (e.g. a
	 * donation webhook landing between a request's find() and save()).
	 *
	 * @var string[]
	 */
	private const AGGREGATE_COLUMNS = [
		'total_raised',
		'transaction_count',
		'donor_count',
		'test_total_raised',
		'test_transaction_count',
		'test_donor_count',
	];

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
		return $wpdb->prefix . 'missiondp_fundraisers';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_fundraisermeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_fundraiser';
	}

	/**
	 * Create a fundraiser.
	 *
	 * @param object $model Fundraiser model.
	 *
	 * @return int New fundraiser ID, 0 when the insert failed (e.g. a
	 *             campaign_donor unique-constraint race).
	 */
	public function create( object $model ): int {
		$this->insert_row( $model );

		if ( $model->id ) {
			/**
			 * Fires after a fundraiser is created.
			 *
			 * @param Fundraiser $model The fundraiser.
			 */
			do_action( 'mission_fundraiser_created', $model );
		}

		return $model->id;
	}

	/**
	 * Raw insert path for create().
	 *
	 * @param object $model Fundraiser model.
	 */
	private function insert_row( object $model ): void {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->model_to_row( $model );

		$data['date_created']  = $data['date_created'] ?: $now;
		$data['date_modified'] = $now;
		unset( $data['id'] );

		$result    = $wpdb->insert( $this->get_table_name(), $data );
		$model->id = false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Read a fundraiser by ID.
	 *
	 * @param int $id Fundraiser ID.
	 *
	 * @return Fundraiser|null
	 */
	public function read( int $id ): ?Fundraiser {
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
	 * Find a fundraiser by its associated post ID.
	 *
	 * @param int $post_id The WP post ID.
	 *
	 * @return Fundraiser|null
	 */
	public function find_by_post_id( int $post_id ): ?Fundraiser {
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
	 * Update a fundraiser.
	 *
	 * @param object $model Fundraiser model.
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
		$data = array_diff_key( $data, array_flip( self::AGGREGATE_COLUMNS ) );

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
	 * Recompute a fundraiser's aggregates from the transactions table.
	 *
	 * Rebuilds total_raised / transaction_count / donor_count and the three test_*
	 * mirrors from transactions attributed to this fundraiser. Fires
	 * mission_fundraiser_aggregates_updated. No-op if the ID is invalid.
	 *
	 * @param int  $fundraiser_id Fundraiser ID.
	 * @param bool $is_test       Mode of the transaction that triggered the
	 *                            recompute (both arms are rebuilt regardless).
	 */
	public function recompute_aggregates( int $fundraiser_id, bool $is_test = false ): void {
		global $wpdb;

		if ( $fundraiser_id <= 0 ) {
			return;
		}

		$transactions_table = $wpdb->prefix . 'missiondp_transactions';
		$fundraisers_table  = $this->get_table_name();

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				// LEAST clamps refunds so total_raised nets only the donation portion
				// of partial refunds; counts still include partially-refunded gifts.
				"SELECT
					COALESCE(SUM(CASE WHEN is_test = 0 THEN amount - LEAST(amount_refunded, amount) END), 0) AS total_raised,
					COALESCE(SUM(CASE WHEN is_test = 0 THEN 1 END), 0)                                       AS transaction_count,
					COALESCE(COUNT(DISTINCT CASE WHEN is_test = 0 THEN donor_id END), 0)                     AS donor_count,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN amount - LEAST(amount_refunded, amount) END), 0) AS test_total_raised,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN 1 END), 0)                                       AS test_transaction_count,
					COALESCE(COUNT(DISTINCT CASE WHEN is_test = 1 THEN donor_id END), 0)                     AS test_donor_count
				FROM %i
				WHERE fundraiser_id = %d AND status = 'completed'",
				$transactions_table,
				$fundraiser_id
			),
			ARRAY_A
		);

		if ( ! $stats ) {
			return;
		}

		$updated = $wpdb->update(
			$fundraisers_table,
			[
				'total_raised'           => (int) $stats['total_raised'],
				'transaction_count'      => (int) $stats['transaction_count'],
				'donor_count'            => (int) $stats['donor_count'],
				'test_total_raised'      => (int) $stats['test_total_raised'],
				'test_transaction_count' => (int) $stats['test_transaction_count'],
				'test_donor_count'       => (int) $stats['test_donor_count'],
				'date_modified'          => current_time( 'mysql', true ),
			],
			[ 'id' => $fundraiser_id ],
			null,
			[ '%d' ]
		);

		if ( $updated ) {
			$this->forget_cached_row( $fundraiser_id );

			/**
			 * Fires when a fundraiser's aggregate columns are recomputed.
			 *
			 * @param int  $fundraiser_id The fundraiser ID.
			 * @param bool $is_test       Mode of the transaction that triggered the recompute.
			 */
			do_action( 'mission_fundraiser_aggregates_updated', $fundraiser_id, $is_test );
		}
	}

	/**
	 * Delete a fundraiser by ID, including its meta.
	 *
	 * Detaches references first so nothing points at the deleted row: attributed
	 * donations and subscriptions become plain campaign giving (their campaign_id
	 * stays), and a team captained by this fundraiser is left captainless for
	 * re-promotion.
	 *
	 * @param int $id Fundraiser ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'missiondp_transactions',
			[ 'fundraiser_id' => null ],
			[ 'fundraiser_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		$wpdb->update(
			$wpdb->prefix . 'missiondp_subscriptions',
			[ 'fundraiser_id' => null ],
			[ 'fundraiser_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		$detached_teams = $wpdb->update(
			$wpdb->prefix . 'missiondp_teams',
			[ 'captain_id' => null ],
			[ 'captain_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $detached_teams ) {
			wp_cache_flush_group( TeamDataStore::CACHE_GROUP );
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE missiondp_fundraiser_id = %d',
				$this->get_meta_table_name(),
				$id
			)
		);

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		$this->forget_cached_row( $id );

		return false !== $result;
	}

	/**
	 * Query fundraisers.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Fundraiser[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->build_where_clause( $args );

		$allowed_orderby = [ 'id', 'status', 'goal', 'date_created', 'date_modified', 'total_raised', 'transaction_count', 'donor_count' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$per_page = (int) ( $args['per_page'] ?? 100 );
		$per_page = $per_page < 1 ? PHP_INT_MAX : $per_page;
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql          = "SELECT * FROM %i WHERE {$where} ORDER BY %i {$order}, id {$order} LIMIT %d OFFSET %d";
		$prepare_args = array_merge( [ $this->get_table_name() ], $values, [ $orderby, $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/orderby via %i, filters via placeholders built from counted arrays, direction whitelisted.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count fundraisers matching filters.
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
	 * Clauses are added only when the corresponding filter is present, keeping
	 * the SQL portable (no MySQL-only functions, works on SQLite installs).
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
			$clauses[] = 'headline LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$clauses[] = 'campaign_id = %d';
			$values[]  = (int) $args['campaign_id'];
		}

		if ( ! empty( $args['donor_id'] ) ) {
			$clauses[] = 'donor_id = %d';
			$values[]  = (int) $args['donor_id'];
		}

		if ( ! empty( $args['team_id'] ) ) {
			$clauses[] = 'team_id = %d';
			$values[]  = (int) $args['team_id'];
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

		if ( ! empty( $args['id__in'] ) && is_array( $args['id__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['id__in'] ), '%d' ) );
			$clauses[]    = "id IN ( {$placeholders} )";
			$values       = array_merge( $values, array_map( 'intval', $args['id__in'] ) );
		}

		if ( ! empty( $args['cover_image'] ) ) {
			$clauses[] = 'cover_image = %s';
			$values[]  = (string) $args['cover_image'];
		}

		if ( ! empty( $args['profile_image'] ) ) {
			$clauses[] = 'profile_image = %s';
			$values[]  = (string) $args['profile_image'];
		}

		return [ $clauses ? implode( ' AND ', $clauses ) : '1 = 1', $values ];
	}

	/**
	 * Map a database row to a Fundraiser model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Fundraiser
	 */
	private function row_to_model( array $row ): Fundraiser {
		return new Fundraiser( $row );
	}

	/**
	 * Map a Fundraiser model to a database row array.
	 *
	 * @param Fundraiser $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Fundraiser $model ): array {
		return [
			'id'                     => $model->id,
			'campaign_id'            => $model->campaign_id,
			'donor_id'               => $model->donor_id,
			'team_id'                => $model->team_id,
			'post_id'                => $model->post_id,
			'status'                 => $model->status,
			'goal'                   => $model->goal,
			'headline'               => $model->headline,
			'story'                  => $model->story,
			'cover_image'            => $model->cover_image,
			'profile_image'          => $model->profile_image,
			'total_raised'           => $model->total_raised,
			'transaction_count'      => $model->transaction_count,
			'donor_count'            => $model->donor_count,
			'test_total_raised'      => $model->test_total_raised,
			'test_transaction_count' => $model->test_transaction_count,
			'test_donor_count'       => $model->test_donor_count,
			'date_created'           => $model->date_created,
			'date_modified'          => $model->date_modified,
		];
	}
}
