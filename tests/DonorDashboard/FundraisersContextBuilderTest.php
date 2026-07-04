<?php
/**
 * Tests for the FundraisersContextBuilder.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\FundraisersContextBuilder;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use MissionDP\Reporting\ReportingService;
use WP_UnitTestCase;

/**
 * Fundraisers context builder test class.
 */
class FundraisersContextBuilderTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Use live mode so reporting reads the same columns the transactions write.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false ] );
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( [ 'transactionmeta', 'transactions', 'fundraisermeta', 'fundraisers', 'teammeta', 'teams', 'campaignmeta', 'campaigns', 'donormeta', 'donors' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create a donor.
	 *
	 * @param string $email Donor email.
	 * @return Donor
	 */
	private function create_donor( string $email = 'owner@example.com' ): Donor {
		$donor = new Donor(
			[
				'email'      => $email,
				'first_name' => 'Sarah',
				'last_name'  => 'Mitchell',
			]
		);
		$donor->save();

		return $donor;
	}

	/**
	 * Create a campaign.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign(
			array_merge(
				[
					'title' => 'Drive',
					'type'  => Campaign::TYPE_P2P,
				],
				$overrides
			)
		);
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create a fundraiser.
	 *
	 * @param int                  $campaign_id Campaign ID.
	 * @param int                  $donor_id    Donor ID.
	 * @param array<string, mixed> $overrides   Column values to override.
	 * @return Fundraiser
	 */
	private function create_fundraiser( int $campaign_id, int $donor_id, array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser(
			array_merge(
				[
					'campaign_id' => $campaign_id,
					'donor_id'    => $donor_id,
					'status'      => Fundraiser::STATUS_ACTIVE,
					'goal'        => 100000,
					'headline'    => 'Help me help',
					'story'       => 'My story',
				],
				$overrides
			)
		);
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Build the fundraisers context for a donor.
	 *
	 * @param Donor $donor The donor.
	 * @return array{builder: FundraisersContextBuilder, context: array|null}
	 */
	private function build( Donor $donor ): array {
		$builder = new FundraisersContextBuilder(
			$donor,
			[ 'currency' => 'USD' ],
			new ReportingService(),
			$donor->fundraisers(
				[
					'orderby' => 'date_created',
					'order'   => 'DESC',
				]
			)
		);

		return [
			'builder' => $builder,
			'context' => $builder->build(),
		];
	}

	/**
	 * Test cards are grouped by campaign state with the right badges.
	 */
	public function test_cards_grouped_by_campaign_state_with_badges(): void {
		$donor = $this->create_donor();
		$live  = $this->create_campaign();
		$ended = $this->create_campaign(
			[
				'title'  => 'Last Year',
				'status' => Campaign::STATUS_ENDED,
			]
		);

		$this->create_fundraiser( $live->id, $donor->id, [ 'headline' => 'Current page' ] );

		// Goal met on the ended campaign: raised >= goal.
		$met = $this->create_fundraiser( $ended->id, $donor->id, [ 'headline' => 'Winner', 'goal' => 5000 ] );
		$txn = new Transaction(
			[
				'status'        => Transaction::STATUS_COMPLETED,
				'donor_id'      => 999,
				'fundraiser_id' => $met->id,
				'amount'        => 5000,
			]
		);
		$txn->save();

		$context = $this->build( $donor )['context'];

		$this->assertCount( 1, $context['active'] );
		$this->assertCount( 1, $context['ended'] );
		$this->assertSame( 'Current page', $context['active'][0]['headline'] );
		$this->assertSame( 'active', $context['active'][0]['badgeType'] );
		$this->assertSame( 'goal-met', $context['ended'][0]['badgeType'] );
		$this->assertTrue( $context['ended'][0]['isLocked'] );
		$this->assertSame( 'Active (1)', $context['activeCountLabel'] );
		$this->assertSame( [ $context['active'][0]['id'], $context['ended'][0]['id'] ], $context['ids'] );
	}

	/**
	 * Test pending and inactive statuses outrank the campaign-state badges.
	 */
	public function test_status_badges_take_precedence(): void {
		$donor = $this->create_donor();
		$live  = $this->create_campaign();

		$this->create_fundraiser( $live->id, $donor->id, [ 'status' => Fundraiser::STATUS_PENDING ] );

		$context = $this->build( $donor )['context'];

		$this->assertSame( 'pending', $context['active'][0]['badgeType'] );
		$this->assertTrue( $context['active'][0]['isPending'] );
	}

	/**
	 * Test supporters page 1 is embedded only for live-campaign pages.
	 */
	public function test_supporters_embedded_for_active_pages_only(): void {
		$donor = $this->create_donor();
		$giver = $this->create_donor( 'giver@example.com' );
		$live  = $this->create_campaign();
		$ended = $this->create_campaign(
			[
				'title'  => 'Last Year',
				'status' => Campaign::STATUS_ENDED,
			]
		);

		$active_page = $this->create_fundraiser( $live->id, $donor->id );
		$ended_page  = $this->create_fundraiser( $ended->id, $donor->id );

		foreach ( [ $active_page, $ended_page ] as $page ) {
			$txn = new Transaction(
				[
					'status'        => Transaction::STATUS_COMPLETED,
					'donor_id'      => $giver->id,
					'fundraiser_id' => $page->id,
					'amount'        => 2500,
				]
			);
			$txn->save();
		}

		$context = $this->build( $donor )['context'];

		$this->assertCount( 1, $context['active'][0]['supporters'] );
		$this->assertSame( 'Sarah Mitchell', $context['active'][0]['supporters'][0]['name'] );
		$this->assertSame( [], $context['ended'][0]['supporters'] );
		$this->assertSame( 1, $context['ended'][0]['supportersTotal'] );

		// Ended pages also drop the story from the payload (no edit form).
		$this->assertSame( '', $context['ended'][0]['story'] );
		$this->assertSame( 'My story', $context['active'][0]['story'] );
	}

	/**
	 * Test the dedication meta surfaces as a card label and edit fields.
	 */
	public function test_dedication_on_card(): void {
		$donor = $this->create_donor();
		$live  = $this->create_campaign();

		$fundraiser = $this->create_fundraiser( $live->id, $donor->id );
		$fundraiser->set_dedication( 'memory', 'Jane Smith' );

		$context = $this->build( $donor )['context'];

		$this->assertSame( 'In memory of Jane Smith', $context['active'][0]['dedicationLabel'] );
		$this->assertSame( 'memory', $context['edit']['tributeType'] );
		$this->assertSame( 'Jane Smith', $context['edit']['tributeName'] );
	}

	/**
	 * Test the spotlight features the newest live page and lists the others.
	 */
	public function test_spotlight_features_newest_active_page(): void {
		$donor = $this->create_donor();
		$live  = $this->create_campaign();
		$other = $this->create_campaign( [ 'title' => 'Second Drive' ] );

		$older = $this->create_fundraiser( $live->id, $donor->id, [ 'headline' => 'Older page', 'date_created' => '2025-01-01 00:00:00' ] );
		$newer = $this->create_fundraiser( $other->id, $donor->id, [ 'headline' => 'Newer page', 'date_created' => '2026-01-01 00:00:00' ] );

		$result = $this->build( $donor );
		$extras = $result['builder']->overview_extras( true );

		$this->assertFalse( $extras['isFundraiserVariant'] );
		$this->assertSame( $newer->id, $extras['spotlight']['fundraiserId'] );
		$this->assertSame( 'Newer page', $extras['spotlight']['headline'] );
		$this->assertTrue( $extras['spotlight']['hasOthers'] );
		$this->assertSame( $older->id, $extras['spotlight']['others'][0]['id'] );
	}

	/**
	 * Test the spotlight is null when no page is live.
	 */
	public function test_spotlight_null_without_live_page(): void {
		$donor = $this->create_donor();
		$ended = $this->create_campaign(
			[
				'title'  => 'Last Year',
				'status' => Campaign::STATUS_ENDED,
			]
		);
		$this->create_fundraiser( $ended->id, $donor->id );

		$extras = $this->build( $donor )['builder']->overview_extras( true );

		$this->assertNull( $extras['spotlight'] );
	}

	/**
	 * Test fundraiser stats aggregate across pages and count only live active ones.
	 */
	public function test_fundraiser_stats(): void {
		$donor = $this->create_donor();
		$giver = $this->create_donor( 'giver@example.com' );
		$live  = $this->create_campaign();
		$ended = $this->create_campaign(
			[
				'title'  => 'Last Year',
				'status' => Campaign::STATUS_ENDED,
			]
		);

		$active_page = $this->create_fundraiser( $live->id, $donor->id );
		$ended_page  = $this->create_fundraiser( $ended->id, $donor->id );
		$this->create_fundraiser( $live->id, $giver->id ); // Someone else's page never counts.

		foreach ( [ [ $active_page, 4000 ], [ $ended_page, 6000 ] ] as [ $page, $amount ] ) {
			$txn = new Transaction(
				[
					'status'        => Transaction::STATUS_COMPLETED,
					'donor_id'      => $giver->id,
					'fundraiser_id' => $page->id,
					'amount'        => $amount,
				]
			);
			$txn->save();
		}

		$extras = $this->build( $donor )['builder']->overview_extras( false );
		$stats  = $extras['fundraiserStats'];

		$this->assertTrue( $extras['isFundraiserVariant'] );
		$this->assertSame( '$100.00', $stats[0]['value'] ); // Total Raised.
		$this->assertSame( '2', $stats[1]['value'] );       // Donations Received.
		$this->assertSame( '1', $stats[2]['value'] );       // Active Fundraisers (ended page excluded).
	}

	/**
	 * Test recent page donations are prepared for fundraiser-only users.
	 */
	public function test_recent_page_donations_for_fundraiser_only(): void {
		$donor = $this->create_donor();
		$giver = $this->create_donor( 'giver@example.com' );
		$live  = $this->create_campaign();

		$page = $this->create_fundraiser( $live->id, $donor->id, [ 'headline' => '' ] );
		$txn  = new Transaction(
			[
				'status'        => Transaction::STATUS_COMPLETED,
				'donor_id'      => $giver->id,
				'fundraiser_id' => $page->id,
				'amount'        => 2500,
			]
		);
		$txn->save();

		$extras = $this->build( $donor )['builder']->overview_extras( false );

		$this->assertTrue( $extras['hasPageDonations'] );
		$this->assertSame( '$25.00', $extras['recentPageDonations'][0]['amount'] );
		$this->assertSame( 'Sarah Mitchell', $extras['recentPageDonations'][0]['donor'] );
		// Empty headlines fall back to the campaign title for the Page column.
		$this->assertSame( 'Drive', $extras['recentPageDonations'][0]['page'] );

		// Donors with giving history use the donor overview instead.
		$this->assertSame( [], $this->build( $donor )['builder']->overview_extras( true )['recentPageDonations'] );
	}

	/**
	 * Test the spotlight team chip carries the role and team-goal progress.
	 */
	public function test_spotlight_team_chip(): void {
		$donor = $this->create_donor();
		$live  = $this->create_campaign();

		$team = new Team(
			[
				'campaign_id' => $live->id,
				'name'        => 'Rangers',
				'status'      => Team::STATUS_ACTIVE,
				'goal'        => 1000000,
			]
		);
		$team->save();

		$fundraiser = $this->create_fundraiser( $live->id, $donor->id, [ 'team_id' => $team->id, 'is_team_captain' => true ] );
		$team->set_captain( $fundraiser );

		$extras = $this->build( $donor )['builder']->overview_extras( true );

		$this->assertTrue( $extras['spotlight']['onTeam'] );
		$this->assertSame( 'Rangers', $extras['spotlight']['teamName'] );
		$this->assertTrue( $extras['spotlight']['isCaptain'] );
		$this->assertSame( '$0.00 of $10,000.00 team goal', $extras['spotlight']['teamProgressLabel'] );
	}

	/**
	 * Test build returns null for a donor with no pages.
	 */
	public function test_build_null_without_fundraisers(): void {
		$this->assertNull( $this->build( $this->create_donor() )['context'] );
	}
}
