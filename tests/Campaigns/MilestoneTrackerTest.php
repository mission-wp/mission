<?php
/**
 * Tests for the MilestoneTracker class.
 *
 * @package Mission
 */

namespace Mission\Tests\Campaigns;

use Mission\Campaigns\CampaignPostType;
use Mission\Campaigns\MilestoneTracker;
use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use WP_UnitTestCase;

/**
 * MilestoneTracker test class.
 */
class MilestoneTrackerTest extends WP_UnitTestCase {

	private MilestoneTracker $tracker;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$cpt = new CampaignPostType();
		$cpt->register();

		$this->tracker = new MilestoneTracker();
		$this->tracker->init();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a donor.
	 *
	 * @return Donor
	 */
	private function create_donor(): Donor {
		$donor = new Donor( [
			'email'      => 'milestone-test@example.com',
			'first_name' => 'Milestone',
			'last_name'  => 'Tester',
		] );
		$donor->save();

		return $donor;
	}

	/**
	 * Create and save a completed transaction.
	 *
	 * @param int    $donor_id       Donor ID.
	 * @param int    $campaign_id    Campaign ID.
	 * @param int    $amount         Amount in minor units.
	 * @param string $date_completed Date completed (MySQL datetime).
	 * @return Transaction
	 */
	private function create_completed_transaction( int $donor_id, int $campaign_id, int $amount, string $date_completed ): Transaction {
		$transaction = new Transaction( [
			'status'         => 'completed',
			'donor_id'       => $donor_id,
			'campaign_id'    => $campaign_id,
			'amount'         => $amount,
			'total_amount'   => $amount,
			'source_post_id' => 1,
			'date_completed' => $date_completed,
		] );
		$transaction->save();

		return $transaction;
	}

	// -------------------------------------------------------------------------
	// Existing tests.
	// -------------------------------------------------------------------------

	/**
	 * Test that creating a campaign with a goal via create_from_input
	 * produces milestones including percentage thresholds.
	 */
	public function test_campaign_with_goal_includes_percentage_milestones(): void {
		$campaign = new Campaign(
			[
				'title'       => 'Test Campaign',
				'description' => 'A test campaign',
				'goal_amount' => 100000,
			]
		);
		$campaign->save();

		$this->assertNotNull( $campaign->id, 'Campaign should be created.' );

		$milestones = $campaign->get_meta( 'milestones' );

		$this->assertIsArray( $milestones, 'Milestones should be an array.' );

		$ids = array_column( $milestones, 'id' );

		$this->assertContains( 'created', $ids, 'Should have "created" milestone.' );
		$this->assertContains( 'first-donation', $ids, 'Should have "first-donation" milestone.' );
		$this->assertContains( '25-pct', $ids, 'Should have 25% milestone.' );
		$this->assertContains( '50-pct', $ids, 'Should have 50% milestone.' );
		$this->assertContains( '75-pct', $ids, 'Should have 75% milestone.' );
		$this->assertContains( '100-pct', $ids, 'Should have 100% milestone.' );
	}

	/**
	 * Test that creating a campaign without a goal does not include percentage milestones.
	 */
	public function test_campaign_without_goal_excludes_percentage_milestones(): void {
		$campaign = new Campaign(
			[
				'title'       => 'No Goal Campaign',
				'description' => 'A campaign without a goal',
				'goal_amount' => 0,
			]
		);
		$campaign->save();

		$this->assertNotNull( $campaign->id );

		$milestones = $campaign->get_meta( 'milestones' );
		$ids        = array_column( $milestones, 'id' );

		$this->assertContains( 'created', $ids );
		$this->assertContains( 'first-donation', $ids );
		$this->assertNotContains( '25-pct', $ids, 'Should not have percentage milestones without a goal.' );
	}

	// -------------------------------------------------------------------------
	// Threshold crossing with real transactions.
	// -------------------------------------------------------------------------

	/**
	 * Test recompile marks percentage milestones reached with correct dates.
	 *
	 * Creates 4 transactions of 25000 each against a 100000 goal and verifies
	 * each threshold is crossed at the right time with the right date.
	 */
	public function test_recompile_marks_percentage_milestones_reached_with_correct_dates(): void {
		$campaign = new Campaign( [
			'title'       => 'Goal Campaign',
			'description' => 'Testing milestones.',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$donor = $this->create_donor();
		$dates = [
			'2025-01-10 10:00:00',
			'2025-02-15 12:00:00',
			'2025-03-20 14:00:00',
			'2025-04-25 16:00:00',
		];

		// Transaction 1: crosses 25%.
		$this->create_completed_transaction( $donor->id, $campaign->id, 25000, $dates[0] );
		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		$this->assertTrue( $by_id['created']['reached'], 'Created milestone should always be reached.' );
		$this->assertNotEmpty( $by_id['created']['date'], 'Created milestone should have a date.' );
		$this->assertTrue( $by_id['first-donation']['reached'] );
		$this->assertSame( $dates[0], $by_id['first-donation']['date'] );
		$this->assertTrue( $by_id['25-pct']['reached'] );
		$this->assertSame( $dates[0], $by_id['25-pct']['date'] );
		$this->assertFalse( $by_id['50-pct']['reached'] );

		// Transaction 2: crosses 50%.
		$this->create_completed_transaction( $donor->id, $campaign->id, 25000, $dates[1] );
		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		$this->assertTrue( $by_id['50-pct']['reached'] );
		$this->assertSame( $dates[1], $by_id['50-pct']['date'] );
		$this->assertFalse( $by_id['75-pct']['reached'] );

		// Transaction 3: crosses 75%.
		$this->create_completed_transaction( $donor->id, $campaign->id, 25000, $dates[2] );
		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		$this->assertTrue( $by_id['75-pct']['reached'] );
		$this->assertSame( $dates[2], $by_id['75-pct']['date'] );
		$this->assertFalse( $by_id['100-pct']['reached'] );

		// Transaction 4: crosses 100%.
		$this->create_completed_transaction( $donor->id, $campaign->id, 25000, $dates[3] );
		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		$this->assertTrue( $by_id['100-pct']['reached'] );
		$this->assertSame( $dates[3], $by_id['100-pct']['date'] );

		// First donation date should still be from the first transaction.
		$this->assertSame( $dates[0], $by_id['first-donation']['date'] );
	}

	/**
	 * Test campaign without goal marks first-donation after a real donation.
	 */
	public function test_campaign_without_goal_first_donation_marked_reached_after_donation(): void {
		$campaign = new Campaign( [
			'title'       => 'No Goal',
			'description' => 'No goal campaign.',
			'goal_amount' => 0,
		] );
		$campaign->save();

		// Before any transactions, first-donation should be unreached.
		$milestones = $campaign->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );
		$this->assertFalse( $by_id['first-donation']['reached'] );
		$this->assertCount( 2, $milestones, 'Only created and first-donation milestones.' );

		// Create a transaction.
		$donor          = $this->create_donor();
		$donation_date  = '2025-06-01 09:00:00';
		$this->create_completed_transaction( $donor->id, $campaign->id, 5000, $donation_date );

		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		$this->assertTrue( $by_id['first-donation']['reached'] );
		$this->assertSame( $donation_date, $by_id['first-donation']['date'] );

		// Still no percentage milestones.
		$ids = array_column( $milestones, 'id' );
		$this->assertNotContains( '25-pct', $ids );
		$this->assertNotContains( '50-pct', $ids );
		$this->assertNotContains( '75-pct', $ids );
		$this->assertNotContains( '100-pct', $ids );
	}

	/**
	 * Test milestones are not duplicated when recompile is called multiple times.
	 */
	public function test_milestones_not_duplicated_when_threshold_crossed_multiple_times(): void {
		$campaign = new Campaign( [
			'title'       => 'Dedup Campaign',
			'description' => 'Testing deduplication.',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$donor = $this->create_donor();

		// Two transactions that together cross 25%.
		$this->create_completed_transaction( $donor->id, $campaign->id, 15000, '2025-01-01 10:00:00' );
		$this->create_completed_transaction( $donor->id, $campaign->id, 15000, '2025-01-02 10:00:00' );

		// Manually recompile again to verify no duplication.
		$this->tracker->recompile( $campaign->id );

		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$ids        = array_column( $milestones, 'id' );

		// Each milestone ID should appear exactly once.
		$id_counts = array_count_values( $ids );
		foreach ( $id_counts as $id => $count ) {
			$this->assertSame( 1, $count, "Milestone '{$id}' should appear exactly once." );
		}

		// 25-pct date should be from the second transaction (when threshold was first crossed).
		$by_id = array_column( $milestones, null, 'id' );
		$this->assertTrue( $by_id['25-pct']['reached'] );
		$this->assertSame( '2025-01-02 10:00:00', $by_id['25-pct']['date'] );
	}

	/**
	 * Test milestone event fires action hook.
	 */
	public function test_milestone_event_fires_action_hook(): void {
		$campaign = new Campaign( [
			'post_id'     => self::factory()->post->create( [ 'post_type' => 'mission_campaign' ] ),
			'goal_amount' => 10000,
		] );
		$campaign->save();
		$this->tracker->on_campaign_created( $campaign );

		$donor = $this->create_donor();

		$fired = [];
		add_action( 'mission_campaign_milestone_reached', function ( $c, $id ) use ( &$fired ) {
			$fired[] = $id;
		}, 10, 2 );

		// Create a transaction that crosses 25%.
		$txn = new Transaction( [
			'donor_id'       => $donor->id,
			'campaign_id'    => $campaign->id,
			'amount'         => 3000,
			'status'         => 'completed',
			'date_completed' => '2025-01-01 10:00:00',
		] );
		$txn->save();

		$campaign->transaction_count = 1;
		$campaign->total_raised      = 3000;
		$campaign->save();

		$this->tracker->recompile( $campaign->id );

		$this->assertContains( 'first-donation', $fired );
		$this->assertContains( '25-pct', $fired );
		$this->assertNotContains( '100-pct', $fired );

		// Recompile again without changes — should not re-fire.
		$fired = [];
		$this->tracker->recompile( $campaign->id );
		$this->assertEmpty( $fired, 'Already-reached milestones should not fire again.' );
	}

	/**
	 * Test a single large transaction crosses all percentage thresholds.
	 */
	public function test_single_large_transaction_crosses_multiple_thresholds(): void {
		$campaign = new Campaign( [
			'title'       => 'Big Donation',
			'description' => 'One large donation.',
			'goal_amount' => 100000,
		] );
		$campaign->save();

		$donor = $this->create_donor();
		$date  = '2025-05-01 08:00:00';

		$this->create_completed_transaction( $donor->id, $campaign->id, 100000, $date );

		$milestones = Campaign::find( $campaign->id )->get_meta( 'milestones' );
		$by_id      = array_column( $milestones, null, 'id' );

		// All percentage milestones reached with the same date.
		foreach ( [ '25-pct', '50-pct', '75-pct', '100-pct' ] as $milestone_id ) {
			$this->assertTrue( $by_id[ $milestone_id ]['reached'], "{$milestone_id} should be reached." );
			$this->assertSame( $date, $by_id[ $milestone_id ]['date'], "{$milestone_id} should have the transaction date." );
		}

		// First donation also reached.
		$this->assertTrue( $by_id['first-donation']['reached'] );
		$this->assertSame( $date, $by_id['first-donation']['date'] );
	}

	/**
	 * Test recompile with a nonexistent campaign returns early without error.
	 */
	public function test_recompile_with_nonexistent_campaign_returns_early(): void {
		$this->tracker->recompile( 99999 );

		$this->assertNull( Campaign::find( 99999 ) );
	}
}
