<?php
/**
 * Tests for the WebhookDelivery model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\WebhookDelivery;
use WP_UnitTestCase;

/**
 * WebhookDelivery model test class.
 */
class WebhookDeliveryTest extends WP_UnitTestCase {

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
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_webhook_deliveries" );

		parent::tear_down();
	}

	/**
	 * Test constructor defaults.
	 */
	public function test_constructor_defaults(): void {
		$delivery = new WebhookDelivery();

		$this->assertNull( $delivery->id );
		$this->assertSame( 0, $delivery->webhook_id );
		$this->assertSame( '', $delivery->event );
		$this->assertSame( 1, $delivery->attempt );
		$this->assertSame( 'pending', $delivery->status );
		$this->assertNull( $delivery->request_headers );
		$this->assertNull( $delivery->request_body );
		$this->assertNull( $delivery->response_code );
		$this->assertNull( $delivery->response_body );
		$this->assertNull( $delivery->duration_ms );
		$this->assertNull( $delivery->error_message );
	}

	/**
	 * Test save and find round-trip with nullable fields populated.
	 */
	public function test_save_and_find_round_trip(): void {
		$delivery = new WebhookDelivery(
			[
				'webhook_id'    => 7,
				'event'         => 'donation.completed',
				'event_id'      => 'evt_abc123',
				'url'           => 'https://example.com/hook',
				'request_body'  => '{"event":"donation.completed"}',
				'response_code' => 200,
				'response_body' => 'ok',
				'duration_ms'   => 152,
				'attempt'       => 2,
				'status'        => 'success',
			]
		);

		$id = $delivery->save();

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$found = WebhookDelivery::find( $id );

		$this->assertInstanceOf( WebhookDelivery::class, $found );
		$this->assertSame( 7, $found->webhook_id );
		$this->assertSame( 'donation.completed', $found->event );
		$this->assertSame( 'evt_abc123', $found->event_id );
		$this->assertSame( '{"event":"donation.completed"}', $found->request_body );
		$this->assertSame( 200, $found->response_code );
		$this->assertSame( 152, $found->duration_ms );
		$this->assertSame( 2, $found->attempt );
		$this->assertSame( 'success', $found->status );
	}

	/**
	 * Test updating an existing delivery persists changes.
	 */
	public function test_update_persists_changes(): void {
		$delivery = new WebhookDelivery(
			[
				'webhook_id' => 1,
				'event'      => 'ping',
				'event_id'   => 'evt_ping1',
				'url'        => 'https://example.com/hook',
			]
		);
		$delivery->save();

		$delivery->status        = 'failed';
		$delivery->error_message = 'HTTP 500: Internal Server Error';
		$result                  = $delivery->save();

		$this->assertTrue( $result );

		$fresh = $delivery->fresh();
		$this->assertSame( 'failed', $fresh->status );
		$this->assertSame( 'HTTP 500: Internal Server Error', $fresh->error_message );
	}
}
