<?php
/**
 * Tests for the OutgoingWebhookModule class.
 *
 * Events are driven through real model saves so the module's globally
 * registered listeners fire, and deliveries execute synchronously via the
 * Action Scheduler fallback (AS is not loaded in the test suite), with HTTP
 * mocked through pre_http_request.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\OutgoingWebhooks;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\WebhookDelivery;
use WP_UnitTestCase;

/**
 * OutgoingWebhookModule test class.
 */
class OutgoingWebhookModuleTest extends WP_UnitTestCase {

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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaigns" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test: mock all outgoing HTTP with a 200 response.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->add_tracked_filter(
			'pre_http_request',
			static fn() => [
				'headers'  => [],
				'body'     => 'ok',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			]
		);
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
		foreach ( [ 'outgoing_webhooks', 'webhook_deliveries', 'activity_log', 'transaction_history', 'transactionmeta', 'transactions', 'subscriptions', 'donormeta', 'donors', 'campaignmeta', 'campaigns' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
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
	 * Create and save a webhook.
	 *
	 * @param array $events    Subscribed events.
	 * @param array $overrides Column values to override.
	 * @return OutgoingWebhook
	 */
	private function create_webhook( array $events, array $overrides = [] ): OutgoingWebhook {
		$webhook = new OutgoingWebhook(
			array_merge(
				[
					'name'   => 'Module test hook',
					'url'    => 'https://example.com/hook',
					'events' => $events,
				],
				$overrides
			)
		);
		$webhook->save();

		return $webhook;
	}

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$donor = new Donor(
			array_merge(
				[
					'email'      => "webhook.donor{$counter}@example.com",
					'first_name' => 'Web',
					'last_name'  => 'Hook',
				],
				$overrides
			)
		);
		$donor->save();

		return $donor;
	}

	/**
	 * Create a completed transaction.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_completed_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction(
			array_merge(
				[
					'status'          => 'completed',
					'donor_id'        => 1,
					'amount'          => 5000,
					'total_amount'    => 5000,
					'currency'        => 'usd',
					'payment_gateway' => 'stripe',
					'date_completed'  => current_time( 'mysql', true ),
				],
				$overrides
			)
		);
		$transaction->save();

		return $transaction;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Test a completed transaction dispatches donation.completed to a subscribed webhook.
	 */
	public function test_completed_transaction_dispatches_donation_completed(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'donation.completed' ] );

		$transaction = $this->create_completed_transaction( [ 'donor_id' => $donor->id ] );

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );

		$this->assertCount( 1, $deliveries );
		$delivery = $deliveries[0];

		$this->assertSame( 'donation.completed', $delivery->event );
		$this->assertSame( 'success', $delivery->status );
		$this->assertStringStartsWith( 'evt_', $delivery->event_id );

		$payload = json_decode( $delivery->request_body, true );
		$this->assertSame( 'donation.completed', $payload['event'] );
		$this->assertFalse( $payload['is_test'] );
		$this->assertSame( 5000, $payload['data']['amount'] );
		$this->assertSame( $transaction->id, $payload['data']['id'] );
		$this->assertSame( $donor->email, $payload['data']['donor']['email'] );
	}

	/**
	 * Test a test-mode transaction is dispatched with is_test set in the payload.
	 */
	public function test_test_mode_event_is_flagged_in_payload(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'donation.completed' ] );

		$this->create_completed_transaction(
			[
				'donor_id' => $donor->id,
				'is_test'  => true,
			]
		);

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );
		$this->assertCount( 1, $deliveries );

		$payload = json_decode( $deliveries[0]->request_body, true );
		$this->assertTrue( $payload['is_test'] );
	}

	/**
	 * Test webhooks only receive events they subscribe to.
	 */
	public function test_unsubscribed_event_is_not_dispatched(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'donor.updated' ] );

		$this->create_completed_transaction( [ 'donor_id' => $donor->id ] );

		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );
	}

	/**
	 * Test the wildcard subscription receives any event.
	 */
	public function test_wildcard_subscription_receives_events(): void {
		$webhook = $this->create_webhook( [ '*' ] );

		$this->create_donor();

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'donor.created', $deliveries[0]->event );
	}

	/**
	 * Test paused webhooks are skipped.
	 */
	public function test_paused_webhook_is_skipped(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'donation.completed' ], [ 'status' => 'paused' ] );

		$this->create_completed_transaction( [ 'donor_id' => $donor->id ] );

		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );
	}

	/**
	 * Test a new subscription dispatches subscription.created.
	 */
	public function test_subscription_created_dispatches(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'subscription.created' ] );

		$subscription = new Subscription(
			[
				'status'          => 'active',
				'donor_id'        => $donor->id,
				'amount'          => 2500,
				'total_amount'    => 2500,
				'frequency'       => 'monthly',
				'payment_gateway' => 'stripe',
			]
		);
		$subscription->save();

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'subscription.created', $deliveries[0]->event );

		$payload = json_decode( $deliveries[0]->request_body, true );
		$this->assertSame( 2500, $payload['data']['amount'] );
		$this->assertSame( 'monthly', $payload['data']['frequency'] );
	}

	/**
	 * Test only the 100% milestone dispatches campaign.goal_reached.
	 */
	public function test_campaign_goal_reached_only_at_full_milestone(): void {
		$webhook = $this->create_webhook( [ 'campaign.goal_reached' ] );

		$campaign = new Campaign(
			[
				'title'       => 'Goal Campaign',
				'description' => 'Test campaign',
			]
		);
		$campaign->save();

		do_action( 'mission_campaign_milestone_reached', $campaign, '50-pct', false );
		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );

		do_action( 'mission_campaign_milestone_reached', $campaign, '100-pct', true );

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( 'campaign.goal_reached', $deliveries[0]->event );

		$payload = json_decode( $deliveries[0]->request_body, true );
		$this->assertTrue( $payload['is_test'] );
	}

	/**
	 * Test the should_dispatch filter can suppress events entirely.
	 */
	public function test_should_dispatch_filter_suppresses_events(): void {
		$donor   = $this->create_donor();
		$webhook = $this->create_webhook( [ 'donation.completed' ] );

		$this->add_tracked_filter( 'mission_outgoing_webhook_should_dispatch', '__return_false' );

		$this->create_completed_transaction( [ 'donor_id' => $donor->id ] );

		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );
	}

	/**
	 * Test the payload filter can modify the payload before delivery.
	 */
	public function test_payload_filter_modifies_payload(): void {
		$webhook = $this->create_webhook( [ 'donor.created' ] );

		$this->add_tracked_filter(
			'mission_outgoing_webhook_payload',
			static function ( array $payload ) {
				$payload['data']['custom_field'] = 'added';

				return $payload;
			}
		);

		$this->create_donor();

		$deliveries = WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] );
		$this->assertCount( 1, $deliveries );

		$payload = json_decode( $deliveries[0]->request_body, true );
		$this->assertSame( 'added', $payload['data']['custom_field'] );
	}

	/**
	 * Test delivery log pruning runs on the daily cleanup cron.
	 */
	public function test_prune_runs_on_daily_cleanup(): void {
		$old = new WebhookDelivery(
			[
				'webhook_id'   => 1,
				'event'        => 'donation.completed',
				'event_id'     => 'evt_old',
				'url'          => 'https://example.com/hook',
				'date_created' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			]
		);
		$old->save();

		$recent = new WebhookDelivery(
			[
				'webhook_id' => 1,
				'event'      => 'donation.completed',
				'event_id'   => 'evt_recent',
				'url'        => 'https://example.com/hook',
			]
		);
		$recent->save();

		do_action( 'missiondp_daily_cleanup' );

		$this->assertNull( WebhookDelivery::find( $old->id ) );
		$this->assertNotNull( WebhookDelivery::find( $recent->id ) );
	}

	/**
	 * Test one event creates a delivery per matching webhook.
	 */
	public function test_event_fans_out_to_all_matching_webhooks(): void {
		$first  = $this->create_webhook( [ 'donor.created' ] );
		$second = $this->create_webhook( [ '*' ] );
		$third  = $this->create_webhook( [ 'donation.completed' ] );

		$this->create_donor();

		$this->assertCount( 1, WebhookDelivery::query( [ 'webhook_id' => $first->id ] ) );
		$this->assertCount( 1, WebhookDelivery::query( [ 'webhook_id' => $second->id ] ) );
		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $third->id ] ) );
	}
}
