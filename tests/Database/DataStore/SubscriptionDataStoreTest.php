<?php
/**
 * Tests for the SubscriptionDataStore class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database\DataStore;

use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\SubscriptionDataStore;
use Mission\Models\Subscription;
use WP_UnitTestCase;

/**
 * SubscriptionDataStore test class.
 */
class SubscriptionDataStoreTest extends WP_UnitTestCase {

	private SubscriptionDataStore $store;

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
		$this->store = new SubscriptionDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_subscriptions" );

		parent::tear_down();
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
}
