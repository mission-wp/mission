<?php
/**
 * Tests for the AdminModule class.
 *
 * @package Mission
 */

namespace Mission\Tests\Admin;

use Mission\Admin\AdminModule;
use Mission\Admin\Pages\DashboardPage;
use Mission\Admin\Pages\TransactionsPage;
use Mission\Admin\Pages\DonorsPage;
use Mission\Admin\Pages\SettingsPage;
use WP_UnitTestCase;

/**
 * AdminModule test class.
 */
class AdminModuleTest extends WP_UnitTestCase {

	/**
	 * The AdminModule instance.
	 *
	 * @var AdminModule
	 */
	private AdminModule $admin;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		$this->admin = new AdminModule();
		$this->admin->init();

		// Grant current user the manage_options capability.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Test that the main admin menu is registered.
	 */
	public function test_admin_menu_is_registered(): void {
		global $menu;

		$this->admin->register_admin_menu();

		$menu_slugs = array_column( $menu, 2 );
		$this->assertContains( AdminModule::MENU_SLUG, $menu_slugs );
	}

	/**
	 * Test that all submenu pages are registered.
	 */
	public function test_submenu_pages_are_registered(): void {
		global $submenu;

		$this->admin->register_admin_menu();

		$this->assertArrayHasKey( AdminModule::MENU_SLUG, $submenu );

		$submenu_slugs = array_column( $submenu[ AdminModule::MENU_SLUG ], 2 );

		$this->assertContains( AdminModule::MENU_SLUG, $submenu_slugs, 'Dashboard submenu missing.' );
		$this->assertContains( 'edit.php?post_type=mission_campaign', $submenu_slugs, 'Campaigns submenu missing.' );
		$this->assertContains( AdminModule::TRANSACTIONS_SLUG, $submenu_slugs, 'Transactions submenu missing.' );
		$this->assertContains( AdminModule::DONORS_SLUG, $submenu_slugs, 'Donors submenu missing.' );
		$this->assertContains( AdminModule::SETTINGS_SLUG, $submenu_slugs, 'Settings submenu missing.' );
		$this->assertCount( 5, $submenu[ AdminModule::MENU_SLUG ] );
	}

	/**
	 * Test that the dashboard page renders expected content.
	 */
	public function test_dashboard_page_renders(): void {
		$page = new DashboardPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="mission-admin"', $output );
		$this->assertStringContainsString( 'data-page="dashboard"', $output );
	}

	/**
	 * Test that the transactions page renders expected content.
	 */
	public function test_transactions_page_renders(): void {
		$page = new TransactionsPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="mission-admin"', $output );
		$this->assertStringContainsString( 'data-page="transactions"', $output );
	}

	/**
	 * Test that the donors page renders expected content.
	 */
	public function test_donors_page_renders(): void {
		$page = new DonorsPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="mission-admin"', $output );
		$this->assertStringContainsString( 'data-page="donors"', $output );
	}

	/**
	 * Test that the settings page renders expected content.
	 */
	public function test_settings_page_renders(): void {
		$page = new SettingsPage();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="mission-admin"', $output );
		$this->assertStringContainsString( 'data-page="settings"', $output );
	}
}
