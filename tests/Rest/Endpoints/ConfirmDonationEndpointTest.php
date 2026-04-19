<?php
/**
 * Tests for the ConfirmDonationEndpoint class.
 *
 * The endpoint verifies PaymentIntent status with Stripe (via the Mission API)
 * and transitions the transaction synchronously when Stripe reports success.
 * Tests mock the HTTP call using the `pre_http_request` filter.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ConfirmDonationEndpoint test class.
 */
class ConfirmDonationEndpointTest extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Hooks added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );

		DatabaseModule::create_tables();
	}

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		] );
		$this->donor->save();

		$this->campaign = new Campaign( [
			'title'       => 'General Fund',
			'goal_amount' => 100000,
		] );
		$this->campaign->save();

		// Populate the site_token so the verifier attempts the API call.
		$settings = new SettingsService();
		$settings->update( [ 'stripe_site_token' => 'tok_test_abc' ] );
	}

	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                 => 'pending',
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
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
	}

	/**
	 * Intercept wp_remote_* calls to the Mission API and return a mocked response.
	 *
	 * @param int   $code Response HTTP status code.
	 * @param array $body Response JSON body.
	 */
	private function mock_api_response( int $code, array $body ): void {
		$callback = function ( $preempt, $args, $url ) use ( $code, $body ) {
			if ( ! str_contains( $url, '/confirm-payment-intent' ) ) {
				return $preempt;
			}

			return [
				'response' => [ 'code' => $code ],
				'body'     => wp_json_encode( $body ),
				'headers'  => [],
				'cookies'  => [],
				'filename' => null,
			];
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->hooks_to_remove[] = [ 'pre_http_request', $callback, 10 ];
	}

	/**
	 * Intercept wp_remote_* and return a WP_Error (simulates network failure).
	 */
	private function mock_api_network_error(): void {
		$callback = function ( $preempt, $args, $url ) {
			if ( ! str_contains( $url, '/confirm-payment-intent' ) ) {
				return $preempt;
			}

			return new \WP_Error( 'http_request_failed', 'Could not resolve host' );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->hooks_to_remove[] = [ 'pre_http_request', $callback, 10 ];
	}

	private function dispatch_confirm( int $transaction_id, string $payment_intent_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission/v1/donations/confirm' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'transaction_id'    => $transaction_id,
			'payment_intent_id' => $payment_intent_id,
		] ) );

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Stripe reports succeeded via the Mission API — endpoint transitions the
	 * pending transaction to completed and returns 200.
	 */
	public function test_transitions_to_completed_when_stripe_reports_succeeded(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_response( 200, [
			'status'          => 'succeeded',
			'amount_received' => 5000,
			'currency'        => 'usd',
			'payment_method'  => [ 'brand' => 'visa', 'last4' => '4242' ],
		] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $after->status );
		$this->assertNotNull( $after->date_completed );
		$this->assertSame( 'visa', $after->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $after->get_meta( 'payment_method_last4' ) );
	}

	/**
	 * Returns 200 + completed when the webhook has already transitioned the
	 * transaction (rare but possible race). No Mission API call needed.
	 */
	public function test_returns_completed_when_webhook_has_transitioned(): void {
		$transaction = $this->create_transaction( [
			'status'         => 'completed',
			'date_completed' => current_time( 'mysql', true ),
		] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );
	}

	/**
	 * Stripe reports canceled — endpoint marks transaction failed and returns 402.
	 */
	public function test_marks_failed_when_stripe_reports_canceled(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_response( 200, [ 'status' => 'canceled' ] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$this->assertSame( 402, $response->get_status() );
		$this->assertSame( 'payment_failed', $response->as_error()->get_error_code() );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'failed', $after->status );
	}

	/**
	 * Stripe reports requires_payment_method — endpoint marks transaction failed.
	 */
	public function test_marks_failed_when_stripe_requires_payment_method(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_response( 200, [ 'status' => 'requires_payment_method' ] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$this->assertSame( 402, $response->get_status() );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'failed', $after->status );
	}

	/**
	 * Stripe reports processing — endpoint leaves transaction pending and returns 202.
	 */
	public function test_returns_processing_when_stripe_still_processing(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_response( 200, [ 'status' => 'processing' ] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'processing', $data['status'] );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'pending', $after->status );
	}

	/**
	 * Mission API endpoint not yet deployed (404) — endpoint falls back to
	 * processing response. Webhook authority takes over.
	 */
	public function test_returns_processing_when_api_endpoint_not_deployed(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_response( 404, [ 'error' => 'Not Found' ] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'processing', $data['status'] );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'pending', $after->status, 'Transaction must not transition when API is unavailable.' );
	}

	/**
	 * Mission API network failure — endpoint falls back to processing response.
	 */
	public function test_returns_processing_when_api_unreachable(): void {
		$transaction = $this->create_transaction();

		$this->mock_api_network_error();

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'processing', $data['status'] );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'pending', $after->status );
	}

	/**
	 * Returns 404 for a nonexistent transaction.
	 */
	public function test_returns_404_for_nonexistent_transaction(): void {
		$response = $this->dispatch_confirm( 999999, 'pi_test_123' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transaction_not_found', $response->as_error()->get_error_code() );
	}

	/**
	 * Returns 403 when the submitted payment_intent_id does not match the
	 * transaction. Blocks enumeration by transaction ID alone.
	 */
	public function test_returns_403_for_mismatched_payment_intent(): void {
		$transaction = $this->create_transaction();

		$response = $this->dispatch_confirm( $transaction->id, 'pi_wrong_456' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'transaction_mismatch', $response->as_error()->get_error_code() );
	}

	/**
	 * Transitioning fires donor/campaign aggregate updates via the
	 * status_transition hook chain.
	 */
	public function test_transition_updates_donor_and_campaign_aggregates(): void {
		$transaction = $this->create_transaction( [
			'amount'       => 5000,
			'tip_amount'   => 300,
			'total_amount' => 5300,
		] );

		$this->mock_api_response( 200, [
			'status'          => 'succeeded',
			'amount_received' => 5300,
			'payment_method'  => [ 'brand' => 'visa', 'last4' => '4242' ],
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 5000, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	/**
	 * The confirm-donation endpoint must not activate subscriptions — that
	 * responsibility belongs to confirm-subscription.
	 */
	public function test_does_not_activate_subscription(): void {
		$subscription = new Subscription( [
			'status'                  => 'pending',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 5000,
			'total_amount'            => 5000,
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_123',
		] );
		$subscription->save();

		$transaction = $this->create_transaction( [
			'subscription_id' => $subscription->id,
			'type'            => 'monthly',
		] );

		$this->mock_api_response( 200, [
			'status'         => 'succeeded',
			'payment_method' => [ 'brand' => 'visa', 'last4' => '4242' ],
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$after = Subscription::find( $subscription->id );
		$this->assertSame( 'pending', $after->status );
		$this->assertNull( $after->initial_transaction_id );
	}
}
