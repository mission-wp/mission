<?php
/**
 * Tests for the WebhookDeliveryDataStore class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database\DataStore;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\WebhookDeliveryDataStore;
use MissionDP\Models\WebhookDelivery;
use WP_UnitTestCase;

/**
 * WebhookDeliveryDataStore test class.
 */
class WebhookDeliveryDataStoreTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var WebhookDeliveryDataStore
	 */
	private WebhookDeliveryDataStore $store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_webhook_deliveries" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->store = new WebhookDeliveryDataStore();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_webhook_deliveries" );

		parent::tear_down();
	}

	/**
	 * Build an unsaved delivery with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return WebhookDelivery
	 */
	private function make_delivery( array $overrides = [] ): WebhookDelivery {
		static $counter = 0;
		++$counter;

		return new WebhookDelivery(
			array_merge(
				[
					'webhook_id' => 1,
					'event'      => 'donation.completed',
					'event_id'   => "evt_test{$counter}",
					'url'        => 'https://example.com/hook',
				],
				$overrides
			)
		);
	}

	/**
	 * Test create and read round-trip.
	 */
	public function test_create_and_read(): void {
		$delivery = $this->make_delivery( [ 'request_body' => '{"a":1}' ] );

		$id = $this->store->create( $delivery );

		$this->assertGreaterThan( 0, $id );

		$read = $this->store->read( $id );

		$this->assertInstanceOf( WebhookDelivery::class, $read );
		$this->assertSame( '{"a":1}', $read->request_body );
		$this->assertSame( 'pending', $read->status );
	}

	/**
	 * Test update and delete.
	 */
	public function test_update_and_delete(): void {
		$delivery = $this->make_delivery();
		$this->store->create( $delivery );

		$delivery->status        = 'success';
		$delivery->response_code = 204;

		$this->assertTrue( $this->store->update( $delivery ) );

		$read = $this->store->read( $delivery->id );
		$this->assertSame( 'success', $read->status );
		$this->assertSame( 204, $read->response_code );

		$this->assertTrue( $this->store->delete( $delivery->id ) );
		$this->assertNull( $this->store->read( $delivery->id ) );
	}

	/**
	 * Test query filters by webhook_id, event, event_id, and status.
	 */
	public function test_query_filters(): void {
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 1, 'event' => 'donation.completed', 'status' => 'success' ] ) );
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 1, 'event' => 'donor.created', 'status' => 'failed' ] ) );
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 2, 'event' => 'donation.completed', 'event_id' => 'evt_shared' ] ) );

		$this->assertCount( 2, $this->store->query( [ 'webhook_id' => 1 ] ) );
		$this->assertCount( 2, $this->store->query( [ 'event' => 'donation.completed' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'event_id' => 'evt_shared' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'status' => 'failed' ] ) );
		$this->assertCount(
			1,
			$this->store->query(
				[
					'webhook_id' => 1,
					'status'     => 'success',
				]
			)
		);
		$this->assertCount( 3, $this->store->query() );
	}

	/**
	 * Test query pagination and ordering.
	 */
	public function test_query_pagination_and_order(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->store->create( $this->make_delivery( [ 'attempt' => $i + 1 ] ) );
		}

		$page = $this->store->query(
			[
				'per_page' => 2,
				'page'     => 2,
				'orderby'  => 'attempt',
				'order'    => 'ASC',
			]
		);

		$this->assertCount( 2, $page );
		$this->assertSame( 3, $page[0]->attempt );
		$this->assertSame( 4, $page[1]->attempt );
	}

	/**
	 * Test count matches filters and ignores pagination.
	 */
	public function test_count_matches_filters(): void {
		$this->store->create( $this->make_delivery( [ 'status' => 'success' ] ) );
		$this->store->create( $this->make_delivery( [ 'status' => 'success' ] ) );
		$this->store->create( $this->make_delivery( [ 'status' => 'failed' ] ) );

		$this->assertSame( 3, $this->store->count() );
		$this->assertSame( 2, $this->store->count( [ 'status' => 'success', 'per_page' => 1 ] ) );
	}

	/**
	 * Test prune deletes only rows older than the retention window.
	 */
	public function test_prune_deletes_old_rows_only(): void {
		$old = $this->make_delivery(
			[ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ]
		);
		$this->store->create( $old );

		$recent = $this->make_delivery(
			[ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ]
		);
		$this->store->create( $recent );

		$deleted = $this->store->prune( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->store->read( $old->id ) );
		$this->assertNotNull( $this->store->read( $recent->id ) );
	}

	/**
	 * Test delete_by_webhook removes only that webhook's rows.
	 */
	public function test_delete_by_webhook(): void {
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 5 ] ) );
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 5 ] ) );
		$this->store->create( $this->make_delivery( [ 'webhook_id' => 6 ] ) );

		$deleted = $this->store->delete_by_webhook( 5 );

		$this->assertSame( 2, $deleted );
		$this->assertSame( 0, $this->store->count( [ 'webhook_id' => 5 ] ) );
		$this->assertSame( 1, $this->store->count( [ 'webhook_id' => 6 ] ) );
	}
}
