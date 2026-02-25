<?php
/**
 * Admin module - handles admin functionality.
 *
 * @package Mission
 */

namespace Mission\Admin;

use Mission\Admin\Pages\CampaignsPage;
use Mission\Admin\Pages\DashboardPage;
use Mission\Admin\Pages\DonationsPage;
use Mission\Admin\Pages\DonorsPage;
use Mission\Admin\Pages\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Admin module class.
 */
class AdminModule {

	/**
	 * Menu slug for the main Mission menu.
	 */
	public const MENU_SLUG = 'mission';

	/**
	 * Submenu slug for Campaigns.
	 */
	public const CAMPAIGNS_SLUG = 'mission-campaigns';

	/**
	 * Submenu slug for Donations.
	 */
	public const DONATIONS_SLUG = 'mission-donations';

	/**
	 * Submenu slug for Donors.
	 */
	public const DONORS_SLUG = 'mission-donors';

	/**
	 * Submenu slug for Settings.
	 */
	public const SETTINGS_SLUG = 'mission-settings';

	/**
	 * Page instances.
	 *
	 * @var array<string, AdminPage>
	 */
	private array $pages = array();

	/**
	 * Initialize the admin module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->pages = array(
			'dashboard' => new DashboardPage(),
			'campaigns' => new CampaignsPage(),
			'donations' => new DonationsPage(),
			'donors'    => new DonorsPage(),
			'settings'  => new SettingsPage(),
		);

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Register admin menu and submenus.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		$icon_url = $this->get_menu_icon_url();

		add_menu_page(
			__( 'Mission', 'mission' ),
			__( 'Mission', 'mission' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->pages['dashboard'], 'render' ),
			$icon_url,
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'mission' ),
			__( 'Dashboard', 'mission' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->pages['dashboard'], 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'mission' ),
			__( 'Campaigns', 'mission' ),
			'manage_options',
			self::CAMPAIGNS_SLUG,
			array( $this->pages['campaigns'], 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donations', 'mission' ),
			__( 'Donations', 'mission' ),
			'manage_options',
			self::DONATIONS_SLUG,
			array( $this->pages['donations'], 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donors', 'mission' ),
			__( 'Donors', 'mission' ),
			'manage_options',
			self::DONORS_SLUG,
			array( $this->pages['donors'], 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'mission' ),
			__( 'Settings', 'mission' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this->pages['settings'], 'render' )
		);
	}

	/**
	 * Get menu icon URL as base64-encoded SVG data URI.
	 *
	 * @return string
	 */
	private function get_menu_icon_url(): string {
		$logo_path = plugin_dir_path( __DIR__ ) . '../assets/img/icon.svg';

		if ( ! file_exists( $logo_path ) ) {
			return 'dashicons-heart';
		}

		$svg_content = file_get_contents( $logo_path );
		if ( false === $svg_content ) {
			return 'dashicons-heart';
		}

		$base64 = base64_encode( $svg_content );
		return 'data:image/svg+xml;base64,' . $base64;
	}
}
