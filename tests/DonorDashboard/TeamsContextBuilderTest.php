<?php
/**
 * Tests for the TeamsContextBuilder.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\DonorDashboard;

use MissionDP\Database\DatabaseModule;
use MissionDP\DonorDashboard\TeamsContextBuilder;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use MissionDP\Reporting\ReportingService;
use WP_UnitTestCase;

/**
 * Teams context builder test class.
 */
class TeamsContextBuilderTest extends WP_UnitTestCase {

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
		foreach ( [ 'team_invitations', 'transactionmeta', 'transactions', 'fundraisermeta', 'fundraisers', 'teammeta', 'teams', 'campaignmeta', 'campaigns', 'donormeta', 'donors' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create a donor.
	 *
	 * @param string $email Donor email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return Donor
	 */
	private function create_donor( string $email = 'owner@example.com', string $first = 'Sarah', string $last = 'Mitchell' ): Donor {
		$donor = new Donor(
			[
				'email'      => $email,
				'first_name' => $first,
				'last_name'  => $last,
			]
		);
		$donor->save();

		return $donor;
	}

	/**
	 * Create a campaign, a team on it, and a fundraiser for the donor on the team.
	 *
	 * @param Donor                $donor         The member donor.
	 * @param bool                 $as_captain    Whether the donor captains the team.
	 * @param array<string, mixed> $campaign_data Campaign overrides.
	 * @param array<string, mixed> $team_data     Team overrides.
	 * @return array{campaign: Campaign, team: Team, fundraiser: Fundraiser}
	 */
	private function create_membership( Donor $donor, bool $as_captain = false, array $campaign_data = [], array $team_data = [] ): array {
		$campaign = new Campaign(
			array_merge(
				[
					'title' => 'Drive',
					'type'  => Campaign::TYPE_P2P,
				],
				$campaign_data
			)
		);
		$campaign->save();

		$team = new Team(
			array_merge(
				[
					'campaign_id' => $campaign->id,
					'name'        => 'Rangers',
					'status'      => Team::STATUS_ACTIVE,
					'goal'        => 1000000,
				],
				$team_data
			)
		);
		$team->save();

		$fundraiser = new Fundraiser(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'team_id'     => $team->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
				'goal'        => 100000,
			]
		);
		$fundraiser->save();

		if ( $as_captain ) {
			$team->set_captain( $fundraiser );
		}

		return compact( 'campaign', 'team', 'fundraiser' );
	}

	/**
	 * Build the teams context for a donor.
	 *
	 * @param Donor $donor The donor.
	 * @return array<string, mixed>|null
	 */
	private function build( Donor $donor ): ?array {
		return ( new TeamsContextBuilder(
			$donor,
			[ 'currency' => 'USD' ],
			new ReportingService(),
			$donor->fundraisers(
				[
					'orderby' => 'date_created',
					'order'   => 'DESC',
				]
			)
		) )->build();
	}

	/**
	 * Member counts use the active-only basis and the card embeds only the
	 * first roster page with paging totals.
	 */
	public function test_member_count_is_active_only_and_roster_is_paged(): void {
		$donor  = $this->create_donor();
		$result = $this->create_membership( $donor, true );

		// Six more active members and one pending (approval-required signup).
		for ( $i = 1; $i <= 6; $i++ ) {
			$member = $this->create_donor( "m{$i}@example.com", 'Mem', "Ber{$i}" );
			( new Fundraiser(
				[
					'campaign_id' => $result['campaign']->id,
					'donor_id'    => $member->id,
					'team_id'     => $result['team']->id,
					'status'      => Fundraiser::STATUS_ACTIVE,
				]
			) )->save();
		}
		$pending = $this->create_donor( 'pending@example.com', 'Pen', 'Ding' );
		( new Fundraiser(
			[
				'campaign_id' => $result['campaign']->id,
				'donor_id'    => $pending->id,
				'team_id'     => $result['team']->id,
				'status'      => Fundraiser::STATUS_PENDING,
			]
		) )->save();

		$context = $this->build( $donor );
		$card    = $context['current'][0];

		// Captain + 6 active members; the pending fundraiser never counts.
		$this->assertSame( 7, $card['memberCount'] );
		$this->assertSame( '7 members', $card['memberCountLabel'] );
		$this->assertSame( 7, $card['membersTotal'] );
		$this->assertSame( 2, $card['membersTotalPages'] );
		$this->assertCount( TeamsContextBuilder::MEMBERS_PER_PAGE, $card['members'] );
	}

	/**
	 * The captain chip resolves even when the captain is not on the first
	 * roster page (members are ordered by raised).
	 */
	public function test_captain_chip_resolves_off_page_captain(): void {
		$donor   = $this->create_donor( 'member@example.com', 'Plain', 'Member' );
		$result  = $this->create_membership( $donor );
		$captain = $this->create_donor( 'cap@example.com', 'Quiet', 'Captain' );

		$captain_fundraiser = new Fundraiser(
			[
				'campaign_id' => $result['campaign']->id,
				'donor_id'    => $captain->id,
				'team_id'     => $result['team']->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
			]
		);
		$captain_fundraiser->save();
		$result['team']->set_captain( $captain_fundraiser );

		// Five members who out-raised the captain fill the first page.
		global $wpdb;
		for ( $i = 1; $i <= 5; $i++ ) {
			$member = $this->create_donor( "big{$i}@example.com", 'Big', "Raiser{$i}" );
			$f      = new Fundraiser(
				[
					'campaign_id' => $result['campaign']->id,
					'donor_id'    => $member->id,
					'team_id'     => $result['team']->id,
					'status'      => Fundraiser::STATUS_ACTIVE,
				]
			);
			$f->save();
			$wpdb->update( "{$wpdb->prefix}missiondp_fundraisers", [ 'total_raised' => 10000 * $i ], [ 'id' => $f->id ] );
		}
		wp_cache_flush();

		$context = $this->build( $donor );
		$card    = $context['current'][0];

		$page_names = array_column( $card['members'], 'name' );
		$this->assertNotContains( 'Quiet Captain', $page_names );
		$this->assertSame( 'Quiet Captain', $card['captainName'] );
	}

	/**
	 * Test current and past teams split by campaign state.
	 */
	public function test_current_and_past_split(): void {
		$donor = $this->create_donor();

		$current = $this->create_membership( $donor );
		$this->create_membership(
			$donor,
			false,
			[
				'title'  => 'Last Year',
				'status' => Campaign::STATUS_ENDED,
			],
			[ 'name' => 'Old Rangers' ]
		);

		$context = $this->build( $donor );

		$this->assertCount( 1, $context['current'] );
		$this->assertCount( 1, $context['past'] );
		$this->assertSame( 'Rangers', $context['current'][0]['name'] );
		$this->assertSame( [ $current['team']->id ], $context['ids'] );
		$this->assertSame( 'Old Rangers', $context['past'][0]['name'] );
		$this->assertSame( 'Member', $context['past'][0]['roleLabel'] );
		$this->assertSame( 'team raised $0.00', $context['past'][0]['raisedLabel'] );
	}

	/**
	 * Test the member card carries the captain chip and no captain tools.
	 */
	public function test_member_card_shows_captain_chip(): void {
		$donor   = $this->create_donor();
		$captain = $this->create_donor( 'cap@example.com', 'Marcus', 'Chen' );

		$membership = $this->create_membership( $donor );

		$captain_fundraiser = new Fundraiser(
			[
				'campaign_id' => $membership['campaign']->id,
				'donor_id'    => $captain->id,
				'team_id'     => $membership['team']->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
			]
		);
		$captain_fundraiser->save();
		$membership['team']->set_captain( $captain_fundraiser );

		$card = $this->build( $donor )['current'][0];

		$this->assertFalse( $card['isCaptain'] );
		$this->assertSame( 'Member', $card['roleLabel'] );
		$this->assertSame( 'Captain: Marcus Chen', $card['captainChipLabel'] );
		$this->assertSame( [], $card['invitations'] );
		$this->assertSame( $membership['fundraiser']->id, $card['myFundraiserId'] );
	}

	/**
	 * Test member rows carry initials, self detection, and personal progress.
	 */
	public function test_member_rows(): void {
		$donor  = $this->create_donor();
		$mate   = $this->create_donor( 'mate@example.com', 'Tom', 'Nguyen' );
		$result = $this->create_membership( $donor, true );

		$mate_fundraiser = new Fundraiser(
			[
				'campaign_id' => $result['campaign']->id,
				'donor_id'    => $mate->id,
				'team_id'     => $result['team']->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
				'goal'        => 50000,
			]
		);
		$mate_fundraiser->save();

		$txn = new Transaction(
			[
				'status'        => Transaction::STATUS_COMPLETED,
				'donor_id'      => $donor->id,
				'fundraiser_id' => $mate_fundraiser->id,
				'amount'        => 25000,
			]
		);
		$txn->save();

		$card = $this->build( $donor )['current'][0];
		$rows = $card['members'];

		$this->assertCount( 2, $rows );

		// Ordered by raised: Tom first.
		$this->assertSame( 'TN', $rows[0]['initials'] );
		$this->assertFalse( $rows[0]['isSelf'] );
		$this->assertSame( 50.0, $rows[0]['progress'] );
		$this->assertSame( '$250.00 of $500.00', $rows[0]['raisedOfGoalLabel'] );

		$this->assertTrue( $rows[1]['isSelf'] );
		$this->assertTrue( $rows[1]['isCaptain'] );
	}

	/**
	 * Test the rank label appears for active teams with competition.
	 */
	public function test_rank_label(): void {
		$donor  = $this->create_donor();
		$result = $this->create_membership( $donor, true );

		// A richer rival in the same campaign.
		$rival = new Team(
			[
				'campaign_id' => $result['campaign']->id,
				'name'        => 'Rivals',
				'status'      => Team::STATUS_ACTIVE,
			]
		);
		$rival->save();
		$txn = new Transaction(
			[
				'status'   => Transaction::STATUS_COMPLETED,
				'donor_id' => $donor->id,
				'team_id'  => $rival->id,
				'amount'   => 90000,
			]
		);
		$txn->save();

		$card = $this->build( $donor )['current'][0];

		$this->assertSame( 2, $card['rank'] );
		$this->assertSame( 2, $card['rankTotal'] );
		$this->assertSame( 'Rank #2 of 2 teams', $card['rankLabel'] );
		$this->assertSame( '#2', $card['rankStatValue'] );
	}

	/**
	 * Test captains get their pending invitations in the card.
	 */
	public function test_captain_gets_invitations(): void {
		$donor  = $this->create_donor();
		$result = $this->create_membership( $donor, true );

		$result['team']->invite( 'friend@example.com' );

		$card = $this->build( $donor )['current'][0];

		$this->assertTrue( $card['isCaptain'] );
		$this->assertCount( 1, $card['invitations'] );
		$this->assertSame( 'friend@example.com', $card['invitations'][0]['email'] );
	}

	/**
	 * Test build returns null when the donor never joined a team.
	 */
	public function test_build_null_without_teams(): void {
		$donor    = $this->create_donor();
		$campaign = new Campaign(
			[
				'title' => 'Drive',
				'type'  => Campaign::TYPE_P2P,
			]
		);
		$campaign->save();

		$solo = new Fundraiser(
			[
				'campaign_id' => $campaign->id,
				'donor_id'    => $donor->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
			]
		);
		$solo->save();

		$this->assertNull( $this->build( $donor ) );
	}
}
