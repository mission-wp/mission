<?php
/**
 * Tests for fundraiser/team shell page URLs, rendering, and the 1.4.2 migration.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\P2P;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\FundraiserPostType;
use MissionDP\P2P\P2PRewrites;
use MissionDP\P2P\TeamPostType;
use WP_UnitTestCase;

/**
 * P2P pages test class.
 */
class P2PPagesTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Drop and recreate so the tables carry the current schema (dbDelta can't
		// convert an existing KEY post_id into a UNIQUE KEY on a persistent DB).
		global $wpdb;
		foreach ( [ 'fundraisers', 'fundraisermeta', 'teams', 'teammeta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_{$table}" ); // phpcs:ignore WordPress.DB
		}

		DatabaseModule::create_tables();
	}

	/**
	 * Register post types/rewrites and enable pretty permalinks per test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Pretty permalinks first, then (re-)register the post types so their
		// permastructs are built under the pretty structure.
		$this->set_permalink_structure( '/%postname%/' );

		( new CampaignPostType() )->register();
		( new FundraiserPostType() )->register();
		( new TeamPostType() )->register();
		( new P2PRewrites() )->add_rewrite_rules();

		flush_rewrite_rules();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Create a published p2p campaign.
	 *
	 * @param string $title Campaign title.
	 * @return Campaign
	 */
	private function create_campaign( string $title = 'Winter Drive' ): Campaign {
		$campaign = new Campaign( [ 'title' => $title, 'type' => 'p2p' ] );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create an active fundraiser (published shell post) on a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $donor_id    Donor ID.
	 * @return Fundraiser
	 */
	private function create_fundraiser( int $campaign_id, int $donor_id = 1 ): Fundraiser {
		$fundraiser = new Fundraiser( [ 'campaign_id' => $campaign_id, 'donor_id' => $donor_id, 'status' => 'active' ] );
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Create an active team (published shell post) on a campaign.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $name        Team name.
	 * @return Team
	 */
	private function create_team( int $campaign_id, string $name = 'Trail Blazers' ): Team {
		$team = new Team( [ 'campaign_id' => $campaign_id, 'name' => $name, 'status' => 'active' ] );
		$team->save();

		return $team;
	}

	// -------------------------------------------------------------------------
	// Nested permalinks.
	// -------------------------------------------------------------------------

	/**
	 * Test the fundraiser permalink nests under the parent campaign.
	 */
	public function test_fundraiser_permalink_is_nested(): void {
		$campaign     = $this->create_campaign();
		$fundraiser   = $this->create_fundraiser( $campaign->id );
		$campaign_url = get_permalink( $campaign->post_id );

		$this->assertSame(
			trailingslashit( $campaign_url ) . 'fundraiser/' . get_post( $fundraiser->post_id )->post_name . '/',
			get_permalink( $fundraiser->post_id )
		);
	}

	/**
	 * Test the team permalink nests under the parent campaign.
	 */
	public function test_team_permalink_is_nested(): void {
		$campaign     = $this->create_campaign();
		$team         = $this->create_team( $campaign->id );
		$campaign_url = get_permalink( $campaign->post_id );

		$this->assertSame(
			trailingslashit( $campaign_url ) . 'team/' . get_post( $team->post_id )->post_name . '/',
			get_permalink( $team->post_id )
		);
	}

	/**
	 * Test the segment is filterable.
	 */
	public function test_url_segment_is_filterable(): void {
		add_filter( 'mission_fundraiser_url_segment', static fn() => 'supporter' );

		$campaign   = $this->create_campaign();
		$fundraiser = $this->create_fundraiser( $campaign->id );

		$this->assertStringContainsString( '/supporter/', get_permalink( $fundraiser->post_id ) );

		remove_all_filters( 'mission_fundraiser_url_segment' );
	}

	// -------------------------------------------------------------------------
	// URL resolution.
	// -------------------------------------------------------------------------

	/**
	 * Test the nested fundraiser URL resolves to the shell post.
	 */
	public function test_fundraiser_url_resolves_to_post(): void {
		$campaign   = $this->create_campaign();
		$fundraiser = $this->create_fundraiser( $campaign->id );

		$this->go_to( get_permalink( $fundraiser->post_id ) );

		$this->assertTrue( is_singular( Fundraiser::POST_TYPE ) );
		$this->assertSame( $fundraiser->post_id, get_queried_object_id() );
	}

	/**
	 * Test the nested team URL resolves to the shell post.
	 */
	public function test_team_url_resolves_to_post(): void {
		$campaign = $this->create_campaign();
		$team     = $this->create_team( $campaign->id );

		$this->go_to( get_permalink( $team->post_id ) );

		$this->assertTrue( is_singular( Team::POST_TYPE ) );
		$this->assertSame( $team->post_id, get_queried_object_id() );
	}

	// -------------------------------------------------------------------------
	// Rendering.
	// -------------------------------------------------------------------------

	/**
	 * Test the fundraiser page renders the block template into the_content.
	 */
	public function test_fundraiser_page_renders_template(): void {
		$campaign   = $this->create_campaign();
		$fundraiser = $this->create_fundraiser( $campaign->id );

		$this->go_to( get_permalink( $fundraiser->post_id ) );
		the_post();
		$rendered = apply_filters( 'the_content', get_the_content() );

		$this->assertStringContainsString( 'mission-donation-form', $rendered );
	}

	/**
	 * Test the fundraiser and team pages are registered as editable Site Editor templates.
	 */
	public function test_block_templates_are_registered(): void {
		// Registered by the post types on init during plugin boot.
		$registry = \WP_Block_Templates_Registry::get_instance();

		$fundraiser = $registry->get_by_slug( 'single-' . Fundraiser::POST_TYPE );
		$team       = $registry->get_by_slug( 'single-' . Team::POST_TYPE );

		$this->assertInstanceOf( \WP_Block_Template::class, $fundraiser );
		$this->assertInstanceOf( \WP_Block_Template::class, $team );
		$this->assertSame( 'Fundraiser Page', $fundraiser->title );
		$this->assertSame( 'Team Page', $team->title );
		$this->assertStringContainsString( 'mission-donation-platform/fundraiser-profile', $fundraiser->content );
		$this->assertStringContainsString( 'mission-donation-platform/team-profile', $team->content );
	}

	/**
	 * Test the document title is prefixed with the parent campaign name.
	 */
	public function test_document_title_uses_campaign_name(): void {
		$campaign   = $this->create_campaign( 'Spring Appeal' );
		$fundraiser = $this->create_fundraiser( $campaign->id );

		$this->go_to( get_permalink( $fundraiser->post_id ) );

		$parts = apply_filters( 'document_title_parts', [ 'title' => 'Jane', 'site' => 'Site' ] );
		$this->assertSame( 'Spring Appeal', $parts['site'] );
	}

	// -------------------------------------------------------------------------
	// 1.4.2 migration backfill primitive + unique post_id.
	// -------------------------------------------------------------------------

	/**
	 * Test that re-saving a legacy row (post_id 0) backfills its shell post.
	 *
	 * This is the action the 1.4.2 migration performs for every fundraiser/team
	 * that predates shell posts.
	 */
	public function test_legacy_row_backfills_shell_post_on_save(): void {
		global $wpdb;

		$fundraiser = $this->create_fundraiser( 1 );

		// Simulate a pre-1.4.2 row: drop its shell post and zero the link.
		wp_delete_post( $fundraiser->post_id, true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( "{$wpdb->prefix}missiondp_fundraisers", [ 'post_id' => 0 ], [ 'id' => $fundraiser->id ] );

		$legacy = Fundraiser::find( $fundraiser->id );
		$this->assertSame( 0, $legacy->post_id );

		$legacy->save();

		$this->assertGreaterThan( 0, $legacy->post_id );
		$this->assertSame( Fundraiser::POST_TYPE, get_post( $legacy->post_id )->post_type );
	}

	/**
	 * Test the post_id column rejects duplicates.
	 */
	public function test_post_id_is_unique(): void {
		global $wpdb;

		$first  = $this->create_fundraiser( 1, 1 );
		$second = $this->create_fundraiser( 1, 2 );

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( "{$wpdb->prefix}missiondp_fundraisers", [ 'post_id' => $first->post_id ], [ 'id' => $second->id ] );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $result );
	}
}
