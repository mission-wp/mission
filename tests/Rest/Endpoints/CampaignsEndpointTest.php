<?php
/**
 * Tests for the CampaignsEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Models\Campaign;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * CampaignsEndpoint test class.
 */
class CampaignsEndpointTest extends WP_UnitTestCase {

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

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
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
	 * Create a campaign via the model and return it.
	 *
	 * @param array $overrides Data overrides.
	 * @return Campaign
	 */
	private function create_campaign( array $overrides = [] ): Campaign {
		$campaign = new Campaign( array_merge(
			[
				'title'       => 'Test Campaign',
				'description' => '',
				'goal_amount' => 0,
			],
			$overrides
		) );
		$campaign->save();

		return $campaign;
	}

	/**
	 * Test GET requires manage_options capability.
	 */
	public function test_get_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test GET returns campaigns with expected fields.
	 */
	public function test_get_returns_campaigns_with_expected_fields(): void {
		wp_set_current_user( $this->admin_id );

		$this->create_campaign( [ 'title' => 'Test Campaign' ] );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );

		$campaign = $data[0];
		$this->assertArrayHasKey( 'id', $campaign );
		$this->assertArrayHasKey( 'title', $campaign );
		$this->assertArrayHasKey( 'status', $campaign );
		$this->assertArrayHasKey( 'goal_amount', $campaign );
		$this->assertArrayHasKey( 'total_raised', $campaign );
		$this->assertArrayHasKey( 'transaction_count', $campaign );
		$this->assertArrayHasKey( 'edit_url', $campaign );
		$this->assertArrayHasKey( 'date_start', $campaign );
		$this->assertArrayHasKey( 'date_end', $campaign );

		$this->assertSame( 'Test Campaign', $campaign['title'] );
		$this->assertSame( 'active', $campaign['status'] );
	}

	/**
	 * Test GET returns empty array when no campaigns exist.
	 */
	public function test_get_returns_empty_when_no_campaigns(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * Test pagination headers are present.
	 */
	public function test_pagination_headers_present(): void {
		wp_set_current_user( $this->admin_id );

		$this->create_campaign();

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
		$this->assertSame( '1', $headers['X-WP-Total'] );
		$this->assertSame( '1', $headers['X-WP-TotalPages'] );
	}

	/**
	 * Test search filters by title.
	 */
	public function test_search_filters_by_title(): void {
		wp_set_current_user( $this->admin_id );

		$this->create_campaign( [ 'title' => 'Annual Fundraiser' ] );
		$this->create_campaign( [ 'title' => 'Emergency Relief' ] );

		$request = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$request->set_param( 'search', 'Annual' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Annual Fundraiser', $data[0]['title'] );
	}

	/**
	 * Test status filter returns only active campaigns.
	 */
	public function test_status_filter_active(): void {
		wp_set_current_user( $this->admin_id );

		$active_campaign = $this->create_campaign( [ 'title' => 'Active Campaign' ] );
		$active_campaign->date_start = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
		$active_campaign->date_end   = null;
		$active_campaign->save();

		$ended_campaign = $this->create_campaign( [ 'title' => 'Ended Campaign' ] );
		$ended_campaign->status     = 'ended';
		$ended_campaign->date_start = wp_date( 'Y-m-d', strtotime( '-30 days' ) );
		$ended_campaign->date_end   = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
		$ended_campaign->save();

		$request = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$request->set_param( 'status', 'active' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Active Campaign', $data[0]['title'] );
		$this->assertSame( 'active', $data[0]['status'] );
	}

	/**
	 * Test future date_start returns scheduled status.
	 */
	public function test_future_start_returns_scheduled(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'title' => 'Future Campaign' ] );
		$campaign->status     = 'scheduled';
		$campaign->date_start = wp_date( 'Y-m-d', strtotime( '+7 days' ) );
		$campaign->date_end   = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
		$campaign->save();

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $campaign->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'scheduled', $data['status'] );
	}

	/**
	 * Test past date_end returns ended status.
	 */
	public function test_past_end_returns_ended(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'title' => 'Past Campaign' ] );
		$campaign->status     = 'ended';
		$campaign->date_start = wp_date( 'Y-m-d', strtotime( '-30 days' ) );
		$campaign->date_end   = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
		$campaign->save();

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $campaign->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'ended', $data['status'] );
	}

	/**
	 * Test DELETE requires manage_options capability.
	 */
	public function test_delete_requires_manage_options(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign();

		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'DELETE', '/mission/v1/campaigns/' . $campaign->id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test DELETE trashes the campaign.
	 */
	public function test_delete_trashes_campaign(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'title' => 'To Be Deleted' ] );

		$request  = new WP_REST_Request( 'DELETE', '/mission/v1/campaigns/' . $campaign->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( $campaign->id, $data['id'] );
		$this->assertSame( 'trash', get_post_status( $campaign->post_id ) );
	}

	/**
	 * Test DELETE returns 404 for non-existent campaign.
	 */
	public function test_delete_returns_404_for_missing_campaign(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/mission/v1/campaigns/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test batch delete requires manage_options capability.
	 */
	public function test_batch_delete_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns/batch-delete' );
		$request->set_body_params( array( 'ids' => array( 1 ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test batch delete trashes multiple campaigns.
	 */
	public function test_batch_delete_trashes_multiple_campaigns(): void {
		wp_set_current_user( $this->admin_id );

		$campaigns = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$campaigns[] = $this->create_campaign( [ 'title' => "Campaign $i" ] );
		}

		$campaign_ids = array_map( fn( $c ) => $c->id, $campaigns );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns/batch-delete' );
		$request->set_body_params( array( 'ids' => $campaign_ids ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['deleted'] );
		$this->assertEmpty( $data['errors'] );

		foreach ( $campaigns as $campaign ) {
			$this->assertSame( 'trash', get_post_status( $campaign->post_id ) );
		}
	}

	/**
	 * Test POST creates a campaign with expected fields.
	 */
	public function test_create_campaign_with_expected_fields(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns' );
		$request->set_body_params( array(
			'title'       => 'New Campaign',
			'excerpt'     => 'A test campaign.',
			'goal_amount' => 500000,
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'New Campaign', $data['title'] );
		$this->assertSame( 'A test campaign.', $data['excerpt'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 500000, $data['goal_amount'] );
		$this->assertStringStartsWith( wp_date( 'Y-m-d' ), $data['date_start'] );
		$this->assertNull( $data['date_end'] );
		$this->assertArrayHasKey( 'meta', $data );
	}

	/**
	 * Test POST requires title.
	 */
	public function test_create_campaign_requires_title(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns' );
		$request->set_body_params( array(
			'excerpt' => 'Missing title.',
		) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test POST requires manage_options capability.
	 */
	public function test_create_campaign_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns' );
		$request->set_body_params( array(
			'title' => 'Forbidden Campaign',
		) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test GET single returns full campaign data.
	 */
	public function test_get_single_returns_full_campaign_data(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'title' => 'Detail Campaign' ] );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $campaign->id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Detail Campaign', $data['title'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertArrayHasKey( 'excerpt', $data );
		$this->assertArrayHasKey( 'post_id', $data );
		$this->assertArrayHasKey( 'edit_url', $data );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'goal_amount', $data );
		$this->assertArrayHasKey( 'total_raised', $data );
		$this->assertArrayHasKey( 'transaction_count', $data );
		$this->assertArrayHasKey( 'currency', $data );
		$this->assertArrayHasKey( 'date_start', $data );
		$this->assertArrayHasKey( 'date_end', $data );
		$this->assertArrayHasKey( 'show_in_listings', $data );
		$this->assertTrue( $data['show_in_listings'] );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'close_on_goal', $data['meta'] );
		$this->assertArrayHasKey( 'stop_donations_on_end', $data['meta'] );
		$this->assertArrayHasKey( 'recurring_end_behavior', $data['meta'] );
		$this->assertArrayHasKey( 'recurring_redirect_campaign', $data['meta'] );
		$this->assertSame( $campaign->id, $data['id'] );
		$this->assertSame( $campaign->post_id, $data['post_id'] );
	}

	/**
	 * Test GET single returns 404 for missing campaign.
	 */
	public function test_get_single_returns_404_for_missing(): void {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test batch delete reports errors for non-existent campaigns.
	 */
	public function test_batch_delete_reports_errors_for_missing(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign();

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns/batch-delete' );
		$request->set_body_params( array( 'ids' => array( $campaign->id, 999999 ) ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $campaign->id, $data['deleted'] );
		$this->assertContains( 999999, $data['errors'] );
		$this->assertSame( 'trash', get_post_status( $campaign->post_id ) );
	}

	/**
	 * Test PUT updates campaign fields.
	 */
	public function test_update_campaign_fields(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [
			'title'       => 'Original Title',
			'goal_amount' => 100000,
		] );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [
			'goal_amount' => 250000,
			'excerpt'     => 'Updated description.',
			'date_end'    => '2026-12-31',
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 250000, $data['goal_amount'] );
		$this->assertSame( 'Updated description.', $data['excerpt'] );
		$this->assertStringStartsWith( '2026-12-31', $data['date_end'] );
	}

	/**
	 * Test PUT saves meta fields.
	 */
	public function test_update_campaign_meta(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign();

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [
			'close_on_goal'               => true,
			'stop_donations_on_end'       => false,
			'show_ended_message'          => true,
			'remove_from_listings_on_end' => true,
			'recurring_end_behavior'      => 'cancel',
			'recurring_redirect_campaign' => '5',
		] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['meta']['close_on_goal'] );
		$this->assertEmpty( $data['meta']['stop_donations_on_end'] );
		$this->assertNotEmpty( $data['meta']['show_ended_message'] );
		$this->assertNotEmpty( $data['meta']['remove_from_listings_on_end'] );
		$this->assertSame( 'cancel', $data['meta']['recurring_end_behavior'] );
		$this->assertSame( '5', $data['meta']['recurring_redirect_campaign'] );
	}

	/**
	 * Test PUT returns 400 for negative goal amount.
	 */
	public function test_update_rejects_negative_goal(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'goal_amount' => 100000 ] );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'goal_amount' => -50000 ] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		// Verify original goal was not changed.
		$refreshed = Campaign::find( $campaign->id );
		$this->assertSame( 100000, $refreshed->goal_amount );
	}

	/**
	 * Test PUT returns 404 for non-existent campaign.
	 */
	public function test_update_returns_404_for_missing(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/999999' );
		$request->set_body_params( [ 'goal_amount' => 100000 ] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test PUT requires manage_options capability.
	 */
	public function test_update_requires_manage_options(): void {
		wp_set_current_user( $this->admin_id );
		$campaign = $this->create_campaign();

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'goal_amount' => 100000 ] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test PUT fires campaign_updated action.
	 */
	public function test_update_fires_campaign_updated_action(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign();
		$fired    = false;

		$this->add_tracked_action( 'mission_campaign_updated', function () use ( &$fired ) {
			$fired = true;
		} );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'fee_recovery' => false ] );

		$this->server->dispatch( $request );

		$this->assertTrue( $fired );
	}

	/**
	 * Test PUT fires goal_updated action when goal changes.
	 */
	public function test_update_fires_goal_updated_when_goal_changes(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'goal_amount' => 100000 ] );
		$fired    = false;

		$this->add_tracked_action( 'mission_campaign_goal_updated', function () use ( &$fired ) {
			$fired = true;
		} );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'goal_amount' => 200000 ] );

		$this->server->dispatch( $request );

		$this->assertTrue( $fired );
	}

	/**
	 * Test PUT does not fire goal_updated when goal is unchanged.
	 */
	public function test_update_does_not_fire_goal_updated_when_unchanged(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign( [ 'goal_amount' => 100000 ] );
		$fired    = false;

		$this->add_tracked_action( 'mission_campaign_goal_updated', function () use ( &$fired ) {
			$fired = true;
		} );

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'goal_amount' => 100000 ] );

		$this->server->dispatch( $request );

		$this->assertFalse( $fired );
	}

	/**
	 * Test PUT updates show_in_listings.
	 */
	public function test_update_show_in_listings(): void {
		wp_set_current_user( $this->admin_id );

		$campaign = $this->create_campaign();

		$request = new WP_REST_Request( 'PUT', '/mission/v1/campaigns/' . $campaign->id );
		$request->set_body_params( [ 'show_in_listings' => false ] );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['show_in_listings'] );

		// Verify it persisted by re-fetching.
		$get_request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $campaign->id );
		$get_response = $this->server->dispatch( $get_request );

		$this->assertFalse( $get_response->get_data()['show_in_listings'] );
	}
}
