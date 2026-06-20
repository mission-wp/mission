<?php
/**
 * Tests for the TeamInvitation model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Team;
use MissionDP\Models\TeamInvitation;
use WP_UnitTestCase;

/**
 * TeamInvitation model test class.
 */
class TeamInvitationTest extends WP_UnitTestCase {

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_teams" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save an invitation.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return TeamInvitation
	 */
	private function create_invitation( array $overrides = [] ): TeamInvitation {
		$invitation = new TeamInvitation( array_merge(
			[
				'team_id' => 1,
				'email'   => 'invitee@example.com',
			],
			$overrides
		) );
		$invitation->save();

		return $invitation;
	}

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$invitation = new TeamInvitation();

		$this->assertNull( $invitation->id );
		$this->assertSame( 0, $invitation->team_id );
		$this->assertSame( '', $invitation->email );
		$this->assertSame( 'pending', $invitation->status );
		$this->assertNull( $invitation->sent_at );
	}

	/**
	 * Test save() and find() round-trip; date_created is stamped.
	 */
	public function test_save_and_find_round_trip(): void {
		$invitation = $this->create_invitation( [ 'email' => 'jane@example.com' ] );

		$found = TeamInvitation::find( $invitation->id );
		$this->assertNotNull( $found );
		$this->assertSame( 'jane@example.com', $found->email );
		$this->assertSame( 'pending', $found->status );
		$this->assertNotEmpty( $found->date_created );
		$this->assertNull( $found->sent_at );
	}

	/**
	 * Test sent_at persists once stamped.
	 */
	public function test_sent_at_persists(): void {
		$invitation          = $this->create_invitation();
		$invitation->status  = 'accepted';
		$invitation->sent_at = '2026-06-01 12:00:00';
		$invitation->save();

		$found = TeamInvitation::find( $invitation->id );
		$this->assertSame( 'accepted', $found->status );
		$this->assertSame( '2026-06-01 12:00:00', $found->sent_at );
	}

	/**
	 * Test mission_team_invitation_created action fires on insert.
	 */
	public function test_created_action_fires(): void {
		$fired = false;

		add_action( 'mission_team_invitation_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->create_invitation();

		$this->assertTrue( $fired );
	}

	/**
	 * Test query() filters by team, email, and status.
	 */
	public function test_query_filters(): void {
		$this->create_invitation( [ 'team_id' => 1, 'email' => 'a@example.com', 'status' => 'pending' ] );
		$this->create_invitation( [ 'team_id' => 1, 'email' => 'b@example.com', 'status' => 'accepted' ] );
		$this->create_invitation( [ 'team_id' => 2, 'email' => 'c@example.com', 'status' => 'pending' ] );

		$this->assertCount( 2, TeamInvitation::query( [ 'team_id' => 1 ] ) );
		$this->assertCount( 2, TeamInvitation::query( [ 'status' => 'pending' ] ) );
		$this->assertCount( 1, TeamInvitation::query( [ 'email' => 'b@example.com' ] ) );
		$this->assertSame( 3, TeamInvitation::count() );
	}

	/**
	 * Test team() relationship resolves.
	 */
	public function test_team_relationship(): void {
		$team = new Team( [ 'campaign_id' => 1, 'name' => 'Invitees' ] );
		$team->save();

		$invitation = $this->create_invitation( [ 'team_id' => $team->id ] );

		$this->assertSame( $team->id, $invitation->team()->id );
	}

	/**
	 * Test the token round-trips through save and find.
	 */
	public function test_token_round_trip(): void {
		$invitation = $this->create_invitation( [ 'token' => 'abc123token' ] );

		$found = TeamInvitation::find( $invitation->id );
		$this->assertSame( 'abc123token', $found->token );
	}

	/**
	 * Test find_by_token() locates the invitation, or returns null.
	 */
	public function test_find_by_token(): void {
		$invitation = $this->create_invitation( [ 'token' => 'lookup-token' ] );

		$found = TeamInvitation::find_by_token( 'lookup-token' );
		$this->assertNotNull( $found );
		$this->assertSame( $invitation->id, $found->id );

		$this->assertNull( TeamInvitation::find_by_token( 'missing' ) );
		$this->assertNull( TeamInvitation::find_by_token( '' ) );
	}

	/**
	 * Test is_pending() reflects the status.
	 */
	public function test_is_pending(): void {
		$pending  = $this->create_invitation( [ 'status' => TeamInvitation::STATUS_PENDING ] );
		$accepted = $this->create_invitation( [ 'status' => TeamInvitation::STATUS_ACCEPTED ] );

		$this->assertTrue( $pending->is_pending() );
		$this->assertFalse( $accepted->is_pending() );
	}

	/**
	 * Test is_expired() compares date_created against the filterable TTL.
	 */
	public function test_is_expired(): void {
		$fresh = $this->create_invitation( [ 'date_created' => current_time( 'mysql', true ) ] );
		$this->assertFalse( $fresh->is_expired() );

		$stale = $this->create_invitation( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) ] );
		$this->assertTrue( $stale->is_expired() );
	}

	/**
	 * Test the TTL filter changes the expiry window.
	 */
	public function test_is_expired_respects_ttl_filter(): void {
		$invitation = $this->create_invitation( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ) ] );

		$this->assertFalse( $invitation->is_expired() );

		add_filter( 'mission_team_invitation_ttl', static fn() => DAY_IN_SECONDS );
		$this->assertTrue( $invitation->is_expired() );
	}
}
