<?php
/**
 * Tests for the TeamMembersEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TeamMembersEndpoint test class.
 */
class TeamMembersEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Campaign for tests.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $this->admin_id );

		$this->campaign = new Campaign( [
			'title' => 'P2P Campaign',
			'type'  => Campaign::TYPE_P2P,
		] );
		$this->campaign->save();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create a team.
	 *
	 * @param array $overrides Data overrides.
	 * @return Team
	 */
	private function create_team( array $overrides = [] ): Team {
		$team = new Team( array_merge(
			[
				'campaign_id' => $this->campaign->id,
				'name'        => 'Marathon Runners',
				'status'      => Team::STATUS_ACTIVE,
				'goal'        => 200000,
			],
			$overrides
		) );
		$team->save();

		return $team;
	}

	/**
	 * Create a fundraiser, optionally on a team.
	 *
	 * @param array $overrides Data overrides (donor_id auto-created when absent).
	 * @return Fundraiser
	 */
	private function create_member( array $overrides = [] ): Fundraiser {
		if ( empty( $overrides['donor_id'] ) ) {
			$donor = new Donor( [
				'email'      => uniqid( 'd', true ) . '@example.com',
				'first_name' => 'Sam',
				'last_name'  => 'Smith',
			] );
			$donor->save();
			$overrides['donor_id'] = $donor->id;
		}

		$fundraiser = new Fundraiser( array_merge(
			[
				'campaign_id' => $this->campaign->id,
				'status'      => Fundraiser::STATUS_ACTIVE,
				'goal'        => 50000,
			],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Dispatch a POST member action.
	 *
	 * @param int    $team_id       Team ID.
	 * @param int    $fundraiser_id Fundraiser ID.
	 * @param string $action        Route action segment (promote or remove).
	 * @return \WP_REST_Response
	 */
	private function dispatch_action( int $team_id, int $fundraiser_id, string $action ): \WP_REST_Response {
		$route = "/mission-donation-platform/v1/teams/{$team_id}/members/{$fundraiser_id}/{$action}";

		return $this->server->dispatch( new WP_REST_Request( 'POST', $route ) );
	}

	/**
	 * Test both routes reject a subscriber with 403 and change nothing.
	 */
	public function test_routes_require_manage_options(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( [ 'team_id' => $team->id ] );
		$team->set_captain( $captain );
		$member = $this->create_member( [ 'team_id' => $team->id ] );

		wp_set_current_user( $this->subscriber_id );

		foreach ( [ 'promote', 'remove' ] as $action ) {
			$response = $this->dispatch_action( $team->id, $member->id, $action );
			$this->assertSame( 403, $response->get_status(), "{$action} should be forbidden for a subscriber." );
		}

		$this->assertSame( $captain->id, Team::find( $team->id )->captain_id );
		$this->assertSame( $team->id, Fundraiser::find( $member->id )->team_id );
	}

	/**
	 * Test promote repoints the captaincy.
	 */
	public function test_promote_member_repoints_captain(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( [ 'team_id' => $team->id ] );
		$team->set_captain( $captain );
		$member = $this->create_member( [ 'team_id' => $team->id ] );

		$response = $this->dispatch_action( $team->id, $member->id, 'promote' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( $member->id, Team::find( $team->id )->captain_id );
		$this->assertTrue( Fundraiser::find( $member->id )->is_captain() );
		$this->assertFalse( Fundraiser::find( $captain->id )->is_captain() );
	}

	/**
	 * Test remove detaches the member from the team.
	 */
	public function test_remove_member_clears_team(): void {
		$team   = $this->create_team();
		$member = $this->create_member( [ 'team_id' => $team->id ] );

		$response = $this->dispatch_action( $team->id, $member->id, 'remove' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Fundraiser::find( $member->id )->team_id );
	}

	/**
	 * Test removing the captain is rejected.
	 */
	public function test_remove_captain_rejected(): void {
		$team    = $this->create_team();
		$captain = $this->create_member( [ 'team_id' => $team->id ] );
		$team->set_captain( $captain );

		$response = $this->dispatch_action( $team->id, $captain->id, 'remove' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cannot_remove_captain', $response->get_data()['code'] );
		$this->assertSame( $team->id, Fundraiser::find( $captain->id )->team_id );
	}

	/**
	 * Test acting on a non-member is rejected.
	 */
	public function test_non_member_rejected(): void {
		$team     = $this->create_team();
		$outsider = $this->create_member();

		foreach ( [ 'promote', 'remove' ] as $action ) {
			$response = $this->dispatch_action( $team->id, $outsider->id, $action );

			$this->assertSame( 400, $response->get_status(), "{$action} should reject a non-member." );
			$this->assertSame( 'not_a_member', $response->get_data()['code'] );
		}
	}

	/**
	 * Test missing team and missing member return 404.
	 */
	public function test_missing_team_and_member_404(): void {
		$team   = $this->create_team();
		$member = $this->create_member( [ 'team_id' => $team->id ] );

		$missing_team = $this->dispatch_action( 999999, $member->id, 'promote' );
		$this->assertSame( 404, $missing_team->get_status() );
		$this->assertSame( 'team_not_found', $missing_team->get_data()['code'] );

		$missing_member = $this->dispatch_action( $team->id, 999999, 'promote' );
		$this->assertSame( 404, $missing_member->get_status() );
		$this->assertSame( 'member_not_found', $missing_member->get_data()['code'] );
	}
}
