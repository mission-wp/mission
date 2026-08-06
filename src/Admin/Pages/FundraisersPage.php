<?php
/**
 * Fundraisers admin page.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin\Pages;

use MissionDP\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Fundraisers page class.
 */
class FundraisersPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'fundraisers';
	}
}
