<?php
/**
 * Tests for the FundraiserDataStore class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database\DataStore;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\FundraiserDataStore;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Transaction;
use WP_UnitTestCase;

/**
 * FundraiserDataStore test class.
 */
class FundraiserDataStoreTest extends WP_UnitTestCase {

	private FundraiserDataStore $store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Drop and recreate to pick up schema changes (dbDelta can't drop columns/keys).
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_fundraisers" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_fundraisermeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->store = new FundraiserDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );

		parent::tear_down();
	}

	/**
	 * update() must not overwrite aggregates recomputed after the model was
	 * loaded (e.g. by a donation webhook landing mid-request).
	 */
	public function test_update_preserves_concurrently_recomputed_aggregates(): void {
		$id = $this->store->create(
			new Fundraiser(
				[
					'campaign_id' => 1,
					'donor_id'    => 1,
					'status'      => Fundraiser::STATUS_ACTIVE,
					'goal'        => 10000,
					'headline'    => 'Original headline',
				]
			)
		);

		// Stale copy loaded before the "webhook" recompute.
		$stale = $this->store->read( $id );

		( new Transaction(
			[
				'donor_id'       => 1,
				'campaign_id'    => 1,
				'fundraiser_id'  => $id,
				'amount'         => 5000,
				'status'         => Transaction::STATUS_COMPLETED,
				'date_completed' => '2026-01-01 10:00:00',
			]
		) )->save();
		$this->store->recompute_aggregates( $id );

		$stale->headline = 'Edited mid-race';
		$this->assertTrue( $this->store->update( $stale ) );

		$fresh = $this->store->read( $id );
		$this->assertSame( 'Edited mid-race', $fresh->headline );
		$this->assertSame( 5000, $fresh->total_raised );
		$this->assertSame( 1, $fresh->transaction_count );
		$this->assertSame( 1, $fresh->donor_count );
	}
}
