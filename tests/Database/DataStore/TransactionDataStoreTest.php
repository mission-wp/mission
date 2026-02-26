<?php
/**
 * Tests for the TransactionDataStore class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database\DataStore;

use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\CampaignDataStore;
use Mission\Database\DataStore\TransactionDataStore;
use Mission\Database\DataStore\DonorDataStore;
use Mission\Models\Campaign;
use Mission\Models\Transaction;
use Mission\Models\Donor;
use WP_UnitTestCase;

/**
 * TransactionDataStore test class.
 */
class TransactionDataStoreTest extends WP_UnitTestCase {

	private TransactionDataStore $store;
	private DonorDataStore $donor_store;
	private CampaignDataStore $campaign_store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Drop and recreate to pick up schema changes (dbDelta can't drop columns/keys).
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaign_meta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->store          = new TransactionDataStore();
		$this->donor_store    = new DonorDataStore();
		$this->campaign_store = new CampaignDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_transaction_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_donors" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_donor_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_campaigns" );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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
	 * Create a test campaign and return its ID.
	 */
	private function create_campaign( array $overrides = array() ): int {
		$campaign = new Campaign(
			array_merge(
				array(
					'post_id'     => 1,
					'goal_amount' => 100000,
				),
				$overrides
			)
		);
		return $this->campaign_store->create( $campaign );
	}

	/**
	 * Build a Transaction model with sensible defaults.
	 */
	private function make_transaction( array $overrides = array() ): Transaction {
		return new Transaction(
			array_merge(
				array(
					'donor_id'        => 1,
					'source_post_id'         => 1,
					'amount'          => 5000,
					'total_amount'    => 5000,
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
	 * Test create returns ID and sets model ID.
	 */
	public function test_create_returns_id_and_sets_model_id(): void {
		$transaction = $this->make_transaction();
		$id          = $this->store->create( $transaction );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $id, $transaction->id );
	}

	/**
	 * Test read returns a Transaction.
	 */
	public function test_read_returns_transaction(): void {
		$transaction = $this->make_transaction( array( 'amount' => 2500 ) );
		$id          = $this->store->create( $transaction );

		$read = $this->store->read( $id );

		$this->assertInstanceOf( Transaction::class, $read );
		$this->assertSame( $id, $read->id );
		$this->assertSame( 2500, $read->amount );
	}

	/**
	 * Test read nonexistent returns null.
	 */
	public function test_read_nonexistent_returns_null(): void {
		$this->assertNull( $this->store->read( 99999 ) );
	}

	/**
	 * Test update modifies fields.
	 */
	public function test_update_modifies_fields(): void {
		$transaction = $this->make_transaction();
		$this->store->create( $transaction );

		$transaction->amount       = 9999;
		$transaction->total_amount = 9999;
		$result                    = $this->store->update( $transaction );

		$this->assertTrue( $result );

		$updated = $this->store->read( $transaction->id );
		$this->assertSame( 9999, $updated->amount );
	}

	/**
	 * Test delete removes record and meta.
	 */
	public function test_delete_removes_record_and_meta(): void {
		$transaction = $this->make_transaction();
		$id          = $this->store->create( $transaction );

		$this->store->add_meta( $id, 'test_key', 'test_value' );
		$result = $this->store->delete( $id );

		$this->assertTrue( $result );
		$this->assertNull( $this->store->read( $id ) );
		$this->assertSame( '', $this->store->get_meta( $id, 'test_key' ) );
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	/**
	 * Test query by status.
	 */
	public function test_query_by_status(): void {
		$this->store->create( $this->make_transaction( array( 'status' => 'completed' ) ) );
		$this->store->create( $this->make_transaction( array( 'status' => 'completed' ) ) );
		$this->store->create( $this->make_transaction( array( 'status' => 'pending' ) ) );

		$results = $this->store->query( array( 'status' => 'completed' ) );

		$this->assertCount( 2, $results );
		$this->assertSame( 'completed', $results[0]->status );
	}

	/**
	 * Test query with pagination.
	 */
	public function test_query_with_pagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->store->create( $this->make_transaction() );
		}

		$page1 = $this->store->query( array( 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ) );
		$page2 = $this->store->query( array( 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ) );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 2, $page2 );
		$this->assertGreaterThan( $page1[1]->id, $page2[0]->id );
	}

	/**
	 * Test count.
	 */
	public function test_count(): void {
		$this->store->create( $this->make_transaction( array( 'status' => 'completed' ) ) );
		$this->store->create( $this->make_transaction( array( 'status' => 'completed' ) ) );
		$this->store->create( $this->make_transaction( array( 'status' => 'pending' ) ) );

		$this->assertSame( 3, $this->store->count() );
		$this->assertSame( 2, $this->store->count( array( 'status' => 'completed' ) ) );
	}

	// -------------------------------------------------------------------------
	// Status transitions
	// -------------------------------------------------------------------------

	/**
	 * Test status change fires generic transition hook.
	 */
	public function test_status_change_fires_transition_hook(): void {
		$transaction = $this->make_transaction( array( 'status' => 'pending' ) );
		$this->store->create( $transaction );

		$fired = false;
		add_action(
			'mission_transaction_status_transition',
			function ( $d, $old, $new ) use ( &$fired ) {
				$fired = true;
				$this->assertSame( 'pending', $old );
				$this->assertSame( 'completed', $new );
			},
			10,
			3
		);

		$transaction->status = 'completed';
		$this->store->update( $transaction );

		$this->assertTrue( $fired, 'mission_transaction_status_transition hook did not fire.' );
	}

	/**
	 * Test status change fires specific hook.
	 */
	public function test_status_change_fires_specific_hook(): void {
		$transaction = $this->make_transaction( array( 'status' => 'pending' ) );
		$this->store->create( $transaction );

		$fired = false;
		add_action(
			'mission_transaction_status_pending_to_completed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$transaction->status = 'completed';
		$this->store->update( $transaction );

		$this->assertTrue( $fired, 'mission_transaction_status_pending_to_completed hook did not fire.' );
	}

	// -------------------------------------------------------------------------
	// Aggregates
	// -------------------------------------------------------------------------

	/**
	 * Test completing a transaction increments donor aggregates.
	 */
	public function test_completing_transaction_increments_donor_aggregates(): void {
		$donor_id    = $this->create_donor();
		$transaction = $this->make_transaction(
			array(
				'donor_id'   => $donor_id,
				'status'     => 'pending',
				'amount'     => 5000,
				'tip_amount' => 500,
			)
		);
		$this->store->create( $transaction );

		$transaction->status = 'completed';
		$this->store->update( $transaction );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 500, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );
		$this->assertNotNull( $donor->first_transaction );
		$this->assertNotNull( $donor->last_transaction );
	}

	/**
	 * Test refunding a transaction decrements donor aggregates.
	 */
	public function test_refunding_transaction_decrements_donor_aggregates(): void {
		$donor_id    = $this->create_donor();
		$transaction = $this->make_transaction(
			array(
				'donor_id'   => $donor_id,
				'status'     => 'pending',
				'amount'     => 5000,
				'tip_amount' => 500,
			)
		);
		$this->store->create( $transaction );

		// Complete first.
		$transaction->status = 'completed';
		$this->store->update( $transaction );

		// Then refund.
		$transaction->status = 'refunded';
		$this->store->update( $transaction );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test completing a transaction increments campaign aggregates.
	 */
	public function test_completing_transaction_increments_campaign_aggregates(): void {
		$campaign_id = $this->create_campaign();
		$donor_id    = $this->create_donor();
		$transaction = $this->make_transaction(
			array(
				'donor_id'    => $donor_id,
				'campaign_id' => $campaign_id,
				'status'      => 'pending',
				'amount'      => 7500,
			)
		);
		$this->store->create( $transaction );

		$transaction->status = 'completed';
		$this->store->update( $transaction );

		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 7500, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	// -------------------------------------------------------------------------
	// Meta
	// -------------------------------------------------------------------------

	/**
	 * Test add and get meta.
	 */
	public function test_add_and_get_meta(): void {
		$transaction = $this->make_transaction();
		$id          = $this->store->create( $transaction );

		$meta_id = $this->store->add_meta( $id, 'stripe_fee', '145' );

		$this->assertIsInt( $meta_id );
		$this->assertSame( '145', $this->store->get_meta( $id, 'stripe_fee' ) );
	}

	/**
	 * Test update meta.
	 */
	public function test_update_meta(): void {
		$transaction = $this->make_transaction();
		$id          = $this->store->create( $transaction );

		$this->store->add_meta( $id, 'stripe_fee', '100' );
		$this->store->update_meta( $id, 'stripe_fee', '200' );

		$this->assertSame( '200', $this->store->get_meta( $id, 'stripe_fee' ) );
	}

	/**
	 * Test delete meta.
	 */
	public function test_delete_meta(): void {
		$transaction = $this->make_transaction();
		$id          = $this->store->create( $transaction );

		$this->store->add_meta( $id, 'stripe_fee', '100' );
		$result = $this->store->delete_meta( $id, 'stripe_fee' );

		$this->assertTrue( $result );
		$this->assertSame( '', $this->store->get_meta( $id, 'stripe_fee' ) );
	}
}
