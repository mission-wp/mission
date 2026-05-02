<?php
/**
 * Plugin Name: Mission Donation Platform
 * Plugin URI: https://missionwp.com
 * Description: The free donation plugin for nonprofits. Powerful features, modern forms, no add-ons required.
 * Version: 1.1.0
 * Author: Mission
 * Author URI: https://github.com/mission-wp
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mission-donation-platform
 * Requires at least: 6.7
 * Requires PHP: 8.0
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'MISSIONDP_VERSION', '1.1.0' );
define( 'MISSIONDP_FILE', __FILE__ );
define( 'MISSIONDP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MISSIONDP_URL', plugin_dir_url( __FILE__ ) );
define( 'MISSIONDP_BASENAME', plugin_basename( __FILE__ ) );
define( 'MISSIONDP_STRIPE_PK_TEST', 'pk_test_51T5DwoQLFYekpV0FSkXZtgzDJ9c1NxnIT0yXWzueakHgSaQyW5xSBwnIt6ysjmXMTlsHAQ0aX9KUTSk6h27PeonZ00kW2hnLQF' );
define( 'MISSIONDP_STRIPE_PK_LIVE', 'pk_live_51T5DwoQLFYekpV0Fmd9UBolXWaoBAnSvLud40NTmdRBkJlHgbhBbzEhIeXlDMrNe7KosZTskGSTY7KI1RejfBuRn00pnVlZyia' );

// Load Composer autoloader.
$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
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
