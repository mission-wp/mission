<?php
/**
 * Tests for the ConfirmDonationEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ConfirmDonationEndpoint test class.
 */
class ConfirmDonationEndpointTest extends WP_UnitTestCase {

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

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

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
	 * Create a pending transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_pending_transaction( array $overrides = [] ): Transaction {
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
	 * Build and dispatch a POST to /mission/v1/donations/confirm.
	 *
	 * @param int    $transaction_id    Transaction ID.
	 * @param string $payment_intent_id Payment intent ID.
	 * @return \WP_REST_Response
	 */
	private function dispatch_confirm( int $transaction_id, string $payment_intent_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission/v1/donations/confirm' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'transaction_id'    => $transaction_id,
			'payment_intent_id' => $payment_intent_id,
		] ) );

		return $this->server->dispatch( $request );
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
	 * Test confirms a pending transaction and transitions it to completed.
	 */
	public function test_confirms_pending_transaction(): void {
		$transaction = $this->create_pending_transaction();

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $transaction->id, $data['transaction_id'] );

		// Re-read from DB.
		$updated = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $updated->status );
		$this->assertNotNull( $updated->date_completed );
		$this->assertSame( 'pi_test_123', $updated->gateway_transaction_id );
	}

	/**
	 * Test returns 404 for a nonexistent transaction ID.
	 */
	public function test_returns_404_for_nonexistent_transaction(): void {
		$response = $this->dispatch_confirm( 999999, 'pi_test_123' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transaction_not_found', $response->as_error()->get_error_code() );
	}

	/**
	 * Test returns 409 for an already completed transaction.
	 */
	public function test_returns_409_for_already_completed_transaction(): void {
		$transaction = $this->create_pending_transaction();

		// Complete the transaction first.
		$transaction->status         = 'completed';
		$transaction->date_completed = current_time( 'mysql', true );
		$transaction->save();

		$response = $this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'transaction_already_processed', $response->as_error()->get_error_code() );
	}

	/**
	 * Test returns 403 for a mismatched payment intent ID.
	 */
	public function test_returns_403_for_mismatched_payment_intent(): void {
		$transaction = $this->create_pending_transaction( [
			'gateway_transaction_id' => 'pi_test_123',
		] );

		$response = $this->dispatch_confirm( $transaction->id, 'pi_wrong_456' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'transaction_mismatch', $response->as_error()->get_error_code() );
	}

	/**
	 * Test fires status transition hooks on completion.
	 */
	public function test_fires_status_transition_hooks(): void {
		$generic_fired  = false;
		$generic_args   = [];
		$specific_fired = false;

		$generic_callback = function ( $transaction, $old_status, $new_status ) use ( &$generic_fired, &$generic_args ) {
			$generic_fired = true;
			$generic_args  = [
				'old_status' => $old_status,
				'new_status' => $new_status,
			];
		};

		add_action( 'mission_transaction_status_transition', $generic_callback, 10, 3 );
		$this->hooks_to_remove[] = [ 'mission_transaction_status_transition', $generic_callback, 10 ];

		$this->add_tracked_action(
			'mission_transaction_status_pending_to_completed',
			function () use ( &$specific_fired ) {
				$specific_fired = true;
			},
			10
		);

		$transaction = $this->create_pending_transaction();
		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$this->assertTrue( $generic_fired, 'Generic transition hook should fire.' );
		$this->assertSame( 'pending', $generic_args['old_status'] );
		$this->assertSame( 'completed', $generic_args['new_status'] );
		$this->assertTrue( $specific_fired, 'Specific pending_to_completed hook should fire.' );
	}

	/**
	 * Test donor aggregates are updated on transaction completion.
	 */
	public function test_donor_aggregates_updated_on_completion(): void {
		$transaction = $this->create_pending_transaction( [
			'amount'       => 5000,
			'tip_amount'   => 300,
			'total_amount' => 5300,
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$donor = Donor::find( $this->donor->id );

		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );
		$this->assertNotNull( $donor->first_transaction );
		$this->assertNotNull( $donor->last_transaction );
	}

	/**
	 * Test campaign aggregates are updated on transaction completion.
	 */
	public function test_campaign_aggregates_updated_on_completion(): void {
		$transaction = $this->create_pending_transaction( [
			'amount'       => 7500,
			'total_amount' => 7500,
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$campaign = Campaign::find( $this->campaign->id );

		$this->assertSame( 7500, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	/**
	 * Test donor test mode aggregates are updated separately from live columns.
	 */
	public function test_donor_test_mode_aggregates(): void {
		$transaction = $this->create_pending_transaction( [
			'amount'       => 2000,
			'tip_amount'   => 100,
			'total_amount' => 2100,
			'is_test'      => true,
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$donor = Donor::find( $this->donor->id );

		$this->assertSame( 2000, $donor->test_total_donated );
		$this->assertSame( 100, $donor->test_total_tip );
		$this->assertSame( 1, $donor->test_transaction_count );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test that confirm-donation does NOT activate subscriptions.
	 *
	 * Subscription activation happens via the separate confirm-subscription
	 * endpoint, not confirm-donation. This verifies the one-time donation
	 * confirm path leaves subscriptions untouched.
	 */
	public function test_confirm_donation_does_not_activate_subscription(): void {
		$subscription = new Subscription( [
			'status'                  => 'pending',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 5000,
			'total_amount'            => 5000,
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_123',
			'initial_transaction_id'  => null,
		] );
		$subscription->save();

		$transaction = $this->create_pending_transaction( [
			'subscription_id' => $subscription->id,
			'type'            => 'monthly',
		] );

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$updated_sub = Subscription::find( $subscription->id );

		$this->assertNull(
			$updated_sub->initial_transaction_id,
			'Confirm-donation should not set subscription initial_transaction_id (use confirm-subscription instead).'
		);
		$this->assertSame( 'pending', $updated_sub->status, 'Subscription should remain pending after confirm-donation.' );
	}

	/**
	 * Test receipt email is triggered on donation completion.
	 *
	 * EXPECTED FAILURE: This feature is not yet implemented. The test documents
	 * the expected behavior that a receipt email is sent to the donor when a
	 * transaction is completed.
	 */
	public function test_receipt_email_triggered_on_completion(): void {
		$emails_sent = [];

		$this->add_tracked_action(
			'wp_mail',
			function ( $args ) use ( &$emails_sent ) {
				$emails_sent[] = $args;
			},
			10
		);

		$transaction = $this->create_pending_transaction();

		$this->dispatch_confirm( $transaction->id, 'pi_test_123' );

		$this->assertNotEmpty( $emails_sent, 'A receipt email should be sent on donation completion.' );

		$recipient_found = false;
		foreach ( $emails_sent as $email ) {
			if ( isset( $email['to'] ) && str_contains( $email['to'], 'jane@example.com' ) ) {
				$recipient_found = true;
				break;
			}
		}

		$this->assertTrue( $recipient_found, 'Receipt email should be sent to the donor email address.' );
	}
}
