<?php
/**
 * Fired during plugin deactivation.
 *
 * @package Mission
 */

namespace Mission;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivator class.
 */
class Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * Does NOT delete data — that only happens in uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::clear_scheduled_events();
		self::clear_transients();

		flush_rewrite_rules();
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		$scheduled_hooks = array(
			'mission_daily_cleanup',
			'mission_check_recurring_payments',
		);

		foreach ( $scheduled_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Clear plugin transients.
	 *
	 * @return void
	 */
	private static function clear_transients(): void {
		delete_transient( 'mission_activated' );

		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_mission_%'
			OR option_name LIKE '_transient_timeout_mission_%'"
		);
	}
}
