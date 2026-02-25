<?php
/**
 * Donations admin page.
 *
 * @package Mission
 */

namespace Mission\Admin\Pages;

use Mission\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Donations page class.
 */
class DonationsPage implements AdminPage {

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Donations coming soon.', 'mission' ); ?></p>
		</div>
		<?php
	}
}
