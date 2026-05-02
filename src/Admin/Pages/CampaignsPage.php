<?php
/**
 * Campaigns admin page.
 *
 * @package MissionDP
 */

namespace MissionDP\Admin\Pages;

use MissionDP\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns page class.
 */
class CampaignsPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'campaigns';
	}
}
