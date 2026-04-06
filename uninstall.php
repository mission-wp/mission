<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * This is the nuclear option — removes all plugin data from the database.
 * Only runs when the user deletes the plugin from WP admin.
 *
 * @package Mission
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only remove data if the admin explicitly opted in via Settings > Data.
$settings = get_option( 'mission_settings', [] );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// -------------------------------------------------------------------------
// Plugin-created pages
// -------------------------------------------------------------------------
$dashboard_page_id = (int) get_option( 'mission_dashboard_page_id', 0 );
if ( $dashboard_page_id ) {
	wp_delete_post( $dashboard_page_id, true );
}

// -------------------------------------------------------------------------
// Options
// -------------------------------------------------------------------------
$options = [
	'mission_version',
	'mission_db_version',
	'mission_settings',
	'mission_dashboard_page_id',
	'mission_installed_at',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// -------------------------------------------------------------------------
// Transients
// -------------------------------------------------------------------------
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_mission_%'
	OR option_name LIKE '_transient_timeout_mission_%'"
);

// -------------------------------------------------------------------------
// Campaign CPT posts and meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.post_type = 'mission_campaign'" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'mission_campaign'" );

// -------------------------------------------------------------------------
// Custom tables
// -------------------------------------------------------------------------
// Load the Schema class to get table names dynamically.
$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;

	if ( class_exists( '\Mission\Database\DatabaseModule' ) ) {
		\Mission\Database\DatabaseModule::drop_tables();
	}
}

// -------------------------------------------------------------------------
// Capabilities
// -------------------------------------------------------------------------
$capabilities = [
	'manage_mission',
	'view_mission_reports',
	'edit_mission_transactions',
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
// Donor user accounts (mission_donor role)
// -------------------------------------------------------------------------
$donor_users = get_users( [ 'role' => 'mission_donor' ] );
foreach ( $donor_users as $donor_user ) {
	wp_delete_user( $donor_user->ID );
}
remove_role( 'mission_donor' );

// -------------------------------------------------------------------------
// User meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mission_%'" );

// -------------------------------------------------------------------------
// Scheduled events
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( 'mission_daily_cleanup' );
wp_clear_scheduled_hook( 'mission_check_recurring_payments' );
wp_clear_scheduled_hook( 'mission_campaign_lifecycle' );

// Clear the cache to ensure stale data isn't served.
wp_cache_flush();
