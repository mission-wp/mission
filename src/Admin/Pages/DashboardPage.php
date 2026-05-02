<?php
/**
 * Dashboard admin page.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin\Pages;

use MissionDP\Admin\AdminPage;

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
