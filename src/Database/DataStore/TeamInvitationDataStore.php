<?php
/**
 * Team Invitation DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\TeamInvitation;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the team_invitations table.
 *
 * Invitations have no meta and no date_modified column; a captain creates them
 * with a pending status and the system stamps sent_at once the email goes out.
 */
class TeamInvitationDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_team_invitations';
	}

	/**
	 * Create a team invitation.
	 *
	 * @param object $model TeamInvitation model.
	 *
	 * @return int New invitation ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->model_to_row( $model );

		$data['date_created'] = $data['date_created'] ?: $now;
		unset( $data['id'] );

		$wpdb->insert( $this->get_table_name(), $data );
		$model->id = (int) $wpdb->insert_id;

		/**
		 * Fires after a team invitation is created.
		 *
		 * @param TeamInvitation $model The invitation.
		 */
		do_action( 'mission_team_invitation_created', $model );

		return $model->id;
	}

	/**
	 * Read an invitation by ID.
	 *
	 * @param int $id Invitation ID.
	 *
	 * @return TeamInvitation|null
	 */
	public function read( int $id ): ?TeamInvitation {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update an invitation.
	 *
	 * @param object $model TeamInvitation model.
	 *
	 * @return bool
	 */
	public function update( object $model ): bool {
		global $wpdb;

		$old = $this->read( $model->id );
		if ( ! $old ) {
			return false;
		}

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
	 * Delete an invitation by ID.
	 *
	 * @param int $id Invitation ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query invitations.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return TeamInvitation[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->build_where_clause( $args );

		$allowed_orderby = [ 'id', 'email', 'status', 'date_created', 'sent_at' ];
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
	 * Count invitations matching filters.
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
		$clauses = [];
		$values  = [];

		if ( ! empty( $args['team_id'] ) ) {
			$clauses[] = 'team_id = %d';
			$values[]  = (int) $args['team_id'];
		}

		if ( ! empty( $args['email'] ) ) {
			$clauses[] = 'email = %s';
			$values[]  = (string) $args['email'];
		}

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = (string) $args['status'];
		}

		return [ $clauses ? implode( ' AND ', $clauses ) : '1 = 1', $values ];
	}

	/**
	 * Map a database row to a TeamInvitation model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return TeamInvitation
	 */
	private function row_to_model( array $row ): TeamInvitation {
		return new TeamInvitation( $row );
	}

	/**
	 * Map a TeamInvitation model to a database row array.
	 *
	 * @param TeamInvitation $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( TeamInvitation $model ): array {
		return [
			'id'           => $model->id,
			'team_id'      => $model->team_id,
			'email'        => $model->email,
			'status'       => $model->status,
			'date_created' => $model->date_created,
			'sent_at'      => $model->sent_at,
		];
	}
}
