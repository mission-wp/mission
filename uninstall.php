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

global $wpdb;

// -------------------------------------------------------------------------
// Options
// -------------------------------------------------------------------------
$options = array(
	'mission_version',
	'mission_db_version',
	'mission_settings',
);

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
// Form CPT posts and meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.post_type = 'mission_form'" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'mission_form'" );

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
$capabilities = array(
	'manage_mission',
	'view_mission_reports',
	'edit_mission_donations',
);

foreach ( wp_roles()->roles as $role_name => $role_info ) {
	$wp_role = get_role( $role_name );
	if ( $wp_role ) {
		foreach ( $capabilities as $cap ) {
			$wp_role->remove_cap( $cap );
		}
	}
}

// -------------------------------------------------------------------------
// User meta
// -------------------------------------------------------------------------
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mission_%'" );

// -------------------------------------------------------------------------
// Scheduled events
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( 'mission_daily_cleanup' );
wp_clear_scheduled_hook( 'mission_check_recurring_payments' );

// Clear the cache to ensure stale data isn't served.
wp_cache_flush();
