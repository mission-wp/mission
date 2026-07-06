<?php
/**
 * Database module - handles custom tables and schema updates.
 *
 * @package MissionDP
 */

namespace MissionDP\Database;

use MissionDP\Database\DataStore\CampaignDataStore;
use MissionDP\Database\DataStore\FundraiserDataStore;
use MissionDP\Database\DataStore\TeamDataStore;

defined( 'ABSPATH' ) || exit;

/**
 * Database module class.
 */
class DatabaseModule {

	/**
	 * Current database schema version.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1.4.4';

	/**
	 * Option name for storing database version.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'missiondp_db_version';

	/**
	 * Option name used as a mutex while migrations run.
	 *
	 * @var string
	 */
	public const MIGRATION_LOCK_OPTION = 'missiondp_db_migrating';

	/**
	 * Schema instance.
	 *
	 * @var Schema|null
	 */
	private ?Schema $schema = null;

	/**
	 * Register custom meta tables with $wpdb.
	 *
	 * Must be called early so WP's metadata API knows about our tables.
	 * Safe to call multiple times.
	 *
	 * @return void
	 */
	public static function register_meta_tables(): void {
		global $wpdb;

		$wpdb->missiondp_campaignmeta     = $wpdb->prefix . 'missiondp_campaignmeta';
		$wpdb->missiondp_transactionmeta  = $wpdb->prefix . 'missiondp_transactionmeta';
		$wpdb->missiondp_donormeta        = $wpdb->prefix . 'missiondp_donormeta';
		$wpdb->missiondp_subscriptionmeta = $wpdb->prefix . 'missiondp_subscriptionmeta';
		$wpdb->missiondp_fundraisermeta   = $wpdb->prefix . 'missiondp_fundraisermeta';
		$wpdb->missiondp_teammeta         = $wpdb->prefix . 'missiondp_teammeta';
	}

	/**
	 * Initialize the database module.
	 *
	 * @return void
	 */
	public function init(): void {
		self::register_meta_tables();

		// The row memo must stay per-request: a persistent drop-in (Redis,
		// Memcached) would otherwise serve rows across requests with no
		// cross-server invalidation.
		wp_cache_add_non_persistent_groups(
			[
				FundraiserDataStore::CACHE_GROUP,
				TeamDataStore::CACHE_GROUP,
				CampaignDataStore::CACHE_GROUP,
			]
		);

		// Campaign aggregates are also written by raw SQL in the transaction
		// flow; every write site fires this action, so one listener keeps the
		// row memo coherent.
		add_action(
			'mission_campaign_aggregates_updated',
			static function ( int $campaign_id ): void {
				wp_cache_delete( 'id:' . $campaign_id, CampaignDataStore::CACHE_GROUP );
			}
		);

		$this->schema = new Schema();

		// Priority 20 so post types are registered (init 10) before the shell-post
		// backfill creates properly-slugged posts.
		add_action( 'init', [ $this, 'maybe_run_migrations' ], 20 );
	}

	/**
	 * Check if migrations need to run and execute them if needed.
	 *
	 * A lock option prevents concurrent requests from migrating twice.
	 *
	 * @return void
	 */
	public function maybe_run_migrations(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( ! version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			return;
		}

		if ( ! $this->acquire_migration_lock() ) {
			return;
		}

		try {
			// Data migrations that must run before dbDelta applies the new schema.
			if ( version_compare( $installed_version, '1.4.2', '<' ) ) {
				$this->migrate_142_shell_posts();
			}

			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} finally {
			delete_option( self::MIGRATION_LOCK_OPTION );
		}
	}

	/**
	 * Try to acquire the migration lock.
	 *
	 * add_option() is a no-op when the option already exists, which makes it a
	 * cheap mutex. A crashed migration must not block forever, so locks older
	 * than five minutes are stolen by deleting and re-adding: only one of the
	 * concurrent stealers wins the add (same pattern as WP_Upgrader::create_lock).
	 *
	 * @return bool Whether this request may run migrations.
	 */
	private function acquire_migration_lock(): bool {
		if ( add_option( self::MIGRATION_LOCK_OPTION, (string) time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::MIGRATION_LOCK_OPTION );

		if ( time() - $locked_at < 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		delete_option( self::MIGRATION_LOCK_OPTION );

		return add_option( self::MIGRATION_LOCK_OPTION, (string) time(), '', false );
	}

	/**
	 * 1.4.2 migration: give every fundraiser/team a shell post and tighten the
	 * post_id index to UNIQUE.
	 *
	 * Backfills shell posts for legacy rows (post_id 0) so no duplicate zeros
	 * remain, then drops the old non-unique post_id index so the UNIQUE one in
	 * the schema applies cleanly via dbDelta. A rewrite flush is scheduled so
	 * the new fundraiser/team page rules register.
	 *
	 * @return void
	 */
	private function migrate_142_shell_posts(): void {
		global $wpdb;

		$fundraisers_table = $wpdb->prefix . 'missiondp_fundraisers';

		update_option( 'missiondp_flush_rewrite_rules', 1 );

		// A pre-1.4 install has no P2P tables yet, so there is nothing to migrate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fundraisers_table ) ) !== $fundraisers_table ) {
			return;
		}

		$this->backfill_shell_posts( $fundraisers_table, \MissionDP\Models\Fundraiser::class );
		$this->backfill_shell_posts( $wpdb->prefix . 'missiondp_teams', \MissionDP\Models\Team::class );

		$this->drop_nonunique_post_id_index( $fundraisers_table );
		$this->drop_nonunique_post_id_index( $wpdb->prefix . 'missiondp_teams' );
	}

	/**
	 * Create a shell post for every model row that lacks one (post_id 0).
	 *
	 * @param string                                $table       Fully prefixed table name.
	 * @param class-string<\MissionDP\Models\Model> $model_class Fundraiser or Team.
	 * @return void
	 */
	private function backfill_shell_posts( string $table, string $model_class ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = 0', $table ) );

		foreach ( $ids as $id ) {
			$model = $model_class::find( (int) $id );
			// Re-check per row: a concurrent run may have backfilled it already.
			if ( $model && ! $model->post_id ) {
				$model->save();
			}
		}
	}

	/**
	 * Drop a non-unique post_id index if one exists, leaving the unique index
	 * from the schema to be added by dbDelta.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return void
	 */
	private function drop_nonunique_post_id_index( string $table ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// Filtered in PHP rather than with WHERE: the SQLite translator ignores
		// WHERE clauses on SHOW statements.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );

		foreach ( $indexes as $index ) {
			if ( 'post_id' === ( $index['Key_name'] ?? '' ) && '1' === (string) ( $index['Non_unique'] ?? '' ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id', $table ) );
				break;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Create or update all custom database tables.
	 *
	 * Uses dbDelta for safe table creation and updates.
	 * Called during activation and when schema version changes.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::register_meta_tables();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema = new Schema();
		$tables = $schema->get_table_schemas();

		foreach ( $tables as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	/**
	 * Get schema instance.
	 *
	 * @return Schema
	 */
	public function get_schema(): Schema {
		return $this->schema;
	}
}
