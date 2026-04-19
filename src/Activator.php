<?php
/**
 * Fired during plugin activation.
 *
 * @package Mission
 */

namespace Mission;

use Mission\Campaigns\MilestoneTracker;
use Mission\Database\DatabaseModule;
use Mission\Models\ActivityLog;
use Mission\Models\Campaign;

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
		$is_fresh_install = ! get_option( 'mission_version' );

		self::check_requirements();
		self::create_tables();
		self::add_capabilities();
		self::set_default_options();
		self::create_pages();
		self::backfill_milestones();

		if ( $is_fresh_install ) {
			self::log_plugin_installed();
			update_option( 'mission_installed_at', current_time( 'mysql', true ) );
			set_transient( 'mission_do_activation_redirect', true, 30 );
		} else {
			self::log_plugin_activated();
		}

		// Store the plugin version for future upgrade routines.
		update_option( 'mission_version', MISSION_VERSION );

		// Set a transient to trigger a welcome/activation notice.
		set_transient( 'mission_activated', true, 30 );

		// Register the post type so its rewrite rules are included in the flush.
		( new Campaigns\CampaignPostType() )->register();
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
				esc_html__( 'MissionWP Donation Platform requires PHP 8.0 or higher.', 'missionwp-donation-platform' ),
				esc_html__( 'Plugin Activation Error', 'missionwp-donation-platform' ),
				[ 'back_link' => true ]
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.7', '<' ) ) {
			deactivate_plugins( MISSION_BASENAME );
			wp_die(
				esc_html__( 'MissionWP Donation Platform requires WordPress 6.7 or higher.', 'missionwp-donation-platform' ),
				esc_html__( 'Plugin Activation Error', 'missionwp-donation-platform' ),
				[ 'back_link' => true ]
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

		// Register the donor role (zero capabilities — only used to identify logged-in donors).
		if ( ! get_role( 'mission_donor' ) ) {
			add_role( 'mission_donor', __( 'MissionWP Donor', 'missionwp-donation-platform' ), [] );
		}
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
	 * Create plugin pages if they don't already exist.
	 *
	 * @return void
	 */
	private static function create_pages(): void {
		\Mission\DonorDashboard\DonorDashboardModule::create_dashboard_page();
	}

	/**
	 * Log the plugin_installed event into the activity log.
	 *
	 * @return void
	 */
	private static function log_plugin_installed(): void {
		$entry = new ActivityLog(
			[
				'event'       => 'plugin_installed',
				'object_type' => 'settings',
				'data'        => wp_json_encode( [ 'version' => MISSION_VERSION ] ),
			]
		);
		$entry->save();
	}

	/**
	 * Log the plugin_activated event into the activity log.
	 *
	 * @return void
	 */
	private static function log_plugin_activated(): void {
		$entry = new ActivityLog(
			[
				'event'       => 'plugin_activated',
				'object_type' => 'settings',
				'data'        => wp_json_encode( [ 'version' => MISSION_VERSION ] ),
			]
		);
		$entry->save();
	}

	/**
	 * Backfill milestones for any campaigns that don't have them yet.
	 *
	 * @return void
	 */
	private static function backfill_milestones(): void {
		$tracker = new MilestoneTracker();

		foreach ( Campaign::query() as $campaign ) {
			$existing = $campaign->get_meta( 'milestones' );
			if ( empty( $existing ) ) {
				$tracker->recompile( $campaign->id );
			}
		}
	}

	/**
	 * Get default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_default_settings(): array {
		return [
			'currency'                 => 'USD',
			'primary_color'            => '#2fa36b',
			'show_powered_by'          => false,
			'test_mode'                => true,
			'stripe_site_id'           => '',
			'stripe_site_token'        => '',
			'stripe_account_id'        => '',
			'stripe_connection_status' => 'disconnected',
			'stripe_display_name'      => '',
			'stripe_fee_percent'       => 2.9,
			'stripe_fee_fixed'         => 30,
			'org_name'                 => get_bloginfo( 'name' ),
			'org_street'               => '',
			'org_city'                 => '',
			'org_state'                => '',
			'org_zip'                  => '',
			'org_country'              => 'US',
			'org_ein'                  => '',
			'onboarding_completed'     => false,
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_bloginfo( 'admin_email' ),
		];
	}
}
