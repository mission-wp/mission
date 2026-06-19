<?php
/**
 * Milestone tracker for peer-to-peer fundraisers.
 *
 * Fires a domain action the first time a fundraiser crosses 25/50/75/100% of
 * their personal goal. Fired milestones are stored in fundraiser meta (per
 * mode) so a milestone never emails twice.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Models\Fundraiser;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraiser milestone tracker class.
 */
class FundraiserMilestoneTracker {

	/**
	 * Percentage thresholds tracked for each fundraiser.
	 *
	 * @var int[]
	 */
	private const THRESHOLDS = [ 25, 50, 75, 100 ];

	/**
	 * Register event listeners.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'mission_fundraiser_aggregates_updated', [ $this, 'recompile' ], 10, 2 );
	}

	/**
	 * Fire milestone actions for thresholds newly crossed by a fundraiser.
	 *
	 * @param int  $fundraiser_id The fundraiser ID.
	 * @param bool $is_test       Whether the triggering donation is a test.
	 * @return void
	 */
	public function recompile( int $fundraiser_id, bool $is_test = false ): void {
		$fundraiser = Fundraiser::find( $fundraiser_id );

		if ( ! $fundraiser || $fundraiser->goal <= 0 ) {
			return;
		}

		$progress = $fundraiser->progress( $is_test );
		$meta_key = $is_test ? 'test_milestones_reached' : 'milestones_reached';
		$reached  = $fundraiser->get_meta( $meta_key );
		$reached  = is_array( $reached ) ? $reached : [];

		$newly = [];
		foreach ( self::THRESHOLDS as $pct ) {
			$id = "{$pct}-pct";
			if ( $progress >= $pct && ! in_array( $id, $reached, true ) ) {
				$reached[] = $id;
				$newly[]   = $id;
			}
		}

		if ( empty( $newly ) ) {
			return;
		}

		$fundraiser->update_meta( $meta_key, $reached );

		foreach ( $newly as $id ) {
			/**
			 * Fires when a fundraiser reaches a goal milestone for the first time.
			 *
			 * @param Fundraiser $fundraiser   The fundraiser.
			 * @param string     $milestone_id Milestone ID ('25-pct', '50-pct', '75-pct', '100-pct').
			 * @param bool        $is_test      Whether the triggering donation is a test.
			 */
			do_action( 'mission_fundraiser_milestone_reached', $fundraiser, $id, $is_test );
		}
	}
}
