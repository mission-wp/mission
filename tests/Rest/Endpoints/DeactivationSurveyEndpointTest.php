<?php
/**
 * Tests for the DeactivationSurveyEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DeactivationSurveyEndpoint test class.
 */
class DeactivationSurveyEndpointTest extends WP_UnitTestCase {

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

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
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
	 * Build a survey submission request.
	 *
	 * @param array $params Body parameters.
	 * @return WP_REST_Request
	 */
	private function build_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/deactivation-survey' );
		$request->set_body_params( $params );

		return $request;
	}

	/**
	 * Logged-out users are rejected.
	 */
	public function test_logged_out_user_is_rejected(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->build_request( [ 'reason' => 'temporary' ] ) );

		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertEmpty( $this->captured_requests );
	}

	/**
	 * Users without activate_plugins are rejected.
	 */
	public function test_subscriber_is_rejected(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->server->dispatch( $this->build_request( [ 'reason' => 'temporary' ] ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->captured_requests );
	}

	/**
	 * Reasons outside the enum are rejected.
	 */
	public function test_invalid_reason_is_rejected(): void {
		$response = $this->server->dispatch( $this->build_request( [ 'reason' => 'i-made-this-up' ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->captured_requests );
	}

	/**
	 * A missing reason is rejected.
	 */
	public function test_missing_reason_is_rejected(): void {
		$response = $this->server->dispatch( $this->build_request( [] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->captured_requests );
	}

	/**
	 * A valid submission relays an anonymous payload to the Mission API.
	 */
	public function test_valid_submission_relays_anonymous_payload(): void {
		$response = $this->server->dispatch(
			$this->build_request(
				[
					'reason'   => 'missing-feature',
					'feedback' => 'PayPal support',
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'https://api.missionwp.com/v1/deactivation-survey', $this->captured_requests[0]['url'] );

		$body = $this->captured_requests[0]['args']['body'];
		$this->assertSame( 'missing-feature', $body['reason'] );
		$this->assertSame( 'PayPal support', $body['feedback'] );
		$this->assertSame( MISSIONDP_VERSION, $body['version'] );
		$this->assertArrayNotHasKey( 'domain', $body );
	}

	/**
	 * Feedback is optional and defaults to an empty string.
	 */
	public function test_feedback_is_optional(): void {
		$response = $this->server->dispatch( $this->build_request( [ 'reason' => 'temporary' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $this->captured_requests[0]['args']['body']['feedback'] );
	}

	/**
	 * Over-long feedback is truncated to 1000 characters.
	 */
	public function test_feedback_is_truncated(): void {
		$response = $this->server->dispatch(
			$this->build_request(
				[
					'reason'   => 'other',
					'feedback' => str_repeat( 'a', 1500 ),
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1000, mb_strlen( $this->captured_requests[0]['args']['body']['feedback'] ) );
	}
}
