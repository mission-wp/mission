<?php
/**
 * Tests for the DeliveryHandler class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\OutgoingWebhooks;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;
use MissionDP\OutgoingWebhooks\DeliveryHandler;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/as-stubs.php';

/**
 * DeliveryHandler test class.
 */
class DeliveryHandlerTest extends WP_UnitTestCase {

	/**
	 * Handler under test.
	 *
	 * @var DeliveryHandler
	 */
	private DeliveryHandler $handler;

	/**
	 * Number of HTTP requests intercepted in the current test.
	 *
	 * @var int
	 */
	private int $http_calls = 0;

	/**
	 * Args of the last intercepted HTTP request.
	 *
	 * @var array
	 */
	private array $last_request_args = [];

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

		$this->handler                          = new DeliveryHandler();
		$this->http_calls                       = 0;
		$this->last_request_args                = [];
		$GLOBALS['missiondp_as_stub_calls']     = [
			'schedule_single' => [],
			'unschedule_all'  => [],
		];
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

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
	 * Mock all outgoing HTTP with a fixed response (or WP_Error).
	 *
	 * @param array|WP_Error $response Response to return.
	 */
	private function mock_http( $response ): void {
		$this->add_tracked_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( $response ) {
				++$this->http_calls;
				$this->last_request_args = $args;

				return $response;
			},
			10,
			2
		);
	}

	/**
	 * Build a successful HTTP response array.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return array
	 */
	private function http_response( int $code = 200, string $body = 'ok' ): array {
		return [
			'headers'  => [ 'content-type' => 'text/plain' ],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => 500 === $code ? 'Internal Server Error' : 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
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
					'name'   => 'Test hook',
					'url'    => 'https://example.com/hook',
					'events' => [ '*' ],
				],
				$overrides
			)
		);
		$webhook->save();

		return $webhook;
	}

	/**
	 * Create and save a pending delivery for a webhook.
	 *
	 * @param OutgoingWebhook $webhook   Parent webhook.
	 * @param array           $overrides Column values to override.
	 * @return WebhookDelivery
	 */
	private function create_delivery( OutgoingWebhook $webhook, array $overrides = [] ): WebhookDelivery {
		$delivery = new WebhookDelivery(
			array_merge(
				[
					'webhook_id'   => $webhook->id,
					'event'        => 'donation.completed',
					'event_id'     => 'evt_' . bin2hex( random_bytes( 6 ) ),
					'url'          => $webhook->url,
					'request_body' => '{"event":"donation.completed","data":{"amount":5000}}',
				],
				$overrides
			)
		);
		$delivery->save();

		return $delivery;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Test a 2xx response marks the delivery successful and resets webhook health.
	 */
	public function test_successful_delivery(): void {
		$this->mock_http( $this->http_response( 200, 'received' ) );

		$webhook = $this->create_webhook(
			[
				'failure_count' => 3,
				'health'        => 'healthy',
			]
		);
		$delivery = $this->create_delivery( $webhook );

		$delivered_args = [];
		$this->add_tracked_filter(
			'mission_outgoing_webhook_delivered',
			function ( ...$args ) use ( &$delivered_args ) {
				$delivered_args = $args;

				return $args[0];
			},
			10,
			3
		);

		$this->handler->deliver( $delivery->id );

		$delivery = $delivery->fresh();
		$this->assertSame( 'success', $delivery->status );
		$this->assertSame( 200, $delivery->response_code );
		$this->assertSame( 'received', $delivery->response_body );
		$this->assertNotNull( $delivery->duration_ms );
		$this->assertSame( 1, $this->http_calls );

		$webhook = $webhook->fresh();
		$this->assertSame( 0, $webhook->failure_count );
		$this->assertSame( 'healthy', $webhook->health );
		$this->assertSame( 200, $webhook->last_response_code );
		$this->assertNotNull( $webhook->last_delivery_at );

		$this->assertNotEmpty( $delivered_args );
		$this->assertTrue( $delivered_args[2] );
	}

	/**
	 * Test the request is signed with an HMAC-SHA256 signature header.
	 */
	public function test_request_is_signed_with_hmac(): void {
		$this->mock_http( $this->http_response() );

		$webhook  = $this->create_webhook();
		$delivery = $this->create_delivery( $webhook );

		$this->handler->deliver( $delivery->id );

		$headers  = $this->last_request_args['headers'];
		$expected = 'sha256=' . hash_hmac( 'sha256', $delivery->request_body, $webhook->secret );

		$this->assertSame( $expected, $headers['X-Mission-Signature'] );
		$this->assertSame( 'donation.completed', $headers['X-Mission-Event'] );
		$this->assertSame( $delivery->event_id, $headers['X-Mission-Delivery'] );
		$this->assertSame( $delivery->request_body, $this->last_request_args['body'] );

		// The headers sent are also recorded on the delivery row.
		$recorded = json_decode( $delivery->fresh()->request_headers, true );
		$this->assertSame( $expected, $recorded['X-Mission-Signature'] );
	}

	/**
	 * Test a network failure marks the delivery failed and schedules a retry.
	 */
	public function test_network_failure_schedules_retry(): void {
		$this->mock_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );

		$webhook  = $this->create_webhook();
		$delivery = $this->create_delivery( $webhook );

		$this->handler->deliver( $delivery->id );

		$delivery = $delivery->fresh();
		$this->assertSame( 'failed', $delivery->status );
		$this->assertSame( 'Connection refused', $delivery->error_message );

		$webhook = $webhook->fresh();
		$this->assertSame( 1, $webhook->failure_count );

		// A retry row was created for attempt 2 with the same event ID.
		$retries = WebhookDelivery::query(
			[
				'webhook_id' => $webhook->id,
				'status'     => 'pending',
			]
		);
		$this->assertCount( 1, $retries );
		$this->assertSame( 2, $retries[0]->attempt );
		$this->assertSame( $delivery->event_id, $retries[0]->event_id );

		// And scheduled via Action Scheduler with the first retry delay (60s).
		$scheduled = $GLOBALS['missiondp_as_stub_calls']['schedule_single'];
		$this->assertCount( 1, $scheduled );
		$this->assertSame( 'missiondp_deliver_webhook', $scheduled[0]['hook'] );
		$this->assertSame( [ $retries[0]->id ], $scheduled[0]['args'] );
		$this->assertSame( 'mission-webhook-' . $webhook->id, $scheduled[0]['group'] );
		$this->assertEqualsWithDelta( time() + 60, $scheduled[0]['timestamp'], 10 );
	}

	/**
	 * Test a non-2xx response records the HTTP error message.
	 */
	public function test_http_error_response_is_failure(): void {
		$this->mock_http( $this->http_response( 500, 'boom' ) );

		$webhook  = $this->create_webhook();
		$delivery = $this->create_delivery( $webhook );

		$this->handler->deliver( $delivery->id );

		$delivery = $delivery->fresh();
		$this->assertSame( 'failed', $delivery->status );
		$this->assertSame( 500, $delivery->response_code );
		$this->assertStringContainsString( 'HTTP 500', $delivery->error_message );
	}

	/**
	 * Test no retry is scheduled once the attempt limit is reached.
	 */
	public function test_no_retry_after_max_attempts(): void {
		$this->mock_http( $this->http_response( 500 ) );

		$webhook  = $this->create_webhook();
		$delivery = $this->create_delivery( $webhook, [ 'attempt' => 5 ] );

		$this->handler->deliver( $delivery->id );

		$this->assertSame( 'failed', $delivery->fresh()->status );
		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id, 'status' => 'pending' ] ) );
		$this->assertCount( 0, $GLOBALS['missiondp_as_stub_calls']['schedule_single'] );
	}

	/**
	 * Test the webhook is marked failing at the consecutive-failure threshold.
	 */
	public function test_failing_threshold_marks_webhook_failing(): void {
		$this->mock_http( $this->http_response( 500 ) );

		$webhook  = $this->create_webhook( [ 'failure_count' => 14 ] );
		$delivery = $this->create_delivery( $webhook );

		$this->handler->deliver( $delivery->id );

		$webhook = $webhook->fresh();
		$this->assertSame( 15, $webhook->failure_count );
		$this->assertSame( 'failing', $webhook->health );
		$this->assertNotNull( $webhook->failing_since );
	}

	/**
	 * Test prolonged failure auto-pauses the webhook.
	 */
	public function test_prolonged_failure_auto_pauses_webhook(): void {
		$this->mock_http( $this->http_response( 500 ) );

		$webhook = $this->create_webhook(
			[
				'failure_count' => 20,
				'health'        => 'failing',
				'failing_since' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ),
			]
		);
		$delivery = $this->create_delivery( $webhook );

		$paused = null;
		$this->add_tracked_filter(
			'mission_outgoing_webhook_auto_paused',
			function ( $hook_webhook ) use ( &$paused ) {
				$paused = $hook_webhook;

				return $hook_webhook;
			}
		);

		$this->handler->deliver( $delivery->id );

		$this->assertSame( 'paused', $webhook->fresh()->status );
		$this->assertNotNull( $paused );
		$this->assertSame( $webhook->id, $paused->id );

		// Pending deliveries for this webhook were unscheduled.
		$unscheduled = $GLOBALS['missiondp_as_stub_calls']['unschedule_all'];
		$this->assertNotEmpty( $unscheduled );
		$this->assertSame( 'missiondp_deliver_webhook', $unscheduled[0]['hook'] );
		$this->assertSame( 'mission-webhook-' . $webhook->id, $unscheduled[0]['group'] );
	}

	/**
	 * Test delivering to a paused webhook fails without an HTTP request.
	 */
	public function test_paused_webhook_skips_http(): void {
		$this->mock_http( $this->http_response() );

		$webhook  = $this->create_webhook( [ 'status' => 'paused' ] );
		$delivery = $this->create_delivery( $webhook );

		$this->handler->deliver( $delivery->id );

		$delivery = $delivery->fresh();
		$this->assertSame( 'failed', $delivery->status );
		$this->assertSame( 'Webhook is paused or deleted.', $delivery->error_message );
		$this->assertSame( 0, $this->http_calls );
	}

	/**
	 * Test only pending deliveries are processed.
	 */
	public function test_non_pending_delivery_is_skipped(): void {
		$this->mock_http( $this->http_response() );

		$webhook  = $this->create_webhook();
		$delivery = $this->create_delivery( $webhook, [ 'status' => 'success' ] );

		$this->handler->deliver( $delivery->id );

		$this->assertSame( 0, $this->http_calls );
	}
}
