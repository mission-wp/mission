<?php
/**
 * Interface for admin pages.
 *
 * @package Mission
 */

namespace Mission\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page interface.
 */
interface AdminPage {

	/**
	 * Render the page output.
	 *
	 * @return void
	 */
	public function render(): void;
}
