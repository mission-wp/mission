<?php
/**
 * Dashboard admin page.
 *
 * @package Mission
 */

namespace Mission\Admin\Pages;

use Mission\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard page class.
 */
class DashboardPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'dashboard';
	}
}
