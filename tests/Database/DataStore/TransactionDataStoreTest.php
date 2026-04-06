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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

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
	 * Test is_test flag persists through create and read.
	 */
	public function test_is_test_flag_persists(): void {
		$test_txn = $this->make_transaction( array( 'is_test' => true ) );
		$test_id  = $this->store->create( $test_txn );

		$live_txn = $this->make_transaction( array( 'is_test' => false ) );
		$live_id  = $this->store->create( $live_txn );

		$this->assertTrue( $this->store->read( $test_id )->is_test );
		$this->assertFalse( $this->store->read( $live_id )->is_test );
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
	 * Test query by type filter.
	 */
	public function test_query_by_type(): void {
		$this->store->create( $this->make_transaction( array( 'type' => 'one_time' ) ) );
		$this->store->create( $this->make_transaction( array( 'type' => 'monthly' ) ) );
		$this->store->create( $this->make_transaction( array( 'type' => 'monthly' ) ) );

		$results = $this->store->query( array( 'type' => 'monthly' ) );

		$this->assertCount( 2, $results );
		$this->assertSame( 'monthly', $results[0]->type );
	}

	/**
	 * Test query by type__not excludes the given type.
	 */
	public function test_query_by_type_not(): void {
		$this->store->create( $this->make_transaction( array( 'type' => 'one_time' ) ) );
		$this->store->create( $this->make_transaction( array( 'type' => 'monthly' ) ) );
		$this->store->create( $this->make_transaction( array( 'type' => 'quarterly' ) ) );

		$results = $this->store->query( array( 'type__not' => 'one_time' ) );

		$this->assertCount( 2, $results );
		foreach ( $results as $txn ) {
			$this->assertNotSame( 'one_time', $txn->type );
		}
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
	 * Test creating a pending transaction does not increment aggregates.
	 */
	public function test_create_as_pending_does_not_increment_aggregates(): void {
		$donor_id    = $this->create_donor();
		$campaign_id = $this->create_campaign();
		$transaction = $this->make_transaction(
			array(
				'donor_id'    => $donor_id,
				'campaign_id' => $campaign_id,
				'status'      => 'pending',
				'amount'      => 5000,
				'tip_amount'  => 300,
			)
		);
		$this->store->create( $transaction );

		$donor    = $this->donor_store->read( $donor_id );
		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->transaction_count );
	}

	/**
	 * Test creating a transaction as completed increments donor aggregates immediately.
	 */
	public function test_create_as_completed_increments_donor_aggregates(): void {
		$donor_id    = $this->create_donor();
		$transaction = $this->make_transaction(
			array(
				'donor_id'   => $donor_id,
				'status'     => 'completed',
				'amount'     => 3000,
				'tip_amount' => 200,
			)
		);
		$this->store->create( $transaction );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 3000, $donor->total_donated );
		$this->assertSame( 200, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );
	}

	/**
	 * Test creating a transaction as completed increments campaign aggregates immediately.
	 */
	public function test_create_as_completed_increments_campaign_aggregates(): void {
		$donor_id    = $this->create_donor();
		$campaign_id = $this->create_campaign();
		$transaction = $this->make_transaction(
			array(
				'donor_id'    => $donor_id,
				'campaign_id' => $campaign_id,
				'status'      => 'completed',
				'amount'      => 4000,
			)
		);
		$this->store->create( $transaction );

		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 4000, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

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
	// Aggregate edge cases
	// -------------------------------------------------------------------------

	/**
	 * Test multiple completed transactions accumulate donor totals correctly.
	 */
	public function test_multiple_completed_transactions_accumulate_donor_totals(): void {
		$donor_id = $this->create_donor();

		$amounts = [ [ 3000, 100 ], [ 5000, 200 ], [ 2000, 300 ] ];
		foreach ( $amounts as [ $amount, $tip ] ) {
			$this->store->create(
				$this->make_transaction(
					[
						'donor_id'   => $donor_id,
						'status'     => 'completed',
						'amount'     => $amount,
						'tip_amount' => $tip,
					]
				)
			);
		}

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 10000, $donor->total_donated );
		$this->assertSame( 600, $donor->total_tip );
		$this->assertSame( 3, $donor->transaction_count );
	}

	/**
	 * Test multiple completed transactions accumulate campaign totals correctly.
	 */
	public function test_multiple_completed_transactions_accumulate_campaign_totals(): void {
		$donor_id    = $this->create_donor();
		$campaign_id = $this->create_campaign();

		foreach ( [ 4000, 6000, 1000 ] as $amount ) {
			$this->store->create(
				$this->make_transaction(
					[
						'donor_id'    => $donor_id,
						'campaign_id' => $campaign_id,
						'status'      => 'completed',
						'amount'      => $amount,
					]
				)
			);
		}

		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 11000, $campaign->total_raised );
		$this->assertSame( 3, $campaign->transaction_count );
	}

	/**
	 * Test that test and live transactions are tracked in separate aggregate fields.
	 */
	public function test_test_and_live_transactions_tracked_separately(): void {
		$donor_id    = $this->create_donor();
		$campaign_id = $this->create_campaign();

		// 2 live transactions.
		$live_data = [ [ 3000, 100 ], [ 2000, 200 ] ];
		foreach ( $live_data as [ $amount, $tip ] ) {
			$this->store->create(
				$this->make_transaction(
					[
						'donor_id'    => $donor_id,
						'campaign_id' => $campaign_id,
						'status'      => 'completed',
						'amount'      => $amount,
						'tip_amount'  => $tip,
						'is_test'     => false,
					]
				)
			);
		}

		// 2 test transactions.
		$test_data = [ [ 1000, 50 ], [ 4000, 150 ] ];
		foreach ( $test_data as [ $amount, $tip ] ) {
			$this->store->create(
				$this->make_transaction(
					[
						'donor_id'    => $donor_id,
						'campaign_id' => $campaign_id,
						'status'      => 'completed',
						'amount'      => $amount,
						'tip_amount'  => $tip,
						'is_test'     => true,
					]
				)
			);
		}

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 300, $donor->total_tip );
		$this->assertSame( 2, $donor->transaction_count );
		$this->assertSame( 5000, $donor->test_total_donated );
		$this->assertSame( 200, $donor->test_total_tip );
		$this->assertSame( 2, $donor->test_transaction_count );

		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 5000, $campaign->total_raised );
		$this->assertSame( 2, $campaign->transaction_count );
		$this->assertSame( 5000, $campaign->test_total_raised );
		$this->assertSame( 2, $campaign->test_transaction_count );
	}

	/**
	 * Test that first_transaction is set only on the first completion and not overwritten.
	 */
	public function test_first_transaction_not_overwritten_by_subsequent_completions(): void {
		$donor_id = $this->create_donor();

		$this->store->create(
			$this->make_transaction(
				[
					'donor_id'       => $donor_id,
					'status'         => 'completed',
					'amount'         => 1000,
					'date_completed' => '2025-01-01 00:00:00',
				]
			)
		);

		$donor             = $this->donor_store->read( $donor_id );
		$first_transaction = $donor->first_transaction;
		$this->assertNotNull( $first_transaction );

		$this->store->create(
			$this->make_transaction(
				[
					'donor_id'       => $donor_id,
					'status'         => 'completed',
					'amount'         => 2000,
					'date_completed' => '2025-01-01 00:00:01',
				]
			)
		);

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( $first_transaction, $donor->first_transaction );
	}

	/**
	 * Test that last_transaction is updated on each new completion.
	 */
	public function test_last_transaction_updated_on_each_completion(): void {
		$donor_id = $this->create_donor();

		$this->store->create(
			$this->make_transaction(
				[
					'donor_id'       => $donor_id,
					'status'         => 'completed',
					'amount'         => 1000,
					'date_completed' => '2025-01-01 00:00:00',
				]
			)
		);

		$donor            = $this->donor_store->read( $donor_id );
		$last_transaction = $donor->last_transaction;

		$this->store->create(
			$this->make_transaction(
				[
					'donor_id'       => $donor_id,
					'status'         => 'completed',
					'amount'         => 2000,
					'date_completed' => '2025-01-01 00:00:01',
				]
			)
		);

		$donor = $this->donor_store->read( $donor_id );
		$this->assertGreaterThan( $last_transaction, $donor->last_transaction );
	}

	/**
	 * Test that refund never produces negative totals.
	 */
	public function test_refund_never_produces_negative_totals(): void {
		global $wpdb;

		$donor_id    = $this->create_donor();
		$campaign_id = $this->create_campaign();

		$transaction = $this->make_transaction(
			[
				'donor_id'    => $donor_id,
				'campaign_id' => $campaign_id,
				'status'      => 'completed',
				'amount'      => 1000,
				'tip_amount'  => 100,
			]
		);
		$this->store->create( $transaction );

		// Simulate a data inconsistency: totals are lower than the transaction amount.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"{$wpdb->prefix}mission_donors",
			[ 'total_donated' => 500, 'total_tip' => 50 ],
			[ 'id' => $donor_id ]
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"{$wpdb->prefix}mission_campaigns",
			[ 'total_raised' => 500 ],
			[ 'id' => $campaign_id ]
		);

		// Refund — tries to subtract 1000 from 500.
		$transaction->status = 'refunded';
		$this->store->update( $transaction );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );

		$campaign = $this->campaign_store->read( $campaign_id );
		$this->assertSame( 0, $campaign->total_raised );
	}

	/**
	 * Test that a transaction with null campaign_id doesn't error on aggregate update.
	 */
	public function test_null_campaign_id_does_not_error_on_aggregate_update(): void {
		global $wpdb;

		$donor_id = $this->create_donor();

		$transaction = $this->make_transaction(
			[
				'donor_id'    => $donor_id,
				'campaign_id' => null,
				'status'      => 'completed',
				'amount'      => 2500,
				'tip_amount'  => 150,
			]
		);
		$this->store->create( $transaction );

		$this->assertEmpty( $wpdb->last_error, 'Expected no DB error for null campaign_id on create.' );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 2500, $donor->total_donated );
		$this->assertSame( 150, $donor->total_tip );
		$this->assertSame( 1, $donor->transaction_count );

		// Refund — should also not error.
		$transaction->status = 'refunded';
		$this->store->update( $transaction );

		$this->assertEmpty( $wpdb->last_error, 'Expected no DB error for null campaign_id on refund.' );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test that completing and refunding transactions correctly accumulates total_tip.
	 */
	public function test_completing_transaction_with_tip_accumulates_total_tip(): void {
		$donor_id = $this->create_donor();

		$txn1 = $this->make_transaction(
			[
				'donor_id'   => $donor_id,
				'status'     => 'completed',
				'amount'     => 1000,
				'tip_amount' => 750,
			]
		);
		$this->store->create( $txn1 );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 750, $donor->total_tip );

		$txn2 = $this->make_transaction(
			[
				'donor_id'   => $donor_id,
				'status'     => 'completed',
				'amount'     => 2000,
				'tip_amount' => 250,
			]
		);
		$this->store->create( $txn2 );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 1000, $donor->total_tip );

		// Refund second transaction — tip should decrease back to 750.
		$txn2->status = 'refunded';
		$this->store->update( $txn2 );

		$donor = $this->donor_store->read( $donor_id );
		$this->assertSame( 750, $donor->total_tip );
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
