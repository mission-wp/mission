<?php
/**
 * Donation DataStore.
 *
 * @package Mission
 */

namespace Mission\Database\DataStore;

use Mission\Models\Donation;

defined( 'ABSPATH' ) || exit;

/**
 * Handles CRUD operations for the donations table.
 */
class DonationDataStore implements DataStoreInterface {

	use MetaTrait;

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_donations';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mission_donation_meta';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_meta_foreign_key(): string {
		return 'donation_id';
	}

	/**
	 * Create a donation.
	 *
	 * @param object $model Donation model.
	 *
	 * @return int New donation ID.
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
		 * Fires after a donation is created.
		 *
		 * @param Donation $model The donation.
		 */
		do_action( 'mission_donation_created', $model );

		return $model->id;
	}

	/**
	 * Read a donation by ID.
	 *
	 * @param int $id Donation ID.
	 *
	 * @return Donation|null
	 */
	public function read( int $id ): ?Donation {
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
	 * Update a donation.
	 *
	 * @param object $model Donation model with updated values.
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

		// Fire status transition hooks and update aggregates.
		if ( $old->status !== $model->status ) {
			$this->handle_status_transition( $model, $old->status, $model->status );
		}

		return true;
	}

	/**
	 * Delete a donation by ID.
	 *
	 * @param int $id Donation ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// Delete associated meta first.
		$meta_table = $this->get_meta_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE donation_id = %d", $id ) );

		$result = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Query donations.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return Donation[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$sql = $this->build_query_sql( 'SELECT *', $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'row_to_model' ), $rows ?: array() );
	}

	/**
	 * Count donations matching filters.
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

		if ( ! empty( $args['donor_id'] ) ) {
			$where[]  = 'donor_id = %d';
			$values[] = $args['donor_id'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$values[] = $args['campaign_id'];
		}

		if ( ! empty( $args['subscription_id'] ) ) {
			$where[]  = 'subscription_id = %d';
			$values[] = $args['subscription_id'];
		}

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$values[] = $args['form_id'];
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

		$allowed_orderby = array( 'id', 'date_created', 'date_modified', 'total_amount', 'status' );
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
	 * Handle donation status transitions.
	 *
	 * @param Donation $donation   The donation.
	 * @param string   $old_status Previous status.
	 * @param string   $new_status New status.
	 */
	private function handle_status_transition( Donation $donation, string $old_status, string $new_status ): void {
		/**
		 * Fires on any donation status change.
		 *
		 * @param Donation $donation   The donation.
		 * @param string   $old_status Previous status.
		 * @param string   $new_status New status.
		 */
		do_action( 'mission_donation_status_transition', $donation, $old_status, $new_status );

		/**
		 * Fires on a specific donation status transition.
		 *
		 * @param Donation $donation The donation.
		 */
		do_action( "mission_donation_status_{$old_status}_to_{$new_status}", $donation );

		// Update donor and campaign aggregates.
		if ( 'completed' === $new_status ) {
			$this->increment_aggregates( $donation );
		} elseif ( 'completed' === $old_status && in_array( $new_status, array( 'refunded', 'cancelled', 'failed' ), true ) ) {
			$this->decrement_aggregates( $donation );
		}
	}

	/**
	 * Increment donor and campaign aggregates when a donation is completed.
	 *
	 * @param Donation $donation The completed donation.
	 */
	private function increment_aggregates( Donation $donation ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $donation->donor_id ) {
			$donor_table = $wpdb->prefix . 'mission_donors';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$donor_table}
					SET total_donated = total_donated + %d,
						total_tip = total_tip + %d,
						donation_count = donation_count + 1,
						first_donation = COALESCE(NULLIF(first_donation, '0000-00-00 00:00:00'), %s),
						last_donation = %s,
						date_modified = %s
					WHERE id = %d",
					$donation->amount,
					$donation->tip_amount,
					$now,
					$now,
					$now,
					$donation->donor_id
				)
			);
		}

		if ( $donation->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'mission_campaigns';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET total_raised = total_raised + %d,
						donation_count = donation_count + 1,
						date_modified = %s
					WHERE id = %d",
					$donation->amount,
					$now,
					$donation->campaign_id
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Decrement donor and campaign aggregates when a donation leaves completed status.
	 *
	 * @param Donation $donation The donation.
	 */
	private function decrement_aggregates( Donation $donation ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $donation->donor_id ) {
			$donor_table = $wpdb->prefix . 'mission_donors';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$donor_table}
					SET total_donated = GREATEST(0, total_donated - %d),
						total_tip = GREATEST(0, total_tip - %d),
						donation_count = GREATEST(0, donation_count - 1),
						date_modified = %s
					WHERE id = %d",
					$donation->amount,
					$donation->tip_amount,
					$now,
					$donation->donor_id
				)
			);
		}

		if ( $donation->campaign_id ) {
			$campaign_table = $wpdb->prefix . 'mission_campaigns';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$campaign_table}
					SET total_raised = GREATEST(0, total_raised - %d),
						donation_count = GREATEST(0, donation_count - 1),
						date_modified = %s
					WHERE id = %d",
					$donation->amount,
					$now,
					$donation->campaign_id
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Map a database row to a Donation model.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return Donation
	 */
	private function row_to_model( array $row ): Donation {
		return new Donation( $row );
	}

	/**
	 * Map a Donation model to a database row array.
	 *
	 * @param Donation $model The model.
	 *
	 * @return array<string, mixed>
	 */
	private function model_to_row( Donation $model ): array {
		return array(
			'id'                      => $model->id,
			'status'                  => $model->status,
			'type'                    => $model->type,
			'donor_id'                => $model->donor_id,
			'subscription_id'         => $model->subscription_id,
			'parent_id'               => $model->parent_id,
			'form_id'                 => $model->form_id,
			'campaign_id'             => $model->campaign_id,
			'amount'                  => $model->amount,
			'fee_amount'              => $model->fee_amount,
			'tip_amount'              => $model->tip_amount,
			'total_amount'            => $model->total_amount,
			'currency'                => $model->currency,
			'payment_gateway'         => $model->payment_gateway,
			'gateway_transaction_id'  => $model->gateway_transaction_id,
			'gateway_subscription_id' => $model->gateway_subscription_id,
			'is_anonymous'            => (int) $model->is_anonymous,
			'donor_ip'                => $model->donor_ip,
			'date_created'            => $model->date_created,
			'date_completed'          => $model->date_completed,
			'date_refunded'           => $model->date_refunded,
			'date_modified'           => $model->date_modified,
		);
	}
}
