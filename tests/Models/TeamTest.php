<?php
/**
 * Tests for the Team model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use WP_UnitTestCase;

/**
 * Team model test class.
 */
class TeamTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_team_invitations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teammeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a team.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Team
	 */
	private function create_team( array $overrides = [] ): Team {
		$team = new Team( array_merge(
			[
				'campaign_id' => 1,
				'name'        => 'Marathon Runners',
			],
			$overrides
		) );
		$team->save();

		return $team;
	}

	/**
	 * Create a member fundraiser on a team.
	 *
	 * @param int   $team_id   Team ID.
	 * @param int   $donor_id  Donor ID (must be unique per campaign).
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Fundraiser
	 */
	private function create_member( int $team_id, int $donor_id, array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser( array_merge(
			[
				'campaign_id' => 1,
				'donor_id'    => $donor_id,
				'team_id'     => $team_id,
			],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	// -------------------------------------------------------------------------
	// Construction tests.
	// -------------------------------------------------------------------------

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$team = new Team();

		$this->assertNull( $team->id );
		$this->assertSame( 0, $team->campaign_id );
		$this->assertNull( $team->captain_id );
		$this->assertSame( 0, $team->post_id );
		$this->assertSame( 'pending', $team->status );
		$this->assertSame( 'public', $team->access );
		$this->assertSame( 0, $team->goal );
	}

	/**
	 * Test save() and find() round-trip.
	 */
	public function test_save_and_find_round_trip(): void {
		$team = $this->create_team( [ 'name' => 'Trail Blazers', 'goal' => 100000, 'access' => 'private' ] );

		$found = Team::find( $team->id );
		$this->assertNotNull( $found );
		$this->assertSame( 'Trail Blazers', $found->name );
		$this->assertSame( 100000, $found->goal );
		$this->assertSame( 'private', $found->access );
	}

	/**
	 * Test mission_team_created action fires on insert.
	 */
	public function test_mission_team_created_action_fires(): void {
		$fired = false;

		add_action( 'mission_team_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->create_team();

		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------------
	// Registration tests.
	// -------------------------------------------------------------------------

	/**
	 * Test register() creates an active team with no captain yet.
	 */
	public function test_register_creates_team_without_captain(): void {
		$team = Team::register( 1, 'New Team', 50000 );

		$this->assertNotNull( $team->id );
		$this->assertNull( $team->captain_id );
		$this->assertSame( 'active', $team->status );
		$this->assertSame( 'public', $team->access );
		$this->assertSame( 50000, $team->goal );
		$this->assertGreaterThan( 0, $team->post_id );
	}

	/**
	 * Test set_captain() backfills captain_id and persists.
	 */
	public function test_set_captain_backfills_captain_id(): void {
		$team    = Team::register( 1, 'Captained', 50000 );
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );

		$this->assertTrue( $team->set_captain( $captain ) );
		$this->assertSame( $captain->id, $team->captain_id );
		$this->assertSame( $captain->id, Team::find( $team->id )->captain_id );
	}

	// -------------------------------------------------------------------------
	// query() / count() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test query() filters by campaign, status, and access.
	 */
	public function test_query_filters(): void {
		$this->create_team( [ 'campaign_id' => 1, 'status' => 'active', 'access' => 'public' ] );
		$this->create_team( [ 'campaign_id' => 1, 'status' => 'pending', 'access' => 'private' ] );
		$this->create_team( [ 'campaign_id' => 2, 'status' => 'active', 'access' => 'public' ] );

		$this->assertCount( 2, Team::query( [ 'campaign_id' => 1 ] ) );
		$this->assertCount( 2, Team::query( [ 'status' => 'active' ] ) );
		$this->assertCount( 1, Team::query( [ 'access' => 'private' ] ) );
		$this->assertSame( 3, Team::count() );
	}

	/**
	 * Test query() pagination.
	 */
	public function test_query_pagination(): void {
		$this->create_team( [ 'name' => 'A' ] );
		$this->create_team( [ 'name' => 'B' ] );
		$this->create_team( [ 'name' => 'C' ] );

		$page1 = Team::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$page2 = Team::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );
	}

	// -------------------------------------------------------------------------
	// Relationship tests.
	// -------------------------------------------------------------------------

	/**
	 * Test campaign() and captain() relationships resolve.
	 */
	public function test_campaign_and_captain_relationships(): void {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );
		$campaign->save();

		$team    = $this->create_team( [ 'campaign_id' => $campaign->id ] );
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );

		$team->captain_id = $captain->id;
		$team->save();

		$this->assertSame( $campaign->id, $team->campaign()->id );
		$this->assertSame( $captain->id, $team->captain()->id );
	}

	/**
	 * Test members() and member_count() reflect the team's fundraisers.
	 */
	public function test_members_and_member_count(): void {
		$team = $this->create_team();
		$this->create_member( $team->id, 1 );
		$this->create_member( $team->id, 2 );

		// A fundraiser on another team must not count.
		$other = $this->create_team( [ 'name' => 'Others' ] );
		$this->create_member( $other->id, 3 );

		$this->assertCount( 2, $team->members() );
		$this->assertSame( 2, $team->member_count() );
	}

	/**
	 * Test invitations() returns invitations for the team.
	 */
	public function test_invitations_relationship(): void {
		$team = $this->create_team();

		$invite = new TeamInvitation( [ 'team_id' => $team->id, 'email' => 'invitee@example.com' ] );
		$invite->save();

		$invitations = $team->invitations();
		$this->assertCount( 1, $invitations );
		$this->assertSame( 'invitee@example.com', $invitations[0]->email );
	}

	// -------------------------------------------------------------------------
	// amount_raised() / progress() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test amount_raised sums member fundraisers' stored totals.
	 */
	public function test_amount_raised_sums_members(): void {
		$team = $this->create_team();

		$this->assertSame( 0, $team->amount_raised() );

		$this->create_member( $team->id, 1, [ 'total_raised' => 5000, 'test_total_raised' => 100 ] );
		$this->create_member( $team->id, 2, [ 'total_raised' => 2500, 'test_total_raised' => 50 ] );

		$this->assertSame( 7500, $team->amount_raised() );
		$this->assertSame( 150, $team->amount_raised( true ) );
	}

	/**
	 * Test progress is 0 without a goal and capped at 100.
	 */
	public function test_progress(): void {
		$team = $this->create_team( [ 'goal' => 10000 ] );
		$this->create_member( $team->id, 1, [ 'total_raised' => 2500 ] );

		$this->assertSame( 25.0, $team->progress() );

		$no_goal = $this->create_team( [ 'name' => 'No Goal', 'goal' => 0 ] );
		$this->assertSame( 0.0, $no_goal->progress() );
	}

	// -------------------------------------------------------------------------
	// Meta tests.
	// -------------------------------------------------------------------------

	/**
	 * Test meta get/update via HasMeta.
	 */
	public function test_meta_crud(): void {
		$team = $this->create_team();

		$team->update_meta( 'rally_cry', 'Go team' );
		$this->assertSame( 'Go team', $team->get_meta( 'rally_cry' ) );
	}

	// -------------------------------------------------------------------------
	// Shell post (HasShellPost) tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() creates a shell post titled with the team name, status mapped.
	 */
	public function test_save_creates_shell_post(): void {
		$team = $this->create_team( [ 'name' => 'Trail Blazers', 'status' => 'pending' ] );

		$this->assertGreaterThan( 0, $team->post_id );

		$post = get_post( $team->post_id );
		$this->assertSame( 'missiondp_team', $post->post_type );
		$this->assertSame( 'Trail Blazers', $post->post_title );
		$this->assertSame( 'pending', $post->post_status );
	}

	/**
	 * Test approving publishes the shell post and deactivating drafts it.
	 */
	public function test_status_changes_sync_post_status(): void {
		$team    = $this->create_team( [ 'status' => 'pending' ] );
		$post_id = $team->post_id;

		$team->approve();
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		$team->deactivate();
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test find_by_post_id() resolves the team from its shell post.
	 */
	public function test_find_by_post_id(): void {
		$team = $this->create_team();

		$found = Team::find_by_post_id( $team->post_id );
		$this->assertNotNull( $found );
		$this->assertSame( $team->id, $found->id );

		$this->assertNull( Team::find_by_post_id( 0 ) );
	}

	/**
	 * Test the slug proxy reads post_name from the shell post.
	 */
	public function test_slug_proxy_reads_post_name(): void {
		// An active team publishes its shell post, so WP generates the slug from the name.
		$team = $this->create_team( [ 'name' => 'Trail Blazers', 'status' => 'active' ] );

		$this->assertSame( 'trail-blazers', Team::find( $team->id )->slug );
	}

	/**
	 * Test trash() trashes the post and removes the row.
	 */
	public function test_trash_trashes_post_and_deletes_row(): void {
		$team    = $this->create_team();
		$post_id = $team->post_id;
		$id      = $team->id;

		$this->assertTrue( $team->trash() );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertNull( Team::find( $id ) );
	}

	/**
	 * Test delete() permanently removes the shell post and the row.
	 */
	public function test_delete_removes_shell_post(): void {
		$team    = $this->create_team();
		$post_id = $team->post_id;
		$id      = $team->id;

		$this->assertTrue( $team->delete() );
		$this->assertNull( get_post( $post_id ) );
		$this->assertNull( Team::find( $id ) );
	}

	// -------------------------------------------------------------------------
	// Captain management: invite().
	// -------------------------------------------------------------------------

	/**
	 * Test invite() creates a pending invitation with a random token.
	 */
	public function test_invite_creates_pending_invitation_with_token(): void {
		$team   = $this->create_team();
		$invite = $team->invite( 'invitee@example.com' );

		$this->assertInstanceOf( TeamInvitation::class, $invite );
		$this->assertSame( 'invitee@example.com', $invite->email );
		$this->assertSame( TeamInvitation::STATUS_PENDING, $invite->status );
		$this->assertSame( 32, strlen( $invite->token ) );
		$this->assertSame( $team->id, $invite->team_id );
	}

	/**
	 * Test invite() rejects an invalid email address.
	 */
	public function test_invite_rejects_invalid_email(): void {
		$team   = $this->create_team();
		$result = $team->invite( 'not-an-email' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	/**
	 * Test invite() reuses an outstanding pending invitation rather than duplicating.
	 */
	public function test_invite_dedupes_pending_invitation(): void {
		$team  = $this->create_team();
		$first = $team->invite( 'invitee@example.com' );

		$second = $team->invite( 'invitee@example.com' );

		$this->assertSame( $first->id, $second->id );
		$this->assertCount( 1, $team->invitations() );
	}

	/**
	 * Test invite() rejects someone who is already a member.
	 */
	public function test_invite_rejects_existing_member(): void {
		$campaign = new Campaign( [ 'title' => 'P2P', 'type' => 'p2p' ] );
		$campaign->save();

		$donor = new \MissionDP\Models\Donor( [ 'email' => 'member@example.com', 'first_name' => 'Mem' ] );
		$donor->save();

		$team = $this->create_team( [ 'campaign_id' => $campaign->id ] );
		$this->create_member( $team->id, $donor->id, [ 'campaign_id' => $campaign->id ] );

		$result = $team->invite( 'member@example.com' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'already_member', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Captain management: remove_member().
	// -------------------------------------------------------------------------

	/**
	 * Test remove_member() clears the member's team association.
	 */
	public function test_remove_member_clears_team(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );
		$team->set_captain( $captain );
		$member = $this->create_member( $team->id, 2 );

		$this->assertTrue( $team->remove_member( $member ) );
		$this->assertNull( Fundraiser::find( $member->id )->team_id );
		$this->assertSame( 1, $team->member_count() );
	}

	/**
	 * Test remove_member() refuses to remove the captain.
	 */
	public function test_remove_member_refuses_captain(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );
		$team->set_captain( $captain );

		$result = $team->remove_member( $captain );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cannot_remove_captain', $result->get_error_code() );
		$this->assertSame( $team->id, Fundraiser::find( $captain->id )->team_id );
	}

	/**
	 * Test remove_member() rejects a fundraiser on a different team.
	 */
	public function test_remove_member_rejects_non_member(): void {
		$team  = $this->create_team();
		$other = $this->create_team( [ 'name' => 'Others' ] );
		$alien = $this->create_member( $other->id, 1 );

		$result = $team->remove_member( $alien );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_a_member', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Captain management: promote_captain().
	// -------------------------------------------------------------------------

	/**
	 * Test promote_captain() swaps the flag on both fundraisers and the team FK.
	 */
	public function test_promote_captain_updates_both_sides(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );
		$team->set_captain( $captain );
		$member = $this->create_member( $team->id, 2 );

		$this->assertTrue( $team->promote_captain( $member ) );

		$this->assertSame( $member->id, Team::find( $team->id )->captain_id );
		$this->assertTrue( Fundraiser::find( $member->id )->is_team_captain );
		$this->assertFalse( Fundraiser::find( $captain->id )->is_team_captain );
	}

	/**
	 * Test promote_captain() fires the captain-promoted action.
	 */
	public function test_promote_captain_fires_action(): void {
		$fired = false;
		add_action(
			'mission_team_captain_promoted',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$team    = $this->create_team();
		$captain = $this->create_member( $team->id, 1, [ 'is_team_captain' => true ] );
		$team->set_captain( $captain );
		$member = $this->create_member( $team->id, 2 );

		$team->promote_captain( $member );

		$this->assertTrue( $fired );
	}

	/**
	 * Test promote_captain() rejects a non-member.
	 */
	public function test_promote_captain_rejects_non_member(): void {
		$team  = $this->create_team();
		$other = $this->create_team( [ 'name' => 'Others' ] );
		$alien = $this->create_member( $other->id, 1 );

		$result = $team->promote_captain( $alien );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_a_member', $result->get_error_code() );
	}
}
