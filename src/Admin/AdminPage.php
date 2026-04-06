<?php
/**
 * Base class for admin pages.
 *
 * @package Mission
 */

namespace Mission\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract admin page class.
 */
abstract class AdminPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	abstract public function get_slug(): string;

	/**
	 * Render the page output.
	 *
	 * @return void
	 */
	public function render(): void {
		printf( '<div id="mission-admin" data-page="%s"></div>', esc_attr( $this->get_slug() ) );
	}
}
