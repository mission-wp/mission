<?php
/**
 * Tools admin page.
 *
 * @package Mission
 */

namespace Mission\Admin\Pages;

use Mission\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Tools page class.
 */
class ToolsPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'tools';
	}
}
