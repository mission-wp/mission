<?php
/**
 * Tests for the Schema class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\Schema;
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
	 * Test that get_table_schemas returns all tables.
	 */
	public function test_get_table_schemas_returns_all_tables(): void {
		$schemas = $this->schema->get_table_schemas();

		$this->assertCount( 21, $schemas );

		global $wpdb;
		$prefix = $wpdb->prefix . 'missiondp_';

		$expected = array(
			"{$prefix}transactions",
			"{$prefix}transactionmeta",
			"{$prefix}donors",
			"{$prefix}donormeta",
			"{$prefix}subscriptions",
			"{$prefix}subscriptionmeta",
			"{$prefix}campaignmeta",
			"{$prefix}campaigns",
			"{$prefix}fundraisers",
			"{$prefix}fundraisermeta",
			"{$prefix}teams",
			"{$prefix}teammeta",
			"{$prefix}team_invitations",
			"{$prefix}notes",
			"{$prefix}transaction_history",
			"{$prefix}tributes",
			"{$prefix}import_jobs",
			"{$prefix}migration_phases",
			"{$prefix}activity_log",
			"{$prefix}outgoing_webhooks",
			"{$prefix}webhook_deliveries",
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
	 * Test that dbDelta adds the compound indexes to pre-existing tables (the
	 * upgrade path for sites created before the indexes were introduced).
	 */
	public function test_dbdelta_adds_compound_indexes_to_existing_tables(): void {
		global $wpdb;

		DatabaseModule::create_tables();

		$expected = [
			$wpdb->prefix . 'missiondp_transactions'  => [
				'status_test_currency_date' => [ 'status', 'is_test', 'currency', 'date_created' ],
				'status_test_completed'     => [ 'status', 'is_test', 'date_completed' ],
			],
			$wpdb->prefix . 'missiondp_subscriptions' => [
				'donor_status_test' => [ 'donor_id', 'status', 'is_test' ],
			],
		];

		// Simulate a site created before the compound indexes existed.
		foreach ( $expected as $table => $indexes ) {
			foreach ( array_keys( $indexes ) as $index_name ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index_name ) );
			}
		}

		// Run twice: the first pass must add the indexes, the second must not duplicate them.
		DatabaseModule::create_tables();
		DatabaseModule::create_tables();

		foreach ( $expected as $table => $indexes ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );

			foreach ( $indexes as $index_name => $columns ) {
				$index_columns = [];
				foreach ( $rows as $row ) {
					if ( $row['Key_name'] === $index_name ) {
						$index_columns[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
					}
				}
				ksort( $index_columns );

				$this->assertSame( $columns, array_values( $index_columns ), "Index {$index_name} on {$table} has wrong columns or is missing." );
				$this->assertEmpty(
					array_filter( $rows, static fn( $row ) => $row['Key_name'] === $index_name . '_2' ),
					"dbDelta duplicated index {$index_name} on {$table}."
				);
			}
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
