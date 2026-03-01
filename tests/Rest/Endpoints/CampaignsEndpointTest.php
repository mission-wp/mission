<?php
/**
 * Tests for the CampaignsEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

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

		wp_set_current_user( 0 );

		parent::tear_down();
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

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Test Campaign',
			'post_status' => 'publish',
		) );

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

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_status' => 'publish',
		) );

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

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Annual Fundraiser',
			'post_status' => 'publish',
		) );

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Emergency Relief',
			'post_status' => 'publish',
		) );

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

		$active_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Active Campaign',
			'post_status' => 'publish',
		) );

		$ended_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Ended Campaign',
			'post_status' => 'publish',
		) );

		// Set dates via the data store.
		$store = new \Mission\Database\DataStore\CampaignDataStore();

		$active_campaign = $store->find_by_post_id( $active_id );
		if ( $active_campaign ) {
			$active_campaign->date_start = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
			$active_campaign->date_end   = null;
			$store->update( $active_campaign );
		}

		$ended_campaign = $store->find_by_post_id( $ended_id );
		if ( $ended_campaign ) {
			$ended_campaign->date_start = wp_date( 'Y-m-d', strtotime( '-30 days' ) );
			$ended_campaign->date_end   = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
			$store->update( $ended_campaign );
		}

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

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Future Campaign',
			'post_status' => 'publish',
		) );

		$store    = new \Mission\Database\DataStore\CampaignDataStore();
		$campaign = $store->find_by_post_id( $post_id );
		if ( $campaign ) {
			$campaign->date_start = wp_date( 'Y-m-d', strtotime( '+7 days' ) );
			$campaign->date_end   = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
			$store->update( $campaign );
		}

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $post_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'scheduled', $data['status'] );
	}

	/**
	 * Test past date_end returns ended status.
	 */
	public function test_past_end_returns_ended(): void {
		wp_set_current_user( $this->admin_id );

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Past Campaign',
			'post_status' => 'publish',
		) );

		$store    = new \Mission\Database\DataStore\CampaignDataStore();
		$campaign = $store->find_by_post_id( $post_id );
		if ( $campaign ) {
			$campaign->date_start = wp_date( 'Y-m-d', strtotime( '-30 days' ) );
			$campaign->date_end   = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
			$store->update( $campaign );
		}

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $post_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 'ended', $data['status'] );
	}

	/**
	 * Test DELETE requires manage_options capability.
	 */
	public function test_delete_requires_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_status' => 'publish',
		) );

		$request  = new WP_REST_Request( 'DELETE', '/mission/v1/campaigns/' . $post_id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test DELETE trashes the campaign.
	 */
	public function test_delete_trashes_campaign(): void {
		wp_set_current_user( $this->admin_id );

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'To Be Deleted',
			'post_status' => 'publish',
		) );

		$request  = new WP_REST_Request( 'DELETE', '/mission/v1/campaigns/' . $post_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( $post_id, $data['id'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
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

		$post_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$post_ids[] = self::factory()->post->create( array(
				'post_type'   => 'mission_campaign',
				'post_title'  => "Campaign $i",
				'post_status' => 'publish',
			) );
		}

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns/batch-delete' );
		$request->set_body_params( array( 'ids' => $post_ids ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['deleted'] );
		$this->assertEmpty( $data['errors'] );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( 'trash', get_post_status( $post_id ) );
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
			'amounts'     => array( 1000, 5000 ),
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
		$this->assertSame( array( 1000, 5000 ), $data['meta']['amounts'] );
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

		$post_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Detail Campaign',
			'post_status' => 'publish',
		) );

		$request  = new WP_REST_Request( 'GET', '/mission/v1/campaigns/' . $post_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Detail Campaign', $data['title'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertArrayHasKey( 'excerpt', $data );
		$this->assertArrayHasKey( 'edit_url', $data );
		$this->assertArrayHasKey( 'view_url', $data );
		$this->assertArrayHasKey( 'goal_amount', $data );
		$this->assertArrayHasKey( 'total_raised', $data );
		$this->assertArrayHasKey( 'transaction_count', $data );
		$this->assertArrayHasKey( 'currency', $data );
		$this->assertArrayHasKey( 'date_start', $data );
		$this->assertArrayHasKey( 'date_end', $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'amounts', $data['meta'] );
		$this->assertArrayHasKey( 'custom_amount', $data['meta'] );
		$this->assertArrayHasKey( 'recurring_enabled', $data['meta'] );
		$this->assertArrayHasKey( 'fee_recovery', $data['meta'] );
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

		$valid_id = self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_status' => 'publish',
		) );

		$request = new WP_REST_Request( 'POST', '/mission/v1/campaigns/batch-delete' );
		$request->set_body_params( array( 'ids' => array( $valid_id, 999999 ) ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $valid_id, $data['deleted'] );
		$this->assertContains( 999999, $data['errors'] );
		$this->assertSame( 'trash', get_post_status( $valid_id ) );
	}
}
