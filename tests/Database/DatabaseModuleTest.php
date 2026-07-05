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
		delete_option( DatabaseModule::MIGRATION_LOCK_OPTION );

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
	 */
	public function test_migration_updates_version_when_outdated(): void {
		// Set an outdated version.
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );

		$module = new DatabaseModule();
		$module->init();

		// Migrations are registered on init; invoke directly to avoid firing
		// unrelated core init hooks in the CLI test context.
		$module->maybe_run_migrations();

		$this->assertSame(
			DatabaseModule::DB_VERSION,
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
	}

	/**
	 * Test a true pre-1.4 upgrade (no P2P tables yet) migrates cleanly: no DB
	 * errors from the shell-post backfill, tables created, flush scheduled.
	 */
	public function test_migration_from_pre_p2p_install_is_clean(): void {
		global $wpdb;

		// Simulate a 1.3.x install: version predates P2P and its tables are absent.
		update_option( DatabaseModule::DB_VERSION_OPTION, '1.3.0' );
		delete_option( 'missiondp_flush_rewrite_rules' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_fundraisermeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_teammeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_team_invitations" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_teams" );
		// phpcs:enable

		$module = new DatabaseModule();
		$module->init();

		$wpdb->last_error = '';
		$module->maybe_run_migrations();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( DatabaseModule::DB_VERSION, get_option( DatabaseModule::DB_VERSION_OPTION ) );
		$this->assertSame( 1, (int) get_option( 'missiondp_flush_rewrite_rules' ) );

		// The P2P tables now exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame(
			$wpdb->prefix . 'missiondp_fundraisers',
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'missiondp_fundraisers' ) )
		);
	}

	/**
	 * Test that migrations are hooked on init for every request type, so a
	 * frontend/REST request after a cron plugin update migrates the schema
	 * before any donation write (is_admin() is false by default in tests).
	 */
	public function test_migration_runs_on_frontend_requests(): void {
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );

		$module = new DatabaseModule();
		$module->init();

		$this->assertSame( 20, has_action( 'init', [ $module, 'maybe_run_migrations' ] ) );

		$module->maybe_run_migrations();

		$this->assertSame(
			DatabaseModule::DB_VERSION,
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
	}

	/**
	 * Test that a concurrent request holding the migration lock is respected.
	 */
	public function test_migration_respects_active_lock(): void {
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );
		add_option( DatabaseModule::MIGRATION_LOCK_OPTION, (string) time(), '', false );

		$module = new DatabaseModule();
		$module->init();
		$module->maybe_run_migrations();

		// Version should remain outdated since another request holds the lock.
		$this->assertSame(
			'0.0.0',
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
	}

	/**
	 * Test that a stale lock (crashed migration) is stolen and released.
	 */
	public function test_migration_steals_stale_lock(): void {
		update_option( DatabaseModule::DB_VERSION_OPTION, '0.0.0' );
		add_option( DatabaseModule::MIGRATION_LOCK_OPTION, (string) ( time() - 10 * MINUTE_IN_SECONDS ), '', false );

		$module = new DatabaseModule();
		$module->init();
		$module->maybe_run_migrations();

		$this->assertSame(
			DatabaseModule::DB_VERSION,
			get_option( DatabaseModule::DB_VERSION_OPTION )
		);
		$this->assertFalse( get_option( DatabaseModule::MIGRATION_LOCK_OPTION ) );
	}
}
