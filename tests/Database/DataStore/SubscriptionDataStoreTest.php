<?php
/**
 * Tests for the SubscriptionDataStore class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database\DataStore;

use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\DonorDataStore;
use Mission\Database\DataStore\SubscriptionDataStore;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use WP_UnitTestCase;

/**
 * SubscriptionDataStore test class.
 */
class SubscriptionDataStoreTest extends WP_UnitTestCase {

	private SubscriptionDataStore $store;
	private DonorDataStore $donor_store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->store       = new SubscriptionDataStore();
		$this->donor_store = new DonorDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );

		parent::tear_down();
	}

	/**
	 * Create a test donor and return its ID.
	 */
	private function create_donor( array $overrides = array() ): int {
		$donor = new Donor(
			array_merge(
				array(
					'email'      => 'donor@example.com',
					'first_name' => 'Test',
					'last_name'  => 'Donor',
				),
				$overrides
			)
		);

		return $this->donor_store->create( $donor );
	}

	/**
	 * Build a Subscription model with sensible defaults.
	 */
	private function make_subscription( array $overrides = array() ): Subscription {
		return new Subscription(
			array_merge(
				array(
					'donor_id'        => 1,
					'form_id'         => 1,
					'amount'          => 2500,
					'total_amount'    => 2500,
					'payment_gateway' => 'stripe',
				),
				$overrides
			)
		);
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Test create and read.
	 */
	public function test_create_and_read(): void {
		$sub = $this->make_subscription();
		$id  = $this->store->create( $sub );

		$this->assertGreaterThan( 0, $id );

		$read = $this->store->read( $id );
		$this->assertInstanceOf( Subscription::class, $read );
		$this->assertSame( 2500, $read->amount );
	}

	/**
	 * Test is_test flag persists through create and read.
	 */
	public function test_is_test_flag_persists(): void {
		$test_sub = $this->make_subscription( array( 'is_test' => true ) );
		$test_id  = $this->store->create( $test_sub );

		$live_sub = $this->make_subscription( array( 'is_test' => false ) );
		$live_id  = $this->store->create( $live_sub );

		$this->assertTrue( $this->store->read( $test_id )->is_test );
		$this->assertFalse( $this->store->read( $live_id )->is_test );
	}

	/**
	 * Test update.
	 */
	public function test_update(): void {
		$sub = $this->make_subscription();
		$this->store->create( $sub );

		$sub->amount       = 5000;
		$sub->total_amount = 5000;
		$this->store->update( $sub );

		$read = $this->store->read( $sub->id );
		$this->assertSame( 5000, $read->amount );
	}

	/**
	 * Test delete.
	 */
	public function test_delete(): void {
		$sub = $this->make_subscription();
		$id  = $this->store->create( $sub );

		$this->assertTrue( $this->store->delete( $id ) );
		$this->assertNull( $this->store->read( $id ) );
	}

	// -------------------------------------------------------------------------
	// Status transitions
	// -------------------------------------------------------------------------

	/**
	 * Test status change fires hooks.
	 */
	public function test_status_change_fires_hooks(): void {
		$sub = $this->make_subscription( array( 'status' => 'pending' ) );
		$this->store->create( $sub );

		$generic_fired  = false;
		$specific_fired = false;

		add_action(
			'mission_subscription_status_transition',
			function ( $s, $old, $new ) use ( &$generic_fired ) {
				$generic_fired = true;
				$this->assertSame( 'pending', $old );
				$this->assertSame( 'active', $new );
			},
			10,
			3
		);

		add_action(
			'mission_subscription_status_pending_to_active',
			function () use ( &$specific_fired ) {
				$specific_fired = true;
			}
		);

		$sub->status = 'active';
		$this->store->update( $sub );

		$this->assertTrue( $generic_fired, 'Generic subscription status transition hook did not fire.' );
		$this->assertTrue( $specific_fired, 'Specific subscription status transition hook did not fire.' );
	}

	// -------------------------------------------------------------------------
	// Query filters
	// -------------------------------------------------------------------------

	/**
	 * Test query by donor_id.
	 */
	public function test_query_by_donor_id(): void {
		$this->store->create( $this->make_subscription( array( 'donor_id' => 1 ) ) );
		$this->store->create( $this->make_subscription( array( 'donor_id' => 1 ) ) );
		$this->store->create( $this->make_subscription( array( 'donor_id' => 2 ) ) );

		$this->assertCount( 2, $this->store->query( array( 'donor_id' => 1 ) ) );
		$this->assertCount( 1, $this->store->query( array( 'donor_id' => 2 ) ) );
	}

	/**
	 * Test query by campaign_id.
	 */
	public function test_query_by_campaign_id(): void {
		$this->store->create( $this->make_subscription( array( 'campaign_id' => 10 ) ) );
		$this->store->create( $this->make_subscription( array( 'campaign_id' => 10 ) ) );
		$this->store->create( $this->make_subscription( array( 'campaign_id' => 20 ) ) );

		$this->assertCount( 2, $this->store->query( array( 'campaign_id' => 10 ) ) );
		$this->assertCount( 1, $this->store->query( array( 'campaign_id' => 20 ) ) );
	}

	/**
	 * Test query by status.
	 */
	public function test_query_by_status(): void {
		$this->store->create( $this->make_subscription( array( 'status' => 'active' ) ) );
		$this->store->create( $this->make_subscription( array( 'status' => 'active' ) ) );
		$this->store->create( $this->make_subscription( array( 'status' => 'cancelled' ) ) );

		$this->assertCount( 2, $this->store->query( array( 'status' => 'active' ) ) );
		$this->assertCount( 1, $this->store->query( array( 'status' => 'cancelled' ) ) );
	}

	/**
	 * Test query by is_test.
	 */
	public function test_query_by_is_test(): void {
		$this->store->create( $this->make_subscription( array( 'is_test' => true ) ) );
		$this->store->create( $this->make_subscription( array( 'is_test' => true ) ) );
		$this->store->create( $this->make_subscription( array( 'is_test' => false ) ) );

		$this->assertCount( 2, $this->store->query( array( 'is_test' => true ) ) );
		$this->assertCount( 1, $this->store->query( array( 'is_test' => false ) ) );
	}

	// -------------------------------------------------------------------------
	// Renewals
	// -------------------------------------------------------------------------

	/**
	 * Test delete cascades to subscriptionmeta.
	 */
	public function test_delete_removes_meta(): void {
		$sub = $this->make_subscription();
		$id  = $this->store->create( $sub );

		$sub->update_meta( 'payment_method_brand', 'visa' );
		$sub->update_meta( 'payment_method_last4', '4242' );

		$this->assertSame( 'visa', $sub->get_meta( 'payment_method_brand' ) );

		$this->store->delete( $id );

		// Meta should be gone too.
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mission_subscriptionmeta WHERE mission_subscription_id = %d",
				$id
			)
		);

		$this->assertSame( 0, $count );
	}

	/**
	 * Test renewal updates count and total.
	 */
	public function test_renewal_updates_count_and_total(): void {
		$donor_id = $this->create_donor();

		$sub = $this->make_subscription(
			array(
				'donor_id'     => $donor_id,
				'amount'       => 2500,
				'total_amount' => 2500,
				'status'       => 'active',
				'frequency'    => 'monthly',
			)
		);
		$this->store->create( $sub );

		$sub->record_renewal();

		$reloaded = $this->store->read( $sub->id );
		$this->assertSame( 1, $reloaded->renewal_count );
		$this->assertSame( 2500, $reloaded->total_renewed );

		$sub->record_renewal();

		$reloaded = $this->store->read( $sub->id );
		$this->assertSame( 2, $reloaded->renewal_count );
		$this->assertSame( 5000, $reloaded->total_renewed );
	}
}
