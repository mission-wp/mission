<?php
/**
 * Tests for the OutgoingWebhooksEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * OutgoingWebhooksEndpoint test class.
 */
class OutgoingWebhooksEndpointTest extends WP_UnitTestCase {

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
	 * Filters added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $filters_to_remove = [];

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_outgoing_webhooks" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_webhook_deliveries" );

		DatabaseModule::create_tables();
	}

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
		global $wpdb, $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		foreach ( $this->filters_to_remove as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->filters_to_remove = [];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_outgoing_webhooks" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_webhook_deliveries" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		// phpcs:enable

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Register a filter and track it for automatic cleanup.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	private function add_tracked_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters_to_remove[] = [ $hook, $callback, $priority ];
	}

	/**
	 * Dispatch a GET request.
	 *
	 * @param string $route  Route path.
	 * @param array  $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_get( string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a request with a JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $body   Body parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_json( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Create and save a webhook.
	 *
	 * @param array $overrides Column values to override.
	 * @return OutgoingWebhook
	 */
	private function create_webhook( array $overrides = [] ): OutgoingWebhook {
		$webhook = new OutgoingWebhook(
			array_merge(
				[
					'name'   => 'Endpoint test hook',
					'url'    => 'https://example.com/hook',
					'events' => [ 'donation.completed' ],
				],
				$overrides
			)
		);
		$webhook->save();

		return $webhook;
	}

	/**
	 * Mock all outgoing HTTP with a fixed response (or WP_Error).
	 *
	 * @param array|WP_Error $response Response to return.
	 */
	private function mock_http( $response ): void {
		$this->add_tracked_filter( 'pre_http_request', static fn() => $response );
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Test all routes reject unauthenticated and unauthorized users.
	 */
	public function test_routes_require_manage_options(): void {
		$webhook = $this->create_webhook();

		$routes = [
			[ 'GET', '/mission-donation-platform/v1/outgoing-webhooks' ],
			[ 'POST', '/mission-donation-platform/v1/outgoing-webhooks' ],
			[ 'GET', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}" ],
			[ 'PATCH', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}" ],
			[ 'DELETE', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}" ],
			[ 'GET', '/mission-donation-platform/v1/outgoing-webhooks/events' ],
			[ 'GET', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}/deliveries" ],
			[ 'POST', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}/ping" ],
		];

		foreach ( [ 0, $this->subscriber_id ] as $user_id ) {
			wp_set_current_user( $user_id );

			foreach ( $routes as [ $method, $route ] ) {
				$request = new WP_REST_Request( $method, $route );

				// Required params are validated before the permission callback,
				// so satisfy them to make the permission check the failing step.
				if ( 'POST' === $method ) {
					$request->set_param( 'name', 'Denied' );
					$request->set_param( 'url', 'https://example.com/denied' );
				}

				$response = $this->server->dispatch( $request );
				$this->assertContains(
					$response->get_status(),
					[ 401, 403 ],
					"Route {$method} {$route} should be denied for user {$user_id}."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Test listing webhooks returns items with pagination headers.
	 */
	public function test_get_list_returns_webhooks_with_pagination(): void {
		$this->create_webhook( [ 'name' => 'First' ] );
		$this->create_webhook( [ 'name' => 'Second' ] );
		$this->create_webhook( [ 'name' => 'Third' ] );

		$response = $this->dispatch_get(
			'/mission-donation-platform/v1/outgoing-webhooks',
			[ 'per_page' => 2 ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Test creating a webhook returns 201 with the signing secret.
	 */
	public function test_create_webhook(): void {
		$response = $this->dispatch_json(
			'POST',
			'/mission-donation-platform/v1/outgoing-webhooks',
			[
				'name'   => 'New hook',
				'url'    => 'https://example.com/new',
				'events' => [ 'donation.completed', 'donor.created' ],
			]
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'New hook', $data['name'] );
		$this->assertSame( 'https://example.com/new', $data['url'] );
		$this->assertSame( [ 'donation.completed', 'donor.created' ], $data['events'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['secret'] );

		$this->assertNotNull( OutgoingWebhook::find( $data['id'] ) );
	}

	/**
	 * Test creating a webhook validates the URL.
	 */
	public function test_create_rejects_invalid_urls(): void {
		$cases = [
			[ 'url' => 'not-a-url', 'code' => 'invalid_url' ],
			[ 'url' => 'ftp://example.com/x', 'code' => 'invalid_url_scheme' ],
		];

		foreach ( $cases as $case ) {
			$response = $this->dispatch_json(
				'POST',
				'/mission-donation-platform/v1/outgoing-webhooks',
				[
					'name' => 'Bad',
					'url'  => $case['url'],
				]
			);

			$this->assertSame( 400, $response->get_status(), "URL {$case['url']} should be rejected." );
			$this->assertSame( $case['code'], $response->get_data()['code'] );
		}
	}

	/**
	 * Test creating a webhook validates event names.
	 */
	public function test_create_rejects_unknown_events(): void {
		$response = $this->dispatch_json(
			'POST',
			'/mission-donation-platform/v1/outgoing-webhooks',
			[
				'name'   => 'Bad events',
				'url'    => 'https://example.com/x',
				'events' => [ 'nonsense.event' ],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_event', $response->get_data()['code'] );
	}

	/**
	 * Test getting a single webhook, and 404 for unknown IDs.
	 */
	public function test_get_single_webhook(): void {
		$webhook = $this->create_webhook();

		$response = $this->dispatch_get( "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $webhook->id, $response->get_data()['id'] );

		$missing = $this->dispatch_get( '/mission-donation-platform/v1/outgoing-webhooks/999999' );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test PATCH updates name, url, and events.
	 */
	public function test_update_webhook_fields(): void {
		$webhook = $this->create_webhook();

		$response = $this->dispatch_json(
			'PATCH',
			"/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}",
			[
				'name'   => 'Renamed',
				'url'    => 'https://example.com/renamed',
				'events' => [ '*' ],
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$fresh = $webhook->fresh();
		$this->assertSame( 'Renamed', $fresh->name );
		$this->assertSame( 'https://example.com/renamed', $fresh->url );
		$this->assertSame( [ '*' ], $fresh->events );
	}

	/**
	 * Test reactivating a paused webhook resets its failure tracking.
	 */
	public function test_update_status_resume_resets_failure_tracking(): void {
		$webhook = $this->create_webhook(
			[
				'status'        => 'paused',
				'health'        => 'failing',
				'failure_count' => 20,
				'failing_since' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			]
		);

		$response = $this->dispatch_json(
			'PATCH',
			"/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}",
			[ 'status' => 'active' ]
		);

		$this->assertSame( 200, $response->get_status() );

		$fresh = $webhook->fresh();
		$this->assertSame( 'active', $fresh->status );
		$this->assertSame( 'healthy', $fresh->health );
		$this->assertSame( 0, $fresh->failure_count );
		$this->assertNull( $fresh->failing_since );
	}

	/**
	 * Test regenerating the secret replaces it.
	 */
	public function test_update_regenerates_secret(): void {
		$webhook  = $this->create_webhook();
		$original = $webhook->secret;

		$response = $this->dispatch_json(
			'PATCH',
			"/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}",
			[ 'regenerate_secret' => true ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( $original, $response->get_data()['secret'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $response->get_data()['secret'] );
	}

	/**
	 * Test deleting a webhook removes it and its deliveries.
	 */
	public function test_delete_webhook_cascades(): void {
		$webhook = $this->create_webhook();

		( new WebhookDelivery(
			[
				'webhook_id' => $webhook->id,
				'event'      => 'ping',
				'event_id'   => 'evt_del',
				'url'        => $webhook->url,
			]
		) )->save();

		$response = $this->server->dispatch(
			new WP_REST_Request( 'DELETE', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}" )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( OutgoingWebhook::find( $webhook->id ) );
		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );
	}

	// -------------------------------------------------------------------------
	// Events, deliveries, ping
	// -------------------------------------------------------------------------

	/**
	 * Test the events route returns the grouped registry.
	 */
	public function test_get_events_returns_grouped_registry(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/outgoing-webhooks/events' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'donation', $data );
		$this->assertArrayHasKey( 'subscription', $data );
		$this->assertArrayHasKey( 'donor', $data );
		$this->assertArrayHasKey( 'campaign', $data );
		$this->assertArrayHasKey( 'donation.completed', $data['donation']['events'] );
	}

	/**
	 * Test the deliveries route lists a webhook's deliveries with pagination.
	 */
	public function test_get_deliveries_paginates(): void {
		$webhook = $this->create_webhook();

		for ( $i = 0; $i < 3; $i++ ) {
			( new WebhookDelivery(
				[
					'webhook_id' => $webhook->id,
					'event'      => 'donation.completed',
					'event_id'   => "evt_list{$i}",
					'url'        => $webhook->url,
				]
			) )->save();
		}

		$response = $this->dispatch_get(
			"/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}/deliveries",
			[ 'per_page' => 2 ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );

		$missing = $this->dispatch_get( '/mission-donation-platform/v1/outgoing-webhooks/999999/deliveries' );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Test a successful ping delivers synchronously and returns the delivery.
	 */
	public function test_ping_success(): void {
		$this->mock_http(
			[
				'headers'  => [],
				'body'     => 'pong',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			]
		);

		$webhook = $this->create_webhook();

		$response = $this->server->dispatch(
			new WP_REST_Request( 'POST', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}/ping" )
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'ping', $data['event'] );
		$this->assertSame( 'success', $data['status'] );
		$this->assertSame( 200, $data['response_code'] );
		$this->assertSame( 'pong', $data['response_body'] );
	}

	/**
	 * Test a failed ping reports the failure on the returned delivery.
	 */
	public function test_ping_failure(): void {
		$this->mock_http( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$webhook = $this->create_webhook();

		$response = $this->server->dispatch(
			new WP_REST_Request( 'POST', "/mission-donation-platform/v1/outgoing-webhooks/{$webhook->id}/ping" )
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'Could not resolve host', $data['error_message'] );
	}
}
