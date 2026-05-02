<?php
/**
 * Tests for the StripeWebhookEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * StripeWebhookEndpoint test class.
 */
class StripeWebhookEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Default donor for tests.
	 *
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * Default campaign for tests.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Webhook signing secret.
	 *
	 * @var string
	 */
	private string $webhook_secret = 'whsec_test_secret_123';

	/**
	 * Hooks added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = [];

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_notes" );
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
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		// Configure webhook secret.
		update_option( SettingsService::OPTION_NAME, [
			'stripe_webhook_secret' => $this->webhook_secret,
		] );

		// Create a default donor.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		] );
		$this->donor->save();

		// Create a default campaign.
		$this->campaign = new Campaign( [
			'title'       => 'General Fund',
			'goal_amount' => 100000,
		] );
		$this->campaign->save();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		delete_option( SettingsService::OPTION_NAME );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a webhook signature for a request body.
	 *
	 * @param string   $body      Raw request body.
	 * @param string   $secret    Webhook secret.
	 * @param int|null $timestamp Unix timestamp (defaults to current time).
	 * @return string Signature header value (t={timestamp},v1={hash}).
	 */
	private function generate_signature( string $body, string $secret, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		$hash      = hash_hmac( 'sha256', "{$timestamp}.{$body}", $secret );

		return "t={$timestamp},v1={$hash}";
	}

	/**
	 * Dispatch a webhook request with a raw body and optional signature.
	 *
	 * @param string      $body      Raw request body.
	 * @param string|null $signature Signature header value.
	 * @return \WP_REST_Response
	 */
	private function dispatch_webhook( string $body, ?string $signature = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/webhooks/stripe' );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( null !== $signature ) {
			$request->set_header( 'X-Mission-Signature', $signature );
		}

		$request->set_body( $body );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a signed webhook request from an array payload.
	 *
	 * @param array       $payload   Event payload.
	 * @param string|null $secret    Webhook secret (defaults to $this->webhook_secret).
	 * @param int|null    $timestamp Unix timestamp.
	 * @return \WP_REST_Response
	 */
	private function dispatch_signed_webhook( array $payload, ?string $secret = null, ?int $timestamp = null ): \WP_REST_Response {
		$secret = $secret ?? $this->webhook_secret;
		$body   = wp_json_encode( $payload );
		$sig    = $this->generate_signature( $body, $secret, $timestamp );

		return $this->dispatch_webhook( $body, $sig );
	}

	/**
	 * Create a completed transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_completed_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                 => 'completed',
			'type'                   => 'one_time',
			'donor_id'               => $this->donor->id,
			'campaign_id'            => $this->campaign->id,
			'amount'                 => 5000,
			'tip_amount'             => 0,
			'fee_amount'             => 0,
			'total_amount'           => 5000,
			'currency'               => 'usd',
			'payment_gateway'        => 'stripe',
			'gateway_transaction_id' => 'pi_test_123',
			'is_test'                => false,
			'date_completed'         => current_time( 'mysql', true ),
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
	}

	/**
	 * Register an action hook and track it for automatic cleanup.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_tracked_action( string $hook, callable $callback, int $priority = 10 ): void {
		add_action( $hook, $callback, $priority );
		$this->hooks_to_remove[] = [ $hook, $callback, $priority ];
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Test that a valid signature is accepted and returns 200.
	 */
	public function test_valid_signature_accepted(): void {
		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'some.test.event',
			'data'       => [],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['received'] );
	}

	/**
	 * Test that an invalid signature is rejected with 400.
	 */
	public function test_invalid_signature_rejected(): void {
		$body      = wp_json_encode( [ 'event_type' => 'test', 'data' => [] ] );
		$signature = 't=123,v1=badhash';

		$response = $this->dispatch_webhook( $body, $signature );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_signature', $response->as_error()->get_error_code() );
	}

	/**
	 * Test that a stale timestamp is rejected.
	 */
	public function test_stale_timestamp_rejected(): void {
		$response = $this->dispatch_signed_webhook(
			[ 'event_type' => 'test', 'data' => [] ],
			null,
			time() - 400
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_signature', $response->as_error()->get_error_code() );
	}

	/**
	 * Test that a charge.refunded event transitions a transaction to refunded.
	 */
	public function test_charge_refunded_transitions_to_refunded(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_refund_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_refund_test',
				'amount_refunded'   => 5000,
				'amount_total'      => 5000,
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'refunded', $updated->status );
		$this->assertNotNull( $updated->date_refunded );
	}

	/**
	 * Test that a partial refund does not mark the transaction as fully refunded.
	 */
	public function test_partial_refund_does_not_mark_fully_refunded(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_partial_refund',
			'amount'                 => 5000,
			'total_amount'           => 5000,
		] );

		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_partial_refund',
				'amount_refunded'   => 2000,
				'amount_total'      => 5000,
			],
		] );

		$updated = Transaction::find( $transaction->id );

		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( 2000, $updated->amount_refunded );
	}

	/**
	 * Test that a partial refund decrements donor and campaign aggregates by the refund delta.
	 */
	public function test_partial_refund_decrements_aggregates(): void {
		$this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_partial_agg',
			'amount'                 => 5000,
			'tip_amount'             => 300,
			'total_amount'           => 5300,
		] );

		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_partial_agg',
				'amount_refunded'   => 2000,
				'amount_total'      => 5300,
			],
		] );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 3000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 3000, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	/**
	 * Test that sequential partial refunds accumulate correctly.
	 */
	public function test_sequential_partial_refunds(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_seq_refund',
			'amount'                 => 5000,
			'tip_amount'             => 0,
			'total_amount'           => 5000,
		] );

		// First partial refund.
		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_seq_refund',
				'amount_refunded'   => 1000,
				'amount_total'      => 5000,
			],
		] );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( 1000, $updated->amount_refunded );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 4000, $donor->total_donated );

		// Second partial refund (Stripe sends cumulative amount_refunded).
		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_seq_refund',
				'amount_refunded'   => 3000,
				'amount_total'      => 5000,
			],
		] );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( 3000, $updated->amount_refunded );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 2000, $donor->total_donated );
		$this->assertSame( 1, $donor->transaction_count );
	}

	/**
	 * Test that a partial refund followed by a full refund works correctly.
	 */
	public function test_partial_then_full_refund(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_partial_full',
			'amount'                 => 5000,
			'tip_amount'             => 300,
			'total_amount'           => 5300,
		] );

		// Partial refund first.
		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_partial_full',
				'amount_refunded'   => 2000,
				'amount_total'      => 5300,
			],
		] );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $updated->status );

		// Full refund (cumulative amount_refunded equals total).
		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_partial_full',
				'amount_refunded'   => 5300,
				'amount_total'      => 5300,
			],
		] );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'refunded', $updated->status );
		$this->assertSame( 5300, $updated->amount_refunded );
		$this->assertNotNull( $updated->date_refunded );

		// All aggregates should be fully decremented.
		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->transaction_count );
	}

	/**
	 * Test that a duplicate partial refund webhook is idempotent.
	 */
	public function test_partial_refund_idempotent(): void {
		$this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_partial_idem',
			'amount'                 => 5000,
			'total_amount'           => 5000,
		] );

		$payload = [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_partial_idem',
				'amount_refunded'   => 2000,
				'amount_total'      => 5000,
			],
		];

		$this->dispatch_signed_webhook( $payload );
		$this->dispatch_signed_webhook( $payload );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 3000, $donor->total_donated );
		$this->assertSame( 1, $donor->transaction_count );
	}

	/**
	 * Test that a refund decrements donor aggregates.
	 */
	public function test_refund_decrements_donor_aggregates(): void {
		$this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_donor_agg',
			'amount'                 => 5000,
			'tip_amount'             => 300,
			'total_amount'           => 5300,
		] );

		// Sanity-check: aggregates incremented on creation.
		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );

		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_donor_agg',
				'amount_refunded'   => 5300,
				'amount_total'      => 5300,
			],
		] );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test that a refund decrements campaign aggregates.
	 */
	public function test_refund_decrements_campaign_aggregates(): void {
		$this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_camp_agg',
			'amount'                 => 7500,
			'total_amount'           => 7500,
		] );

		// Sanity-check: aggregates incremented on creation.
		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 7500, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );

		$this->dispatch_signed_webhook( [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_camp_agg',
				'amount_refunded'   => 7500,
				'amount_total'      => 7500,
			],
		] );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->transaction_count );
	}

	/**
	 * Test idempotency: same refund webhook twice does not double-decrement.
	 */
	public function test_idempotency_same_refund_twice(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_idempotent',
			'amount'                 => 5000,
			'tip_amount'             => 300,
			'total_amount'           => 5300,
		] );

		$payload = [
			'event_type' => 'charge.refunded',
			'data'       => [
				'payment_intent_id' => 'pi_idempotent',
				'amount_refunded'   => 5300,
				'amount_total'      => 5300,
			],
		];

		$first  = $this->dispatch_signed_webhook( $payload );
		$second = $this->dispatch_signed_webhook( $payload );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'refunded', $updated->status );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test that unknown events return 200 and fire a dynamic hook.
	 */
	public function test_unknown_event_returns_200(): void {
		$hook_fired = false;

		$this->add_tracked_action(
			'missiondp_webhook_some.unknown.event',
			function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'some.unknown.event',
			'data'       => [ 'foo' => 'bar' ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['received'] );
		$this->assertTrue( $hook_fired, 'Dynamic webhook hook should fire for unknown events.' );
	}

	/**
	 * Test that a missing webhook secret returns 500.
	 */
	public function test_missing_webhook_secret_returns_error(): void {
		update_option( SettingsService::OPTION_NAME, [] );

		$body      = wp_json_encode( [ 'event_type' => 'test', 'data' => [] ] );
		$signature = $this->generate_signature( $body, 'any_secret' );

		$response = $this->dispatch_webhook( $body, $signature );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'webhook_not_configured', $response->as_error()->get_error_code() );
	}

	/**
	 * Test that malformed JSON returns 400.
	 *
	 * WP_REST_Server validates JSON before the callback runs, so the error
	 * code is `rest_invalid_json` rather than our endpoint's `invalid_payload`.
	 */
	public function test_malformed_json_returns_error(): void {
		$body      = 'not valid json';
		$signature = $this->generate_signature( $body, $this->webhook_secret );

		$response = $this->dispatch_webhook( $body, $signature );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_json', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// Subscription helpers
	// =========================================================================

	/**
	 * Create an active subscription with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$defaults = [
			'status'                  => 'active',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 2500,
			'fee_amount'              => 0,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'currency'                => 'usd',
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_' . wp_rand(),
			'gateway_customer_id'     => 'cus_test_' . wp_rand(),
			'is_test'                 => false,
			'date_next_renewal'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ),
		];

		$subscription = new Subscription( array_merge( $defaults, $overrides ) );
		$subscription->save();

		return $subscription;
	}

	// =========================================================================
	// invoice.paid tests
	// =========================================================================

	/**
	 * Test that invoice.paid creates a renewal transaction.
	 */
	public function test_invoice_paid_creates_renewal_transaction(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_renewal_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'invoice.paid',
			'data'       => [
				'subscription_id' => 'sub_renewal_test',
				'payment_intent_id' => 'pi_renewal_123',
				'billing_reason'    => 'subscription_cycle',
				'customer_id'       => $subscription->gateway_customer_id,
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		// Verify a new transaction was created.
		$transactions = Transaction::query( [
			'gateway_transaction_id' => 'pi_renewal_123',
			'per_page'               => 1,
		] );

		$this->assertCount( 1, $transactions );
		$this->assertSame( 'completed', $transactions[0]->status );
		$this->assertSame( $subscription->id, $transactions[0]->subscription_id );
		$this->assertSame( 2500, $transactions[0]->amount );

		// Verify subscription renewal count incremented.
		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 1, $updated->renewal_count );
	}

	/**
	 * Test that invoice.paid skips the first invoice (subscription_create).
	 */
	public function test_invoice_paid_skips_first_invoice(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_first_invoice',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'invoice.paid',
			'data'       => [
				'subscription_id'   => 'sub_first_invoice',
				'payment_intent_id' => 'pi_first_123',
				'billing_reason'    => 'subscription_create',
				'customer_id'       => $subscription->gateway_customer_id,
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		// No transaction should be created.
		$transactions = Transaction::query( [
			'gateway_transaction_id' => 'pi_first_123',
			'per_page'               => 1,
		] );

		$this->assertCount( 0, $transactions );
	}

	/**
	 * Test invoice.paid idempotency — same payment intent twice creates only one transaction.
	 */
	public function test_invoice_paid_idempotent(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_idempotent',
		] );

		$payload = [
			'event_type' => 'invoice.paid',
			'data'       => [
				'subscription_id'   => 'sub_idempotent',
				'payment_intent_id' => 'pi_idempotent_renewal',
				'billing_reason'    => 'subscription_cycle',
				'customer_id'       => $subscription->gateway_customer_id,
			],
		];

		$this->dispatch_signed_webhook( $payload );
		$this->dispatch_signed_webhook( $payload );

		$transactions = Transaction::query( [
			'gateway_transaction_id' => 'pi_idempotent_renewal',
		] );

		$this->assertCount( 1, $transactions );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 1, $updated->renewal_count );
	}

	// =========================================================================
	// customer.subscription.paused / resumed tests
	// =========================================================================

	/**
	 * Test that customer.subscription.paused sets status to paused.
	 */
	public function test_subscription_paused_event(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_pause_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'customer.subscription.paused',
			'data'       => [
				'subscription_id' => 'sub_pause_test',
				'status'          => 'paused',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'paused', $updated->status );
	}

	/**
	 * Test that customer.subscription.resumed sets status back to active.
	 */
	public function test_subscription_resumed_event(): void {
		$subscription = $this->create_subscription( [
			'status'                  => 'paused',
			'gateway_subscription_id' => 'sub_resume_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'customer.subscription.resumed',
			'data'       => [
				'subscription_id' => 'sub_resume_test',
				'status'          => 'active',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'active', $updated->status );
	}

	// =========================================================================
	// payment_intent.succeeded tests
	// =========================================================================

	/**
	 * Test that payment_intent.succeeded stores card details on the transaction.
	 */
	public function test_payment_intent_succeeded_stores_card_on_transaction(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_card_test',
		] );

		$this->dispatch_signed_webhook( [
			'event_type' => 'payment_intent.succeeded',
			'data'       => [
				'payment_intent_id' => 'pi_card_test',
				'payment_method'    => [
					'brand' => 'visa',
					'last4' => '4242',
				],
			],
		] );

		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'visa', $updated->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $updated->get_meta( 'payment_method_last4' ) );
	}

	/**
	 * Test that payment_intent.succeeded stores card details on the subscription.
	 */
	public function test_payment_intent_succeeded_stores_card_on_subscription(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_card_test',
		] );

		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_sub_card',
			'subscription_id'        => $subscription->id,
		] );

		$this->dispatch_signed_webhook( [
			'event_type' => 'payment_intent.succeeded',
			'data'       => [
				'payment_intent_id' => 'pi_sub_card',
				'payment_method'    => [
					'brand' => 'mastercard',
					'last4' => '8910',
				],
			],
		] );

		$updated_sub = Subscription::find( $subscription->id );
		$this->assertSame( 'mastercard', $updated_sub->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '8910', $updated_sub->get_meta( 'payment_method_last4' ) );
	}

	/**
	 * Test that payment_intent.succeeded is a no-op without payment_method data.
	 */
	public function test_payment_intent_succeeded_ignores_missing_card_data(): void {
		$transaction = $this->create_completed_transaction( [
			'gateway_transaction_id' => 'pi_no_card',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'payment_intent.succeeded',
			'data'       => [
				'payment_intent_id' => 'pi_no_card',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Transaction::find( $transaction->id );
		$this->assertEmpty( $updated->get_meta( 'payment_method_brand' ) );
	}

	// =========================================================================
	// invoice.payment_failed tests
	// =========================================================================

	/**
	 * Test that invoice.payment_failed marks an active subscription as past_due.
	 */
	public function test_invoice_payment_failed_marks_past_due(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_fail_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'invoice.payment_failed',
			'data'       => [
				'subscription_id' => 'sub_fail_test',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'past_due', $updated->status );
	}

	/**
	 * Test that invoice.payment_failed does not regress a subscription already past_due.
	 */
	public function test_invoice_payment_failed_idempotent_when_already_past_due(): void {
		$subscription = $this->create_subscription( [
			'status'                  => 'past_due',
			'gateway_subscription_id' => 'sub_fail_idem',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'invoice.payment_failed',
			'data'       => [
				'subscription_id' => 'sub_fail_idem',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'past_due', $updated->status );
	}

	/**
	 * Test that invoice.payment_failed fires the action hook.
	 */
	public function test_invoice_payment_failed_fires_hook(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_fail_hook',
		] );

		$hook_fired = false;

		$this->add_tracked_action(
			'missiondp_subscription_payment_failed',
			function ( $sub ) use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$this->dispatch_signed_webhook( [
			'event_type' => 'invoice.payment_failed',
			'data'       => [
				'subscription_id' => 'sub_fail_hook',
			],
		] );

		$this->assertTrue( $hook_fired, 'missiondp_subscription_payment_failed hook should fire.' );
	}

	// =========================================================================
	// customer.subscription.deleted tests
	// =========================================================================

	/**
	 * Test that customer.subscription.deleted cancels the subscription.
	 */
	public function test_subscription_deleted_event(): void {
		$subscription = $this->create_subscription( [
			'gateway_subscription_id' => 'sub_delete_test',
		] );

		$response = $this->dispatch_signed_webhook( [
			'event_type' => 'customer.subscription.deleted',
			'data'       => [
				'subscription_id' => 'sub_delete_test',
			],
		] );

		$this->assertSame( 200, $response->get_status() );

		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'cancelled', $updated->status );
		$this->assertNotNull( $updated->date_cancelled );
	}
}
