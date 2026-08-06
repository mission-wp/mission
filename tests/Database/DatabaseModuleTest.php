<?php
/**
 * Tests for the DatabaseModule class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Database;

use MissionDP\Database\DatabaseModule;
use MissionDP\Database\DataStore\FundraiserDataStore;
use MissionDP\Database\Schema;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use WP_UnitTestCase;

/**
 * DatabaseModule test class.
 */
class DatabaseModuleTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		delete_option( DatabaseModule::DB_VERSION_OPTION );
		delete_option( DatabaseModule::MIGRATION_LOCK_OPTION );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_fundraisers" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

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
	 * The 1.4.2 backfill creates a shell post for legacy rows (post_id 0) and
	 * leaves rows that already have one untouched.
	 */
	public function test_142_backfill_targets_only_legacy_rows(): void {
		global $wpdb;

		$campaign = new Campaign( [ 'title' => 'Backfill Drive', 'type' => 'p2p' ] );
		$campaign->save();

		$legacy = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 1, 'status' => 'active' ] );
		$legacy->save();
		$linked = new Fundraiser( [ 'campaign_id' => $campaign->id, 'donor_id' => 2, 'status' => 'active' ] );
		$linked->save();
		$linked_post_id = $linked->post_id;

		// Simulate a pre-1.4.2 beta row: detach first so the shell-post guard
		// can't touch the row when its post is deleted.
		$legacy_post_id = $legacy->post_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'missiondp_fundraisers', [ 'post_id' => 0 ], [ 'id' => $legacy->id ] );
		wp_delete_post( $legacy_post_id, true );
		wp_cache_flush_group( FundraiserDataStore::CACHE_GROUP );

		update_option( DatabaseModule::DB_VERSION_OPTION, '1.4.0' );

		$module = new DatabaseModule();
		$module->init();
		$module->maybe_run_migrations();

		$backfilled = Fundraiser::find( $legacy->id );
		$this->assertGreaterThan( 0, $backfilled->post_id );
		$this->assertSame( 'publish', get_post_status( $backfilled->post_id ) );

		// The already-linked row kept its original shell post.
		$this->assertSame( $linked_post_id, Fundraiser::find( $linked->id )->post_id );
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
