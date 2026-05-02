<?php
/**
 * Donors admin page.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin\Pages;

use MissionDP\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Donors page class.
 */
class DonorsPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'donors';
	}
}
