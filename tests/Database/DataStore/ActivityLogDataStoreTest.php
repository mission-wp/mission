<?php
/**
 * Tests for the ActivityLogDataStore class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database\DataStore;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\ActivityLog;
use WP_UnitTestCase;

/**
 * ActivityLogDataStore test class.
 */
class ActivityLogDataStoreTest extends WP_UnitTestCase {

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );

		parent::tear_down();
	}

	/**
	 * Test that query() without per_page caps results at the default of 100
	 * instead of loading the whole table.
	 */
	public function test_query_defaults_to_capped_per_page(): void {
		for ( $i = 0; $i < 105; $i++ ) {
			$entry = new ActivityLog(
				[
					'event'       => "test_event_{$i}",
					'object_type' => 'test',
				]
			);
			$entry->save();
		}

		$this->assertCount( 105, ActivityLog::query( [ 'per_page' => 200 ] ) );
		$this->assertCount( 100, ActivityLog::query() );
	}
}
