<?php
/**
 * Multi-column search clause builder.
 *
 * @package MissionDP
 */

namespace MissionDP\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Builds tokenized LIKE-search WHERE fragments for $wpdb->prepare().
 *
 * Splits the search string on whitespace; each token must match at least one
 * of the supplied columns. Token groups are AND'd together, columns within a
 * group are OR'd, so "jane doe" matches a row with first_name='Jane' and
 * last_name='Doe' even though no single column contains both words.
 */
class SearchClauseBuilder {

	/**
	 * Build a tokenized LIKE-search WHERE fragment.
	 *
	 * The returned `sql` contains `%s` placeholders ready for $wpdb->prepare(),
	 * and `params` lists the matching values in order. The SQL is wrapped in a
	 * single outer paren group so callers can splice it into a larger WHERE.
	 *
	 * Column expressions are inlined into the SQL as trusted identifiers —
	 * pass only hard-coded column names (optionally table-qualified).
	 *
	 * @param string   $search  Raw user search string.
	 * @param string[] $columns Column expressions to LIKE-match (e.g. "d.first_name").
	 *
	 * @return array{sql: string, params: array<int, string>}|null Null when search is empty after trimming, or no columns supplied.
	 */
	public static function build_like_clause( string $search, array $columns ): ?array {
		global $wpdb;

		$search = trim( $search );
		if ( '' === $search || empty( $columns ) ) {
			return null;
		}

		$tokens = preg_split( '/\s+/', $search );
		if ( ! $tokens ) {
			return null;
		}

		$token_groups = [];
		$params       = [];

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			$like = '%' . $wpdb->esc_like( $token ) . '%';

			$column_clauses = [];
			foreach ( $columns as $column ) {
				$column_clauses[] = $column . ' LIKE %s';
				$params[]         = $like;
			}

			$token_groups[] = '(' . implode( ' OR ', $column_clauses ) . ')';
		}

		if ( empty( $token_groups ) ) {
			return null;
		}

		return [
			'sql'    => '(' . implode( ' AND ', $token_groups ) . ')',
			'params' => $params,
		];
	}
}
