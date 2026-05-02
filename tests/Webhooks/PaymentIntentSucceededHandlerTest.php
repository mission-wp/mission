<?php
/**
 * Tests for the PaymentIntentSucceededHandler class.
 *
 * This handler owns the authoritative transition of pending transactions to
 * completed, and the activation of pending subscriptions tied to an initial
 * payment.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Webhooks;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Webhooks\PaymentIntentSucceededHandler;
use WP_UnitTestCase;

/**
 * PaymentIntentSucceededHandler test class.
 */
class PaymentIntentSucceededHandlerTest extends WP_UnitTestCase {

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
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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

	private function payload( array $overrides = [] ): array {
		return array_merge(
			[
				'payment_intent_id' => 'pi_test_123',
				'payment_method'    => [
					'brand' => 'visa',
					'last4' => '4242',
				],
			],
			$overrides
		);
	}

	private function add_tracked_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, $priority, $accepted_args );
		$this->hooks_to_remove[] = [ $hook, $callback, $priority ];
	}

	// =========================================================================
	// Tests
	// =========================================================================

	public function test_transitions_pending_transaction_to_completed(): void {
		$transaction = $this->create_pending_transaction();

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $after->status );
		$this->assertNotNull( $after->date_completed );
	}

	public function test_fires_status_transition_hooks(): void {
		$generic_fired  = false;
		$generic_args   = [];
		$specific_fired = false;

		$this->add_tracked_action(
			'missiondp_transaction_status_transition',
			function ( $txn, $old, $new ) use ( &$generic_fired, &$generic_args ) {
				$generic_fired = true;
				$generic_args  = [ 'old_status' => $old, 'new_status' => $new ];
			},
			10,
			3
		);
		$this->add_tracked_action(
			'missiondp_transaction_status_pending_to_completed',
			function () use ( &$specific_fired ) {
				$specific_fired = true;
			},
			10
		);

		$this->create_pending_transaction();
		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$this->assertTrue( $generic_fired );
		$this->assertSame( 'pending', $generic_args['old_status'] );
		$this->assertSame( 'completed', $generic_args['new_status'] );
		$this->assertTrue( $specific_fired );
	}

	public function test_updates_donor_aggregates(): void {
		$this->create_pending_transaction( [
			'amount'       => 5000,
			'tip_amount'   => 300,
			'total_amount' => 5300,
		] );

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );
	}

	public function test_updates_campaign_aggregates(): void {
		$this->create_pending_transaction( [
			'amount'       => 7500,
			'total_amount' => 7500,
		] );

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 7500, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	public function test_test_mode_aggregates_tracked_separately(): void {
		$this->create_pending_transaction( [
			'amount'       => 2000,
			'tip_amount'   => 100,
			'total_amount' => 2100,
			'is_test'      => true,
		] );

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 2000, $donor->test_total_donated );
		$this->assertSame( 100, $donor->test_total_tip );
		$this->assertSame( 1, $donor->test_transaction_count );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->transaction_count );
	}

	public function test_activates_pending_subscription_for_initial_payment(): void {
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

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$after = Subscription::find( $subscription->id );
		$this->assertSame( 'active', $after->status );
		$this->assertSame( $transaction->id, $after->initial_transaction_id );
	}

	public function test_stores_card_metadata_on_transaction(): void {
		$transaction = $this->create_pending_transaction();

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'visa', $after->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $after->get_meta( 'payment_method_last4' ) );
	}

	public function test_stores_card_metadata_on_subscription(): void {
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

		$this->create_pending_transaction( [
			'subscription_id' => $subscription->id,
			'type'            => 'monthly',
		] );

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$after = Subscription::find( $subscription->id );
		$this->assertSame( 'visa', $after->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $after->get_meta( 'payment_method_last4' ) );
	}

	public function test_is_idempotent_on_redelivery(): void {
		$transaction = $this->create_pending_transaction();

		$handler = new PaymentIntentSucceededHandler();
		$handler->handle( $this->payload() );

		$first_completed_at = Transaction::find( $transaction->id )->date_completed;

		$fired = 0;
		$this->add_tracked_action(
			'missiondp_transaction_status_pending_to_completed',
			function () use ( &$fired ) {
				$fired++;
			},
			10
		);

		// Redeliver — Stripe may send the same webhook multiple times.
		$handler->handle( $this->payload() );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'completed', $after->status );
		$this->assertSame( $first_completed_at, $after->date_completed, 'Should not re-stamp completion.' );
		$this->assertSame( 0, $fired, 'Transition hook should not fire on redelivery.' );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 1, $donor->transaction_count, 'Donor aggregates should not double-increment.' );
	}

	public function test_does_nothing_for_unknown_payment_intent(): void {
		$this->create_pending_transaction();

		$fired = false;
		$this->add_tracked_action(
			'missiondp_transaction_status_transition',
			function () use ( &$fired ) {
				$fired = true;
			},
			10
		);

		( new PaymentIntentSucceededHandler() )->handle(
			$this->payload( [ 'payment_intent_id' => 'pi_unknown_999' ] )
		);

		$this->assertFalse( $fired );
	}

	public function test_missing_payment_intent_id_is_noop(): void {
		$transaction = $this->create_pending_transaction();

		( new PaymentIntentSucceededHandler() )->handle( [ 'payment_method' => [ 'brand' => 'visa' ] ] );

		$after = Transaction::find( $transaction->id );
		$this->assertSame( 'pending', $after->status );
	}

	public function test_activates_subscription_when_transaction_already_completed(): void {
		// Covers the case where the status transition happened separately
		// (e.g. via admin action or prior webhook event) but the subscription
		// still needs activation. The handler should still activate it.
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

		$transaction = $this->create_pending_transaction( [
			'status'          => 'completed',
			'date_completed'  => current_time( 'mysql', true ),
			'subscription_id' => $subscription->id,
			'type'            => 'monthly',
		] );

		( new PaymentIntentSucceededHandler() )->handle( $this->payload() );

		$after = Subscription::find( $subscription->id );
		$this->assertSame( 'active', $after->status );
		$this->assertSame( $transaction->id, $after->initial_transaction_id );
	}
}
