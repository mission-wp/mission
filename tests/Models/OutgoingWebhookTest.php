<?php
/**
 * Tests for the OutgoingWebhook model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\OutgoingWebhook;
use WP_UnitTestCase;

/**
 * OutgoingWebhook model test class.
 */
class OutgoingWebhookTest extends WP_UnitTestCase {

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
	 * Test constructor defaults.
	 */
	public function test_constructor_defaults(): void {
		$webhook = new OutgoingWebhook();

		$this->assertNull( $webhook->id );
		$this->assertSame( '', $webhook->name );
		$this->assertSame( '', $webhook->url );
		$this->assertSame( [], $webhook->events );
		$this->assertSame( 'active', $webhook->status );
		$this->assertSame( 'healthy', $webhook->health );
		$this->assertSame( 0, $webhook->failure_count );
		$this->assertNull( $webhook->failing_since );
		$this->assertNull( $webhook->last_delivery_at );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $webhook->secret );
	}

	/**
	 * Test generate_secret returns unique 64-char hex strings.
	 */
	public function test_generate_secret_is_unique_hex(): void {
		$first  = OutgoingWebhook::generate_secret();
		$second = OutgoingWebhook::generate_secret();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $second );
		$this->assertNotSame( $first, $second );
	}

	/**
	 * Test subscribes_to with explicit event subscriptions.
	 */
	public function test_subscribes_to_explicit_events(): void {
		$webhook = new OutgoingWebhook( [ 'events' => [ 'donation.completed', 'donor.created' ] ] );

		$this->assertTrue( $webhook->subscribes_to( 'donation.completed' ) );
		$this->assertTrue( $webhook->subscribes_to( 'donor.created' ) );
		$this->assertFalse( $webhook->subscribes_to( 'subscription.created' ) );
	}

	/**
	 * Test subscribes_to with the wildcard subscription.
	 */
	public function test_subscribes_to_wildcard(): void {
		$webhook = new OutgoingWebhook( [ 'events' => [ '*' ] ] );

		$this->assertTrue( $webhook->subscribes_to( 'donation.completed' ) );
		$this->assertTrue( $webhook->subscribes_to( 'campaign.goal_reached' ) );
	}

	/**
	 * Test save and find round-trip, including the events JSON column.
	 */
	public function test_save_and_find_round_trip(): void {
		$webhook = new OutgoingWebhook(
			[
				'name'   => 'CRM sync',
				'url'    => 'https://example.com/hooks/mission',
				'events' => [ 'donation.completed', 'donation.refunded' ],
			]
		);

		$id = $webhook->save();

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$found = OutgoingWebhook::find( $id );

		$this->assertInstanceOf( OutgoingWebhook::class, $found );
		$this->assertSame( 'CRM sync', $found->name );
		$this->assertSame( 'https://example.com/hooks/mission', $found->url );
		$this->assertSame( [ 'donation.completed', 'donation.refunded' ], $found->events );
		$this->assertSame( $webhook->secret, $found->secret );
	}

	/**
	 * Test updating an existing webhook persists changes.
	 */
	public function test_update_persists_changes(): void {
		$webhook = new OutgoingWebhook(
			[
				'name'   => 'Original',
				'url'    => 'https://example.com/a',
				'events' => [ '*' ],
			]
		);
		$webhook->save();

		$webhook->name   = 'Renamed';
		$webhook->status = 'paused';
		$result          = $webhook->save();

		$this->assertTrue( $result );

		$fresh = $webhook->fresh();
		$this->assertSame( 'Renamed', $fresh->name );
		$this->assertSame( 'paused', $fresh->status );
	}

	/**
	 * Test delete removes the record.
	 */
	public function test_delete_removes_record(): void {
		$webhook = new OutgoingWebhook(
			[
				'name'   => 'Doomed',
				'url'    => 'https://example.com/x',
				'events' => [ '*' ],
			]
		);
		$webhook->save();
		$id = $webhook->id;

		$this->assertTrue( $webhook->delete() );
		$this->assertNull( OutgoingWebhook::find( $id ) );
	}
}
