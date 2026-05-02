<?php
/**
 * Tests for the ConfirmSubscriptionEndpoint class.
 *
 * The endpoint verifies PaymentIntent status with Stripe via the Mission API
 * and, on success, transitions the initial transaction and activates the
 * subscription. Tests mock the HTTP call with the `pre_http_request` filter.
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
 * ConfirmSubscriptionEndpoint test class.
 */
class ConfirmSubscriptionEndpointTest extends WP_UnitTestCase {

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
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = [];

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

		( new SettingsService() )->update( [ 'stripe_site_token' => 'tok_test_abc' ] );
	}

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

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function create_subscription_and_transaction( string $txn_status = 'pending', string $sub_status = 'pending' ): array {
		$subscription = new Subscription( [
			'status'                  => $sub_status,
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 5000,
			'total_amount'            => 5000,
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_123',
		] );
		$subscription->save();

		$transaction = new Transaction( [
			'status'                 => $txn_status,
			'type'                   => 'monthly',
			'donor_id'               => $this->donor->id,
			'campaign_id'            => $this->campaign->id,
			'amount'                 => 5000,
			'total_amount'           => 5000,
			'currency'               => 'usd',
			'payment_gateway'        => 'stripe',
			'gateway_transaction_id' => 'pi_test_123',
			'subscription_id'        => $subscription->id,
			'date_completed'         => 'completed' === $txn_status ? current_time( 'mysql', true ) : null,
		] );
		$transaction->save();

		return [ $subscription, $transaction ];
	}

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

	private function dispatch_confirm( int $transaction_id, int $subscription_id, string $payment_intent_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/donations/confirm-subscription' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'transaction_id'    => $transaction_id,
			'subscription_id'   => $subscription_id,
			'payment_intent_id' => $payment_intent_id,
		] ) );

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	public function test_transitions_and_activates_when_stripe_reports_succeeded(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$this->mock_api_response( 200, [
			'status'         => 'succeeded',
			'payment_method' => [ 'brand' => 'visa', 'last4' => '4242' ],
		] );

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );

		$txn_after = Transaction::find( $transaction->id );
		$sub_after = Subscription::find( $subscription->id );

		$this->assertSame( 'completed', $txn_after->status );
		$this->assertNotNull( $txn_after->date_completed );
		$this->assertSame( 'visa', $txn_after->get_meta( 'payment_method_brand' ) );

		$this->assertSame( 'active', $sub_after->status );
		$this->assertSame( $transaction->id, $sub_after->initial_transaction_id );
		$this->assertSame( 'visa', $sub_after->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $sub_after->get_meta( 'payment_method_last4' ) );
	}

	public function test_returns_completed_when_webhook_has_transitioned(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction( 'completed', 'active' );

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );
	}

	public function test_marks_failed_when_stripe_reports_canceled(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$this->mock_api_response( 200, [ 'status' => 'canceled' ] );

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_test_123' );

		$this->assertSame( 402, $response->get_status() );

		$txn_after = Transaction::find( $transaction->id );
		$sub_after = Subscription::find( $subscription->id );

		$this->assertSame( 'failed', $txn_after->status );
		$this->assertSame( 'pending', $sub_after->status, 'Subscription should not be activated on failure.' );
	}

	public function test_returns_processing_when_stripe_still_processing(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$this->mock_api_response( 200, [ 'status' => 'processing' ] );

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'processing', $data['status'] );

		$txn_after = Transaction::find( $transaction->id );
		$sub_after = Subscription::find( $subscription->id );

		$this->assertSame( 'pending', $txn_after->status );
		$this->assertSame( 'pending', $sub_after->status );
	}

	public function test_returns_processing_when_api_endpoint_not_deployed(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$this->mock_api_response( 404, [] );

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'processing', $data['status'] );

		$sub_after = Subscription::find( $subscription->id );
		$this->assertSame( 'pending', $sub_after->status );
	}

	public function test_returns_404_for_nonexistent_transaction(): void {
		$response = $this->dispatch_confirm( 999999, 1, 'pi_test_123' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transaction_not_found', $response->as_error()->get_error_code() );
	}

	public function test_returns_403_for_mismatched_payment_intent(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$response = $this->dispatch_confirm( $transaction->id, $subscription->id, 'pi_wrong_456' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'transaction_mismatch', $response->as_error()->get_error_code() );
	}

	public function test_returns_403_when_subscription_id_does_not_match_transaction(): void {
		[ $subscription, $transaction ] = $this->create_subscription_and_transaction();

		$other_subscription = new Subscription( [
			'status'                  => 'pending',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 2000,
			'total_amount'            => 2000,
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_other_999',
		] );
		$other_subscription->save();

		$response = $this->dispatch_confirm( $transaction->id, $other_subscription->id, 'pi_test_123' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'subscription_mismatch', $response->as_error()->get_error_code() );
	}
}
