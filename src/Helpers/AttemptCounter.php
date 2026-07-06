<?php
/**
 * Atomic fixed-window attempt counter.
 *
 * Backs security-sensitive caps (OTP guess limits, REST rate limits) with
 * counter rows in the options table written via conditional UPDATEs, so
 * concurrent requests cannot race past a limit the way transient
 * read-modify-write counters can.
 *
 * @package MissionDP
 */

namespace MissionDP\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Atomicity requires conditional UPDATEs on the options table; the option/transient APIs are cache-backed read-modify-write and cannot enforce a cap under concurrency.

/**
 * Fixed-window counter whose increments are atomic claims.
 */
class AttemptCounter {

	/**
	 * Option-name prefix for counter rows (cleaned up in uninstall.php).
	 */
	private const PREFIX = 'missiondp_attempts_';

	/**
	 * Atomically claim one attempt slot for a key.
	 *
	 * The gate is `UPDATE ... WHERE value < limit` with the affected-row count
	 * checked, so at most $limit claims succeed per window no matter how many
	 * requests run in parallel. A refused claim does not touch the window.
	 *
	 * @param string $key    Counter key (unique per guarded thing).
	 * @param int    $limit  Maximum successful claims per window.
	 * @param int    $window Window length in seconds, measured from the first claim.
	 * @return bool True if a slot was claimed, false if the limit is spent.
	 */
	public static function claim( string $key, int $limit, int $window ): bool {
		global $wpdb;

		if ( $limit < 1 ) {
			return false;
		}

		$count_key   = self::PREFIX . $key;
		$timeout_key = self::PREFIX . 'timeout_' . $key;
		$now         = time();

		$timeout = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_key )
		);

		if ( null === $timeout ) {
			// First claim for this key; losing this insert race to a sibling request is fine.
			self::insert( $timeout_key, (string) ( $now + $window ) );
		} elseif ( (int) $timeout < $now ) {
			// Window over: the one request that wins this conditional update restarts the count.
			$reset = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value + 0 < %d",
					(string) ( $now + $window ),
					$timeout_key,
					$now
				)
			);
			if ( $reset ) {
				$wpdb->delete( $wpdb->options, [ 'option_name' => $count_key ] );
			}
		}

		if ( self::bump( $count_key, $limit ) ) {
			return true;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $count_key )
		);

		if ( null !== $exists ) {
			return false;
		}

		if ( self::insert( $count_key, '1' ) ) {
			return true;
		}

		// A sibling request created the row first; contend for a slot on it.
		return self::bump( $count_key, $limit );
	}

	/**
	 * Forget a counter entirely (e.g. when the guarded code is burned).
	 *
	 * @param string $key Counter key.
	 * @return void
	 */
	public static function reset( string $key ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name IN ( %s, %s )",
				self::PREFIX . $key,
				self::PREFIX . 'timeout_' . $key
			)
		);
	}

	/**
	 * Delete rows for counters whose window has passed (daily cleanup cron).
	 *
	 * @return void
	 */
	public static function purge_expired(): void {
		global $wpdb;

		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value + 0 < %d",
				$wpdb->esc_like( self::PREFIX . 'timeout_' ) . '%',
				time()
			)
		);

		if ( ! $expired ) {
			return;
		}

		foreach ( array_chunk( $expired, 200 ) as $chunk ) {
			$names = [];
			foreach ( $chunk as $timeout_key ) {
				$names[] = $timeout_key;
				$names[] = self::PREFIX . substr( $timeout_key, strlen( self::PREFIX . 'timeout_' ) );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
			$sql          = "DELETE FROM %i WHERE option_name IN ( {$placeholders} )";
			$prepare_args = array_merge( [ $wpdb->options ], $names );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table via %i, names via %s placeholders built from a counted array.
			$wpdb->query( $wpdb->prepare( $sql, $prepare_args ) );
		}
	}

	/**
	 * Atomically take a slot on the count row if the cap is not spent.
	 *
	 * @param string $count_key Option name of the count row.
	 * @param int    $limit     Maximum claims.
	 * @return bool True if a slot was taken.
	 */
	private static function bump( string $count_key, int $limit ): bool {
		global $wpdb;

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value + 0 < %d",
				$count_key,
				$limit
			)
		);
	}

	/**
	 * Insert a counter row, quietly tolerating a duplicate-key race.
	 *
	 * @param string $option_name Option name.
	 * @param string $value       Option value.
	 * @return bool True if the row was inserted.
	 */
	private static function insert( string $option_name, string $value ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => $option_name,
				'option_value' => $value,
				'autoload'     => 'no',
			]
		);
		$wpdb->suppress_errors( $suppress );

		return (bool) $inserted;
	}
}
