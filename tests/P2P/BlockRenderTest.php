<?php
/**
 * Smoke tests that the P2P display blocks resolve their entity and render.
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
use WP_UnitTestCase;

/**
 * P2P block render test class.
 */
class BlockRenderTest extends WP_UnitTestCase {

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
	 * Use live mode so reporting reads the columns live transactions write.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'missiondp_settings', [ 'test_mode' => false, 'currency' => 'USD' ] );
	}

	/**
	 * Require a built/registered block: skip locally, but FAIL on CI.
	 *
	 * Without the failure, an unbuilt CI runner green-skips the whole class and
	 * P2P block rendering silently has zero coverage.
	 *
	 * @param string $block Full block name.
	 */
	private function require_block( string $block ): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
			return;
		}

		if ( getenv( 'CI' ) ) {
			$this->fail( "{$block} is not registered — run npm run build before the PHP suite in CI." );
		}

		$this->markTestSkipped( "{$block} is not built/registered in this environment." );
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * Test the fundraiser-progress block renders the bound fundraiser's totals.
	 */
	public function test_fundraiser_progress_renders(): void {
		$this->require_block( 'mission-donation-platform/fundraiser-progress' );

		$campaign   = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active', 'goal' => 10000 ] );
		$fundraiser->save();

		$transaction = new Transaction( [ 'status' => 'completed', 'donor_id' => 1, 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] );
		$transaction->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/fundraiser-progress {"fundraiserId":%d} /-->', $fundraiser->id ) );

		$this->assertStringContainsString( 'wp-block-mission-donation-platform-fundraiser-progress', $html );
		$this->assertStringContainsString( 'mission-progress__', $html );
		$this->assertStringContainsString( '$50', $html );
	}

	/**
	 * Test the top-fundraisers block ranks a campaign's fundraisers.
	 */
	public function test_top_fundraisers_renders(): void {
		$this->require_block( 'mission-donation-platform/top-fundraisers' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$donor = new Donor( [ 'email' => 'tf@example.com', 'first_name' => 'Tina', 'last_name' => 'Fund' ] );
		$donor->save();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => $donor->id, 'status' => 'active' ] );
		$fundraiser->save();
		( new Transaction( [ 'status' => 'completed', 'donor_id' => $donor->id, 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] ) )->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/top-fundraisers {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringContainsString( 'mission-top-fundraisers', $html );
		$this->assertStringContainsString( 'Tina Fund', $html );
		$this->assertStringContainsString( '$50', $html );
	}

	/**
	 * Test the top-teams block ranks a campaign's teams with member counts.
	 */
	public function test_top_teams_renders(): void {
		$this->require_block( 'mission-donation-platform/top-teams' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Marathoners', 'status' => 'active' ] );
		$team->save();
		$donor = new Donor( [ 'email' => 'tt@example.com', 'first_name' => 'Tom', 'last_name' => 'Team' ] );
		$donor->save();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => $donor->id, 'team_id' => $team->id, 'status' => 'active' ] );
		$fundraiser->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/top-teams {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringContainsString( 'mission-top-teams', $html );
		$this->assertStringContainsString( 'Marathoners', $html );
		$this->assertStringContainsString( '1 member', $html );
	}

	/**
	 * Test the team-progress join button shows for public teams only.
	 */
	public function test_team_progress_hides_join_on_private_teams(): void {
		$this->require_block( 'mission-donation-platform/team-progress' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$public = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Open Crew', 'status' => 'active', 'access' => Team::ACCESS_PUBLIC ] );
		$public->save();
		$private = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Closed Crew', 'status' => 'active', 'access' => Team::ACCESS_PRIVATE ] );
		$private->save();

		$public_html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/team-progress {"teamId":%d} /-->', $public->id ) );
		$this->assertStringContainsString( 'Join this Team', $public_html );

		// Uninvited visitors can't join a private team, so no join CTA; the
		// donate button stays.
		$private_html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/team-progress {"teamId":%d} /-->', $private->id ) );
		$this->assertStringNotContainsString( 'Join this Team', $private_html );
		$this->assertStringContainsString( 'Donate to the Team', $private_html );
	}

	/**
	 * Test the team-members block lists the team's members.
	 */
	public function test_team_members_renders(): void {
		$this->require_block( 'mission-donation-platform/team-members' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$member   = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'team_id' => $team->id, 'status' => 'active' ] );
		$member->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/team-members {"teamId":%d} /-->', $team->id ) );

		$this->assertStringContainsString( 'mission-tm', $html );
	}

	/**
	 * Test the sign-up modal renders for an open p2p campaign.
	 */
	public function test_signup_modal_renders_when_registration_open(): void {
		$this->require_block( 'mission-donation-platform/signup-modal' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$campaign->update_meta( 'registration_open', true );

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/signup-modal {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringContainsString( 'mission-su__overlay', $html );
		$this->assertStringContainsString( 'mission-su__step', $html );
	}

	/**
	 * Test the sign-up modal renders nothing when registration is closed.
	 */
	public function test_signup_modal_hidden_when_registration_closed(): void {
		$this->require_block( 'mission-donation-platform/signup-modal' );

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$campaign->update_meta( 'registration_open', false );

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/signup-modal {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringNotContainsString( 'mission-su__overlay', $html );
	}

	/**
	 * Test the sign-up modal renders nothing on a scheduled campaign even with the toggle on.
	 */
	public function test_signup_modal_hidden_when_campaign_not_active(): void {
		$this->require_block( 'mission-donation-platform/signup-modal' );

		$campaign = new Campaign(
			[
				'title'  => 'Drive',
				'type'   => 'p2p',
				'status' => Campaign::STATUS_SCHEDULED,
			]
		);
		$campaign->save();
		$campaign->update_meta( 'registration_open', true );

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/signup-modal {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringNotContainsString( 'mission-su__overlay', $html );
	}
}
