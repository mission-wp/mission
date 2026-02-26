<?php
/**
 * Tests for the DonorDataStore class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database\DataStore;

use Mission\Database\DatabaseModule;
use Mission\Database\DataStore\DonorDataStore;
use Mission\Models\Donor;
use WP_UnitTestCase;

/**
 * DonorDataStore test class.
 */
class DonorDataStoreTest extends WP_UnitTestCase {

	private DonorDataStore $store;

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
		$this->store = new DonorDataStore();
	}

	/**
	 * Clean up tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_donors" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_donor_meta" );

		parent::tear_down();
	}

	/**
	 * Build a Donor model with sensible defaults.
	 */
	private function make_donor( array $overrides = array() ): Donor {
		static $counter = 0;
		$counter++;

		return new Donor(
			array_merge(
				array(
					'email'      => "donor{$counter}@example.com",
					'first_name' => 'Test',
					'last_name'  => 'Donor',
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
		$donor = $this->make_donor( array( 'email' => 'crud@example.com' ) );
		$id    = $this->store->create( $donor );

		$this->assertGreaterThan( 0, $id );

		$read = $this->store->read( $id );
		$this->assertInstanceOf( Donor::class, $read );
		$this->assertSame( 'crud@example.com', $read->email );
	}

	/**
	 * Test update.
	 */
	public function test_update(): void {
		$donor = $this->make_donor();
		$this->store->create( $donor );

		$donor->first_name = 'Updated';
		$this->store->update( $donor );

		$read = $this->store->read( $donor->id );
		$this->assertSame( 'Updated', $read->first_name );
	}

	/**
	 * Test delete.
	 */
	public function test_delete(): void {
		$donor = $this->make_donor();
		$id    = $this->store->create( $donor );

		$this->assertTrue( $this->store->delete( $id ) );
		$this->assertNull( $this->store->read( $id ) );
	}

	// -------------------------------------------------------------------------
	// find_by_email
	// -------------------------------------------------------------------------

	/**
	 * Test find by email.
	 */
	public function test_find_by_email(): void {
		$donor = $this->make_donor( array( 'email' => 'find@example.com' ) );
		$this->store->create( $donor );

		$found = $this->store->find_by_email( 'find@example.com' );
		$this->assertInstanceOf( Donor::class, $found );
		$this->assertSame( 'find@example.com', $found->email );
	}

	/**
	 * Test find by email not found returns null.
	 */
	public function test_find_by_email_not_found_returns_null(): void {
		$this->assertNull( $this->store->find_by_email( 'nope@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	/**
	 * Test query with search.
	 */
	public function test_query_with_search(): void {
		$this->store->create( $this->make_donor( array( 'email' => 'alice@example.com', 'first_name' => 'Alice' ) ) );
		$this->store->create( $this->make_donor( array( 'email' => 'bob@example.com', 'first_name' => 'Bob' ) ) );

		$results = $this->store->query( array( 'search' => 'alice' ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Alice', $results[0]->first_name );
	}

	// -------------------------------------------------------------------------
	// Meta
	// -------------------------------------------------------------------------

	/**
	 * Test meta CRUD.
	 */
	public function test_meta_crud(): void {
		$donor = $this->make_donor();
		$id    = $this->store->create( $donor );

		// Add.
		$meta_id = $this->store->add_meta( $id, 'source', 'website' );
		$this->assertIsInt( $meta_id );

		// Get.
		$this->assertSame( 'website', $this->store->get_meta( $id, 'source' ) );

		// Update.
		$this->store->update_meta( $id, 'source', 'referral' );
		$this->assertSame( 'referral', $this->store->get_meta( $id, 'source' ) );

		// Delete.
		$this->assertTrue( $this->store->delete_meta( $id, 'source' ) );
		$this->assertSame( '', $this->store->get_meta( $id, 'source' ) );
	}
}
