<?php
/**
 * Cleanup service — handles cache clearing, test data removal, and resets.
 *
 * @package MissionDP
 */

namespace MissionDP\Cleanup;

use MissionDP\Database\Schema;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom-table layer; direct $wpdb is required. Identifiers use %i and values use %s/%d throughout.

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
		private SettingsService $settings,
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

		$activity_log_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $prefix . 'activity_log' )
		);

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
		$test_transaction_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_test = 1', $prefix . 'transactions' )
		);

		$test_subscription_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_test = 1', $prefix . 'subscriptions' )
		);

		// Donors that have only test transactions (no live ones).
		$test_donor_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE transaction_count = 0 AND test_transaction_count > 0',
				$prefix . 'donors'
			)
		);

		// Webhook delivery count.
		$webhook_delivery_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $prefix . 'webhook_deliveries' )
		);

		return [
			'activity_log_count'      => $activity_log_count,
			'webhook_delivery_count'  => $webhook_delivery_count,
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

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );

		// Announce after the truncate so the audit entry survives the wipe.
		$this->announce_cleanup( 'activity_log_cleared', [ 'entries_deleted' => $count ] );

		return [ 'deleted' => $count ];
	}

	/**
	 * Clear all webhook delivery records.
	 *
	 * @return array{deleted: int}
	 */
	public function clear_webhook_deliveries(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'missiondp_webhook_deliveries';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);

		$this->announce_cleanup( 'webhook_deliveries_cleared', [ 'entries_deleted' => $count ] );

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );

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

		$this->announce_cleanup(
			'log_files_deleted',
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
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE is_test = 1', $prefix . 'transactions' )
		);

		$count = count( $ids );

		if ( $count > 0 ) {
			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$meta_sql     = "DELETE FROM %i WHERE missiondp_transaction_id IN ( {$placeholders} )";
			$by_txn_sql   = "DELETE FROM %i WHERE transaction_id IN ( {$placeholders} )";
			$notes_sql    = "DELETE FROM %i WHERE object_type = %s AND object_id IN ( {$placeholders} )";

			// Cascade: meta, history, notes, tributes.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $meta_sql, array_merge( [ $prefix . 'transactionmeta' ], $ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $by_txn_sql, array_merge( [ $prefix . 'transaction_history' ], $ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $notes_sql, array_merge( [ $prefix . 'notes', 'transaction' ], $ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $by_txn_sql, array_merge( [ $prefix . 'tributes' ], $ids ) ) );

			// Delete the transactions.
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE is_test = 1', $prefix . 'transactions' )
			);

			// Reset test aggregate columns on donors and campaigns.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET
						test_total_donated = 0,
						test_total_tip = 0,
						test_transaction_count = 0,
						test_first_transaction = NULL,
						test_last_transaction = NULL',
					$prefix . 'donors'
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET
						test_total_raised = 0,
						test_donor_count = 0,
						test_transaction_count = 0',
					$prefix . 'campaigns'
				)
			);
		}

		$this->announce_cleanup( 'test_transactions_deleted', [ 'count' => $count ] );

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
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE transaction_count = 0 AND test_transaction_count = 0',
				$prefix . 'donors'
			)
		);

		$count = count( $ids );

		if ( $count > 0 ) {
			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$meta_sql     = "DELETE FROM %i WHERE missiondp_donor_id IN ( {$placeholders} )";
			$notes_sql    = "DELETE FROM %i WHERE object_type = %s AND object_id IN ( {$placeholders} )";
			$donors_sql   = "DELETE FROM %i WHERE id IN ( {$placeholders} )";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $meta_sql, array_merge( [ $prefix . 'donormeta' ], $ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $notes_sql, array_merge( [ $prefix . 'notes', 'donor' ], $ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $donors_sql, array_merge( [ $prefix . 'donors' ], $ids ) ) );
		}

		$this->announce_cleanup( 'test_donors_deleted', [ 'count' => $count ] );

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

		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE is_test = 1', $prefix . 'subscriptions' )
		);

		$count = count( $ids );

		if ( $count > 0 ) {
			$ids          = array_map( 'intval', $ids );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$meta_sql     = "DELETE FROM %i WHERE missiondp_subscription_id IN ( {$placeholders} )";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, ids via %d placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $meta_sql, array_merge( [ $prefix . 'subscriptionmeta' ], $ids ) ) );
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE is_test = 1', $prefix . 'subscriptions' )
			);
		}

		$this->announce_cleanup( 'test_subscriptions_deleted', [ 'count' => $count ] );

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

		$this->announce_cleanup( 'onboarding_reset' );

		return [ 'reset' => true ];
	}

	/**
	 * Reset all settings to defaults.
	 *
	 * @return array{reset: true}
	 */
	public function reset_all_settings(): array {
		$this->announce_cleanup( 'settings_reset' );

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
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}

		// Delete campaign CPT posts and meta.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE meta FROM %i meta
				 INNER JOIN %i posts ON posts.ID = meta.post_id
				 WHERE posts.post_type = %s',
				$wpdb->postmeta,
				$wpdb->posts,
				'missiondp_campaign'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE post_type = %s',
				$wpdb->posts,
				'missiondp_campaign'
			)
		);

		// Reset settings to defaults.
		update_option( 'missiondp_settings', $this->settings->get_defaults() );
		delete_option( 'missiondp_default_campaign' );

		self::clear_plugin_transients();

		wp_cache_flush();

		return [ 'deleted' => true ];
	}

	/**
	 * Delete every Mission plugin transient (and its timeout sibling).
	 *
	 * Used during deactivation and the "delete all data" reset. Static so it
	 * can be called from the Deactivator without instantiating the full
	 * service (which depends on SettingsService).
	 */
	public static function clear_plugin_transients(): void {
		self::delete_transients_like_static( 'missiondp_%' );
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
		self::delete_transients_like_static( $pattern );
	}

	/**
	 * Static implementation of the transient delete.
	 *
	 * @param string $pattern SQL LIKE pattern (e.g. 'missiondp_dashboard_%').
	 */
	private static function delete_transients_like_static( string $pattern ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				'_transient_' . $pattern,
				'_transient_timeout_' . $pattern
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
	 * Announce a completed cleanup operation so listeners (activity feed) can record it.
	 *
	 * @param string              $operation Cleanup event name (e.g. 'test_transactions_deleted').
	 * @param array<string,mixed> $stats     Operation stats (counts, bytes freed, etc.).
	 */
	private function announce_cleanup( string $operation, array $stats = [] ): void {
		/**
		 * Fires when a cleanup operation completes.
		 *
		 * @param string              $operation Cleanup event name.
		 * @param array<string,mixed> $stats     Operation stats.
		 */
		do_action( 'mission_cleanup_performed', $operation, $stats );
	}
}
