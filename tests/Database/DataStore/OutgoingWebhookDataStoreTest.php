<?php
/**
 * Tests for the OutgoingWebhookDataStore class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database\DataStore;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\OutgoingWebhookDataStore;
use MissionDP\Models\OutgoingWebhook;
use MissionDP\Models\WebhookDelivery;
use WP_UnitTestCase;

/**
 * OutgoingWebhookDataStore test class.
 */
class OutgoingWebhookDataStoreTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var OutgoingWebhookDataStore
	 */
	private OutgoingWebhookDataStore $store;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_outgoing_webhooks" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_webhook_deliveries" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->store = new OutgoingWebhookDataStore();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_outgoing_webhooks" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_webhook_deliveries" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Build an unsaved webhook with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return OutgoingWebhook
	 */
	private function make_webhook( array $overrides = [] ): OutgoingWebhook {
		static $counter = 0;
		++$counter;

		return new OutgoingWebhook(
			array_merge(
				[
					'name'   => "Webhook {$counter}",
					'url'    => "https://example.com/hook-{$counter}",
					'events' => [ 'donation.completed' ],
				],
				$overrides
			)
		);
	}

	/**
	 * Test create and read round-trip.
	 */
	public function test_create_and_read(): void {
		$webhook = $this->make_webhook( [ 'name' => 'CRUD hook' ] );

		$id = $this->store->create( $webhook );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $id, $webhook->id );

		$read = $this->store->read( $id );

		$this->assertInstanceOf( OutgoingWebhook::class, $read );
		$this->assertSame( 'CRUD hook', $read->name );
		$this->assertNotSame( '0000-00-00 00:00:00', $read->date_created );
	}

	/**
	 * Test read returns null for a missing ID.
	 */
	public function test_read_returns_null_for_missing_id(): void {
		$this->assertNull( $this->store->read( 999999 ) );
	}

	/**
	 * Test update persists changes and bumps date_modified.
	 */
	public function test_update_persists_changes(): void {
		$webhook = $this->make_webhook();
		$this->store->create( $webhook );

		$webhook->health        = 'failing';
		$webhook->failure_count = 16;

		$this->assertTrue( $this->store->update( $webhook ) );

		$read = $this->store->read( $webhook->id );
		$this->assertSame( 'failing', $read->health );
		$this->assertSame( 16, $read->failure_count );
	}

	/**
	 * Test delete removes the webhook and cascades to its deliveries.
	 */
	public function test_delete_cascades_to_deliveries(): void {
		$webhook = $this->make_webhook();
		$this->store->create( $webhook );

		$other = $this->make_webhook();
		$this->store->create( $other );

		foreach ( [ $webhook->id, $webhook->id, $other->id ] as $webhook_id ) {
			( new WebhookDelivery(
				[
					'webhook_id' => $webhook_id,
					'event'      => 'donation.completed',
					'event_id'   => 'evt_' . $webhook_id . wp_rand(),
					'url'        => 'https://example.com/hook',
				]
			) )->save();
		}

		$this->assertTrue( $this->store->delete( $webhook->id ) );

		$this->assertNull( $this->store->read( $webhook->id ) );
		$this->assertCount( 0, WebhookDelivery::query( [ 'webhook_id' => $webhook->id ] ) );
		$this->assertCount( 1, WebhookDelivery::query( [ 'webhook_id' => $other->id ] ) );
	}

	/**
	 * Test query filters by status and health.
	 */
	public function test_query_filters_by_status_and_health(): void {
		$this->store->create( $this->make_webhook( [ 'status' => 'active' ] ) );
		$this->store->create( $this->make_webhook( [ 'status' => 'paused' ] ) );
		$this->store->create( $this->make_webhook( [ 'status' => 'active', 'health' => 'failing' ] ) );

		$this->assertCount( 2, $this->store->query( [ 'status' => 'active' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'status' => 'paused' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'health' => 'failing' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'status' => 'active', 'health' => 'failing' ] ) );
		$this->assertCount( 3, $this->store->query() );
	}

	/**
	 * Test query search matches name and URL.
	 */
	public function test_query_search_matches_name_and_url(): void {
		$this->store->create( $this->make_webhook( [ 'name' => 'Zapier integration' ] ) );
		$this->store->create( $this->make_webhook( [ 'url' => 'https://zapier.example.com/x' ] ) );
		$this->store->create( $this->make_webhook( [ 'name' => 'CRM sync' ] ) );

		$this->assertCount( 2, $this->store->query( [ 'search' => 'zapier' ] ) );
		$this->assertCount( 1, $this->store->query( [ 'search' => 'CRM' ] ) );
		$this->assertCount( 0, $this->store->query( [ 'search' => 'nomatch' ] ) );
	}

	/**
	 * Test query orderby whitelist falls back to date_created for unknown columns.
	 */
	public function test_query_orderby_whitelist(): void {
		$a = $this->make_webhook( [ 'name' => 'Bravo' ] );
		$this->store->create( $a );
		$b = $this->make_webhook( [ 'name' => 'Alpha' ] );
		$this->store->create( $b );

		$by_name = $this->store->query(
			[
				'orderby' => 'name',
				'order'   => 'ASC',
			]
		);
		$this->assertSame( 'Alpha', $by_name[0]->name );

		// Unknown column falls back to date_created without erroring.
		$fallback = $this->store->query(
			[
				'orderby' => 'secret; DROP TABLE x',
				'order'   => 'ASC',
			]
		);
		$this->assertCount( 2, $fallback );
		$this->assertSame( $a->id, $fallback[0]->id );
	}

	/**
	 * Test query pagination.
	 */
	public function test_query_pagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->store->create( $this->make_webhook() );
		}

		$page_one = $this->store->query(
			[
				'per_page' => 2,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'ASC',
			]
		);
		$page_three = $this->store->query(
			[
				'per_page' => 2,
				'page'     => 3,
				'orderby'  => 'id',
				'order'    => 'ASC',
			]
		);

		$this->assertCount( 2, $page_one );
		$this->assertCount( 1, $page_three );
		$this->assertLessThan( $page_three[0]->id, $page_one[1]->id );
	}

	/**
	 * Test count ignores pagination args.
	 */
	public function test_count_matches_filters(): void {
		$this->store->create( $this->make_webhook( [ 'status' => 'active' ] ) );
		$this->store->create( $this->make_webhook( [ 'status' => 'active' ] ) );
		$this->store->create( $this->make_webhook( [ 'status' => 'paused' ] ) );

		$this->assertSame( 3, $this->store->count() );
		$this->assertSame(
			2,
			$this->store->count(
				[
					'status'   => 'active',
					'per_page' => 1,
					'page'     => 5,
				]
			)
		);
	}

	/**
	 * Test CRUD operations fire the lifecycle actions.
	 */
	public function test_crud_fires_lifecycle_actions(): void {
		$fired = [];

		$on_created = function () use ( &$fired ) {
			$fired[] = 'created';
		};
		$on_updated = function () use ( &$fired ) {
			$fired[] = 'updated';
		};
		$on_deleted = function () use ( &$fired ) {
			$fired[] = 'deleted';
		};

		add_action( 'mission_outgoing_webhook_created', $on_created );
		add_action( 'mission_outgoing_webhook_updated', $on_updated );
		add_action( 'mission_outgoing_webhook_deleted', $on_deleted );

		$webhook = $this->make_webhook();
		$this->store->create( $webhook );
		$this->store->update( $webhook );
		$this->store->delete( $webhook->id );

		remove_action( 'mission_outgoing_webhook_created', $on_created );
		remove_action( 'mission_outgoing_webhook_updated', $on_updated );
		remove_action( 'mission_outgoing_webhook_deleted', $on_deleted );

		$this->assertSame( [ 'created', 'updated', 'deleted' ], $fired );
	}
}
