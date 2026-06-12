<?php
/**
 * Tracks donors/campaigns touched by an import for aggregate recompute.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Imports write rows silently (no per-row aggregate updates), so the donor and
 * campaign IDs they touch accumulate in a per-job transient and aggregates are
 * recomputed once at the end of the job.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class TouchedEntityTracker {

	/**
	 * Accumulate distinct touched IDs in a per-job transient.
	 *
	 * @param string $job_id Public job token.
	 * @param string $bucket 'donors' or 'campaigns'.
	 * @param int    $id     Donor or campaign ID.
	 */
	public function track( string $job_id, string $bucket, int $id ): void {
		if ( $id <= 0 || ! in_array( $bucket, [ 'donors', 'campaigns' ], true ) ) {
			return;
		}

		$key      = $this->transient_key( $job_id );
		$existing = get_transient( $key );

		if ( ! is_array( $existing ) ) {
			$existing = [
				'donors'    => [],
				'campaigns' => [],
			];
		}

		$existing[ $bucket ][] = $id;
		$existing[ $bucket ]   = array_values( array_unique( $existing[ $bucket ] ) );

		set_transient( $key, $existing, DAY_IN_SECONDS );
	}

	/**
	 * Mark a transaction's donor and campaign as touched if the row was committed
	 * with completed status. Touched entities get a recompute at job end.
	 *
	 * @param Transaction $transaction Inserted/updated transaction.
	 * @param string      $job_id      Public job token.
	 */
	public function mark_from_transaction( Transaction $transaction, string $job_id ): void {
		if ( Transaction::STATUS_COMPLETED !== $transaction->status ) {
			// We still want refunds tracked, since the refund path can change donor totals.
			if ( Transaction::STATUS_REFUNDED !== $transaction->status ) {
				return;
			}
		}

		if ( $transaction->donor_id ) {
			$this->track( $job_id, 'donors', $transaction->donor_id );
		}

		if ( $transaction->campaign_id ) {
			$this->track( $job_id, 'campaigns', (int) $transaction->campaign_id );
		}
	}

	/**
	 * Rebuild aggregates for all donors and campaigns touched by a job, then
	 * clear the tracking transient.
	 *
	 * Called when the job transitions to completed, before mark_completed().
	 * Idempotent — running it twice produces the same result.
	 *
	 * @param string $job_id Public job token.
	 */
	public function recompute_and_clear( string $job_id ): void {
		$key     = $this->transient_key( $job_id );
		$touched = get_transient( $key );

		if ( ! is_array( $touched ) ) {
			return;
		}

		foreach ( $touched['donors'] ?? [] as $donor_id ) {
			Donor::recompute_aggregates( (int) $donor_id );
		}

		foreach ( $touched['campaigns'] ?? [] as $campaign_id ) {
			Campaign::recompute_aggregates( (int) $campaign_id );
		}

		delete_transient( $key );
	}

	/**
	 * Per-job transient key.
	 *
	 * @param string $job_id Public job token.
	 */
	private function transient_key( string $job_id ): string {
		return "mission_import_touched_{$job_id}";
	}
}
