<?php
/**
 * Tests for the TransactionHistoryModule class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\TransactionHistory;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use MissionDP\Models\TransactionHistory;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * TransactionHistoryModule test class.
 */
class TransactionHistoryModuleTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

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

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		$defaults = [
			'email'      => 'test@example.com',
			'first_name' => 'Test',
			'last_name'  => 'Donor',
		];

		$donor = new Donor( array_merge( $defaults, $overrides ) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create a pending transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_pending_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                  => 'pending',
			'donor_id'                => 1,
			'amount'                  => 5000,
			'total_amount'            => 5000,
			'currency'                => 'usd',
			'payment_gateway'         => 'stripe',
			'gateway_transaction_id'  => 'pi_test_123',
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Test that history entries are created on status changes, including
	 * handled transitions and the generic fallback.
	 */
	public function test_creates_history_entry_on_status_change(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );

		// pending → completed (handled: payment_completed).
		$transaction->status = 'completed';
		$transaction->save();

		// completed → refunded (handled: refund_completed).
		$transaction->status = 'refunded';
		$transaction->save();

		$entries = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'order'          => 'ASC',
		] );

		$event_types = array_map( fn( $e ) => $e->event_type, $entries );
		$this->assertContains( 'payment_initiated', $event_types );
		$this->assertContains( 'payment_completed', $event_types );
		$this->assertContains( 'refund_completed', $event_types );

		// Handled transitions must NOT also produce a status_changed entry.
		$duplicates = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'status_changed',
		] );
		$this->assertCount( 0, $duplicates, 'Handled transitions should not produce duplicate status_changed entries.' );

		// Test non-handled transition: completed → cancelled triggers status_changed.
		$txn2         = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );
		$txn2->status = 'completed';
		$txn2->save();

		$txn2->status = 'cancelled';
		$txn2->save();

		$status_changed = TransactionHistory::query( [
			'transaction_id' => $txn2->id,
			'event_type'     => 'status_changed',
		] );
		$this->assertCount( 1, $status_changed );
	}

	/**
	 * Test that status_changed history entries include old and new status.
	 */
	public function test_history_entry_includes_old_and_new_status(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );

		$transaction->status = 'completed';
		$transaction->save();

		// completed → cancelled is not in HANDLED_TRANSITIONS, so it logs status_changed.
		$transaction->status = 'cancelled';
		$transaction->save();

		$entries = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'status_changed',
		] );

		$this->assertCount( 1, $entries );

		$context = $entries[0]->get_context_data();
		$this->assertSame( 'completed', $context['old_status'] );
		$this->assertSame( 'cancelled', $context['new_status'] );
	}

	/**
	 * Test that actor_type and actor_id are recorded correctly for
	 * system, gateway, and user actors.
	 */
	public function test_history_entry_records_actor_type_and_actor_id(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );

		// payment_initiated is logged by on_transaction_created → log_system_event.
		$initiated = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'payment_initiated',
		] );
		$this->assertCount( 1, $initiated );
		$this->assertSame( 'system', $initiated[0]->actor_type );
		$this->assertNull( $initiated[0]->actor_id );

		// pending → completed is logged by on_payment_completed → log_gateway_event.
		$transaction->status = 'completed';
		$transaction->save();

		$completed = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'payment_completed',
		] );
		$this->assertCount( 1, $completed );
		$this->assertSame( 'gateway', $completed[0]->actor_type );
		$this->assertNull( $completed[0]->actor_id );

		// completed → cancelled (non-handled) with admin logged in → user actor.
		$transaction->status = 'cancelled';
		$transaction->save();

		$changed = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'status_changed',
		] );
		$this->assertCount( 1, $changed );
		$this->assertSame( 'user', $changed[0]->actor_type );
		$this->assertSame( $this->admin_id, $changed[0]->actor_id );

		// Test system fallback: no user logged in for a non-handled transition.
		wp_set_current_user( 0 );

		$txn2         = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );
		$txn2->status = 'completed';
		$txn2->save();
		$txn2->status = 'cancelled';
		$txn2->save();

		$system_changed = TransactionHistory::query( [
			'transaction_id' => $txn2->id,
			'event_type'     => 'status_changed',
		] );
		$this->assertCount( 1, $system_changed );
		$this->assertSame( 'system', $system_changed[0]->actor_type );
		$this->assertNull( $system_changed[0]->actor_id );
	}

	/**
	 * Test that context JSON contains the expected details for each event type.
	 */
	public function test_context_json_contains_relevant_details(): void {
		$donor = $this->create_donor();

		// payment_initiated context: amount + payment_gateway.
		$transaction = $this->create_pending_transaction( [
			'donor_id'                => $donor->id,
			'amount'                  => 7500,
			'payment_gateway'         => 'stripe',
			'gateway_transaction_id'  => 'pi_abc123',
		] );

		$initiated = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'payment_initiated',
		] );
		$ctx = $initiated[0]->get_context_data();
		$this->assertSame( 7500, $ctx['amount'] );
		$this->assertSame( 'stripe', $ctx['payment_gateway'] );

		// payment_completed context: gateway_transaction_id.
		$transaction->status = 'completed';
		$transaction->save();

		$completed = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'payment_completed',
		] );
		$ctx = $completed[0]->get_context_data();
		$this->assertSame( 'pi_abc123', $ctx['gateway_transaction_id'] );

		// refund_completed context: amount.
		$transaction->status = 'refunded';
		$transaction->save();

		$refunded = TransactionHistory::query( [
			'transaction_id' => $transaction->id,
			'event_type'     => 'refund_completed',
		] );
		$ctx = $refunded[0]->get_context_data();
		$this->assertSame( 7500, $ctx['amount'] );

		// payment_failed context: empty.
		$txn2         = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );
		$txn2->status = 'failed';
		$txn2->save();

		$failed = TransactionHistory::query( [
			'transaction_id' => $txn2->id,
			'event_type'     => 'payment_failed',
		] );
		$this->assertCount( 1, $failed );
		$this->assertEmpty( $failed[0]->get_context_data() );

		// status_changed context: old_status + new_status.
		$txn3         = $this->create_pending_transaction( [ 'donor_id' => $donor->id ] );
		$txn3->status = 'completed';
		$txn3->save();
		$txn3->status = 'cancelled';
		$txn3->save();

		$changed = TransactionHistory::query( [
			'transaction_id' => $txn3->id,
			'event_type'     => 'status_changed',
		] );
		$ctx = $changed[0]->get_context_data();
		$this->assertSame( 'completed', $ctx['old_status'] );
		$this->assertSame( 'cancelled', $ctx['new_status'] );
	}
}
