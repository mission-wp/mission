<?php
/**
 * Tracks donors/campaigns touched by a migration for aggregate recompute.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Database\DataStore\CampaignDataStore;
use MissionDP\Database\DataStore\DonorDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Migration writes rows silently (no per-row aggregate updates), so the IDs
 * they touch accumulate in a per-job transient and are recomputed once at the
 * end. Same approach as ImportService's touched-entity tracking.
 */
class TouchedEntities {

	/**
	 * Constructor.
	 *
	 * @param string $job_id Public job token.
	 */
	public function __construct( private readonly string $job_id ) {}

	/**
	 * Accumulate distinct touched IDs for a bucket.
	 *
	 * @param string $bucket 'donors' or 'campaigns'.
	 * @param int[]  $ids    Touched IDs.
	 */
	public function track_many( string $bucket, array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) || ! in_array( $bucket, [ 'donors', 'campaigns' ], true ) ) {
			return;
		}

		$key      = $this->transient_key();
		$existing = get_transient( $key );

		if ( ! is_array( $existing ) ) {
			$existing = [
				'donors'    => [],
				'campaigns' => [],
			];
		}

		$existing[ $bucket ] = array_values( array_unique( array_merge( $existing[ $bucket ] ?? [], $ids ) ) );

		set_transient( $key, $existing, DAY_IN_SECONDS );
	}

	/**
	 * Rebuild aggregates for every touched donor and campaign, then clear.
	 *
	 * Idempotent — running it twice produces the same result.
	 */
	public function recompute_and_clear(): void {
		$touched = get_transient( $this->transient_key() );

		if ( ! is_array( $touched ) ) {
			return;
		}

		$donor_store    = new DonorDataStore();
		$campaign_store = new CampaignDataStore();

		foreach ( $touched['donors'] ?? [] as $donor_id ) {
			$donor_store->recompute_aggregates( (int) $donor_id );
		}

		foreach ( $touched['campaigns'] ?? [] as $campaign_id ) {
			$campaign_store->recompute_aggregates( (int) $campaign_id );
		}

		$this->clear();
	}

	/**
	 * Delete the tracking transient without recomputing.
	 */
	public function clear(): void {
		delete_transient( $this->transient_key() );
	}

	/**
	 * Per-job transient key (missiondp_ prefix keeps it inside the uninstall sweep).
	 */
	private function transient_key(): string {
		return 'missiondp_migration_touched_' . $this->job_id;
	}
}
