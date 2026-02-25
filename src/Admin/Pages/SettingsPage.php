<?php
/**
 * Settings admin page.
 *
 * @package Mission
 */

namespace Mission\Admin\Pages;

use Mission\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page class.
 */
class SettingsPage implements AdminPage {

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Settings coming soon.', 'mission' ); ?></p>
		</div>
		<?php
	}
}
