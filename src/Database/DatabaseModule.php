<?php
/**
 * Database module - handles custom tables and schema updates.
 *
 * @package Mission
 */

namespace Mission\Database;

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
	public const DB_VERSION = '1.0.0';

	/**
	 * Option name for storing database version.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'mission_db_version';

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

		$wpdb->mission_campaignmeta     = $wpdb->prefix . 'mission_campaignmeta';
		$wpdb->mission_transactionmeta  = $wpdb->prefix . 'mission_transactionmeta';
		$wpdb->mission_donormeta        = $wpdb->prefix . 'mission_donormeta';
		$wpdb->mission_subscriptionmeta = $wpdb->prefix . 'mission_subscriptionmeta';
	}

	/**
	 * Initialize the database module.
	 *
	 * @return void
	 */
	public function init(): void {
		self::register_meta_tables();

		$this->schema = new Schema();

		// Only check for migrations in the admin to avoid frontend overhead.
		if ( is_admin() ) {
			$this->maybe_run_migrations();
		}
	}

	/**
	 * Check if migrations need to run and execute them if needed.
	 *
	 * @return void
	 */
	private function maybe_run_migrations(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
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
	 * Drop all custom database tables.
	 *
	 * Called during uninstall to clean up plugin data.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$schema = new Schema();
		$tables = $schema->get_table_names();

		foreach ( $tables as $table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
