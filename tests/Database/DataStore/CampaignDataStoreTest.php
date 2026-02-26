<?php
/**
 * Tests for the CampaignDataStore class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database\DataStore;

use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\CampaignDataStore;
use Mission\Models\Campaign;
use WP_UnitTestCase;

/**
 * CampaignDataStore test class.
 */
class CampaignDataStoreTest extends WP_UnitTestCase {

	private CampaignDataStore $store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Drop and recreate to pick up schema changes (dbDelta can't drop columns/keys).
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaign_meta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->store = new CampaignDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_campaigns" );

		parent::tear_down();
	}

	/**
	 * Build a Campaign model with sensible defaults.
	 */
	private function make_campaign( array $overrides = array() ): Campaign {
		static $counter = 0;
		$counter++;

		return new Campaign(
			array_merge(
				array(
					'post_id'     => $counter,
					'goal_amount' => 100000,
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
		$campaign = $this->make_campaign( array( 'goal_amount' => 50000 ) );
		$id       = $this->store->create( $campaign );

		$this->assertGreaterThan( 0, $id );

		$read = $this->store->read( $id );
		$this->assertInstanceOf( Campaign::class, $read );
		$this->assertSame( 50000, $read->goal_amount );
	}

	/**
	 * Test update.
	 */
	public function test_update(): void {
		$campaign = $this->make_campaign();
		$this->store->create( $campaign );

		$campaign->goal_amount = 200000;
		$this->store->update( $campaign );

		$read = $this->store->read( $campaign->id );
		$this->assertSame( 200000, $read->goal_amount );
	}

	/**
	 * Test delete.
	 */
	public function test_delete(): void {
		$campaign = $this->make_campaign();
		$id       = $this->store->create( $campaign );

		$this->assertTrue( $this->store->delete( $id ) );
		$this->assertNull( $this->store->read( $id ) );
	}

	// -------------------------------------------------------------------------
	// find_by_post_id
	// -------------------------------------------------------------------------

	/**
	 * Test find by post ID.
	 */
	public function test_find_by_post_id(): void {
		$campaign = $this->make_campaign( array( 'post_id' => 999 ) );
		$this->store->create( $campaign );

		$found = $this->store->find_by_post_id( 999 );
		$this->assertInstanceOf( Campaign::class, $found );
		$this->assertSame( 999, $found->post_id );
	}

	/**
	 * Test find by post ID returns null for nonexistent.
	 */
	public function test_find_by_post_id_returns_null_for_nonexistent(): void {
		$this->assertNull( $this->store->find_by_post_id( 99999 ) );
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	/**
	 * Test query by post_id.
	 */
	public function test_query_by_post_id(): void {
		$this->store->create( $this->make_campaign( array( 'post_id' => 10 ) ) );
		$this->store->create( $this->make_campaign( array( 'post_id' => 20 ) ) );

		$results = $this->store->query( array( 'post_id' => 10 ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 10, $results[0]->post_id );
	}

	/**
	 * Test count.
	 */
	public function test_count(): void {
		$this->store->create( $this->make_campaign( array( 'post_id' => 30 ) ) );
		$this->store->create( $this->make_campaign( array( 'post_id' => 31 ) ) );

		$this->assertSame( 2, $this->store->count() );
	}
}
