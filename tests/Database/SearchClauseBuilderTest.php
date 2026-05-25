<?php
/**
 * Tests for the SearchClauseBuilder helper.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database;

use MissionDP\Database\SearchClauseBuilder;
use WP_UnitTestCase;

/**
 * SearchClauseBuilder test class.
 */
class SearchClauseBuilderTest extends WP_UnitTestCase {

	public function test_empty_search_returns_null(): void {
		$this->assertNull( SearchClauseBuilder::build_like_clause( '', [ 'first_name' ] ) );
	}

	public function test_whitespace_only_search_returns_null(): void {
		$this->assertNull( SearchClauseBuilder::build_like_clause( "   \t\n", [ 'first_name' ] ) );
	}

	public function test_empty_columns_returns_null(): void {
		$this->assertNull( SearchClauseBuilder::build_like_clause( 'alice', [] ) );
	}

	public function test_single_token_builds_or_across_columns(): void {
		$result = SearchClauseBuilder::build_like_clause( 'alice', [ 'first_name', 'last_name', 'email' ] );

		$this->assertNotNull( $result );
		$this->assertSame(
			'((first_name LIKE %s OR last_name LIKE %s OR email LIKE %s))',
			$result['sql']
		);
		$this->assertSame( [ '%alice%', '%alice%', '%alice%' ], $result['params'] );
	}

	public function test_multiple_tokens_build_and_of_or_groups(): void {
		$result = SearchClauseBuilder::build_like_clause( 'antwon klein', [ 'first_name', 'last_name' ] );

		$this->assertNotNull( $result );
		$this->assertSame(
			'((first_name LIKE %s OR last_name LIKE %s) AND (first_name LIKE %s OR last_name LIKE %s))',
			$result['sql']
		);
		$this->assertSame( [ '%antwon%', '%antwon%', '%klein%', '%klein%' ], $result['params'] );
	}

	public function test_collapses_repeated_whitespace_between_tokens(): void {
		$result = SearchClauseBuilder::build_like_clause( "  antwon   \tklein  ", [ 'first_name' ] );

		$this->assertNotNull( $result );
		$this->assertSame(
			'((first_name LIKE %s) AND (first_name LIKE %s))',
			$result['sql']
		);
		$this->assertSame( [ '%antwon%', '%klein%' ], $result['params'] );
	}

	public function test_escapes_like_wildcards_in_tokens(): void {
		$result = SearchClauseBuilder::build_like_clause( '50%_off', [ 'first_name' ] );

		$this->assertNotNull( $result );
		// esc_like escapes both `%` and `_`. Each escaped char is preceded by a backslash.
		$this->assertSame( [ '%50\\%\\_off%' ], $result['params'] );
	}

	public function test_preserves_table_qualified_column_names(): void {
		$result = SearchClauseBuilder::build_like_clause( 'jane', [ 'd.first_name', 'd.last_name' ] );

		$this->assertNotNull( $result );
		$this->assertSame(
			'((d.first_name LIKE %s OR d.last_name LIKE %s))',
			$result['sql']
		);
	}
}
