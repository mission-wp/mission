<?php
/**
 * Tests for the Donor model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use WP_UnitTestCase;

/**
 * Donor model test class.
 */
class DonorTest extends WP_UnitTestCase {

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up donor tables after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Create and save a donor.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		$donor = new Donor( array_merge(
			[
				'email'      => 'donor@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			],
			$overrides
		) );

		$donor->save();

		return $donor;
	}

	// -------------------------------------------------------------------------
	// Construction tests.
	// -------------------------------------------------------------------------

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$donor = new Donor();

		$this->assertNull( $donor->id );
		$this->assertNull( $donor->user_id );
		$this->assertSame( '', $donor->email );
		$this->assertSame( '', $donor->first_name );
		$this->assertSame( '', $donor->last_name );
		$this->assertSame( '', $donor->phone );
		$this->assertSame( 'US', $donor->country );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->total_tip );
		$this->assertSame( 0, $donor->transaction_count );
		$this->assertNull( $donor->first_transaction );
		$this->assertNull( $donor->last_transaction );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$donor = new Donor(
			array(
				'id'         => 10,
				'user_id'    => 3,
				'email'      => 'test@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			)
		);

		$this->assertSame( 10, $donor->id );
		$this->assertSame( 3, $donor->user_id );
		$this->assertSame( 'test@example.com', $donor->email );
		$this->assertSame( 'Jane', $donor->first_name );
		$this->assertSame( 'Doe', $donor->last_name );
	}

	// -------------------------------------------------------------------------
	// find_by_email() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test find_by_email returns donor for existing email.
	 */
	public function test_find_by_email_returns_donor_for_existing_email(): void {
		$donor = $this->create_donor( [
			'email'      => 'find@example.com',
			'first_name' => 'Found',
			'last_name'  => 'Donor',
		] );

		$found = Donor::find_by_email( 'find@example.com' );

		$this->assertInstanceOf( Donor::class, $found );
		$this->assertSame( $donor->id, $found->id );
		$this->assertSame( 'find@example.com', $found->email );
		$this->assertSame( 'Found', $found->first_name );
		$this->assertSame( 'Donor', $found->last_name );
	}

	/**
	 * Test find_by_email returns null for unknown email.
	 */
	public function test_find_by_email_returns_null_for_unknown_email(): void {
		$this->assertNull( Donor::find_by_email( 'nobody@example.com' ) );
	}

	/**
	 * Test find_by_email is case-insensitive.
	 */
	public function test_find_by_email_is_case_insensitive(): void {
		$donor = $this->create_donor( [ 'email' => 'case@example.com' ] );

		$found = Donor::find_by_email( 'CASE@EXAMPLE.COM' );

		$this->assertInstanceOf( Donor::class, $found );
		$this->assertSame( $donor->id, $found->id );
		$this->assertSame( 'case@example.com', $found->email );
	}

	// -------------------------------------------------------------------------
	// full_name() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test full_name returns first and last name.
	 */
	public function test_full_name_returns_first_and_last(): void {
		$donor = new Donor( [
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
		] );

		$this->assertSame( 'Jane Doe', $donor->full_name() );
	}

	/**
	 * Test full_name handles missing names.
	 */
	public function test_full_name_handles_missing_names(): void {
		$first_only = new Donor( [ 'first_name' => 'Jane' ] );
		$this->assertSame( 'Jane', $first_only->full_name() );

		$last_only = new Donor( [ 'last_name' => 'Doe' ] );
		$this->assertSame( 'Doe', $last_only->full_name() );

		$neither = new Donor();
		$this->assertSame( '', $neither->full_name() );
	}

	// -------------------------------------------------------------------------
	// gravatar_hash() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test gravatar_hash returns MD5 of normalized email.
	 */
	public function test_gravatar_hash_returns_md5_of_normalized_email(): void {
		$donor = new Donor( [ 'email' => '  Test@Example.COM  ' ] );

		$this->assertSame( md5( 'test@example.com' ), $donor->gravatar_hash() );

		$empty = new Donor();
		$this->assertSame( '', $empty->gravatar_hash() );
	}

	// -------------------------------------------------------------------------
	// save() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test save() inserts a new donor and returns an ID.
	 */
	public function test_save_inserts_new_donor(): void {
		$donor  = new Donor( [
			'email'      => 'insert@example.com',
			'first_name' => 'New',
			'last_name'  => 'Donor',
		] );
		$result = $donor->save();

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertSame( $result, $donor->id );
	}

	/**
	 * Test save() round-trips through the database.
	 */
	public function test_save_persists_all_fields(): void {
		$donor = $this->create_donor( [
			'email'      => 'persist@example.com',
			'first_name' => 'Persisted',
			'last_name'  => 'Donor',
			'phone'      => '555-1234',
			'city'       => 'Portland',
			'state'      => 'OR',
			'zip'        => '97201',
			'country'    => 'US',
		] );

		$found = Donor::find( $donor->id );

		$this->assertInstanceOf( Donor::class, $found );
		$this->assertSame( 'persist@example.com', $found->email );
		$this->assertSame( 'Persisted', $found->first_name );
		$this->assertSame( 'Donor', $found->last_name );
		$this->assertSame( '555-1234', $found->phone );
		$this->assertSame( 'Portland', $found->city );
		$this->assertSame( 'OR', $found->state );
		$this->assertSame( '97201', $found->zip );
		$this->assertSame( 'US', $found->country );
	}

	/**
	 * Test save() updates an existing donor.
	 */
	public function test_save_updates_existing_donor(): void {
		$donor = $this->create_donor( [ 'first_name' => 'Before' ] );
		$id    = $donor->id;

		$donor->first_name = 'After';
		$result             = $donor->save();

		$this->assertTrue( $result );

		$found = Donor::find( $id );
		$this->assertSame( 'After', $found->first_name );
	}

	// -------------------------------------------------------------------------
	// find() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test find() returns a donor by ID.
	 */
	public function test_find_returns_donor_by_id(): void {
		$donor = $this->create_donor();

		$found = Donor::find( $donor->id );

		$this->assertInstanceOf( Donor::class, $found );
		$this->assertSame( $donor->id, $found->id );
	}

	/**
	 * Test find() returns null for a non-existent ID.
	 */
	public function test_find_returns_null_for_missing_id(): void {
		$this->assertNull( Donor::find( 99999 ) );
	}

	// -------------------------------------------------------------------------
	// delete() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test delete() removes the donor from the database.
	 */
	public function test_delete_removes_donor(): void {
		$donor = $this->create_donor();
		$id    = $donor->id;

		$result = $donor->delete();

		$this->assertTrue( $result );
		$this->assertNull( Donor::find( $id ) );
	}

	/**
	 * Test delete() also removes associated meta.
	 */
	public function test_delete_removes_donor_meta(): void {
		global $wpdb;

		$donor = $this->create_donor();
		$id    = $donor->id;
		$donor->update_meta( 'test_key', 'test_value' );

		$donor->delete();

		// Verify meta rows were cleaned up for the deleted donor.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}missiondp_donormeta WHERE missiondp_donor_id = %d",
			$id
		) );
		$this->assertSame( 0, $count );
	}

	// -------------------------------------------------------------------------
	// fresh() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test fresh() reloads the donor from the database.
	 */
	public function test_fresh_reloads_from_database(): void {
		$donor = $this->create_donor( [ 'first_name' => 'Original' ] );

		// Mutate in memory without saving.
		$donor->first_name = 'Mutated';

		$refreshed = $donor->fresh();

		$this->assertInstanceOf( Donor::class, $refreshed );
		$this->assertSame( 'Original', $refreshed->first_name );
	}

	// -------------------------------------------------------------------------
	// query() and count() tests.
	// -------------------------------------------------------------------------

	/**
	 * Test query() returns all donors.
	 */
	public function test_query_returns_all_donors(): void {
		$this->create_donor( [ 'email' => 'one@example.com' ] );
		$this->create_donor( [ 'email' => 'two@example.com' ] );
		$this->create_donor( [ 'email' => 'three@example.com' ] );

		$donors = Donor::query();

		$this->assertCount( 3, $donors );
		$this->assertContainsOnlyInstancesOf( Donor::class, $donors );
	}

	/**
	 * Test count() returns the correct count.
	 */
	public function test_count_returns_total(): void {
		$this->create_donor( [ 'email' => 'a@example.com' ] );
		$this->create_donor( [ 'email' => 'b@example.com' ] );

		$this->assertSame( 2, Donor::count() );
	}

	/**
	 * Test query() with search filter matches email.
	 */
	public function test_query_search_matches_email(): void {
		$this->create_donor( [ 'email' => 'alice@example.com' ] );
		$this->create_donor( [ 'email' => 'bob@example.com' ] );

		$results = Donor::query( [ 'search' => 'alice' ] );

		$this->assertCount( 1, $results );
		$this->assertSame( 'alice@example.com', $results[0]->email );
	}

	/**
	 * Test query() with search filter matches first name.
	 */
	public function test_query_search_matches_name(): void {
		$this->create_donor( [ 'email' => 'a@example.com', 'first_name' => 'Alice' ] );
		$this->create_donor( [ 'email' => 'b@example.com', 'first_name' => 'Bob' ] );

		$results = Donor::query( [ 'search' => 'Bob' ] );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Bob', $results[0]->first_name );
	}

	/**
	 * Test count() respects search filter.
	 */
	public function test_count_with_search_filter(): void {
		$this->create_donor( [ 'email' => 'match@example.com' ] );
		$this->create_donor( [ 'email' => 'other@example.com' ] );

		$this->assertSame( 1, Donor::count( [ 'search' => 'match' ] ) );
	}

	/**
	 * Test query() pagination with per_page and page.
	 */
	public function test_query_pagination(): void {
		$this->create_donor( [ 'email' => 'p1@example.com' ] );
		$this->create_donor( [ 'email' => 'p2@example.com' ] );
		$this->create_donor( [ 'email' => 'p3@example.com' ] );

		$page1 = Donor::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$page2 = Donor::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 1, $page2 );

		// Pages should not overlap.
		$page1_ids = array_map( fn( $d ) => $d->id, $page1 );
		$page2_ids = array_map( fn( $d ) => $d->id, $page2 );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	/**
	 * Test query() ordering.
	 */
	public function test_query_ordering(): void {
		$this->create_donor( [ 'email' => 'z@example.com', 'total_donated' => 100 ] );
		$this->create_donor( [ 'email' => 'a@example.com', 'total_donated' => 500 ] );
		$this->create_donor( [ 'email' => 'm@example.com', 'total_donated' => 300 ] );

		$results = Donor::query( [ 'orderby' => 'total_donated', 'order' => 'DESC' ] );

		$this->assertSame( 500, $results[0]->total_donated );
		$this->assertSame( 300, $results[1]->total_donated );
		$this->assertSame( 100, $results[2]->total_donated );
	}

	// -------------------------------------------------------------------------
	// Hook tests.
	// -------------------------------------------------------------------------

	/**
	 * Test mission_donor_created action fires on insert.
	 */
	public function test_missiondp_donor_created_action_fires(): void {
		$fired = false;

		add_action( 'missiondp_donor_created', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->create_donor();

		$this->assertTrue( $fired );
	}

	/**
	 * Test mission_donor_updated action fires on update.
	 */
	public function test_missiondp_donor_updated_action_fires(): void {
		$donor = $this->create_donor();
		$fired = false;

		add_action( 'missiondp_donor_updated', function () use ( &$fired ) {
			$fired = true;
		} );

		$donor->first_name = 'Updated';
		$donor->save();

		$this->assertTrue( $fired );
	}
}
