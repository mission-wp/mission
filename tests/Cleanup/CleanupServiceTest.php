<?php
/**
 * Tests for the CleanupService test-data deletes.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Cleanup;

use MissionDP\Cleanup\CleanupService;
use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\DonorDataStore;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Models\Tribute;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * CleanupService test class.
 *
 * Covers the cascade deletes behind Tools > Cleanup: test rows (and their
 * meta/tributes) must be removed while live rows survive untouched.
 */
class CleanupServiceTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 */
	private CleanupService $cleanup;

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up the service.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->cleanup = new CleanupService( new SettingsService() );
	}

	/**
	 * Clean up plugin tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( [ 'transactionmeta', 'transactions', 'tributes', 'transaction_history', 'subscriptionmeta', 'subscriptions', 'donormeta', 'donors', 'notes' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_{$table}" );
		}
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create a donor with a saved meta row.
	 *
	 * @param string $tag Unique tag for the email.
	 * @return Donor
	 */
	private function create_donor( string $tag ): Donor {
		$donor = new Donor(
			[
				'email'      => "cleanup.{$tag}@example.com",
				'first_name' => 'Cleanup',
				'last_name'  => $tag,
			]
		);
		$donor->save();
		$donor->update_meta( 'probe_key', $tag );

		return $donor;
	}

	/**
	 * Create a completed transaction with meta and a tribute.
	 *
	 * @param Donor $donor   Donor.
	 * @param bool  $is_test Test mode flag.
	 * @return Transaction
	 */
	private function create_transaction( Donor $donor, bool $is_test ): Transaction {
		$transaction = new Transaction(
			[
				'status'         => 'completed',
				'donor_id'       => $donor->id,
				'amount'         => 1000,
				'total_amount'   => 1000,
				'is_test'        => $is_test,
				'date_completed' => current_time( 'mysql', true ),
			]
		);
		$transaction->save();
		$transaction->update_meta( 'donor_comment', 'A comment' );

		$tribute = new Tribute(
			[
				'transaction_id' => $transaction->id,
				'tribute_type'   => 'in_honor',
				'honoree_name'   => 'Honoree',
			]
		);
		$tribute->save();

		return $transaction;
	}

	/**
	 * Count meta rows for an object in one of the plugin meta tables.
	 *
	 * @param string $table  Meta table suffix (e.g. 'transactionmeta').
	 * @param string $column Object ID column name.
	 * @param int    $id     Object ID.
	 * @return int
	 */
	private function count_meta_rows( string $table, string $column, int $id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . 'missiondp_' . $table, $column, $id )
		);
	}

	/**
	 * Test delete_test_transactions removes test rows and their meta/tributes, keeps live ones.
	 */
	public function test_delete_test_transactions_cascades_and_keeps_live_rows(): void {
		$donor    = $this->create_donor( 'txn' );
		$live_txn = $this->create_transaction( $donor, false );
		$test_txn = $this->create_transaction( $donor, true );

		$this->cleanup->delete_test_transactions();

		$this->assertNotNull( Transaction::find( $live_txn->id ) );
		$this->assertNull( Transaction::find( $test_txn->id ) );

		$this->assertSame( 1, $this->count_meta_rows( 'transactionmeta', 'missiondp_transaction_id', $live_txn->id ) );
		$this->assertSame( 0, $this->count_meta_rows( 'transactionmeta', 'missiondp_transaction_id', $test_txn->id ) );

		$this->assertNotNull( Tribute::find_by_transaction_id( $live_txn->id ) );
		$this->assertNull( Tribute::find_by_transaction_id( $test_txn->id ) );
	}

	/**
	 * Test delete_test_donors removes orphaned donors and their meta, keeps donors with live history.
	 */
	public function test_delete_test_donors_cascades_and_keeps_live_donors(): void {
		$live_donor   = $this->create_donor( 'live' );
		$orphan_donor = $this->create_donor( 'orphan' );
		$this->create_transaction( $live_donor, false );

		$store = new DonorDataStore();
		$store->recompute_aggregates( $live_donor->id );
		$store->recompute_aggregates( $orphan_donor->id );

		$this->cleanup->delete_test_donors();

		$this->assertNotNull( Donor::find( $live_donor->id ) );
		$this->assertNull( Donor::find( $orphan_donor->id ) );

		$this->assertSame( 1, $this->count_meta_rows( 'donormeta', 'missiondp_donor_id', $live_donor->id ) );
		$this->assertSame( 0, $this->count_meta_rows( 'donormeta', 'missiondp_donor_id', $orphan_donor->id ) );
	}

	/**
	 * Test delete_test_subscriptions removes test subscriptions and their meta, keeps live ones.
	 */
	public function test_delete_test_subscriptions_cascades_and_keeps_live_rows(): void {
		$donor = $this->create_donor( 'sub' );

		$make_subscription = function ( bool $is_test ) use ( $donor ): Subscription {
			$subscription = new Subscription(
				[
					'status'       => 'active',
					'donor_id'     => $donor->id,
					'amount'       => 500,
					'total_amount' => 500,
					'frequency'    => 'monthly',
					'is_test'      => $is_test,
				]
			);
			$subscription->save();
			$subscription->update_meta( 'probe_key', $is_test ? 'test' : 'live' );

			return $subscription;
		};

		$live_sub = $make_subscription( false );
		$test_sub = $make_subscription( true );

		$this->cleanup->delete_test_subscriptions();

		$this->assertNotNull( Subscription::find( $live_sub->id ) );
		$this->assertNull( Subscription::find( $test_sub->id ) );

		$this->assertSame( 1, $this->count_meta_rows( 'subscriptionmeta', 'missiondp_subscription_id', $live_sub->id ) );
		$this->assertSame( 0, $this->count_meta_rows( 'subscriptionmeta', 'missiondp_subscription_id', $test_sub->id ) );
	}
}
