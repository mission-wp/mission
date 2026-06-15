<?php
/**
 * Tests for donation attribution, fundraiser recompute, and P2P reporting.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use MissionDP\Reporting\ReportingService;
use MissionDP\Rest\DonationAttribution;
use WP_UnitTestCase;

/**
 * Attribution / recompute / reporting test class.
 */
class AttributionTest extends WP_UnitTestCase {

	/**
	 * Recreate the P2P tables so they carry the current schema.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		foreach ( [ 'fundraisers', 'fundraisermeta', 'teams', 'teammeta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_{$table}" ); // phpcs:ignore WordPress.DB
		}

		DatabaseModule::create_tables();
	}

	/**
	 * Use live mode so reporting reads the same columns the live transactions write.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * Create a fundraiser.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param int   $donor_id    Donor ID.
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Fundraiser
	 */
	private function make_fundraiser( int $campaign_id, int $donor_id, array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser( array_merge(
			[ 'campaign_id' => $campaign_id, 'donor_id' => $donor_id, 'status' => 'active' ],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Create a completed transaction.
	 *
	 * @param array<string, mixed> $data Transaction data.
	 * @return Transaction
	 */
	private function make_completed( array $data ): Transaction {
		$transaction = new Transaction( array_merge(
			[ 'status' => Transaction::STATUS_COMPLETED, 'donor_id' => 1, 'amount' => 10000 ],
			$data
		) );
		$transaction->save();

		return $transaction;
	}

	// -------------------------------------------------------------------------
	// Recompute via transaction lifecycle.
	// -------------------------------------------------------------------------

	/**
	 * Test a completed donation raises the fundraiser's stored total.
	 */
	public function test_completed_donation_updates_fundraiser_total(): void {
		$fundraiser = $this->make_fundraiser( 1, 1 );

		$this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] );

		$this->assertSame( 5000, $fundraiser->fresh()->total_raised );
		$this->assertSame( 1, $fundraiser->fresh()->transaction_count );
	}

	/**
	 * Test a pending-then-completed donation updates the total on completion.
	 */
	public function test_status_to_completed_updates_total(): void {
		$fundraiser = $this->make_fundraiser( 1, 1 );

		$transaction = new Transaction( [
			'status'        => Transaction::STATUS_PENDING,
			'donor_id'      => 1,
			'fundraiser_id' => $fundraiser->id,
			'amount'        => 7500,
		] );
		$transaction->save();
		$this->assertSame( 0, $fundraiser->fresh()->total_raised );

		$transaction->status = Transaction::STATUS_COMPLETED;
		$transaction->save();

		$this->assertSame( 7500, $fundraiser->fresh()->total_raised );
	}

	/**
	 * Test a partial refund nets the fundraiser's total.
	 */
	public function test_partial_refund_nets_fundraiser_total(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 10000 ] );
		$this->assertSame( 10000, $fundraiser->fresh()->total_raised );

		$transaction->amount_refunded = 3000;
		$transaction->save();

		// Counts unchanged, dollars netted.
		$this->assertSame( 7000, $fundraiser->fresh()->total_raised );
		$this->assertSame( 1, $fundraiser->fresh()->transaction_count );
	}

	/**
	 * Test a full refund (status change) zeroes the fundraiser's total.
	 */
	public function test_refund_status_zeroes_fundraiser_total(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 10000 ] );

		$transaction->amount_refunded = 10000;
		$transaction->status          = Transaction::STATUS_REFUNDED;
		$transaction->save();

		$this->assertSame( 0, $fundraiser->fresh()->total_raised );
		$this->assertSame( 0, $fundraiser->fresh()->transaction_count );
	}

	/**
	 * Test deleting a completed donation updates the fundraiser's total.
	 */
	public function test_delete_donation_updates_fundraiser_total(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 4000 ] );
		$this->assertSame( 4000, $fundraiser->fresh()->total_raised );

		$transaction->delete();

		$this->assertSame( 0, $fundraiser->fresh()->total_raised );
	}

	// -------------------------------------------------------------------------
	// Team rollups (live, no stored aggregates).
	// -------------------------------------------------------------------------

	/**
	 * Test a member's donation rolls up into the team total.
	 */
	public function test_member_donation_rolls_up_to_team(): void {
		$team   = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$member = $this->make_fundraiser( 1, 1, [ 'team_id' => $team->id ] );

		$this->make_completed( [ 'fundraiser_id' => $member->id, 'amount' => 6000 ] );

		$totals = ( new ReportingService() )->team_totals( $team->id );
		$this->assertSame( 6000, $totals['raised'] );
		$this->assertSame( 1, $totals['member_count'] );
	}

	/**
	 * Test a direct-to-team gift counts in the team total alongside members.
	 */
	public function test_direct_team_gift_counts_in_total(): void {
		$team   = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$member = $this->make_fundraiser( 1, 1, [ 'team_id' => $team->id ] );

		$this->make_completed( [ 'fundraiser_id' => $member->id, 'amount' => 6000 ] );
		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 2, 'amount' => 2500 ] );

		$totals = ( new ReportingService() )->team_totals( $team->id );
		$this->assertSame( 8500, $totals['raised'] );
		$this->assertSame( 2, $totals['donations'] );
	}

	// -------------------------------------------------------------------------
	// DonationAttribution resolver.
	// -------------------------------------------------------------------------

	/**
	 * Test a fundraiser ID derives the campaign and drops any team.
	 */
	public function test_resolve_fundraiser_derives_campaign(): void {
		$campaign   = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$fundraiser = $this->make_fundraiser( $campaign->id, 1 );

		$result = DonationAttribution::resolve( 0, $fundraiser->id, 999 );

		$this->assertSame( $campaign->id, $result['campaign_id'] );
		$this->assertSame( $fundraiser->id, $result['fundraiser_id'] );
		$this->assertNull( $result['team_id'] );
	}

	/**
	 * Test a team ID derives the campaign.
	 */
	public function test_resolve_team_derives_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();

		$result = DonationAttribution::resolve( 0, 0, $team->id );

		$this->assertSame( $campaign->id, $result['campaign_id'] );
		$this->assertSame( $team->id, $result['team_id'] );
		$this->assertNull( $result['fundraiser_id'] );
	}

	/**
	 * Test an unknown fundraiser falls back to a plain campaign donation.
	 */
	public function test_resolve_unknown_fundraiser_falls_back_to_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();

		$result = DonationAttribution::resolve( $campaign->id, 999, 0 );

		$this->assertSame( $campaign->id, $result['campaign_id'] );
		$this->assertNull( $result['fundraiser_id'] );
		$this->assertNull( $result['team_id'] );
	}

	// -------------------------------------------------------------------------
	// Leaderboard reporting.
	// -------------------------------------------------------------------------

	/**
	 * Test top_fundraisers orders by amount raised.
	 */
	public function test_top_fundraisers_orders_by_raised(): void {
		$low  = $this->make_fundraiser( 1, 1 );
		$high = $this->make_fundraiser( 1, 2 );

		$this->make_completed( [ 'fundraiser_id' => $low->id, 'amount' => 1000 ] );
		$this->make_completed( [ 'fundraiser_id' => $high->id, 'donor_id' => 2, 'amount' => 9000 ] );

		$top = ( new ReportingService() )->top_fundraisers( 1, 10 );

		$this->assertCount( 2, $top );
		$this->assertSame( $high->id, $top[0]['id'] );
		$this->assertSame( 9000, $top[0]['raised'] );
	}

	/**
	 * Test team_members lists members by raised with the captain flag.
	 */
	public function test_team_members_lists_by_raised(): void {
		$team    = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$captain = $this->make_fundraiser( 1, 1, [ 'team_id' => $team->id, 'is_team_captain' => true ] );
		$member  = $this->make_fundraiser( 1, 2, [ 'team_id' => $team->id ] );

		$this->make_completed( [ 'fundraiser_id' => $member->id, 'donor_id' => 2, 'amount' => 8000 ] );
		$this->make_completed( [ 'fundraiser_id' => $captain->id, 'amount' => 2000 ] );

		$members = ( new ReportingService() )->team_members( $team->id );

		$this->assertSame( $member->id, $members[0]['id'] );
		$this->assertTrue( $members[1]['is_captain'] );
	}
}
