<?php
/**
 * Daily cleanup for migration phase records.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Database\DataStore\MigrationPhaseDataStore;
use MissionDP\Models\MigrationPhase;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the shared daily cleanup event. Prunes finished runs past the
 * retention window (which also retires the rollback option for those runs)
 * and breaks orphaned locks. Touched-entity transients expire on their own.
 */
class MigrationCleanup {

	private const ROW_TTL_DAYS = 30;

	/**
	 * Register on the shared daily cleanup hook.
	 */
	public function register(): void {
		add_action( 'missiondp_daily_cleanup', [ $this, 'run' ] );
	}

	/**
	 * Daily cleanup pass.
	 */
	public function run(): void {
		/**
		 * Filter how long finished migration records (and with them the ability
		 * to roll the run back) are retained, in days.
		 *
		 * @param int $days Retention window.
		 */
		$days   = max( 1, (int) apply_filters( 'mission_migration_job_retention_days', self::ROW_TTL_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		/** @var MigrationPhaseDataStore $store */
		$store = MigrationPhase::store();
		$store->prune_terminal( $cutoff );

		// Break a lock whose job no longer has active phase rows (e.g. crash).
		if ( get_option( MigrationService::LOCK_OPTION ) && null === MigrationPhase::find_active_job_id() ) {
			delete_option( MigrationService::LOCK_OPTION );
		}
	}
}
