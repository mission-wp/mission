<?php
/**
 * Smoke tests that the P2P display blocks resolve their entity and render.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
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
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'mission-donation-platform/fundraiser-progress' ) ) {
			$this->markTestSkipped( 'Blocks are not built/registered in this environment.' );
		}

		$campaign   = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active', 'goal' => 10000 ] );
		$fundraiser->save();

		$transaction = new Transaction( [ 'status' => 'completed', 'donor_id' => 1, 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] );
		$transaction->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/fundraiser-progress {"fundraiserId":%d} /-->', $fundraiser->id ) );

		$this->assertStringContainsString( 'mission-fp-progress', $html );
		$this->assertStringContainsString( '$50', $html );
	}

	/**
	 * Test the p2p-leaderboard block renders both columns.
	 */
	public function test_leaderboard_renders(): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'mission-donation-platform/p2p-leaderboard' ) ) {
			$this->markTestSkipped( 'Blocks are not built/registered in this environment.' );
		}

		$campaign   = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active' ] );
		$fundraiser->save();
		( new Transaction( [ 'status' => 'completed', 'donor_id' => 1, 'fundraiser_id' => $fundraiser->id, 'amount' => 5000 ] ) )->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/p2p-leaderboard {"campaignId":%d} /-->', $campaign->id ) );

		$this->assertStringContainsString( 'mission-lb', $html );
	}

	/**
	 * Test the team-members block lists the team's members.
	 */
	public function test_team_members_renders(): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'mission-donation-platform/team-members' ) ) {
			$this->markTestSkipped( 'Blocks are not built/registered in this environment.' );
		}

		$campaign = new Campaign( [ 'title' => 'Drive', 'type' => 'p2p' ] );
		$campaign->save();
		$team     = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Rangers', 'status' => 'active' ] );
		$team->save();
		$member   = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'team_id' => $team->id, 'status' => 'active' ] );
		$member->save();

		$html = do_blocks( sprintf( '<!-- wp:mission-donation-platform/team-members {"teamId":%d} /-->', $team->id ) );

		$this->assertStringContainsString( 'mission-tm', $html );
	}
}
