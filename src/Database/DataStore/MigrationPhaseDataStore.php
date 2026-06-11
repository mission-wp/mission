<?php
/**
 * MigrationPhase DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\MigrationPhase;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the migration_phases table.
 */
class MigrationPhaseDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_migration_phases';
	}

	/**
	 * Create a migration phase row.
	 *
	 * @param object $model MigrationPhase model.
	 *
	 * @return int New ID.
	 */
	public function create( object $model ): int {
		global $wpdb;

		$data                 = $this->model_to_row( $model );
		$data['date_created'] = $data['date_created'] ?: current_time( 'mysql', true );
		unset( $data['id'] );

		$wpdb->insert( $this->get_table_name(), $data );
		$model->id = (int) $wpdb->insert_id;

		return $model->id;
	}

	/**
	 * Read a migration phase by ID.
	 *
	 * @param int $id Row ID.
	 */
	public function read( int $id ): ?MigrationPhase {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Get all phase rows for a job, ordered by phase_order.
	 *
	 * @param string $job_id Public job token.
	 *
	 * @return MigrationPhase[]
	 */
	public function find_for_job( string $job_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %s ORDER BY phase_order ASC',
				$this->get_table_name(),
				$job_id
			),
			ARRAY_A
		);

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Find the job_id of the currently active (queued or processing) run.
	 */
	public function find_active_job_id(): ?string {
		global $wpdb;

		$job_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT job_id FROM %i WHERE status IN ( %s, %s ) ORDER BY id DESC LIMIT 1',
				$this->get_table_name(),
				MigrationPhase::STATUS_QUEUED,
				MigrationPhase::STATUS_PROCESSING
			)
		);

		return $job_id ?: null;
	}

	/**
	 * Find the job_id of the most recently created run.
	 */
	public function find_latest_job_id(): ?string {
		global $wpdb;

		$job_id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT job_id FROM %i ORDER BY id DESC LIMIT 1', $this->get_table_name() )
		);

		return $job_id ?: null;
	}

	/**
	 * Update a migration phase.
	 *
	 * @param object $model MigrationPhase.
	 */
	public function update( object $model ): bool {
		global $wpdb;

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
	 * Update a single column on a phase row. Used for narrow writes (error_details append).
	 *
	 * @param int    $id    Row ID.
	 * @param string $field Column name (must be in the allowlist).
	 * @param mixed  $value New value.
	 */
	public function update_field( int $id, string $field, mixed $value ): bool {
		global $wpdb;

		$allowed = [ 'error_details', 'last_error', 'status', 'started_at', 'completed_at' ];

		if ( ! in_array( $field, $allowed, true ) ) {
			return false;
		}

		$result = $wpdb->update(
			$this->get_table_name(),
			[ $field => $value ],
			[ 'id' => $id ],
			null,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Atomically increment counters with a single UPDATE so concurrent workers
	 * can never clobber each other.
	 *
	 * @param int                $id     Row ID.
	 * @param array<string, int> $deltas Map of column => positive delta. Only counter columns are honored.
	 */
	public function increment_counts( int $id, array $deltas ): void {
		global $wpdb;

		$allowed = [ 'imported', 'skipped', 'errors', 'processed_items' ];
		$parts   = [];
		$values  = [];

		foreach ( $allowed as $col ) {
			if ( ! isset( $deltas[ $col ] ) ) {
				continue;
			}

			$delta = (int) $deltas[ $col ];

			if ( 0 === $delta ) {
				continue;
			}

			$parts[]  = "{$col} = {$col} + %d";
			$values[] = $delta;
		}

		if ( empty( $parts ) ) {
			return;
		}

		$sql = 'UPDATE %i SET ' . implode( ', ', $parts ) . ' WHERE id = %d';

		$prepare_args = array_merge( [ $this->get_table_name() ], $values, [ $id ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $parts uses only allowlisted column names.
		$wpdb->query( $wpdb->prepare( $sql, $prepare_args ) );
	}

	/**
	 * Advance the source cursor, guarded so it can only move forward. A stale
	 * Action Scheduler tick re-running an already-processed batch becomes a no-op.
	 *
	 * @param int $id        Row ID.
	 * @param int $source_id Highest source ID processed in the batch.
	 */
	public function advance_cursor( int $id, int $source_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_source_id = %d WHERE id = %d AND last_source_id < %d',
				$this->get_table_name(),
				$source_id,
				$id,
				$source_id
			)
		);
	}

	/**
	 * Delete a migration phase row.
	 *
	 * @param int $id Row ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Delete all phase rows for a job.
	 *
	 * @param string $job_id Public job token.
	 */
	public function delete_for_job( string $job_id ): void {
		global $wpdb;

		$wpdb->delete( $this->get_table_name(), [ 'job_id' => $job_id ], [ '%s' ] );
	}

	/**
	 * Delete terminal phase rows older than a cutoff. Used by the daily cleanup.
	 *
	 * @param string $older_than MySQL datetime cutoff (UTC).
	 *
	 * @return int Rows deleted.
	 */
	public function prune_terminal( string $older_than ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status IN ( %s, %s, %s ) AND date_created < %s',
				$this->get_table_name(),
				MigrationPhase::STATUS_COMPLETED,
				MigrationPhase::STATUS_FAILED,
				MigrationPhase::STATUS_CANCELLED,
				$older_than
			)
		);
	}

	/**
	 * Query migration phases.
	 *
	 * Supported args: job_id, status (string|array), older_than (datetime string),
	 * per_page, page, order (ASC|DESC).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return MigrationPhase[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where_sql, $where_params ] = $this->build_where( $args );

		$direction = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? 100 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = 'SELECT * FROM %i' . $where_sql . " ORDER BY id {$direction} LIMIT %d OFFSET %d";

		$prepare_args = array_merge( [ $this->get_table_name() ], $where_params, [ $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- direction is whitelisted, all values use placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count migration phases matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments (same as query()).
	 */
	public function count( array $args = [] ): int {
		global $wpdb;

		[ $where_sql, $where_params ] = $this->build_where( $args );

		$sql          = 'SELECT COUNT(*) FROM %i' . $where_sql;
		$prepare_args = array_merge( [ $this->get_table_name() ], $where_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values use placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $prepare_args ) );
	}

	/**
	 * Build the WHERE clause for query/count.
	 *
	 * @param array<string, mixed> $args Args.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where( array $args ): array {
		$conds  = [];
		$params = [];

		if ( ! empty( $args['job_id'] ) ) {
			$conds[]  = 'job_id = %s';
			$params[] = (string) $args['job_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses     = (array) $args['status'];
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$conds[]      = "status IN ( {$placeholders} )";
			foreach ( $statuses as $s ) {
				$params[] = (string) $s;
			}
		}

		if ( ! empty( $args['older_than'] ) ) {
			$conds[]  = 'date_created < %s';
			$params[] = (string) $args['older_than'];
		}

		$where_sql = $conds ? ' WHERE ' . implode( ' AND ', $conds ) : '';

		return [ $where_sql, $params ];
	}

	/**
	 * Map a DB row to a model.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function row_to_model( array $row ): MigrationPhase {
		return new MigrationPhase( $row );
	}

	/**
	 * Map a model to a DB row array.
	 *
	 * @param MigrationPhase $model Model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( MigrationPhase $model ): array {
		return [
			'id'              => $model->id,
			'job_id'          => $model->job_id,
			'user_id'         => $model->user_id,
			'source'          => $model->source,
			'job_type'        => $model->job_type,
			'entity'          => $model->entity,
			'phase_order'     => $model->phase_order,
			'status'          => $model->status,
			'options'         => $model->options,
			'total_items'     => $model->total_items,
			'processed_items' => $model->processed_items,
			'imported'        => $model->imported,
			'skipped'         => $model->skipped,
			'errors'          => $model->errors,
			'error_details'   => $model->error_details,
			'last_error'      => $model->last_error,
			'last_source_id'  => $model->last_source_id,
			'started_at'      => $model->started_at,
			'completed_at'    => $model->completed_at,
			'date_created'    => $model->date_created,
		];
	}
}
