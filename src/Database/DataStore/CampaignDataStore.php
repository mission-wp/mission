<?php
/**
 * Campaign DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

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
		do_action( 'missiondp_campaign_created', $model );

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
	 * Find a campaign by its associated post ID.
	 *
	 * @param int $post_id The WP post ID.
	 *
	 * @return Campaign|null
	 */
	public function find_by_post_id( int $post_id ): ?Campaign {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $this->row_to_model( $row ) : null;
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

		$sql = $this->build_query_sql( 'SELECT c.*', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
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

		unset( $args['per_page'], $args['page'], $args['orderby'], $args['order'] );
		$sql = $this->build_query_sql( 'SELECT COUNT(c.id)', $args );

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
		$where  = [];
		$values = [];

		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'c.post_id = %d';
			$values[] = $args['post_id'];
		}

		if ( ! empty( $args['date_after'] ) ) {
			$where[]  = 'c.date_created >= %s';
			$values[] = $args['date_after'];
		}

		if ( ! empty( $args['date_before'] ) ) {
			$where[]  = 'c.date_created <= %s';
			$values[] = $args['date_before'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'c.status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['status__in'] ), '%s' ) );
			$where[]      = "c.status IN ($placeholders)";
			array_push( $values, ...$args['status__in'] );
		}

		if ( ! empty( $args['id__in'] ) && is_array( $args['id__in'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['id__in'] ), '%d' ) );
			$where[]      = "c.id IN ($placeholders)";
			array_push( $values, ...$args['id__in'] );
		}

		if ( isset( $args['show_in_listings'] ) ) {
			$where[]  = 'c.show_in_listings = %d';
			$values[] = (int) $args['show_in_listings'];
		}

		// Search by campaign title (from campaigns table — no JOIN needed).
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'c.title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_orderby = [ 'id', 'title', 'status', 'date_created', 'date_modified', 'date_start', 'date_end', 'goal_amount', 'total_raised', 'transaction_count', 'donor_count', 'test_total_raised', 'test_transaction_count', 'test_donor_count' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'date_created';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "{$select} FROM {$table} c {$where_clause} ORDER BY c.{$orderby} {$order}";

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
