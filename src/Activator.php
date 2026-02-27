<?php
/**
 * Fired during plugin activation.
 *
 * @package Mission
 */

namespace Mission;

use Mission\Database\DatabaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Activator class.
 */
class Activator {

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::check_requirements();
		self::create_tables();
		self::add_capabilities();
		self::set_default_options();

		// Store the plugin version for future upgrade routines.
		update_option( 'mission_version', MISSION_VERSION );

		// Set a transient to trigger a welcome/activation notice.
		set_transient( 'mission_activated', true, 30 );

		// Flush rewrite rules after activation.
		flush_rewrite_rules();
	}

	/**
	 * Check that the server meets minimum requirements.
	 *
	 * @return void
	 */
	private static function check_requirements(): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( MISSION_BASENAME );
			wp_die(
				esc_html__( 'Mission requires PHP 8.0 or higher.', 'mission' ),
				esc_html__( 'Plugin Activation Error', 'mission' ),
				array( 'back_link' => true )
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.5', '<' ) ) {
			deactivate_plugins( MISSION_BASENAME );
			wp_die(
				esc_html__( 'Mission requires WordPress 6.5 or higher.', 'mission' ),
				esc_html__( 'Plugin Activation Error', 'mission' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Create custom database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		DatabaseModule::create_tables();
		update_option( DatabaseModule::DB_VERSION_OPTION, DatabaseModule::DB_VERSION );
	}

	/**
	 * Add custom capabilities to roles.
	 *
	 * @return void
	 */
	private static function add_capabilities(): void {
		$admin = get_role( 'administrator' );

		if ( ! $admin ) {
			return;
		}

		$admin->add_cap( 'manage_mission' );
		$admin->add_cap( 'view_mission_reports' );
		$admin->add_cap( 'edit_mission_transactions' );
	}

	/**
	 * Set default plugin options if they don't already exist.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		// Only set defaults on fresh installs, not reactivations.
		if ( false === get_option( 'mission_settings' ) ) {
			add_option( 'mission_settings', self::get_default_settings() );
		}
	}

	/**
	 * Get default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_default_settings(): array {
		return array(
			'currency'                 => 'USD',
			'tip_enabled'              => true,
			'tip_default_percentage'   => 15,
			'stripe_publishable_key'   => '',
			'stripe_secret_key'        => '',
			'stripe_account_id'        => '',
			'stripe_connection_status' => 'disconnected',
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_bloginfo( 'admin_email' ),
		);
	}
}
