<?php
/**
 * Plugin Name: Mission - Donation Platform
 * Plugin URI: https://missionwp.com
 * Description: The free donation plugin for nonprofits. Powerful features, modern forms, no add-ons required.
 * Version: 1.3.2
 * Author: Mission
 * Author URI: https://missionwp.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mission-donation-platform
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.0
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

// PHP version check. Must run before the autoloader is required, since the
// plugin's source uses PHP 8.0+ syntax that would fatal during parsing on
// older versions. Keep this block 7.x-safe.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Mission Donation Platform can\'t run on this site right now.', 'mission-donation-platform' ); ?></strong>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: current PHP version number */
						esc_html__( 'Your site is running PHP %s, which is outdated and no longer receives security updates. Mission requires PHP 8.0 or higher.', 'mission-donation-platform' ),
						esc_html( PHP_VERSION )
					);
					?>
				</p>
				<p>
					<?php esc_html_e( 'Most hosts can upgrade your PHP version with a one-click setting or a quick support request. Contact your hosting provider and ask them to upgrade you to PHP 8.2 or higher. Your whole site will be faster and more secure.', 'mission-donation-platform' ); ?>
				</p>
				<p>
					<a href="https://wordpress.org/support/update-php/" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Learn more about updating PHP →', 'mission-donation-platform' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	);
	return;
}

// Plugin constants.
define( 'MISSIONDP_VERSION', '1.3.2' );
define( 'MISSIONDP_FILE', __FILE__ );
define( 'MISSIONDP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MISSIONDP_URL', plugin_dir_url( __FILE__ ) );
define( 'MISSIONDP_BASENAME', plugin_basename( __FILE__ ) );
define( 'MISSIONDP_STRIPE_PK_TEST', 'pk_test_51T5DwoQLFYekpV0FSkXZtgzDJ9c1NxnIT0yXWzueakHgSaQyW5xSBwnIt6ysjmXMTlsHAQ0aX9KUTSk6h27PeonZ00kW2hnLQF' );
define( 'MISSIONDP_STRIPE_PK_LIVE', 'pk_live_51T5DwoQLFYekpV0Fmd9UBolXWaoBAnSvLud40NTmdRBkJlHgbhBbzEhIeXlDMrNe7KosZTskGSTY7KI1RejfBuRn00pnVlZyia' );

// Load Composer autoloader.
$missiondp_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $missiondp_autoloader ) ) {
	require_once $missiondp_autoloader;

	// Bootstrap Action Scheduler. Its loader picks the highest version if multiple
	// plugins ship it (e.g. WooCommerce), so this is safe to call directly.
	// Skipped in PHPUnit: the suite relies on the synchronous fallbacks and
	// fires scheduling hooks manually.
	$missiondp_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $missiondp_action_scheduler ) && ! defined( 'MISSIONDP_TESTING' ) ) {
		require_once $missiondp_action_scheduler;
	}
} else {
	// If Composer dependencies haven't been installed, show an admin notice.
	add_action(
		'admin_notices',
		static function () {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Mission Donation Platform:', 'mission-donation-platform' ); ?></strong>
					<?php esc_html_e( 'Composer dependencies not found. Please run `composer install` in the plugin directory.', 'mission-donation-platform' ); ?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

// Register custom meta tables with $wpdb early so they're available during activation.
\MissionDP\Database\DatabaseModule::register_meta_tables();

// Activation hook.
register_activation_hook( __FILE__, [ '\MissionDP\Activator', 'activate' ] );

// Deactivation hook.
register_deactivation_hook( __FILE__, [ '\MissionDP\Deactivator', 'deactivate' ] );

// Bootstrap the plugin.
if ( class_exists( '\MissionDP\Plugin' ) ) {
	\MissionDP\Plugin::instance()->init();
}
