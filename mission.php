<?php
/**
 * Plugin Name: Mission - Donation Forms & Fundraising
 * Plugin URI: https://MissionWP.com
 * Description: The free donation plugin for nonprofits. Powerful features, modern forms, no add-ons required.
 * Version: 1.0.0
 * Author: Mission
 * Author URI: https://MissionWP.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mission
 * Requires at least: 6.5
 * Requires PHP: 8.0
 *
 * @package Mission
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'MISSION_VERSION', '1.0.0' );
define( 'MISSION_FILE', __FILE__ );
define( 'MISSION_PATH', plugin_dir_path( __FILE__ ) );
define( 'MISSION_URL', plugin_dir_url( __FILE__ ) );
define( 'MISSION_BASENAME', plugin_basename( __FILE__ ) );
define( 'MISSION_STRIPE_PK', 'pk_test_51T5DwxKmlUXgfwX1imygmc6bNNiXqdJT85CABLAMvanmATbQ6e1ol9RhzP85WyxOq1xmXKUI6LiyeXzbPStlRagi00YXxKtotb' );

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
					<strong><?php esc_html_e( 'Mission:', 'mission' ); ?></strong>
					<?php esc_html_e( 'Composer dependencies not found. Please run `composer install` in the plugin directory.', 'mission' ); ?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

// Activation hook.
register_activation_hook( __FILE__, array( '\Mission\Activator', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( '\Mission\Deactivator', 'deactivate' ) );

// Bootstrap the plugin.
if ( class_exists( '\Mission\Plugin' ) ) {
	\Mission\Plugin::instance()->init();
}
