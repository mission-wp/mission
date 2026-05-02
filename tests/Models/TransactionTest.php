<?php
/**
 * Tests for the Transaction model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * Transaction model test class.
 */
class TransactionTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a donor.
	 *
	 * @return Donor
	 */
	private function create_donor(): Donor {
		$donor = new Donor( [
			'email'      => 'txn-test@example.com',
			'first_name' => 'Txn',
			'last_name'  => 'Tester',
		] );
		$donor->save();

		return $donor;
	}

	/**
	 * Create and save a transaction.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => 'completed',
				'donor_id'       => 1,
				'source_post_id' => 1,
				'amount'         => 5000,
				'total_amount'   => 5000,
			],
			$overrides
		) );
		$transaction->save();

		return $transaction;
	}

	// -------------------------------------------------------------------------
	// Construction tests.
	// -------------------------------------------------------------------------

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$transaction = new Transaction();

		$this->assertNull( $transaction->id );
		$this->assertSame( 'pending', $transaction->status );
		$this->assertSame( 'one_time', $transaction->type );
		$this->assertSame( 0, $transaction->donor_id );
		$this->assertSame( 0, $transaction->amount );
		$this->assertSame( 0, $transaction->total_amount );
		$this->assertSame( 'usd', $transaction->currency );
		$this->assertFalse( $transaction->is_anonymous );
		$this->assertFalse( $transaction->is_test );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$data = array(
			'id'                      => 42,
			'status'                  => 'completed',
			'type'                    => 'recurring',
			'donor_id'                => 5,
			'subscription_id'         => 10,
			'parent_id'               => 3,
			'source_post_id'                 => 1,
			'campaign_id'             => 7,
			'amount'                  => 5000,
			'fee_amount'              => 150,
			'tip_amount'              => 500,
			'total_amount'            => 5650,
			'currency'                => 'eur',
			'payment_gateway'         => 'stripe',
			'gateway_transaction_id'  => 'txn_123',
			'gateway_subscription_id' => 'sub_456',
			'is_anonymous'            => true,
			'is_test'                 => true,
			'donor_ip'                => '127.0.0.1',
			'date_created'            => '2025-01-01 00:00:00',
			'date_completed'          => '2025-01-01 00:01:00',
			'date_refunded'           => '2025-01-02 00:00:00',
			'date_modified'           => '2025-01-02 00:00:00',
		);

		$transaction = new Transaction( $data );

		$this->assertSame( 42, $transaction->id );
		$this->assertSame( 'completed', $transaction->status );
		$this->assertSame( 'recurring', $transaction->type );
		$this->assertSame( 5, $transaction->donor_id );
		$this->assertSame( 10, $transaction->subscription_id );
		$this->assertSame( 3, $transaction->parent_id );
		$this->assertSame( 7, $transaction->campaign_id );
		$this->assertSame( 5000, $transaction->amount );
		$this->assertSame( 5650, $transaction->total_amount );
		$this->assertSame( 'stripe', $transaction->payment_gateway );
		$this->assertTrue( $transaction->is_anonymous );
		$this->assertTrue( $transaction->is_test );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$transaction = new Transaction( array( 'donor_id' => 1, 'form_id' => 1 ) );

		$this->assertNull( $transaction->id );
		$this->assertNull( $transaction->subscription_id );
		$this->assertNull( $transaction->parent_id );
		$this->assertNull( $transaction->campaign_id );
		$this->assertNull( $transaction->gateway_transaction_id );
		$this->assertNull( $transaction->gateway_subscription_id );
		$this->assertNull( $transaction->date_completed );
		$this->assertNull( $transaction->date_refunded );
	}

	// -------------------------------------------------------------------------
	// save() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() inserts a new transaction and returns an ID.
	 */
	public function test_save_inserts_new_transaction(): void {
		$donor       = $this->create_donor();
		$transaction = new Transaction( [
			'status'         => 'completed',
			'donor_id'       => $donor->id,
			'source_post_id' => 1,
			'amount'         => 2500,
			'total_amount'   => 2500,
		] );

		$result = $transaction->save();

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertSame( $result, $transaction->id );
	}

	/**
	 * Test save() round-trips through the database.
	 */
	public function test_save_persists_all_fields(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id'               => $donor->id,
			'status'                 => 'completed',
			'type'                   => 'recurring',
			'amount'                 => 10000,
			'fee_amount'             => 300,
			'tip_amount'             => 500,
			'total_amount'           => 10500,
			'currency'               => 'eur',
			'payment_gateway'        => 'stripe',
			'gateway_transaction_id' => 'pi_abc123',
			'is_anonymous'           => true,
			'is_test'                => true,
			'donor_ip'               => '192.168.1.1',
		] );

		$found = Transaction::find( $transaction->id );

		$this->assertInstanceOf( Transaction::class, $found );
		$this->assertSame( 'completed', $found->status );
		$this->assertSame( 'recurring', $found->type );
		$this->assertSame( $donor->id, $found->donor_id );
		$this->assertSame( 10000, $found->amount );
		$this->assertSame( 300, $found->fee_amount );
		$this->assertSame( 500, $found->tip_amount );
		$this->assertSame( 10500, $found->total_amount );
		$this->assertSame( 'eur', $found->currency );
		$this->assertSame( 'stripe', $found->payment_gateway );
		$this->assertSame( 'pi_abc123', $found->gateway_transaction_id );
		$this->assertTrue( $found->is_anonymous );
		$this->assertTrue( $found->is_test );
		$this->assertSame( '192.168.1.1', $found->donor_ip );
	}

	/**
	 * Test save() updates an existing transaction.
	 */
	public function test_save_updates_existing_transaction(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id' => $donor->id,
			'status'   => 'pending',
		] );
		$id = $transaction->id;

		$transaction->status = 'completed';
		$result              = $transaction->save();

		$this->assertTrue( $result );

		$found = Transaction::find( $id );
		$this->assertSame( 'completed', $found->status );
	}

	// -------------------------------------------------------------------------
	// find() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test find() returns a transaction by ID.
	 */
	public function test_find_returns_transaction_by_id(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );

		$found = Transaction::find( $transaction->id );

		$this->assertInstanceOf( Transaction::class, $found );
		$this->assertSame( $transaction->id, $found->id );
	}

	/**
	 * Test find() returns null for a non-existent ID.
	 */
	public function test_find_returns_null_for_missing_id(): void {
		$this->assertNull( Transaction::find( 99999 ) );
	}

	// -------------------------------------------------------------------------
	// delete() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test delete() removes the transaction from the database.
	 */
	public function test_delete_removes_transaction(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [ 'donor_id' => $donor->id ] );
		$id          = $transaction->id;

		$result = $transaction->delete();

		$this->assertTrue( $result );
		$this->assertNull( Transaction::find( $id ) );
	}

	// -------------------------------------------------------------------------
	// fresh() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test fresh() reloads the transaction from the database.
	 */
	public function test_fresh_reloads_from_database(): void {
		$donor       = $this->create_donor();
		$transaction = $this->create_transaction( [
			'donor_id' => $donor->id,
			'status'   => 'completed',
		] );

		$transaction->status = 'refunded';

		$refreshed = $transaction->fresh();

		$this->assertInstanceOf( Transaction::class, $refreshed );
		$this->assertSame( 'completed', $refreshed->status );
	}

	// -------------------------------------------------------------------------
	// query() and count() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test query() returns all transactions.
	 */
	public function test_query_returns_all_transactions(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id ] );

		$transactions = Transaction::query();

		$this->assertCount( 3, $transactions );
		$this->assertContainsOnlyInstancesOf( Transaction::class, $transactions );
	}

	/**
	 * Test count() returns the correct total.
	 */
	public function test_count_returns_total(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->assertSame( 2, Transaction::count() );
	}

	/**
	 * Test query() filters by status.
	 */
	public function test_query_filters_by_status(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id, 'status' => 'completed' ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'status' => 'completed' ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'status' => 'pending' ] );

		$results = Transaction::query( [ 'status' => 'completed' ] );

		$this->assertCount( 2, $results );
		foreach ( $results as $txn ) {
			$this->assertSame( 'completed', $txn->status );
		}
	}

	/**
	 * Test query() filters by donor_id.
	 */
	public function test_query_filters_by_donor_id(): void {
		$donor1 = $this->create_donor();
		$donor2 = new Donor( [
			'email'      => 'other@example.com',
			'first_name' => 'Other',
		] );
		$donor2->save();

		$this->create_transaction( [ 'donor_id' => $donor1->id ] );
		$this->create_transaction( [ 'donor_id' => $donor1->id ] );
		$this->create_transaction( [ 'donor_id' => $donor2->id ] );

		$results = Transaction::query( [ 'donor_id' => $donor1->id ] );

		$this->assertCount( 2, $results );
		foreach ( $results as $txn ) {
			$this->assertSame( $donor1->id, $txn->donor_id );
		}
	}

	/**
	 * Test count() respects filters.
	 */
	public function test_count_with_status_filter(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id, 'status' => 'completed' ] );
		$this->create_transaction( [ 'donor_id' => $donor->id, 'status' => 'pending' ] );

		$this->assertSame( 1, Transaction::count( [ 'status' => 'completed' ] ) );
	}

	/**
	 * Test query() pagination.
	 */
	public function test_query_pagination(): void {
		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor->id ] );

		$page1 = Transaction::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$page2 = Transaction::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );

		$page1_ids = array_map( fn( $t ) => $t->id, $page1 );
		$page2_ids = array_map( fn( $t ) => $t->id, $page2 );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	// -------------------------------------------------------------------------
	// Hook tests.
	// -------------------------------------------------------------------------

	/**
	 * Test mission_transaction_created action fires on insert.
	 */
	public function test_missiondp_transaction_created_action_fires(): void {
		$fired = false;

		add_action( 'missiondp_transaction_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$donor = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor->id ] );

		$this->assertTrue( $fired );
	}
}
