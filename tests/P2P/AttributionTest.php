<?php
/**
 * Tests for donation attribution, fundraiser recompute, and P2P reporting.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
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
	 * Test a completed donation that flips to failed decrements the totals.
	 */
	public function test_completed_to_failed_decrements_fundraiser_total(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 10000 ] );

		$this->assertSame( 10000, $fundraiser->fresh()->total_raised );
		$this->assertSame( 1, $fundraiser->fresh()->transaction_count );

		$transaction->status = Transaction::STATUS_FAILED;
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

	/**
	 * Test deleting a fundraiser detaches its donations and captaincy.
	 */
	public function test_delete_fundraiser_detaches_transactions_and_captaincy(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'campaign_id' => 1, 'amount' => 5000 ] );

		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'captain_id' => $fundraiser->id, 'status' => 'active' ] );
		$team->save();

		$post_id = $fundraiser->post_id;
		$fundraiser->delete();

		$this->assertNull( Fundraiser::find( $fundraiser->id ) );
		$this->assertNull( get_post( $post_id ) );

		// The donation stays a campaign donation; the team is left captainless.
		$fresh = Transaction::find( $transaction->id );
		$this->assertNull( $fresh->fundraiser_id );
		$this->assertSame( 1, $fresh->campaign_id );
		$this->assertNull( Team::find( $team->id )->captain_id );
	}

	/**
	 * Test re-crediting a donation rebuilds both fundraisers' totals.
	 */
	public function test_recredit_updates_both_fundraisers(): void {
		$original    = $this->make_fundraiser( 1, 1 );
		$replacement = $this->make_fundraiser( 1, 2 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $original->id, 'amount' => 5000 ] );
		$this->assertSame( 5000, $original->fresh()->total_raised );

		$transaction->fundraiser_id = $replacement->id;
		$transaction->save();

		$this->assertSame( 0, $original->fresh()->total_raised );
		$this->assertSame( 5000, $replacement->fresh()->total_raised );
		$this->assertSame( 1, $replacement->fresh()->transaction_count );
	}

	/**
	 * Test un-crediting a donation (back to the campaign) rebuilds the old total.
	 */
	public function test_uncredit_updates_old_fundraiser(): void {
		$fundraiser  = $this->make_fundraiser( 1, 1 );
		$transaction = $this->make_completed( [ 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] );
		$this->assertSame( 5000, $fundraiser->fresh()->total_raised );

		$transaction->fundraiser_id = null;
		$transaction->save();

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

	/**
	 * Test team_totals() excludes refunded and pending direct team gifts.
	 */
	public function test_team_totals_excludes_refunded_and_pending_direct_gifts(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();

		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 2, 'amount' => 2500 ] );

		// A fully refunded direct gift drops out of both raised and donations.
		$refunded = $this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 3, 'amount' => 4000 ] );
		$refunded->status          = Transaction::STATUS_REFUNDED;
		$refunded->amount_refunded = 4000;
		$refunded->save();

		// A pending direct gift never counts.
		$pending = new Transaction( [
			'status'   => Transaction::STATUS_PENDING,
			'donor_id' => 4,
			'team_id'  => $team->id,
			'amount'   => 999,
		] );
		$pending->save();

		$totals = ( new ReportingService() )->team_totals( $team->id );

		$this->assertSame( 2500, $totals['raised'] );
		$this->assertSame( 1, $totals['donations'] );
	}

	/**
	 * Test team_totals() is memoized per instance, with fresh instances re-querying.
	 */
	public function test_team_totals_memoized_per_instance(): void {
		global $wpdb;

		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 2, 'amount' => 2500 ] );

		$reporting = new ReportingService();
		$first     = $reporting->team_totals( $team->id );

		$before = $wpdb->num_queries;
		$second = $reporting->team_totals( $team->id );

		$this->assertSame( 0, $wpdb->num_queries - $before );
		$this->assertSame( $first, $second );

		// The memo is per instance, so a new one sees later donations.
		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 3, 'amount' => 1000 ] );

		$this->assertSame( 3500, ( new ReportingService() )->team_totals( $team->id )['raised'] );
	}

	/**
	 * Test the team_totals() memo is keyed by test mode.
	 */
	public function test_team_totals_memo_keyed_by_test_mode(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 2, 'amount' => 2500 ] );

		$reporting = new ReportingService();

		$this->assertSame( 2500, $reporting->team_totals( $team->id )['raised'] );

		update_option( 'missiondp_settings', [ 'test_mode' => true ] );

		// The live-mode memo entry must not answer for test mode.
		$this->assertSame( 0, $reporting->team_totals( $team->id )['raised'] );
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

	/**
	 * Test pending and deactivated fundraisers stop receiving attribution.
	 */
	public function test_resolve_non_active_fundraiser_falls_back_to_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();

		foreach ( [ Fundraiser::STATUS_PENDING, Fundraiser::STATUS_INACTIVE ] as $donor_id => $status ) {
			$fundraiser = $this->make_fundraiser( $campaign->id, $donor_id + 1, [ 'status' => $status ] );

			$result = DonationAttribution::resolve( $campaign->id, $fundraiser->id, 0 );

			$this->assertSame( $campaign->id, $result['campaign_id'] );
			$this->assertNull( $result['fundraiser_id'] );
		}
	}

	/**
	 * Test pending and deactivated teams stop receiving attribution.
	 */
	public function test_resolve_non_active_team_falls_back_to_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();

		foreach ( [ Team::STATUS_PENDING, Team::STATUS_INACTIVE ] as $status ) {
			$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => "Rangers {$status}", 'status' => $status ] );
			$team->save();

			$result = DonationAttribution::resolve( $campaign->id, 0, $team->id );

			$this->assertSame( $campaign->id, $result['campaign_id'] );
			$this->assertNull( $result['team_id'] );
		}
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
	 * Test top_teams() orders by raised, rolling up member donations and
	 * refund-netted direct gifts, with zero-raised teams still listed.
	 */
	public function test_top_teams_orders_by_raised(): void {
		$alpha = new Team( [ 'campaign_id' => 1, 'name' => 'Alpha', 'status' => 'active' ] );
		$alpha->save();
		$beta = new Team( [ 'campaign_id' => 1, 'name' => 'Beta', 'status' => 'active' ] );
		$beta->save();
		$empty = new Team( [ 'campaign_id' => 1, 'name' => 'Empty', 'status' => 'active' ] );
		$empty->save();

		$member_a = $this->make_fundraiser( 1, 1, [ 'team_id' => $alpha->id ] );
		$member_b = $this->make_fundraiser( 1, 2, [ 'team_id' => $beta->id ] );

		$this->make_completed( [ 'fundraiser_id' => $member_a->id, 'amount' => 3000 ] );
		$this->make_completed( [ 'fundraiser_id' => $member_b->id, 'donor_id' => 2, 'amount' => 2000 ] );

		// A partially refunded direct gift nets to 4000 and pushes Beta ahead.
		$gift                  = $this->make_completed( [ 'team_id' => $beta->id, 'donor_id' => 3, 'amount' => 5000 ] );
		$gift->amount_refunded = 1000;
		$gift->save();

		$top = ( new ReportingService() )->top_teams( 1 );

		$this->assertSame( [ $beta->id, $alpha->id, $empty->id ], array_column( $top, 'id' ) );
		$this->assertSame( [ 6000, 3000, 0 ], array_column( $top, 'raised' ) );
		$this->assertSame( [ 1, 1, 0 ], array_column( $top, 'member_count' ) );
	}

	/**
	 * Test top_teams() lists only public active teams in the given campaign.
	 */
	public function test_top_teams_excludes_private_inactive_and_other_campaigns(): void {
		$public = new Team( [ 'campaign_id' => 1, 'name' => 'Public', 'status' => 'active' ] );
		$public->save();
		$private = new Team( [ 'campaign_id' => 1, 'name' => 'Private', 'status' => 'active', 'access' => Team::ACCESS_PRIVATE ] );
		$private->save();
		$pending = new Team( [ 'campaign_id' => 1, 'name' => 'Pending', 'status' => 'pending' ] );
		$pending->save();
		$elsewhere = new Team( [ 'campaign_id' => 2, 'name' => 'Elsewhere', 'status' => 'active' ] );
		$elsewhere->save();

		$this->make_completed( [ 'team_id' => $elsewhere->id, 'donor_id' => 2, 'amount' => 99000 ] );

		$top = ( new ReportingService() )->top_teams( 1 );

		$this->assertSame( [ $public->id ], array_column( $top, 'id' ) );
	}

	/**
	 * Test team_members lists members by raised with the captain flag.
	 */
	public function test_team_members_lists_by_raised(): void {
		$team    = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$captain = $this->make_fundraiser( 1, 1, [ 'team_id' => $team->id ] );
		$member  = $this->make_fundraiser( 1, 2, [ 'team_id' => $team->id ] );
		$team->set_captain( $captain );

		$this->make_completed( [ 'fundraiser_id' => $member->id, 'donor_id' => 2, 'amount' => 8000 ] );
		$this->make_completed( [ 'fundraiser_id' => $captain->id, 'amount' => 2000 ] );

		$members = ( new ReportingService() )->team_members( $team->id );

		$this->assertSame( $member->id, $members[0]['id'] );
		$this->assertTrue( $members[1]['is_captain'] );
	}

	// -------------------------------------------------------------------------
	// Team rank.
	// -------------------------------------------------------------------------

	/**
	 * Test team_rank() ranks active teams by raised, counting private teams,
	 * direct gifts, and only the team's own campaign.
	 */
	public function test_team_rank_orders_active_teams(): void {
		$first = new Team( [ 'campaign_id' => 1, 'name' => 'First', 'status' => 'active' ] );
		$first->save();
		$second = new Team( [ 'campaign_id' => 1, 'name' => 'Second', 'status' => 'active', 'access' => Team::ACCESS_PRIVATE ] );
		$second->save();
		$third = new Team( [ 'campaign_id' => 1, 'name' => 'Third', 'status' => 'active' ] );
		$third->save();

		// A richer team in another campaign must not affect the ranking.
		$elsewhere = new Team( [ 'campaign_id' => 2, 'name' => 'Elsewhere', 'status' => 'active' ] );
		$elsewhere->save();
		$this->make_completed( [ 'team_id' => $elsewhere->id, 'donor_id' => 5, 'amount' => 99000 ] );

		$member = $this->make_fundraiser( 1, 1, [ 'team_id' => $second->id ] );
		$this->make_completed( [ 'fundraiser_id' => $member->id, 'amount' => 5000 ] );

		// A direct team gift pushes the leader ahead of the private team.
		$this->make_completed( [ 'team_id' => $first->id, 'donor_id' => 2, 'amount' => 9000 ] );

		$reporting = new ReportingService();

		$this->assertSame( [ 'rank' => 1, 'total' => 3 ], $reporting->team_rank( $first->id ) );
		$this->assertSame( [ 'rank' => 2, 'total' => 3 ], $reporting->team_rank( $second->id ) );
		$this->assertSame( [ 'rank' => 3, 'total' => 3 ], $reporting->team_rank( $third->id ) );
	}

	/**
	 * Test tied teams share a rank and non-active teams don't count.
	 */
	public function test_team_rank_ties_and_ignores_non_active_teams(): void {
		$a = new Team( [ 'campaign_id' => 1, 'name' => 'A', 'status' => 'active' ] );
		$a->save();
		$b = new Team( [ 'campaign_id' => 1, 'name' => 'B', 'status' => 'active' ] );
		$b->save();
		$pending = new Team( [ 'campaign_id' => 1, 'name' => 'Pending', 'status' => 'pending' ] );
		$pending->save();

		$this->make_completed( [ 'team_id' => $a->id, 'donor_id' => 2, 'amount' => 1000 ] );
		$this->make_completed( [ 'team_id' => $b->id, 'donor_id' => 3, 'amount' => 1000 ] );
		$this->make_completed( [ 'team_id' => $pending->id, 'donor_id' => 4, 'amount' => 50000 ] );

		$reporting = new ReportingService();

		$this->assertSame( [ 'rank' => 1, 'total' => 2 ], $reporting->team_rank( $a->id ) );
		$this->assertSame( [ 'rank' => 1, 'total' => 2 ], $reporting->team_rank( $b->id ) );
	}

	/**
	 * Test an unknown team returns zeroes.
	 */
	public function test_team_rank_unknown_team_returns_zeroes(): void {
		$this->assertSame( [ 'rank' => 0, 'total' => 0 ], ( new ReportingService() )->team_rank( 12345 ) );
	}

	/**
	 * Test team_rank() runs a single query once the team row and totals memo
	 * are primed, as they are at both call sites.
	 */
	public function test_team_rank_single_query_when_primed(): void {
		global $wpdb;

		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$this->make_completed( [ 'team_id' => $team->id, 'donor_id' => 2, 'amount' => 2500 ] );

		$reporting = new ReportingService();
		Team::find( $team->id );
		$reporting->team_totals( $team->id );

		$before = $wpdb->num_queries;
		$rank   = $reporting->team_rank( $team->id );

		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertSame( [ 'rank' => 1, 'total' => 1 ], $rank );
	}

	// -------------------------------------------------------------------------
	// Cross-page recent donations.
	// -------------------------------------------------------------------------

	/**
	 * Test donor_pages_recent_donations() spans only the donor's own pages, newest first.
	 */
	public function test_donor_pages_recent_donations_spans_own_pages(): void {
		$owner = new Donor( [ 'email' => 'owner@example.com', 'first_name' => 'Sarah' ] );
		$owner->save();
		$giver = new Donor( [ 'email' => 'giver@example.com', 'first_name' => 'Karen', 'last_name' => 'W' ] );
		$giver->save();
		$other_owner = new Donor( [ 'email' => 'other@example.com' ] );
		$other_owner->save();

		$page_a        = $this->make_fundraiser( 1, $owner->id, [ 'headline' => 'Page A' ] );
		$page_b        = $this->make_fundraiser( 2, $owner->id, [ 'headline' => 'Page B' ] );
		$someone_elses = $this->make_fundraiser( 1, $other_owner->id );

		$this->make_completed( [ 'fundraiser_id' => $page_a->id, 'donor_id' => $giver->id, 'amount' => 2500, 'date_completed' => '2026-01-01 00:00:00' ] );
		$this->make_completed( [ 'fundraiser_id' => $page_b->id, 'donor_id' => $giver->id, 'amount' => 4000, 'date_completed' => '2026-02-01 00:00:00' ] );
		$this->make_completed( [ 'fundraiser_id' => $someone_elses->id, 'donor_id' => $giver->id, 'amount' => 999 ] );

		// Non-completed gifts to the owner's pages never appear.
		$pending = new Transaction( [
			'status'        => Transaction::STATUS_PENDING,
			'donor_id'      => $giver->id,
			'fundraiser_id' => $page_a->id,
			'amount'        => 777,
		] );
		$pending->save();

		$items = ( new ReportingService() )->donor_pages_recent_donations( $owner->id );

		$this->assertCount( 2, $items );
		$this->assertSame( 4000, $items[0]['amount'] );
		$this->assertSame( 'Page B', $items[0]['headline'] );
		$this->assertSame( 'Karen', $items[0]['first_name'] );
		$this->assertSame( $page_b->id, $items[0]['fundraiser_id'] );
		$this->assertSame( 2500, $items[1]['amount'] );
	}

	/**
	 * Test donor_pages_recent_donations() respects the limit.
	 */
	public function test_donor_pages_recent_donations_respects_limit(): void {
		$owner = new Donor( [ 'email' => 'owner@example.com' ] );
		$owner->save();
		$giver = new Donor( [ 'email' => 'giver@example.com' ] );
		$giver->save();

		$page = $this->make_fundraiser( 1, $owner->id );

		foreach ( range( 1, 4 ) as $day ) {
			$this->make_completed(
				[
					'fundraiser_id'  => $page->id,
					'donor_id'       => $giver->id,
					'amount'         => 1000 * $day,
					'date_completed' => sprintf( '2026-03-%02d 00:00:00', $day ),
				]
			);
		}

		$items = ( new ReportingService() )->donor_pages_recent_donations( $owner->id, 2 );

		$this->assertCount( 2, $items );
		$this->assertSame( 4000, $items[0]['amount'] );
	}
}
