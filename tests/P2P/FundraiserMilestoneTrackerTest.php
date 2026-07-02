<?php
/**
 * Tests for the FundraiserMilestoneTracker class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Transaction;
use MissionDP\P2P\FundraiserMilestoneTracker;
use MissionDP\P2P\FundraiserPostType;
use WP_UnitTestCase;

/**
 * FundraiserMilestoneTracker test class.
 */
class FundraiserMilestoneTrackerTest extends WP_UnitTestCase {

	private FundraiserMilestoneTracker $tracker;

	/**
	 * Milestone IDs captured from the fired action.
	 *
	 * @var string[]
	 */
	private array $fired = [];

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		( new FundraiserPostType() )->register();

		$this->tracker = new FundraiserMilestoneTracker();
		$this->fired   = [];

		add_action(
			'mission_fundraiser_milestone_reached',
			function ( $fundraiser, $milestone_id ) {
				$this->fired[] = $milestone_id;
			},
			10,
			2
		);
	}

	/**
	 * Create a fundraiser with a given raised total.
	 *
	 * @param int  $goal    Goal in minor units.
	 * @param int  $raised  Raised total in minor units.
	 * @param bool $is_test Whether to set the test mirror.
	 * @return Fundraiser
	 */
	private function create_fundraiser( int $goal, int $raised, bool $is_test = false ): Fundraiser {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => Campaign::TYPE_P2P ] );
		$campaign->save();

		$donor = new Donor( [ 'email' => uniqid( 'f', true ) . '@example.com', 'first_name' => 'Jane' ] );
		$donor->save();

		$fundraiser = new Fundraiser(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
				'goal'        => $goal,
			]
		);
		$fundraiser->save();

		if ( $is_test ) {
			$fundraiser->test_total_raised = $raised;
		} else {
			$fundraiser->total_raised = $raised;
		}
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Crossing thresholds fires an action for each newly-reached milestone.
	 */
	public function test_fires_for_newly_reached_thresholds(): void {
		$fundraiser = $this->create_fundraiser( 10000, 5000 );

		$this->tracker->recompile( $fundraiser->id, false );

		$this->assertSame( [ '25-pct', '50-pct' ], $this->fired );
	}

	/**
	 * Milestones never resend on a later recompile.
	 */
	public function test_does_not_resend_reached_milestones(): void {
		$fundraiser = $this->create_fundraiser( 10000, 5000 );

		$this->tracker->recompile( $fundraiser->id, false );
		$this->fired = [];

		// Raise more, crossing 75% — only the new milestone should fire.
		$fundraiser->total_raised = 8000;
		$fundraiser->save();
		$this->tracker->recompile( $fundraiser->id, false );

		$this->assertSame( [ '75-pct' ], $this->fired );
	}

	/**
	 * A fundraiser with no goal never fires milestones.
	 */
	public function test_no_goal_no_milestones(): void {
		$fundraiser = $this->create_fundraiser( 0, 5000 );

		$this->tracker->recompile( $fundraiser->id, false );

		$this->assertSame( [], $this->fired );
	}

	/**
	 * Test-mode progress is tracked separately from live progress.
	 */
	public function test_mode_tracked_separately(): void {
		$fundraiser = $this->create_fundraiser( 10000, 10000, true );

		$this->tracker->recompile( $fundraiser->id, true );

		$this->assertSame( [ '25-pct', '50-pct', '75-pct', '100-pct' ], $this->fired );

		// Live progress is still zero, so a live recompile fires nothing.
		$this->fired = [];
		$this->tracker->recompile( $fundraiser->id, false );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * Saving a real donation fires milestones through the wired hooks, not just
	 * via a direct recompile() call.
	 */
	public function test_transaction_save_fires_milestones_through_hooks(): void {
		$this->tracker->init();
		$fundraiser = $this->create_fundraiser( 10000, 0 );

		( new Transaction( [
			'status'        => Transaction::STATUS_COMPLETED,
			'donor_id'      => 1,
			'fundraiser_id' => $fundraiser->id,
			'campaign_id'   => $fundraiser->campaign_id,
			'amount'        => 5000,
		] ) )->save();

		$this->assertContains( '25-pct', $this->fired );
		$this->assertContains( '50-pct', $this->fired );
	}

	/**
	 * A test-mode donation tracks milestones in the test meta arm, leaving the
	 * live arm untouched.
	 */
	public function test_test_transaction_tracks_test_milestones_through_hooks(): void {
		$this->tracker->init();
		$fundraiser = $this->create_fundraiser( 10000, 0 );

		( new Transaction( [
			'status'        => Transaction::STATUS_COMPLETED,
			'donor_id'      => 1,
			'fundraiser_id' => $fundraiser->id,
			'campaign_id'   => $fundraiser->campaign_id,
			'amount'        => 10000,
			'is_test'       => true,
		] ) )->save();

		$this->assertContains( '100-pct', (array) $fundraiser->get_meta( 'test_milestones_reached' ) );
		$this->assertEmpty( $fundraiser->get_meta( 'milestones_reached' ) );
	}
}
