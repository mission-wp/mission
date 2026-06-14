<?php
/**
 * Campaign DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the campaigns table.
 *
 * The campaigns table is the source of truth for all campaign data
 * including title and description. The linked WP post provides WordPress
 * integration (URLs, Gutenberg editor, campaign images, slugs).
 */
class CampaignDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_campaigns';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_campaignmeta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_type(): string {
		return 'missiondp_campaign';
	}

	/**
	 * Create a campaign.
	 *
	 * @param object $model Campaign model.
	 *
	 * @return int New campaign ID.
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

		/** @param Campaign $model The campaign. */
		do_action( 'mission_campaign_created', $model );

		return $model->id;
	}

	/**
	 * Read a campaign by ID.
	 *
	 * @param int $id Campaign ID.
	 *
	 * @return Campaign|null
	 */
	public function read( int $id ): ?Campaign {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find a campaign by its associated post ID.
	 *
	 * @param int $post_id The WP post ID.
	 *
	 * @return Campaign|null
	 */
	public function find_by_post_id( int $post_id ): ?Campaign {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $this->get_table_name(), $post_id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find the most recently-created campaign by title (case-insensitive via collation).
	 *
	 * @param string $title Campaign title.
	 *
	 * @return Campaign|null
	 */
	public function read_by_title( string $title ): ?Campaign {
		global $wpdb;

		if ( '' === trim( $title ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE title = %s ORDER BY id DESC LIMIT 1',
				$this->get_table_name(),
				$title
			),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find all campaigns with this title.
	 *
	 * @param string $title Campaign title.
	 *
	 * @return Campaign[]
	 */
	public function read_all_by_title( string $title ): array {
		global $wpdb;

		if ( '' === trim( $title ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE title = %s ORDER BY id DESC',
				$this->get_table_name(),
				$title
			),
			ARRAY_A
		);

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Update a campaign.
	 *
	 * @param object $model Campaign model.
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
	 * Recompute a campaign's aggregates from the transactions table.
	 *
	 * Rebuilds total_raised / transaction_count / donor_count and the three test_*
	 * mirrors. Fires mission_campaign_aggregates_updated so dashboard caches
	 * invalidate. No-op if the campaign row doesn't exist.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function recompute_aggregates( int $campaign_id ): void {
		global $wpdb;

		if ( $campaign_id <= 0 ) {
			return;
		}

		$transactions_table = $wpdb->prefix . 'missiondp_transactions';
		$campaigns_table    = $this->get_table_name();

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN is_test = 0 THEN amount END), 0)                                  AS total_raised,
					COALESCE(SUM(CASE WHEN is_test = 0 THEN 1 END), 0)                                       AS transaction_count,
					COALESCE(COUNT(DISTINCT CASE WHEN is_test = 0 THEN donor_id END), 0)                     AS donor_count,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN amount END), 0)                                  AS test_total_raised,
					COALESCE(SUM(CASE WHEN is_test = 1 THEN 1 END), 0)                                       AS test_transaction_count,
					COALESCE(COUNT(DISTINCT CASE WHEN is_test = 1 THEN donor_id END), 0)                     AS test_donor_count
				FROM %i
				WHERE campaign_id = %d AND status = 'completed'",
				$transactions_table,
				$campaign_id
			),
			ARRAY_A
		);

		if ( ! $stats ) {
			return;
		}

		$updated = $wpdb->update(
			$campaigns_table,
			[
				'total_raised'           => (int) $stats['total_raised'],
				'transaction_count'      => (int) $stats['transaction_count'],
				'donor_count'            => (int) $stats['donor_count'],
				'test_total_raised'      => (int) $stats['test_total_raised'],
				'test_transaction_count' => (int) $stats['test_transaction_count'],
				'test_donor_count'       => (int) $stats['test_donor_count'],
				'date_modified'          => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ],
			null,
			[ '%d' ]
		);

		if ( $updated ) {
			/**
			 * Fires when a campaign's aggregate columns are recomputed.
			 *
			 * @param int  $campaign_id The campaign ID.
			 * @param bool $is_test     Always false here; recompute updates both arms together.
			 */
			do_action( 'mission_campaign_aggregates_updated', $campaign_id, false );
		}
	}

	/**
	 * Delete a campaign by ID.
	 *
	 * @param int $id Campaign ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query campaigns.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Campaign[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->build_where_clause( $args );

		$allowed_orderby = [ 'id', 'title', 'status', 'date_created', 'date_modified', 'date_start', 'date_end', 'goal_amount', 'total_raised', 'transaction_count', 'donor_count', 'test_total_raised', 'test_transaction_count', 'test_donor_count' ];
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
			$clauses[] = 'title LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
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

		if ( ! empty( $args['type'] ) ) {
			$clauses[] = 'type = %s';
			$values[]  = (string) $args['type'];
		}

		if ( ! empty( $args['type__in'] ) && is_array( $args['type__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['type__in'] ), '%s' ) );
			$clauses[]    = "type IN ( {$placeholders} )";
			$values       = array_merge( $values, array_map( 'strval', $args['type__in'] ) );
		}

		if ( isset( $args['show_in_listings'] ) ) {
			$clauses[] = 'show_in_listings = %d';
			$values[]  = (int) $args['show_in_listings'];
		}

		return [ $clauses ? implode( ' AND ', $clauses ) : '1 = 1', $values ];
	}

	/**
	 * Get IDs of scheduled campaigns whose start date has arrived.
	 *
	 * @param string $today Today's date (Y-m-d).
	 * @return int[]
	 */
	public function find_ids_to_activate( string $today ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = \'scheduled\' AND date_start IS NOT NULL AND date_start <= %s',
				$this->get_table_name(),
				$today
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Get IDs of active campaigns whose end date has passed.
	 *
	 * @param string $today Today's date (Y-m-d).
	 * @return int[]
	 */
	public function find_ids_to_end( string $today ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = \'active\' AND date_end IS NOT NULL AND date_end < %s',
				$this->get_table_name(),
				$today
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Count campaigns matching filters.
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
	 * Map a database row to a Campaign model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Campaign
	 */
	private function row_to_model( array $row ): Campaign {
		return new Campaign( $row );
	}

	/**
	 * Map a Campaign model to a database row array.
	 *
	 * @param Campaign $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Campaign $model ): array {
		return [
			'id'                     => $model->id,
			'post_id'                => $model->post_id,
			'title'                  => $model->title,
			'description'            => $model->description,
			'goal_amount'            => $model->goal_amount,
			'goal_type'              => $model->goal_type,
			'type'                   => $model->type,
			'total_raised'           => $model->total_raised,
			'transaction_count'      => $model->transaction_count,
			'donor_count'            => $model->donor_count,
			'test_total_raised'      => $model->test_total_raised,
			'test_transaction_count' => $model->test_transaction_count,
			'test_donor_count'       => $model->test_donor_count,
			'currency'               => $model->currency,
			'show_in_listings'       => (int) $model->show_in_listings,
			'status'                 => $model->status,
			'date_start'             => $model->date_start,
			'date_end'               => $model->date_end,
			'date_created'           => $model->date_created,
			'date_modified'          => $model->date_modified,
		];
	}
}
