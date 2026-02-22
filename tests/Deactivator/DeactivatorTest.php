<?php
/**
 * Tests for the Deactivator class.
 *
 * @package Mission
 */

namespace Mission\Tests\Deactivator;

use Mission\Deactivator;
use WP_UnitTestCase;

/**
 * Deactivator test class.
 */
class DeactivatorTest extends WP_UnitTestCase {

	/**
	 * Test that deactivation clears the daily cleanup cron hook.
	 */
	public function test_clears_daily_cleanup_cron(): void {
		wp_schedule_event( time(), 'daily', 'mission_daily_cleanup' );
		$this->assertNotFalse( wp_next_scheduled( 'mission_daily_cleanup' ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'mission_daily_cleanup' ) );
	}

	/**
	 * Test that deactivation clears the recurring payments cron hook.
	 */
	public function test_clears_recurring_payments_cron(): void {
		wp_schedule_event( time(), 'daily', 'mission_check_recurring_payments' );
		$this->assertNotFalse( wp_next_scheduled( 'mission_check_recurring_payments' ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'mission_check_recurring_payments' ) );
	}

	/**
	 * Test that deactivation clears mission transients.
	 */
	public function test_clears_mission_transients(): void {
		set_transient( 'mission_activated', true );
		set_transient( 'mission_stats_cache', array( 'total' => 100 ) );

		Deactivator::deactivate();

		// The activated transient is explicitly deleted via delete_transient().
		$this->assertFalse( get_transient( 'mission_activated' ) );

		// Other mission_ transients are deleted via direct SQL query.
		// Flush the object cache so get_transient() hits the database.
		wp_cache_flush();
		$this->assertFalse( get_transient( 'mission_stats_cache' ) );
	}

	/**
	 * Test that deactivation does NOT remove options (non-destructive).
	 */
	public function test_does_not_remove_options(): void {
		update_option( 'mission_version', '1.0.0' );
		update_option( 'mission_settings', array( 'currency' => 'USD' ) );

		Deactivator::deactivate();

		$this->assertSame( '1.0.0', get_option( 'mission_version' ) );
		$this->assertNotFalse( get_option( 'mission_settings' ) );
	}
}
