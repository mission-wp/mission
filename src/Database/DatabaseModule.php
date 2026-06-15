<?php
/**
 * Database module - handles custom tables and schema updates.
 *
 * @package MissionDP
 */

namespace MissionDP\Database;

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
	public const DB_VERSION = '1.4.2';

	/**
	 * Option name for storing database version.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'missiondp_db_version';

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

		$this->schema = new Schema();

		// Run migrations on admin_init (after post types register on init, so the
		// shell-post backfill creates properly-slugged posts) and only in the
		// admin to avoid frontend overhead.
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'maybe_run_migrations' ] );
		}
	}

	/**
	 * Check if migrations need to run and execute them if needed.
	 *
	 * @return void
	 */
	public function maybe_run_migrations(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( ! version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			return;
		}

		// Data migrations that must run before dbDelta applies the new schema.
		if ( version_compare( $installed_version, '1.4.2', '<' ) ) {
			$this->migrate_142_shell_posts();
		}

		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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

		$this->backfill_shell_posts( $wpdb->prefix . 'missiondp_fundraisers', \MissionDP\Models\Fundraiser::class );
		$this->backfill_shell_posts( $wpdb->prefix . 'missiondp_teams', \MissionDP\Models\Team::class );

		$this->drop_nonunique_post_id_index( $wpdb->prefix . 'missiondp_fundraisers' );
		$this->drop_nonunique_post_id_index( $wpdb->prefix . 'missiondp_teams' );

		// P2P rewrite rules change in this version; flush on the next init.
		update_option( 'missiondp_flush_rewrite_rules', 1 );
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
			if ( $model ) {
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
		$index = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'post_id' ), ARRAY_A );

		if ( $index && '1' === (string) ( $index['Non_unique'] ?? '' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX post_id', $table ) );
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
