<?php
/**
 * Cleanup service — handles cache clearing, test data removal, and resets.
 *
 * @package MissionDP
 */

namespace MissionDP\Cleanup;

use MissionDP\Database\Schema;
use MissionDP\Plugin;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names use a safe prefix variable.

/**
 * Central cleanup coordinator.
 */
class CleanupService {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {}

	// ------------------------------------------------------------------
	// Stats
	// ------------------------------------------------------------------

	/**
	 * Get counts and sizes for the cleanup UI.
	 *
	 * @return array<string, int>
	 */
	public function get_stats(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'missiondp_';

		// Activity log count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$activity_log_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}activity_log" );

		// Log files.
		$log_dir         = $this->get_log_dir();
		$log_files_size  = 0;
		$log_files_count = 0;

		if ( $log_dir && is_dir( $log_dir ) ) {
			$files = glob( $log_dir . '*.log' );
			if ( $files ) {
				$log_files_count = count( $files );
				foreach ( $files as $file ) {
					$log_files_size += filesize( $file );
				}
			}
		}

		// Test data counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$test_transaction_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$prefix}transactions WHERE is_test = 1"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$test_subscription_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$prefix}subscriptions WHERE is_test = 1"
		);

		// Donors that have only test transactions (no live ones).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$test_donor_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$prefix}donors WHERE transaction_count = 0 AND test_transaction_count > 0"
		);

		return [
			'activity_log_count'      => $activity_log_count,
			'log_files_size'          => $log_files_size,
			'log_files_count'         => $log_files_count,
			'test_transaction_count'  => $test_transaction_count,
			'test_donor_count'        => $test_donor_count,
			'test_subscription_count' => $test_subscription_count,
		];
	}

	// ------------------------------------------------------------------
	// Cache clearing (safe)
	// ------------------------------------------------------------------

	/**
	 * Clear dashboard-related transients.
	 *
	 * @return array{cleared: true}
	 */
	public function clear_dashboard_cache(): array {
		$this->delete_transients_like( 'missiondp_dashboard_%' );
		$this->delete_transients_like( 'missiondp_report_%' );
		$this->delete_transients_like( 'missiondp_stats_%' );

		wp_cache_flush();

		return [ 'cleared' => true ];
	}

	/**
	 * Clear email template transients.
	 *
	 * @return array{cleared: true}
	 */
	public function clear_email_template_cache(): array {
		$this->delete_transients_like( 'missiondp_email_%' );

		return [ 'cleared' => true ];
	}

	/**
	 * Clear Stripe sync transients.
	 *
	 * @return array{cleared: true}
	 */
	public function clear_stripe_sync_cache(): array {
		$this->delete_transients_like( 'missiondp_stripe_%' );

		return [ 'cleared' => true ];
	}

	// ------------------------------------------------------------------
	// Logs & History
	// ------------------------------------------------------------------

	/**
	 * Clear all activity log entries.
	 *
	 * @return array{deleted: int}
	 */
	public function clear_activity_log(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'missiondp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->log_activity( 'activity_log_cleared', 'settings', 0, [ 'entries_deleted' => $count ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		return [ 'deleted' => $count ];
	}

	/**
	 * Delete all log files from the logs directory.
	 *
	 * @return array{deleted_files: int, freed_bytes: int}
	 */
	public function delete_log_files(): array {
		$log_dir     = $this->get_log_dir();
		$deleted     = 0;
		$freed_bytes = 0;

		if ( $log_dir && is_dir( $log_dir ) ) {
			$files = glob( $log_dir . '*.log' );
			if ( $files ) {
				foreach ( $files as $file ) {
					$size = filesize( $file );
					if ( wp_delete_file( $file ) || ! file_exists( $file ) ) {
						++$deleted;
						$freed_bytes += $size;
					}
				}
			}
		}

		$this->log_activity(
			'log_files_deleted',
			'settings',
			0,
			[
				'files_deleted' => $deleted,
				'bytes_freed'   => $freed_bytes,
			]
		);

		return [
			'deleted_files' => $deleted,
			'freed_bytes'   => $freed_bytes,
		];
	}

	// ------------------------------------------------------------------
	// Test data
	// ------------------------------------------------------------------

	/**
	 * Delete all test transactions and cascade to related data.
	 *
	 * @return array{deleted: int}
	 */
	public function delete_test_transactions(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'missiondp_';

		// Get IDs first for cascade cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$prefix}transactions WHERE is_test = 1" );

		$count = count( $ids );

		if ( $count > 0 ) {
			$id_list = implode( ',', array_map( 'intval', $ids ) );

			// Cascade: meta, history, notes, tributes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}transactionmeta WHERE transaction_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}transaction_history WHERE transaction_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}notes WHERE object_type = 'transaction' AND object_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}tributes WHERE transaction_id IN ({$id_list})" );

			// Delete the transactions.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}transactions WHERE is_test = 1" );

			// Reset test aggregate columns on donors and campaigns.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"UPDATE {$prefix}donors SET
					test_total_donated = 0,
					test_total_tip = 0,
					test_transaction_count = 0,
					test_first_transaction = NULL,
					test_last_transaction = NULL"
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"UPDATE {$prefix}campaigns SET
					test_total_raised = 0,
					test_donor_count = 0,
					test_transaction_count = 0"
			);
		}

		$this->log_activity( 'test_transactions_deleted', 'settings', 0, [ 'count' => $count ] );

		return [ 'deleted' => $count ];
	}

	/**
	 * Delete donors that only had test transactions (no live ones).
	 *
	 * Should be called after delete_test_transactions() so aggregates are reset.
	 *
	 * @return array{deleted: int}
	 */
	public function delete_test_donors(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'missiondp_';

		// Donors with zero live and zero test transactions remaining.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT id FROM {$prefix}donors WHERE transaction_count = 0 AND test_transaction_count = 0"
		);

		$count = count( $ids );

		if ( $count > 0 ) {
			$id_list = implode( ',', array_map( 'intval', $ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}donormeta WHERE donor_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}notes WHERE object_type = 'donor' AND object_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}donors WHERE id IN ({$id_list})" );
		}

		$this->log_activity( 'test_donors_deleted', 'settings', 0, [ 'count' => $count ] );

		return [ 'deleted' => $count ];
	}

	/**
	 * Delete all test subscriptions.
	 *
	 * @return array{deleted: int}
	 */
	public function delete_test_subscriptions(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'missiondp_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$prefix}subscriptions WHERE is_test = 1" );

		$count = count( $ids );

		if ( $count > 0 ) {
			$id_list = implode( ',', array_map( 'intval', $ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}subscriptionmeta WHERE subscription_id IN ({$id_list})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$prefix}subscriptions WHERE is_test = 1" );
		}

		$this->log_activity( 'test_subscriptions_deleted', 'settings', 0, [ 'count' => $count ] );

		return [ 'deleted' => $count ];
	}

	/**
	 * Delete all test data (transactions, donors, subscriptions).
	 *
	 * @return array{transactions: int, donors: int, subscriptions: int}
	 */
	public function delete_all_test_data(): array {
		$transactions  = $this->delete_test_transactions();
		$donors        = $this->delete_test_donors();
		$subscriptions = $this->delete_test_subscriptions();

		return [
			'transactions'  => $transactions['deleted'],
			'donors'        => $donors['deleted'],
			'subscriptions' => $subscriptions['deleted'],
		];
	}

	// ------------------------------------------------------------------
	// Danger zone
	// ------------------------------------------------------------------

	/**
	 * Reset onboarding so the wizard shows again.
	 *
	 * @return array{reset: true}
	 */
	public function reset_onboarding(): array {
		$this->settings->update( [ 'onboarding_completed' => false ] );

		$this->log_activity( 'onboarding_reset', 'settings', 0 );

		return [ 'reset' => true ];
	}

	/**
	 * Reset all settings to defaults.
	 *
	 * @return array{reset: true}
	 */
	public function reset_all_settings(): array {
		$this->log_activity( 'settings_reset', 'settings', 0 );

		update_option( 'missiondp_settings', $this->settings->get_defaults() );
		delete_option( 'missiondp_default_campaign' );

		return [ 'reset' => true ];
	}

	/**
	 * Delete all Mission data (nuclear reset).
	 *
	 * Truncates all custom tables, removes campaign posts, clears options
	 * and transients. The plugin remains active and functional.
	 *
	 * @return array{deleted: true}
	 */
	public function delete_all_data(): array {
		global $wpdb;

		// Truncate all custom tables.
		$schema = new Schema();
		foreach ( $schema->get_table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		// Delete campaign CPT posts and meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE meta FROM {$wpdb->postmeta} meta
			 INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id
			 WHERE posts.post_type = 'missiondp_campaign'"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'missiondp_campaign'" );

		// Reset settings to defaults.
		update_option( 'missiondp_settings', $this->settings->get_defaults() );
		delete_option( 'missiondp_default_campaign' );

		// Clear transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_missiondp_%'
			OR option_name LIKE '_transient_timeout_missiondp_%'"
		);

		wp_cache_flush();

		return [ 'deleted' => true ];
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Delete transients matching a LIKE pattern.
	 *
	 * @param string $pattern SQL LIKE pattern (e.g. 'missiondp_dashboard_%').
	 */
	private function delete_transients_like( string $pattern ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				OR option_name LIKE %s",
				'_transient_' . $pattern,
				'_transient_timeout_' . $pattern,
			)
		);
	}

	/**
	 * Get the log directory path.
	 *
	 * @return string|null
	 */
	private function get_log_dir(): ?string {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['basedir'] ) ) {
			return trailingslashit( $upload_dir['basedir'] ) . 'mission-logs/';
		}

		return null;
	}

	/**
	 * Log a cleanup event to the activity feed.
	 *
	 * @param string              $event       Event name.
	 * @param string              $object_type Object type.
	 * @param int                 $object_id   Object ID.
	 * @param array<string,mixed> $data        Optional context data.
	 */
	private function log_activity( string $event, string $object_type, int $object_id, array $data = [] ): void {
		$activity = Plugin::instance()->get_activity_feed_module();

		if ( $activity ) {
			$activity->log( $event, $object_type, $object_id, $data );
		}
	}
}
