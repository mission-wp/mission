<?php
/**
 * Tests for the TeamsEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\Models\Transaction;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TeamsEndpoint test class.
 */
class TeamsEndpointTest extends WP_UnitTestCase {

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
	 * Hooks added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = [];

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
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Add an action hook and track it for cleanup.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_tracked_action( string $hook, callable $callback, int $priority = 10 ): void {
		add_action( $hook, $callback, $priority );
		$this->hooks_to_remove[] = [ $hook, $callback, $priority ];
	}

	/**
	 * Create a P2P campaign.
	 *
	 * @param array $overrides Data overrides.
	 * @return Campaign
	 */
	private function create_p2p_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[
				'title' => 'P2P Campaign',
				'type'  => Campaign::TYPE_P2P,
			],
			$overrides
		) );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Create a team.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $overrides   Data overrides.
	 * @return Team
	 */
	private function create_team( int $campaign_id, array $overrides = [] ): Team {
		$team = new Team( array_merge(
			[
				'campaign_id' => $campaign_id,
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
	 * @param int   $campaign_id Campaign ID.
	 * @param array $overrides   Data overrides (donor_id auto-created when absent).
	 * @return Fundraiser
	 */
	private function create_member( int $campaign_id, array $overrides = [] ): Fundraiser {
		if ( empty( $overrides['donor_id'] ) ) {
			$donor               = new Donor( [ 'email' => uniqid( 'd', true ) . '@example.com', 'first_name' => 'Sam', 'last_name' => 'Smith' ] );
			$donor->save();
			$overrides['donor_id'] = $donor->id;
		}

		// Mirror raised totals into the test column so assertions hold in either mode.
		if ( isset( $overrides['total_raised'] ) && ! isset( $overrides['test_total_raised'] ) ) {
			$overrides['test_total_raised'] = $overrides['total_raised'];
		}

		$fundraiser = new Fundraiser( array_merge(
			[
				'campaign_id' => $campaign_id,
				'status'      => Fundraiser::STATUS_ACTIVE,
				'goal'        => 50000,
			],
			$overrides
		) );
		$fundraiser->save();

		return $fundraiser;
	}

	/**
	 * Test list requires manage_options capability.
	 */
	public function test_list_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test every route rejects a subscriber with 403 and changes nothing.
	 */
	public function test_all_routes_require_manage_options(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team(
			$campaign->id,
			[
				'name'   => 'Original',
				'status' => Team::STATUS_PENDING,
			]
		);

		wp_set_current_user( $this->subscriber_id );

		$base   = '/mission-donation-platform/v1/teams';
		$routes = [
			[ 'GET', "$base/{$team->id}", [] ],
			[ 'POST', $base, [ 'campaign_id' => $campaign->id, 'name' => 'Intruders' ] ],
			[ 'PUT', "$base/{$team->id}", [ 'name' => 'Hijacked' ] ],
			[ 'POST', "$base/{$team->id}/approve", [] ],
			[ 'GET', "$base/summary", [] ],
			[ 'DELETE', "$base/{$team->id}", [] ],
		];

		foreach ( $routes as [ $method, $route, $body ] ) {
			$request = new WP_REST_Request( $method, $route );
			if ( $body ) {
				$request->set_body_params( $body );
			}

			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status(), "$method $route should be forbidden for a subscriber." );
		}

		$after = Team::find( $team->id );
		$this->assertNotNull( $after );
		$this->assertSame( Team::STATUS_PENDING, $after->status );
		$this->assertSame( 'Original', $after->name );
		$this->assertCount( 1, Team::query( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test list returns teams with campaign title, member count, and raised total.
	 */
	public function test_list_returns_teams_with_relations(): void {
		$campaign = $this->create_p2p_campaign( [ 'title' => 'Marathon' ] );
		$team     = $this->create_team( $campaign->id );
		$this->create_member( $campaign->id, [ 'team_id' => $team->id, 'total_raised' => 30000 ] );
		$this->create_member( $campaign->id, [ 'team_id' => $team->id, 'total_raised' => 20000 ] );

		// A gift made directly to the team counts toward its raised total.
		// Mirrored live/test so the assertion holds in either mode.
		foreach ( [ false, true ] as $is_test ) {
			( new Transaction( [
				'status'   => Transaction::STATUS_COMPLETED,
				'donor_id' => 99,
				'team_id'  => $team->id,
				'amount'   => 10000,
				'is_test'  => $is_test,
			] ) )->save();
		}

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Marathon', $data[0]['campaign_title'] );
		$this->assertSame( 2, $data[0]['member_count'] );
		$this->assertSame( 60000, $data[0]['raised'] );
	}

	/**
	 * Test list includes the captain's name.
	 */
	public function test_list_includes_captain_name(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id );
		$captain          = $this->create_member( $campaign->id, [ 'team_id' => $team->id ] );
		$team->captain_id = $captain->id;
		$team->save();

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'Sam Smith', $response->get_data()[0]['captain_name'] );
	}

	/**
	 * Test list filters by status.
	 */
	public function test_list_filters_by_status(): void {
		$campaign = $this->create_p2p_campaign();
		$this->create_team( $campaign->id, [ 'name' => 'Active', 'status' => Team::STATUS_ACTIVE ] );
		$this->create_team( $campaign->id, [ 'name' => 'Pending', 'status' => Team::STATUS_PENDING ] );

		$request = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams' );
		$request->set_param( 'status', Team::STATUS_PENDING );
		$response = $this->server->dispatch( $request );

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'Pending', $response->get_data()[0]['name'] );
	}

	/**
	 * Test GET single returns detail and 404 for missing.
	 */
	public function test_get_single_and_404(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams/' . $team->id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Marathon Runners', $response->get_data()['name'] );

		$missing = $this->server->dispatch( new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams/999999' ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test GET single includes live totals, leaderboard rank, and page URL.
	 */
	public function test_get_single_includes_detail_fields(): void {
		$campaign = $this->create_p2p_campaign();
		$leader   = $this->create_team( $campaign->id, [ 'name' => 'Leaders' ] );
		$team     = $this->create_team( $campaign->id, [ 'name' => 'Runners-up' ] );

		// Leader: one member with 60000 raised. Team under test: one member with
		// 20000 raised across 2 gifts, plus a 10000 direct team gift.
		$this->create_member( $campaign->id, [ 'team_id' => $leader->id, 'total_raised' => 60000 ] );
		$this->create_member(
			$campaign->id,
			[
				'team_id'                => $team->id,
				'total_raised'           => 20000,
				'transaction_count'      => 2,
				'test_transaction_count' => 2,
			]
		);
		// Mirrored live/test so the assertions hold in either mode.
		foreach ( [ false, true ] as $is_test ) {
			( new Transaction( [
				'status'   => Transaction::STATUS_COMPLETED,
				'donor_id' => 99,
				'team_id'  => $team->id,
				'amount'   => 10000,
				'is_test'  => $is_test,
			] ) )->save();
		}

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams/' . $team->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 30000, $data['raised'] );
		$this->assertSame( 3, $data['donation_count'] );
		$this->assertSame( 1, $data['member_count'] );
		$this->assertSame( 2, $data['rank'] );
		$this->assertSame( 2, $data['rank_total'] );
		$this->assertStringContainsString( 'team', $data['page_url'] );
	}

	/**
	 * Test POST creates a team.
	 */
	public function test_create_team(): void {
		$campaign = $this->create_p2p_campaign();

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'Trail Blazers',
			'goal'        => 300000,
			'access'      => 'private',
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Trail Blazers', $data['name'] );
		$this->assertSame( 300000, $data['goal'] );
		$this->assertSame( 'private', $data['access'] );
	}

	/**
	 * Test POST defaults the goal to the campaign's configured default.
	 */
	public function test_create_uses_default_goal(): void {
		$campaign = $this->create_p2p_campaign();

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'Default Goal Team',
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 200000, $response->get_data()['goal'] );
	}

	/**
	 * Test POST rejects a non-P2P campaign.
	 */
	public function test_create_rejects_non_p2p_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Standard' ] );
		$campaign->save();

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'No Team Allowed',
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_campaign_type', $response->get_data()['code'] );
	}

	/**
	 * Test POST rejects a captain from a different campaign.
	 */
	public function test_create_rejects_invalid_captain(): void {
		$campaign       = $this->create_p2p_campaign();
		$other_campaign = $this->create_p2p_campaign( [ 'title' => 'Other' ] );
		$outsider       = $this->create_member( $other_campaign->id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'Bad Captain Team',
			'captain_id'  => $outsider->id,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_captain', $response->get_data()['code'] );
	}

	/**
	 * Test POST with a captain who already captains another team vacates the
	 * old captaincy instead of leaving it pointing at a non-member.
	 */
	public function test_create_with_captain_vacates_previous_captaincy(): void {
		$campaign = $this->create_p2p_campaign();
		$old_team = $this->create_team( $campaign->id, [ 'name' => 'Old Guard' ] );
		$captain  = $this->create_member( $campaign->id, [ 'team_id' => $old_team->id ] );

		$old_team->captain_id = $captain->id;
		$old_team->save();

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'New Horizons',
			'captain_id'  => $captain->id,
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $captain->id, $data['captain_id'] );

		$new_team = Team::find( $data['id'] );
		$this->assertSame( $captain->id, $new_team->captain_id );
		$this->assertSame( $new_team->id, Fundraiser::find( $captain->id )->team_id );
		$this->assertNull( Team::find( $old_team->id )->captain_id );
	}

	/**
	 * Test POST rolls back the created team when the captain assignment fails.
	 */
	public function test_create_rolls_back_team_when_captain_assignment_fails(): void {
		global $wpdb;

		$campaign = $this->create_p2p_campaign();
		$captain  = $this->create_member( $campaign->id );

		// Sabotage fundraiser updates so join_team()'s save fails mid-request.
		$break_updates = function ( $query ) use ( $wpdb ) {
			if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, "{$wpdb->prefix}missiondp_fundraisers" ) ) {
				return "UPDATE {$wpdb->prefix}missiondp_nonexistent SET id = 0";
			}
			return $query;
		};
		add_filter( 'query', $break_updates );
		$suppress = $wpdb->suppress_errors( true );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'Doomed Team',
			'captain_id'  => $captain->id,
		] );

		$response = $this->server->dispatch( $request );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_updates );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'rest_cannot_create', $response->get_data()['code'] );

		// The half-created team was rolled back and the captain is untouched.
		$this->assertCount( 0, Team::query( [ 'campaign_id' => $campaign->id ] ) );
		$this->assertNull( Fundraiser::find( $captain->id )->team_id );
	}

	/**
	 * Test POST fires the team_created action.
	 */
	public function test_create_fires_created_action(): void {
		$campaign = $this->create_p2p_campaign();
		$fired    = false;

		$this->add_tracked_action( 'mission_team_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'name'        => 'Eventful Team',
		] );
		$this->server->dispatch( $request );

		$this->assertTrue( $fired );
	}

	/**
	 * Test PUT updates editable fields.
	 */
	public function test_update_team(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id, [ 'name' => 'Old Name' ] );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/teams/' . $team->id );
		$request->set_body_params( [
			'name' => 'New Name',
			'goal' => 400000,
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New Name', $data['name'] );
		$this->assertSame( 400000, $data['goal'] );
	}

	/**
	 * Test changing the captain via PUT repoints captain_id and rejects
	 * non-members.
	 */
	public function test_update_team_captain_syncs_member_flags(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id );
		$old      = $this->create_member( $campaign->id, [ 'team_id' => $team->id ] );
		$new      = $this->create_member( $campaign->id, [ 'team_id' => $team->id ] );
		$outsider = $this->create_member( $campaign->id );

		$team->captain_id = $old->id;
		$team->save();

		// A fundraiser who isn't on the team can't become its captain.
		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/teams/' . $team->id );
		$request->set_body_params( [ 'captain_id' => $outsider->id ] );
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );

		// Promoting a member repoints captain_id.
		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/teams/' . $team->id );
		$request->set_body_params( [ 'captain_id' => $new->id ] );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$this->assertSame( $new->id, Team::find( $team->id )->captain_id );
		$this->assertTrue( Fundraiser::find( $new->id )->is_captain() );
		$this->assertFalse( Fundraiser::find( $old->id )->is_captain() );

		// Clearing the captain leaves the team captainless.
		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/teams/' . $team->id );
		$request->set_body_params( [ 'captain_id' => 0 ] );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$this->assertNull( Team::find( $team->id )->captain_id );
		$this->assertFalse( Fundraiser::find( $new->id )->is_captain() );
	}

	/**
	 * Test approving via PUT fires the approval event like the /approve route.
	 */
	public function test_update_team_status_fires_approval_event(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id, [ 'status' => 'pending' ] );

		$fired = did_action( 'mission_team_approved' );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/teams/' . $team->id );
		$request->set_body_params( [ 'status' => 'active' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertSame( $fired + 1, did_action( 'mission_team_approved' ) );
	}

	/**
	 * Test DELETE removes the team and 404s for missing.
	 */
	public function test_delete_team(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id );

		$request  = new WP_REST_Request( 'DELETE', '/mission-donation-platform/v1/teams/' . $team->id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Team::find( $team->id ) );

		$missing = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/mission-donation-platform/v1/teams/999999' ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test approve transitions a pending team and fires the action.
	 */
	public function test_approve_team(): void {
		$campaign = $this->create_p2p_campaign();
		$team     = $this->create_team( $campaign->id, [ 'status' => Team::STATUS_PENDING ] );
		$fired    = false;

		$this->add_tracked_action( 'mission_team_approved', function () use ( &$fired ) {
			$fired = true;
		} );

		$request  = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/teams/' . $team->id . '/approve' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertTrue( $fired );
	}

	/**
	 * Test summary returns aggregate stats.
	 */
	public function test_summary(): void {
		$campaign = $this->create_p2p_campaign();
		$this->create_team( $campaign->id, [ 'name' => 'A', 'status' => Team::STATUS_ACTIVE ] );
		$this->create_team( $campaign->id, [ 'name' => 'B', 'status' => Team::STATUS_PENDING ] );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/teams/summary' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['total_teams'] );
		$this->assertSame( 1, $data['active_count'] );
		$this->assertSame( 1, $data['pending_count'] );
	}
}
