<?php
/**
 * Tests for the FundraisersEndpoint class.
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
 * FundraisersEndpoint test class.
 */
class FundraisersEndpointTest extends WP_UnitTestCase {

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
	 * Create a donor.
	 *
	 * @param string $email Email address.
	 * @param array  $overrides Data overrides.
	 * @return Donor
	 */
	private function create_donor( string $email, array $overrides = [] ): Donor {
		$donor = new Donor( array_merge(
			[
				'email'      => $email,
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			],
			$overrides
		) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create a fundraiser.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param int   $donor_id    Donor ID.
	 * @param array $overrides   Data overrides.
	 * @return Fundraiser
	 */
	private function create_fundraiser( int $campaign_id, int $donor_id, array $overrides = [] ): Fundraiser {
		$fundraiser = new Fundraiser( array_merge(
			[
				'campaign_id' => $campaign_id,
				'donor_id'    => $donor_id,
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

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test every route rejects a subscriber with 403 and changes nothing.
	 */
	public function test_all_routes_require_manage_options(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser(
			$campaign->id,
			$donor->id,
			[
				'status'   => Fundraiser::STATUS_PENDING,
				'headline' => 'Original',
			]
		);

		wp_set_current_user( $this->subscriber_id );

		$base   = '/mission-donation-platform/v1/fundraisers';
		$routes = [
			[ 'GET', "$base/{$fundraiser->id}", [] ],
			[ 'POST', $base, [ 'campaign_id' => $campaign->id, 'donor_id' => $donor->id ] ],
			[ 'PUT', "$base/{$fundraiser->id}", [ 'headline' => 'Hijacked' ] ],
			[ 'POST', "$base/{$fundraiser->id}/approve", [] ],
			[ 'GET', "$base/summary", [] ],
			[ 'DELETE', "$base/{$fundraiser->id}", [] ],
		];

		foreach ( $routes as [ $method, $route, $body ] ) {
			$request = new WP_REST_Request( $method, $route );
			if ( $body ) {
				$request->set_body_params( $body );
			}

			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status(), "$method $route should be forbidden for a subscriber." );
		}

		$after = Fundraiser::find( $fundraiser->id );
		$this->assertNotNull( $after );
		$this->assertSame( Fundraiser::STATUS_PENDING, $after->status );
		$this->assertSame( 'Original', $after->headline );
		$this->assertCount( 1, Fundraiser::query( [ 'campaign_id' => $campaign->id ] ) );
	}

	/**
	 * Test list returns fundraisers with related names.
	 */
	public function test_list_returns_fundraisers_with_relations(): void {
		$campaign = $this->create_p2p_campaign( [ 'title' => 'Marathon' ] );
		$donor    = $this->create_donor( 'jane@example.com' );
		$this->create_fundraiser( $campaign->id, $donor->id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Jane Doe', $data[0]['donor_name'] );
		$this->assertSame( 'Marathon', $data[0]['campaign_title'] );
		$this->assertSame( 0, $data[0]['transaction_count'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Test list filters by campaign.
	 */
	public function test_list_filters_by_campaign(): void {
		$campaign_a = $this->create_p2p_campaign( [ 'title' => 'A' ] );
		$campaign_b = $this->create_p2p_campaign( [ 'title' => 'B' ] );
		$donor      = $this->create_donor( 'jane@example.com' );
		$donor_b    = $this->create_donor( 'jane2@example.com' );
		$this->create_fundraiser( $campaign_a->id, $donor->id );
		$this->create_fundraiser( $campaign_b->id, $donor_b->id );

		$request = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers' );
		$request->set_param( 'campaign_id', $campaign_a->id );
		$response = $this->server->dispatch( $request );

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( $campaign_a->id, $response->get_data()[0]['campaign_id'] );
	}

	/**
	 * Test list filters by status.
	 */
	public function test_list_filters_by_status(): void {
		$campaign = $this->create_p2p_campaign();
		$donor_a  = $this->create_donor( 'a@example.com' );
		$donor_b  = $this->create_donor( 'b@example.com' );
		$this->create_fundraiser( $campaign->id, $donor_a->id, [ 'status' => Fundraiser::STATUS_ACTIVE ] );
		$this->create_fundraiser( $campaign->id, $donor_b->id, [ 'status' => Fundraiser::STATUS_PENDING ] );

		$request = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers' );
		$request->set_param( 'status', Fundraiser::STATUS_PENDING );
		$response = $this->server->dispatch( $request );

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'pending', $response->get_data()[0]['status'] );
	}

	/**
	 * Test GET single returns detail and 404 for missing.
	 */
	public function test_get_single_and_404(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'jane@example.com', $response->get_data()['donor_email'] );

		$missing = $this->server->dispatch( new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers/999999' ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test GET single includes the detail-page fields.
	 */
	public function test_get_single_includes_detail_fields(): void {
		$end_date   = gmdate( 'Y-m-d H:i:s', strtotime( '+10 days' ) );
		$campaign   = $this->create_p2p_campaign( [ 'date_end' => $end_date ] );
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $data['transaction_count'] );
		$this->assertNull( $data['dedication'] );
		$this->assertStringContainsString( 'fundraiser', $data['page_url'] );
		$this->assertSame( $end_date, $data['campaign_end_date'] );
		$this->assertIsInt( $data['campaign_days_left'] );
	}

	/**
	 * Test PUT sets and clears the dedication.
	 */
	public function test_update_sets_and_clears_dedication(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [
			'dedication_type' => 'memory',
			'dedication_name' => 'Jane Smith',
		] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'type' => 'memory',
				'name' => 'Jane Smith',
			],
			$response->get_data()['dedication']
		);

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [
			'dedication_type' => '',
			'dedication_name' => '',
		] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['dedication'] );
		$this->assertNull( Fundraiser::find( $fundraiser->id )->dedication() );
	}

	/**
	 * Test POST creates a fundraiser.
	 */
	public function test_create_fundraiser(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
			'goal'        => 120000,
			'headline'    => 'Running for clean water',
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 120000, $data['goal'] );
		$this->assertSame( 'Running for clean water', $data['headline'] );
		$this->assertSame( 'active', $data['status'] );
	}

	/**
	 * Test POST defaults the goal to the campaign's configured default.
	 */
	public function test_create_uses_default_goal(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 50000, $response->get_data()['goal'] );
	}

	/**
	 * Test POST rejects a non-P2P campaign.
	 */
	public function test_create_rejects_non_p2p_campaign(): void {
		$campaign = new Campaign( [ 'title' => 'Standard' ] );
		$campaign->save();
		$donor = $this->create_donor( 'jane@example.com' );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_campaign_type', $response->get_data()['code'] );
	}

	/**
	 * Test POST rejects a missing donor.
	 */
	public function test_create_rejects_missing_donor(): void {
		$campaign = $this->create_p2p_campaign();

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => 999999,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test POST rejects a duplicate fundraiser for the same person/campaign.
	 */
	public function test_create_rejects_duplicate(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );
		$this->create_fundraiser( $campaign->id, $donor->id );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'duplicate_fundraiser', $response->get_data()['code'] );
	}

	/**
	 * Test POST fires the fundraiser_created action.
	 */
	public function test_create_fires_created_action(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );
		$fired    = false;

		$this->add_tracked_action( 'mission_fundraiser_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
		] );
		$this->server->dispatch( $request );

		$this->assertTrue( $fired );
	}

	/**
	 * Test PUT updates editable fields.
	 */
	public function test_update_fundraiser(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id, [ 'headline' => 'Old' ] );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [
			'headline' => 'New headline',
			'goal'     => 90000,
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New headline', $data['headline'] );
		$this->assertSame( 90000, $data['goal'] );
	}

	/**
	 * Test PUT team change vacates the old team's captaincy and clears the flag.
	 */
	public function test_update_team_change_vacates_old_captaincy(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );

		$team_a = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team A' ] );
		$team_a->save();
		$team_b = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team B' ] );
		$team_b->save();

		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );
		$fundraiser->join_team( $team_a, true );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [ 'team_id' => $team_b->id ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $team_b->id, $data['team_id'] );
		$this->assertFalse( $data['is_team_captain'] );

		// The old team must not keep a captain who is no longer a member.
		$this->assertNull( Team::find( $team_a->id )->captain_id );
		$this->assertFalse( Fundraiser::find( $fundraiser->id )->is_captain() );
	}

	/**
	 * Test PUT with a null team_id removes the fundraiser from its team.
	 */
	public function test_update_null_team_removes_from_team(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );

		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team A' ] );
		$team->save();

		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );
		$fundraiser->join_team( $team, true );
		$team->set_captain( $fundraiser );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [ 'team_id' => null ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['team_id'] );
		$this->assertNull( Fundraiser::find( $fundraiser->id )->team_id );
		$this->assertNull( Team::find( $team->id )->captain_id );
	}

	/**
	 * Test POST with a team fires the joined event like a self-serve signup.
	 */
	public function test_create_with_team_fires_joined_event(): void {
		$campaign = $this->create_p2p_campaign();
		$donor    = $this->create_donor( 'jane@example.com' );

		$team = new Team( [ 'campaign_id' => $campaign->id, 'name' => 'Team A' ] );
		$team->save();

		$joined   = [];
		$callback = function ( $fundraiser, $joined_team ) use ( &$joined ) {
			$joined = [ $fundraiser->id, $joined_team->id ];
		};
		add_action( 'mission_team_joined', $callback, 10, 2 );
		$this->hooks_to_remove[] = [ 'mission_team_joined', $callback, 10 ];

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers' );
		$request->set_body_params( [
			'campaign_id' => $campaign->id,
			'donor_id'    => $donor->id,
			'team_id'     => $team->id,
		] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $team->id, $data['team_id'] );
		$this->assertSame( [ $data['id'], $team->id ], $joined );
	}

	/**
	 * Test approving via PUT fires the approval event like the /approve route.
	 */
	public function test_update_fundraiser_status_fires_approval_event(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'pending@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id, [ 'status' => 'pending' ] );

		$fired = did_action( 'mission_fundraiser_approved' );

		$request = new WP_REST_Request( 'PUT', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$request->set_body_params( [ 'status' => 'active' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertSame( $fired + 1, did_action( 'mission_fundraiser_approved' ) );
	}

	/**
	 * Test DELETE removes the fundraiser and 404s for missing.
	 */
	public function test_delete_fundraiser(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id );

		$request  = new WP_REST_Request( 'DELETE', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Fundraiser::find( $fundraiser->id ) );

		$missing = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/mission-donation-platform/v1/fundraisers/999999' ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test approve transitions a pending fundraiser and fires the action.
	 */
	public function test_approve_fundraiser(): void {
		$campaign   = $this->create_p2p_campaign();
		$donor      = $this->create_donor( 'jane@example.com' );
		$fundraiser = $this->create_fundraiser( $campaign->id, $donor->id, [ 'status' => Fundraiser::STATUS_PENDING ] );
		$fired      = false;

		$this->add_tracked_action( 'mission_fundraiser_approved', function () use ( &$fired ) {
			$fired = true;
		} );

		$request  = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/fundraisers/' . $fundraiser->id . '/approve' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertTrue( $fired );
		$this->assertSame( 'active', Fundraiser::find( $fundraiser->id )->status );
	}

	/**
	 * Test summary returns aggregate stats.
	 */
	public function test_summary(): void {
		$campaign = $this->create_p2p_campaign();
		$donor_a  = $this->create_donor( 'a@example.com' );
		$donor_b  = $this->create_donor( 'b@example.com' );
		$this->create_fundraiser( $campaign->id, $donor_a->id, [ 'status' => Fundraiser::STATUS_ACTIVE ] );
		$this->create_fundraiser( $campaign->id, $donor_b->id, [ 'status' => Fundraiser::STATUS_PENDING ] );

		$request  = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/fundraisers/summary' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['total_fundraisers'] );
		$this->assertSame( 1, $data['active_count'] );
		$this->assertSame( 1, $data['pending_count'] );
	}
}
