<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * This is the nuclear option — removes all plugin data from the database.
 * Only runs when the user deletes the plugin from WP admin.
 *
 * @package MissionDP
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only remove data if the admin explicitly opted in via Settings > Data.
$settings = get_option( 'missiondp_settings', [] );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// -------------------------------------------------------------------------
// Plugin-created pages
// -------------------------------------------------------------------------
$dashboard_page_id = (int) get_option( 'missiondp_dashboard_page_id', 0 );
if ( $dashboard_page_id ) {
	wp_delete_post( $dashboard_page_id, true );
}

// -------------------------------------------------------------------------
// Options
// -------------------------------------------------------------------------
$options = [
	'missiondp_version',
	'missiondp_db_version',
	'missiondp_settings',
	'missiondp_dashboard_page_id',
	'missiondp_installed_at',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// -------------------------------------------------------------------------
// Transients
// -------------------------------------------------------------------------
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_missiondp_%'
	OR option_name LIKE '_transient_timeout_missiondp_%'"
);

// -------------------------------------------------------------------------
// Campaign CPT posts and meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.post_type = 'missiondp_campaign'" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'missiondp_campaign'" );

// -------------------------------------------------------------------------
// Custom tables
// -------------------------------------------------------------------------
// Load the Schema class to get table names dynamically.
$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;

	if ( class_exists( '\MissionDP\Database\DatabaseModule' ) ) {
		\MissionDP\Database\DatabaseModule::drop_tables();
	}
}

// -------------------------------------------------------------------------
// Capabilities
// -------------------------------------------------------------------------
$capabilities = [
	'manage_missiondp',
	'view_missiondp_reports',
	'edit_missiondp_transactions',
];

foreach ( wp_roles()->roles as $role_name => $role_info ) {
	$wp_role = get_role( $role_name );
	if ( $wp_role ) {
		foreach ( $capabilities as $cap ) {
			$wp_role->remove_cap( $cap );
		}
	}
}

// -------------------------------------------------------------------------
// Donor user accounts (missiondp_donor role)
// -------------------------------------------------------------------------
$donor_users = get_users( [ 'role' => 'missiondp_donor' ] );
foreach ( $donor_users as $donor_user ) {
	wp_delete_user( $donor_user->ID );
}
remove_role( 'missiondp_donor' );

// -------------------------------------------------------------------------
// User meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'missiondp_%'" );

// -------------------------------------------------------------------------
// Scheduled events
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( 'missiondp_daily_cleanup' );
wp_clear_scheduled_hook( 'missiondp_check_recurring_payments' );
wp_clear_scheduled_hook( 'missiondp_campaign_lifecycle' );

// Clear the cache to ensure stale data isn't served.
wp_cache_flush();
