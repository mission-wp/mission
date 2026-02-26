<?php
/**
 * Tests for the Schema class.
 *
 * @package Mission
 */

namespace Mission\Tests\Database;

use Mission\Database\DatabaseModule;
use Mission\Database\Schema;
use WP_UnitTestCase;

/**
 * Schema test class.
 */
class SchemaTest extends WP_UnitTestCase {

	/**
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->schema = new Schema();
	}

	/**
	 * Test that get_table_schemas returns all 7 tables.
	 */
	public function test_get_table_schemas_returns_all_tables(): void {
		$schemas = $this->schema->get_table_schemas();

		$this->assertCount( 6, $schemas );

		global $wpdb;
		$prefix = $wpdb->prefix . 'mission_';

		$expected = array(
			"{$prefix}transactions",
			"{$prefix}transaction_meta",
			"{$prefix}donors",
			"{$prefix}donor_meta",
			"{$prefix}subscriptions",
			"{$prefix}campaigns",
		);

		foreach ( $expected as $table ) {
			$this->assertArrayHasKey( $table, $schemas, "Missing schema for {$table}." );
		}
	}

	/**
	 * Test that tables are actually created by dbDelta.
	 */
	public function test_tables_are_created_by_dbdelta(): void {
		global $wpdb;

		DatabaseModule::create_tables();

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $this->schema->get_table_names() as $table_name ) {
			$this->assertContains( $table_name, $tables, "Table {$table_name} was not created." );
		}
	}

	/**
	 * Test that get_table_names matches schema keys.
	 */
	public function test_get_table_names_matches_schema_keys(): void {
		$names = $this->schema->get_table_names();
		$keys  = array_keys( $this->schema->get_table_schemas() );

		$this->assertSame( $keys, $names );
	}
}
