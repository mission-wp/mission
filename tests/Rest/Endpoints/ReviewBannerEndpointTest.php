<?php
/**
 * Tests for the ReviewBannerEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ReviewBannerEndpoint test class.
 */
class ReviewBannerEndpointTest extends WP_UnitTestCase {

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
	 * Requests captured by the pre_http_request mock.
	 *
	 * @var array<array{url: string, args: array}>
	 */
	private array $captured_requests = [];

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		$this->captured_requests = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http_request' ], 10, 3 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		remove_filter( 'pre_http_request', [ $this, 'mock_http_request' ] );

		parent::tear_down();
	}

	/**
	 * Capture outbound HTTP requests instead of sending them.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array Mocked response.
	 */
	public function mock_http_request( $preempt, $args, $url ): array {
		$this->captured_requests[] = [
			'url'  => $url,
			'args' => $args,
		];

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => '',
		];
	}

	/**
	 * Build a rating request.
	 *
	 * @param mixed $rating Rating value.
	 * @return WP_REST_Request
	 */
	private function build_rate_request( $rating ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/review-banner/rate' );
		$request->set_body_params( [ 'rating' => $rating ] );

		return $request;
	}

	/**
	 * Dismiss marks the banner dismissed for the current user.
	 */
	public function test_dismiss_sets_user_meta(): void {
		$request  = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/review-banner/dismiss' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', get_user_meta( $this->admin_id, 'missiondp_review_banner_dismissed', true ) );
	}

	/**
	 * A valid rating is relayed to the Mission API and dismisses the banner.
	 */
	public function test_valid_rating_is_relayed(): void {
		$response = $this->server->dispatch( $this->build_rate_request( 4 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'https://api.missionwp.com/v1/review-rating', $this->captured_requests[0]['url'] );
		$this->assertSame( 4, $this->captured_requests[0]['args']['body']['rating'] );
		$this->assertSame( '1', get_user_meta( $this->admin_id, 'missiondp_review_banner_dismissed', true ) );
	}

	/**
	 * Ratings outside 1-5 are rejected before anything is sent.
	 */
	public function test_out_of_range_rating_is_rejected(): void {
		foreach ( [ 0, 6, -3 ] as $rating ) {
			$response = $this->server->dispatch( $this->build_rate_request( $rating ) );

			$this->assertSame( 400, $response->get_status(), "Expected 400 for rating {$rating}" );
		}

		$this->assertEmpty( $this->captured_requests );
	}

	/**
	 * Users without manage_options are rejected.
	 */
	public function test_subscriber_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->server->dispatch( $this->build_rate_request( 5 ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->captured_requests );
	}
}
