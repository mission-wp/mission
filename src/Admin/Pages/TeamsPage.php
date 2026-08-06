<?php
/**
 * Teams admin page.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin\Pages;

use MissionDP\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Teams page class.
 */
class TeamsPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'teams';
	}
}
