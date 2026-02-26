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
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mission_campaign_meta" );

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
					'title'       => "Campaign {$counter}",
					'slug'        => "campaign-{$counter}",
					'status'      => 'active',
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
		$campaign = $this->make_campaign( array( 'title' => 'Whale Fund' ) );
		$id       = $this->store->create( $campaign );

		$this->assertGreaterThan( 0, $id );

		$read = $this->store->read( $id );
		$this->assertInstanceOf( Campaign::class, $read );
		$this->assertSame( 'Whale Fund', $read->title );
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

		$this->store->add_meta( $id, 'test_key', 'test_value' );
		$this->assertTrue( $this->store->delete( $id ) );
		$this->assertNull( $this->store->read( $id ) );
		$this->assertSame( '', $this->store->get_meta( $id, 'test_key' ) );
	}

	// -------------------------------------------------------------------------
	// find_by_slug
	// -------------------------------------------------------------------------

	/**
	 * Test find by slug.
	 */
	public function test_find_by_slug(): void {
		$campaign = $this->make_campaign( array( 'slug' => 'unique-slug' ) );
		$this->store->create( $campaign );

		$found = $this->store->find_by_slug( 'unique-slug' );
		$this->assertInstanceOf( Campaign::class, $found );
		$this->assertSame( 'unique-slug', $found->slug );
	}

	// -------------------------------------------------------------------------
	// Status transitions
	// -------------------------------------------------------------------------

	/**
	 * Test status change fires hooks.
	 */
	public function test_status_change_fires_hooks(): void {
		$campaign = $this->make_campaign( array( 'status' => 'draft' ) );
		$this->store->create( $campaign );

		$fired = false;
		add_action(
			'mission_campaign_status_draft_to_active',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$campaign->status = 'active';
		$this->store->update( $campaign );

		$this->assertTrue( $fired, 'mission_campaign_status_draft_to_active hook did not fire.' );
	}

	// -------------------------------------------------------------------------
	// Meta
	// -------------------------------------------------------------------------

	/**
	 * Test meta CRUD.
	 */
	public function test_meta_crud(): void {
		$campaign = $this->make_campaign();
		$id       = $this->store->create( $campaign );

		// Add.
		$meta_id = $this->store->add_meta( $id, 'color', '#2FA36B' );
		$this->assertIsInt( $meta_id );

		// Get.
		$this->assertSame( '#2FA36B', $this->store->get_meta( $id, 'color' ) );

		// Update.
		$this->store->update_meta( $id, 'color', '#000000' );
		$this->assertSame( '#000000', $this->store->get_meta( $id, 'color' ) );

		// Delete.
		$this->assertTrue( $this->store->delete_meta( $id, 'color' ) );
		$this->assertSame( '', $this->store->get_meta( $id, 'color' ) );
	}
}
