<?php
/**
 * Transactions admin page.
 *
 * @package Mission
 */

namespace Mission\Admin\Pages;

use Mission\Admin\AdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * Transactions page class.
 */
class TransactionsPage extends AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'transactions';
	}
}
