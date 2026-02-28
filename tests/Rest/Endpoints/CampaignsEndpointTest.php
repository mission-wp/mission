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
		$this->assertArrayHasKey( 'date_created', $campaign );

		$this->assertSame( 'Test Campaign', $campaign['title'] );
		$this->assertSame( 'publish', $campaign['status'] );
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
	 * Test status filter works.
	 */
	public function test_status_filter_works(): void {
		wp_set_current_user( $this->admin_id );

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Published Campaign',
			'post_status' => 'publish',
		) );

		self::factory()->post->create( array(
			'post_type'   => 'mission_campaign',
			'post_title'  => 'Draft Campaign',
			'post_status' => 'draft',
		) );

		$request = new WP_REST_Request( 'GET', '/mission/v1/campaigns' );
		$request->set_param( 'status', 'draft' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Draft Campaign', $data[0]['title'] );
		$this->assertSame( 'draft', $data[0]['status'] );
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
