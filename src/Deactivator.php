<?php
/**
 * Fired during plugin deactivation.
 *
 * @package MissionDP
 */

namespace MissionDP;

use MissionDP\Cleanup\CleanupService;

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
		/**
		 * Fires before the plugin is deactivated.
		 */
		do_action( 'mission_plugin_deactivating' );

		self::clear_scheduled_events();
		self::clear_action_scheduler_actions();
		self::clear_transients();

		flush_rewrite_rules();
	}

	/**
	 * Cancel any pending Action Scheduler actions owned by the plugin.
	 *
	 * Prevents queued import ticks from sitting around in the AS table after
	 * the plugin is deactivated.
	 *
	 * @return void
	 */
	private static function clear_action_scheduler_actions(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'missiondp_import_tick' );
		}
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		$scheduled_hooks = [
			'missiondp_daily_cleanup',
			'missiondp_check_recurring_payments',
			'missiondp_campaign_lifecycle',
		];

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
		delete_transient( 'missiondp_activated' );

		CleanupService::clear_plugin_transients();
	}
}
