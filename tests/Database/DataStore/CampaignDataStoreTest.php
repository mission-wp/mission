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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

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
					'title'       => "Campaign {$counter}",
					'description' => "Description for campaign {$counter}",
					'goal_amount' => 100000,
				),
				$overrides
			)
		);
	}

	/**
	 * Create a campaign with a real WP post (needed for query/count which JOIN wp_posts).
	 */
	private function make_campaign_with_post( array $overrides = array() ): Campaign {
		// Remove save_post hook to avoid auto-creating a campaign row.
		remove_all_actions( 'save_post_mission_campaign' );

		$title = $overrides['title'] ?? 'Test Campaign';

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'mission_campaign',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$overrides['post_id'] = $post_id;

		return new Campaign(
			array_merge(
				array(
					'title'       => $title,
					'description' => 'Test description',
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
	 * Test that title and description are stored and read correctly.
	 */
	public function test_title_and_description_stored(): void {
		$campaign = $this->make_campaign(
			array(
				'title'       => 'My Campaign',
				'description' => 'A great cause',
			)
		);
		$id = $this->store->create( $campaign );

		$read = $this->store->read( $id );
		$this->assertSame( 'My Campaign', $read->title );
		$this->assertSame( 'A great cause', $read->description );
	}

	/**
	 * Test update.
	 */
	public function test_update(): void {
		$campaign = $this->make_campaign();
		$this->store->create( $campaign );

		$campaign->goal_amount = 200000;
		$campaign->title       = 'Updated Title';
		$this->store->update( $campaign );

		$read = $this->store->read( $campaign->id );
		$this->assertSame( 200000, $read->goal_amount );
		$this->assertSame( 'Updated Title', $read->title );
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
		$campaign_a = $this->make_campaign_with_post();
		$campaign_b = $this->make_campaign_with_post();
		$this->store->create( $campaign_a );
		$this->store->create( $campaign_b );

		$results = $this->store->query( array( 'post_id' => $campaign_a->post_id ) );
		$this->assertCount( 1, $results );
		$this->assertSame( $campaign_a->post_id, $results[0]->post_id );
	}

	/**
	 * Test count.
	 */
	public function test_count(): void {
		$this->store->create( $this->make_campaign_with_post() );
		$this->store->create( $this->make_campaign_with_post() );

		$this->assertSame( 2, $this->store->count() );
	}

	/**
	 * Test search by title.
	 */
	public function test_search_by_title(): void {
		$this->store->create( $this->make_campaign_with_post( array( 'title' => 'General Fund' ) ) );
		$this->store->create( $this->make_campaign_with_post( array( 'title' => 'Emergency Relief' ) ) );

		$results = $this->store->query( array( 'search' => 'General' ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 'General Fund', $results[0]->title );
	}

	/**
	 * Test orderby title.
	 */
	public function test_orderby_title(): void {
		$this->store->create( $this->make_campaign_with_post( array( 'title' => 'Zebra Campaign' ) ) );
		$this->store->create( $this->make_campaign_with_post( array( 'title' => 'Alpha Campaign' ) ) );

		$results = $this->store->query( array( 'orderby' => 'title', 'order' => 'ASC' ) );
		$this->assertSame( 'Alpha Campaign', $results[0]->title );
		$this->assertSame( 'Zebra Campaign', $results[1]->title );
	}

	// -------------------------------------------------------------------------
	// show_in_listings
	// -------------------------------------------------------------------------

	/**
	 * Test show_in_listings defaults to true.
	 */
	public function test_show_in_listings_defaults_to_true(): void {
		$campaign = $this->make_campaign();
		$id       = $this->store->create( $campaign );

		$read = $this->store->read( $id );
		$this->assertTrue( $read->show_in_listings );
	}

	/**
	 * Test show_in_listings persists as false.
	 */
	public function test_show_in_listings_persists_false(): void {
		$campaign = $this->make_campaign( [ 'show_in_listings' => false ] );
		$id       = $this->store->create( $campaign );

		$read = $this->store->read( $id );
		$this->assertFalse( $read->show_in_listings );
	}
}
