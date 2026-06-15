<?php
/**
 * Tests for the DatabaseModule class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\Schema;
use WP_UnitTestCase;

/**
 * DatabaseModule test class.
 */
class DatabaseModuleTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( DatabaseModule::DB_VERSION_OPTION );

		parent::tear_down();
	}

	/**
	 * Test that create_tables runs without error.
	 */
	public function test_create_tables_runs_without_error(): void {
		DatabaseModule::create_tables();

		// If we get here without an exception, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test that Schema::drop_all_tables runs without error.
	 */
	public function test_drop_all_tables_runs_without_error(): void {
		( new Schema() )->drop_all_tables();

		// If we get here without an exception, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test that init creates a Schema instance.
	 */
	public function test_init_creates_schema(): void {
		$module = new DatabaseModule();
		$module->init();

		$this->assertInstanceOf(
			\MissionDP\Database\Schema::class,
			$module->get_schema()
		);
	}

	/**
	 * Test that migration updates version option when outdated.
	 *
	 * Requires is_admin() to return true.
	 */
	public function test_migration_updates_version_when_outdated(): void {
		// Set an outdated version.
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );

		// Set current screen so is_admin() returns true.
		set_current_screen( 'dashboard' );

		$module = new DatabaseModule();
		$module->init();

		// Migrations are registered on admin_init; invoke directly to avoid
		// firing unrelated core admin_init hooks in the CLI test context.
		$module->maybe_run_migrations();

		$this->assertSame(
			DatabaseModule::DB_VERSION,
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);

		// Reset current screen.
		set_current_screen( 'front' );
	}

	/**
	 * Test that migration skips on frontend (is_admin() false).
	 */
	public function test_migration_skips_on_frontend(): void {
		// Set an outdated version.
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );

		// is_admin() is false by default in tests.
		$module = new DatabaseModule();
		$module->init();

		// Version should remain outdated since migration was skipped.
		$this->assertSame(
			'0.0.0',
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
	}
}
