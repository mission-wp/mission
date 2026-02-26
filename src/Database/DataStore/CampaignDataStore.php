<?php
/**
 * Campaign DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the campaigns table.
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
		return $wpdb->prefix . 'mission_campaigns';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_campaign_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_foreign_key(): string {
		return 'campaign_id';
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
	 * Find a campaign by its slug.
	 *
	 * @param string $slug Campaign slug.
	 *
	 * @return Campaign|null
	 */
	public function find_by_slug( string $slug ): ?Campaign {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ),
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
			array( 'id' => $model->id ),
			null,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( $old->status !== $model->status ) {
			do_action( 'mission_campaign_status_transition', $model, $old->status, $model->status );
			do_action( "mission_campaign_status_{$old->status}_to_{$model->status}", $model );
		}

		return true;
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

		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE campaign_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Query campaigns.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Campaign[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'row_to_model' ), $rows ?: array() );
	}

	/**
	 * Count campaigns matching filters.
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

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR slug LIKE %s)';
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

		$allowed_orderby = array( 'id', 'date_created', 'date_modified', 'total_raised', 'donation_count', 'title' );
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
		return array(
			'id'             => $model->id,
			'status'         => $model->status,
			'title'          => $model->title,
			'slug'           => $model->slug,
			'description'    => $model->description,
			'goal_amount'    => $model->goal_amount,
			'total_raised'   => $model->total_raised,
			'donation_count' => $model->donation_count,
			'currency'       => $model->currency,
			'date_start'     => $model->date_start,
			'date_end'       => $model->date_end,
			'date_created'   => $model->date_created,
			'date_modified'  => $model->date_modified,
		);
	}
}
