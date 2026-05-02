<?php
/**
 * Tests for the ActivityFeedModule class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\ActivityFeed;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\ActivityLog;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Plugin;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * ActivityFeedModule test class.
 */
class ActivityFeedModuleTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Hooks to remove in tear_down.
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

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		wp_set_current_user( 0 );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

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
			'status'          => 'pending',
			'donor_id'        => 1,
			'amount'          => 5000,
			'total_amount'    => 5000,
			'currency'        => 'usd',
			'payment_gateway' => 'stripe',
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Test that a donation_completed event is logged when a pending transaction
	 * transitions to completed.
	 */
	public function test_logs_event_on_donation_completed(): void {
		$donor       = $this->create_donor();
		$campaign    = new Campaign( [ 'title' => 'Test Campaign', 'description' => 'Desc' ] );
		$campaign->save();
		$transaction = $this->create_pending_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
			'amount'      => 5000,
		] );

		// Transition to completed.
		$transaction->status = 'completed';
		$transaction->save();

		$entries = ActivityLog::query( [ 'event' => 'donation_completed' ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( 'transaction', $entry->object_type );
		$this->assertSame( $transaction->id, $entry->object_id );
		$this->assertSame( $this->admin_id, $entry->actor_id );

		$data = json_decode( $entry->data, true );
		$this->assertSame( 5000, $data['amount'] );
		$this->assertSame( $donor->id, $data['donor_id'] );
		$this->assertSame( $campaign->id, $data['campaign_id'] );
	}

	/**
	 * Test that a donation_refunded event is logged when a completed transaction
	 * transitions to refunded.
	 */
	public function test_logs_event_on_donation_refunded(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_pending_transaction( [
			'donor_id' => $donor->id,
			'amount'   => 7500,
			'is_test'  => true,
		] );

		$transaction->status = 'completed';
		$transaction->save();

		$transaction->status = 'refunded';
		$transaction->save();

		$entries = ActivityLog::query( [ 'event' => 'donation_refunded' ] );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( 'transaction', $entry->object_type );
		$this->assertSame( $transaction->id, $entry->object_id );
		$this->assertTrue( $entry->is_test );

		$data = json_decode( $entry->data, true );
		$this->assertSame( 7500, $data['amount'] );
		$this->assertSame( $donor->id, $data['donor_id'] );
	}

	/**
	 * Test that subscription_created and subscription_cancelled events are logged.
	 */
	public function test_logs_event_on_subscription_created_and_cancelled(): void {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 2500,
			'total_amount'    => 2500,
			'frequency'       => 'monthly',
			'payment_gateway' => 'stripe',
		] );
		$subscription->save();

		// Verify subscription_created was logged.
		$created_entries = ActivityLog::query( [ 'event' => 'subscription_created' ] );
		$this->assertCount( 1, $created_entries );
		$this->assertSame( 'subscription', $created_entries[0]->object_type );
		$this->assertSame( $subscription->id, $created_entries[0]->object_id );

		$data = json_decode( $created_entries[0]->data, true );
		$this->assertSame( 2500, $data['amount'] );
		$this->assertSame( 'monthly', $data['frequency'] );

		// Cancel the subscription.
		$subscription->status = 'cancelled';
		$subscription->save();

		$cancelled_entries = ActivityLog::query( [ 'event' => 'subscription_cancelled' ] );
		$this->assertCount( 1, $cancelled_entries );
		$this->assertSame( $subscription->id, $cancelled_entries[0]->object_id );
	}

	/**
	 * Test that subscription_failed event is logged.
	 */
	public function test_logs_event_on_subscription_failed(): void {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 2000,
			'total_amount'    => 2000,
			'frequency'       => 'monthly',
			'payment_gateway' => 'stripe',
			'is_test'         => true,
		] );
		$subscription->save();

		$subscription->status = 'failed';
		$subscription->save();

		$entries = ActivityLog::query( [ 'event' => 'subscription_failed' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'subscription', $entries[0]->object_type );
		$this->assertSame( $subscription->id, $entries[0]->object_id );
		$this->assertTrue( $entries[0]->is_test );
	}

	/**
	 * Test that campaign_created event is logged.
	 */
	public function test_logs_event_on_campaign_created(): void {
		$campaign = new Campaign( [ 'title' => 'Fundraiser', 'description' => 'A fundraiser' ] );
		$campaign->save();

		$created_entries = ActivityLog::query( [ 'event' => 'campaign_created' ] );
		$this->assertCount( 1, $created_entries );
		$this->assertSame( 'campaign', $created_entries[0]->object_type );
		$this->assertSame( $campaign->id, $created_entries[0]->object_id );

		$data = json_decode( $created_entries[0]->data, true );
		$this->assertSame( $campaign->post_id, $data['post_id'] );
		$this->assertSame( 'Fundraiser', $data['title'] );
	}

	/**
	 * Test that recurring_donation_processed is logged for subscription transactions.
	 */
	public function test_logs_recurring_donation_processed_for_subscription_transaction(): void {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 5000,
			'total_amount'    => 5000,
			'frequency'       => 'monthly',
			'payment_gateway' => 'stripe',
		] );
		$subscription->save();

		$transaction = $this->create_pending_transaction( [
			'donor_id'        => $donor->id,
			'amount'          => 5000,
			'subscription_id' => $subscription->id,
		] );
		$transaction->status = 'completed';
		$transaction->save();

		// Should log as recurring_donation_processed, NOT donation_completed.
		$recurring_entries = ActivityLog::query( [ 'event' => 'recurring_donation_processed' ] );
		$this->assertCount( 1, $recurring_entries );

		$data = json_decode( $recurring_entries[0]->data, true );
		$this->assertSame( 5000, $data['amount'] );
		$this->assertSame( 'monthly', $data['frequency'] );
		$this->assertSame( $donor->id, $data['donor_id'] );

		$donation_entries = ActivityLog::query( [ 'event' => 'donation_completed' ] );
		$this->assertCount( 0, $donation_entries );
	}

	/**
	 * Test that subscription_amount_increased is logged when amount goes up.
	 */
	public function test_logs_subscription_amount_increased(): void {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 2500,
			'total_amount'    => 2500,
			'frequency'       => 'monthly',
			'payment_gateway' => 'stripe',
		] );
		$subscription->save();

		// Fire the hook directly (update_amount calls an external API).
		do_action( 'missiondp_subscription_amount_changed', $subscription, 2500, 5000 );

		$entries = ActivityLog::query( [ 'event' => 'subscription_amount_increased' ] );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'subscription', $entries[0]->object_type );
		$this->assertSame( $subscription->id, $entries[0]->object_id );

		$data = json_decode( $entries[0]->data, true );
		$this->assertSame( 2500, $data['old_amount'] );
		$this->assertSame( 5000, $data['new_amount'] );
		$this->assertSame( 'monthly', $data['frequency'] );
	}

	/**
	 * Test that subscription_amount_decreased is logged when amount goes down.
	 */
	public function test_logs_subscription_amount_decreased(): void {
		$donor = $this->create_donor();

		$subscription = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 5000,
			'total_amount'    => 5000,
			'frequency'       => 'weekly',
			'payment_gateway' => 'stripe',
		] );
		$subscription->save();

		do_action( 'missiondp_subscription_amount_changed', $subscription, 5000, 2500 );

		$entries = ActivityLog::query( [ 'event' => 'subscription_amount_decreased' ] );
		$this->assertCount( 1, $entries );

		$data = json_decode( $entries[0]->data, true );
		$this->assertSame( 5000, $data['old_amount'] );
		$this->assertSame( 2500, $data['new_amount'] );
		$this->assertSame( 'weekly', $data['frequency'] );
	}

	/**
	 * Test that donor events are no longer logged.
	 */
	public function test_donor_events_are_not_logged(): void {
		$this->create_donor();

		$entries = ActivityLog::query( [ 'object_type' => 'donor' ] );
		$this->assertCount( 0, $entries );
	}

	/**
	 * Test that settings updates are logged with changed keys.
	 */
	public function test_settings_updated_is_logged(): void {
		$settings = new SettingsService();
		$settings->update( [ 'currency' => 'EUR' ] );

		$entries = ActivityLog::query( [ 'event' => 'settings_updated' ] );
		$this->assertCount( 1, $entries );

		$data = json_decode( $entries[0]->data, true );
		$this->assertContains( 'currency', $data['changed_keys'] );
		$this->assertSame( 'system', $entries[0]->category );
	}

	/**
	 * Test that auto-prune removes entries older than 90 days.
	 */
	public function test_auto_prune_removes_entries_older_than_90_days(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'missiondp_activity_log';

		// Create entries with explicit dates.
		$entry_100 = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 100 ] );
		$entry_100->save();

		$entry_95 = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 95 ] );
		$entry_95->save();

		$entry_80 = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 80 ] );
		$entry_80->save();

		$entry_today = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 1 ] );
		$entry_today->save();

		// Backdate entries using raw SQL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET date_created = DATE_SUB(%s, INTERVAL 100 DAY) WHERE id = %d", current_time( 'mysql', true ), $entry_100->id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET date_created = DATE_SUB(%s, INTERVAL 95 DAY) WHERE id = %d", current_time( 'mysql', true ), $entry_95->id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET date_created = DATE_SUB(%s, INTERVAL 80 DAY) WHERE id = %d", current_time( 'mysql', true ), $entry_80->id ) );
		// phpcs:enable

		Plugin::instance()->get_activity_feed_module()->run_prune();

		$remaining = ActivityLog::query( [ 'event' => 'test' ] );
		$this->assertCount( 2, $remaining );

		$remaining_ids = array_map( fn( $e ) => $e->object_id, $remaining );
		$this->assertContains( 80, $remaining_ids );
		$this->assertContains( 1, $remaining_ids );
	}

	/**
	 * Test that the prune retention period is filterable.
	 */
	public function test_prune_retention_is_filterable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'missiondp_activity_log';

		$entry_50 = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 50 ] );
		$entry_50->save();

		$entry_30 = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 30 ] );
		$entry_30->save();

		$entry_today = new ActivityLog( [ 'event' => 'test', 'object_type' => 'test', 'object_id' => 1 ] );
		$entry_today->save();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET date_created = DATE_SUB(%s, INTERVAL 50 DAY) WHERE id = %d", current_time( 'mysql', true ), $entry_50->id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET date_created = DATE_SUB(%s, INTERVAL 30 DAY) WHERE id = %d", current_time( 'mysql', true ), $entry_30->id ) );
		// phpcs:enable

		$filter = fn() => 40;
		add_filter( 'missiondp_activity_log_retention_days', $filter );
		$this->hooks_to_remove[] = [ 'missiondp_activity_log_retention_days', $filter, 10 ];

		Plugin::instance()->get_activity_feed_module()->run_prune();

		$remaining = ActivityLog::query( [ 'event' => 'test' ] );
		$this->assertCount( 2, $remaining );

		$remaining_ids = array_map( fn( $e ) => $e->object_id, $remaining );
		$this->assertContains( 30, $remaining_ids );
		$this->assertContains( 1, $remaining_ids );
		$this->assertNotContains( 50, $remaining_ids );
	}

	/**
	 * Test that campaign milestone activity entries inherit is_test from the
	 * transaction that triggered them.
	 */
	public function test_campaign_milestone_inherits_is_test_from_transaction(): void {
		$donor    = $this->create_donor();
		$campaign = new Campaign( [
			'title'       => 'Milestone Test',
			'description' => 'Desc',
			'goal_amount' => 10000,
		] );
		$campaign->save();

		// Initialize milestones for the campaign.
		$tracker = new \MissionDP\Campaigns\MilestoneTracker();
		$tracker->on_campaign_created( $campaign );

		// Create a test-mode transaction that crosses 25%.
		$txn = $this->create_pending_transaction( [
			'donor_id'    => $donor->id,
			'campaign_id' => $campaign->id,
			'amount'      => 3000,
			'is_test'     => true,
		] );
		$txn->status = 'completed';
		$txn->save();

		// The milestone activity entry should be marked as a test entry.
		$entries = ActivityLog::query( [ 'event' => 'campaign_milestone_reached' ] );
		$this->assertCount( 1, $entries, 'A campaign_milestone_reached entry should be logged.' );
		$this->assertTrue( $entries[0]->is_test, 'Milestone activity entry should inherit is_test from the triggering transaction.' );
	}

	/**
	 * Test that is_test flag is set correctly on logged events.
	 */
	public function test_test_mode_flag_set_correctly_on_logged_events(): void {
		$donor = $this->create_donor();

		// Test transaction with is_test = true.
		$test_txn = $this->create_pending_transaction( [
			'donor_id' => $donor->id,
			'is_test'  => true,
		] );
		$test_txn->status = 'completed';
		$test_txn->save();

		$test_entries = ActivityLog::query( [ 'event' => 'donation_completed', 'is_test' => true ] );
		$this->assertCount( 1, $test_entries );
		$this->assertTrue( $test_entries[0]->is_test );

		// Live transaction with is_test = false.
		$live_txn = $this->create_pending_transaction( [
			'donor_id' => $donor->id,
			'is_test'  => false,
		] );
		$live_txn->status = 'completed';
		$live_txn->save();

		$live_entries = ActivityLog::query( [ 'event' => 'donation_completed', 'is_test' => false ] );
		$this->assertCount( 1, $live_entries );
		$this->assertFalse( $live_entries[0]->is_test );

		// Subscription with is_test = true.
		$test_sub = new Subscription( [
			'status'          => 'active',
			'donor_id'        => $donor->id,
			'amount'          => 1000,
			'total_amount'    => 1000,
			'frequency'       => 'monthly',
			'payment_gateway' => 'stripe',
			'is_test'         => true,
		] );
		$test_sub->save();

		$sub_entries = ActivityLog::query( [ 'event' => 'subscription_created', 'is_test' => true ] );
		$this->assertCount( 1, $sub_entries );
		$this->assertTrue( $sub_entries[0]->is_test );
	}
}
