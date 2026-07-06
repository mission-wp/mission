<?php
/**
 * Per-request memoization of single-row DataStore lookups.
 *
 * @package MissionDP
 */

namespace MissionDP\Database\DataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Memoizes raw table rows in a non-persistent wp_cache group.
 *
 * Raw rows (not models) are cached so a caller mutating a returned model can
 * never poison later reads; every hit is re-hydrated into a fresh model. The
 * post key stores only the row id (an index, not a copy), so forgetting the
 * id key alone is always enough for correctness: a stale post mapping is
 * detected on read and falls through to the database.
 */
trait CachesRows {

	/**
	 * The non-persistent cache group for this store's rows.
	 *
	 * @return string
	 */
	abstract protected function cache_group(): string;

	/**
	 * Get the memoized raw row for a row ID.
	 *
	 * @param int $id Row ID.
	 * @return array|null The cached row, or null on miss.
	 */
	protected function cached_row( int $id ): ?array {
		$row = wp_cache_get( 'id:' . $id, $this->cache_group() );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get the memoized raw row for a shell post ID.
	 *
	 * @param int $post_id WP post ID.
	 * @return array|null The cached row, or null on miss.
	 */
	protected function cached_row_by_post_id( int $post_id ): ?array {
		$id = wp_cache_get( 'post:' . $post_id, $this->cache_group() );

		if ( false === $id ) {
			return null;
		}

		$row = $this->cached_row( (int) $id );

		if ( null === $row || (int) ( $row['post_id'] ?? 0 ) !== $post_id ) {
			wp_cache_delete( 'post:' . $post_id, $this->cache_group() );
			return null;
		}

		return $row;
	}

	/**
	 * Memoize a row read from the database.
	 *
	 * @param array $row Raw table row.
	 */
	protected function prime_row_cache( array $row ): void {
		$id      = (int) ( $row['id'] ?? 0 );
		$post_id = (int) ( $row['post_id'] ?? 0 );

		if ( $id <= 0 ) {
			return;
		}

		wp_cache_set( 'id:' . $id, $row, $this->cache_group() );

		if ( $post_id > 0 ) {
			wp_cache_set( 'post:' . $post_id, $id, $this->cache_group() );
		}
	}

	/**
	 * Drop a memoized row after a write.
	 *
	 * @param int      $id      Row ID.
	 * @param int|null $post_id Optional post ID whose mapping should also drop.
	 */
	protected function forget_cached_row( int $id, ?int $post_id = null ): void {
		wp_cache_delete( 'id:' . $id, $this->cache_group() );

		if ( $post_id ) {
			wp_cache_delete( 'post:' . $post_id, $this->cache_group() );
		}
	}
}
