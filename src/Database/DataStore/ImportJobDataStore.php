<?php
/**
 * ImportJob DataStore.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

use MissionDP\Models\ImportJob;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the import_jobs table.
 */
class ImportJobDataStore implements DataStoreInterface {

	/**
	 * Get the fully-prefixed table name.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'missiondp_import_jobs';
	}

	/**
	 * Create an import job row.
	 *
	 * @param object $model ImportJob model.
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
	 * Read an import job by ID.
	 *
	 * @param int $id Row ID.
	 */
	public function read( int $id ): ?ImportJob {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->get_table_name(), $id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find by the public job_id token.
	 *
	 * @param string $job_id Token.
	 */
	public function find_by_job_id( string $job_id ): ?ImportJob {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %s', $this->get_table_name(), $job_id ),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Find the current queued/processing job for a user and type.
	 *
	 * @param int    $user_id WP user ID.
	 * @param string $type    Data type.
	 */
	public function find_active_for_user( int $user_id, string $type ): ?ImportJob {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND type = %s AND status IN ( %s, %s ) ORDER BY id DESC LIMIT 1',
				$this->get_table_name(),
				$user_id,
				$type,
				ImportJob::STATUS_QUEUED,
				ImportJob::STATUS_PROCESSING
			),
			ARRAY_A
		);

		return $row ? $this->row_to_model( $row ) : null;
	}

	/**
	 * Update an import job.
	 *
	 * @param object $model ImportJob.
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
	 * Update a single column on a job row. Used for narrow writes (error_details append).
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

		$allowed = [ 'imported', 'skipped', 'updated', 'errors', 'processed_rows' ];
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
	 * Delete an import job row.
	 *
	 * @param int $id Row ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->get_table_name(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Query import jobs.
	 *
	 * Supported args: user_id, type, status (string|array), per_page, page,
	 * orderby (id|date_created), order (ASC|DESC), older_than (datetime string).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return ImportJob[]
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		[ $where_sql, $where_params ] = $this->build_where( $args );

		$allowed_orderby = [ 'id', 'date_created' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$direction       = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) ( $args['per_page'] ?? 100 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = 'SELECT * FROM %i' . $where_sql . " ORDER BY %i {$direction} LIMIT %d OFFSET %d";

		$prepare_args = array_merge(
			[ $this->get_table_name() ],
			$where_params,
			[ $orderby, $per_page, $offset ]
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- direction is whitelisted, all values use placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		return array_map( [ $this, 'row_to_model' ], $rows ?: [] );
	}

	/**
	 * Count import jobs matching filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
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

		if ( isset( $args['user_id'] ) ) {
			$conds[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['type'] ) ) {
			$conds[]  = 'type = %s';
			$params[] = (string) $args['type'];
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
	private function row_to_model( array $row ): ImportJob {
		return new ImportJob( $row );
	}

	/**
	 * Map a model to a DB row array.
	 *
	 * @param ImportJob $model Model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( ImportJob $model ): array {
		return [
			'id'                 => $model->id,
			'job_id'             => $model->job_id,
			'user_id'            => $model->user_id,
			'type'               => $model->type,
			'duplicate_strategy' => $model->duplicate_strategy,
			'status'             => $model->status,
			'file_path'          => $model->file_path,
			'original_filename'  => $model->original_filename,
			'total_rows'         => $model->total_rows,
			'processed_rows'     => $model->processed_rows,
			'imported'           => $model->imported,
			'skipped'            => $model->skipped,
			'updated'            => $model->updated,
			'errors'             => $model->errors,
			'error_details'      => $model->error_details,
			'last_error'         => $model->last_error,
			'started_at'         => $model->started_at,
			'completed_at'       => $model->completed_at,
			'date_created'       => $model->date_created,
		];
	}
}
