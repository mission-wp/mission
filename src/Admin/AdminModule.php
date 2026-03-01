<?php
/**
 * Admin module - handles admin functionality.
 *
 * @package Mission
 */

namespace Mission\Admin;

use Mission\Admin\Pages\CampaignsPage;
use Mission\Admin\Pages\DashboardPage;
use Mission\Admin\Pages\TransactionsPage;
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
	 * Submenu slug for Transactions.
	 */
	public const TRANSACTIONS_SLUG = 'mission-transactions';

	/**
	 * Submenu slug for Campaigns.
	 */
	public const CAMPAIGNS_SLUG = 'mission-campaigns';

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
			'dashboard'    => new DashboardPage(),
			'campaigns'    => new CampaignsPage(),
			'transactions' => new TransactionsPage(),
			'donors'       => new DonorsPage(),
			'settings'     => new SettingsPage(),
		);

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'load-edit.php', array( $this, 'redirect_campaign_list' ) );
		add_filter( 'parent_file', array( $this, 'set_campaign_parent_menu' ) );
		add_filter( 'submenu_file', array( $this, 'set_campaign_submenu_file' ) );
	}

	/**
	 * Enqueue admin scripts and styles on Mission pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$screen = get_current_screen();

		if ( ! $screen || ! $this->is_mission_screen( $screen->id ) ) {
			return;
		}

		$asset_file = MISSION_PATH . 'admin/build/mission-admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mission-admin',
			MISSION_URL . 'admin/build/mission-admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'mission-admin-vendor',
			MISSION_URL . 'admin/build/style-mission-admin.css',
			array(),
			$asset['version']
		);

		wp_enqueue_style(
			'mission-admin',
			MISSION_URL . 'admin/build/mission-admin.css',
			array( 'wp-components', 'mission-admin-vendor' ),
			$asset['version']
		);

		$settings = get_option( 'mission_settings', array() );

		wp_localize_script(
			'mission-admin',
			'missionAdmin',
			array(
				'restUrl'          => rest_url( 'mission/v1/' ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'adminUrl'         => admin_url(),
				'page'             => $screen->id,
				'version'          => MISSION_VERSION,
				'currency'         => $settings['currency'] ?? 'USD',
				'stripeConnectUrl' => 'https://api.missionwp.com/connect/start?' . http_build_query(
					array(
						'domain'     => wp_parse_url( home_url(), PHP_URL_HOST ),
						'return_url' => admin_url( 'admin.php?page=mission-settings' ),
					)
				),
			)
		);
	}

	/**
	 * Check if the given screen ID belongs to a Mission admin page.
	 *
	 * @param string $screen_id The screen ID to check.
	 *
	 * @return bool
	 */
	private function is_mission_screen( string $screen_id ): bool {
		$mission_screens = array(
			'toplevel_page_mission',
			'mission_page_mission-campaigns',
			'mission_page_mission-transactions',
			'mission_page_mission-donors',
			'mission_page_mission-settings',
			'mission_campaign',
		);

		return in_array( $screen_id, $mission_screens, true );
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
			__( 'Transactions', 'mission' ),
			__( 'Transactions', 'mission' ),
			'manage_options',
			self::TRANSACTIONS_SLUG,
			array( $this->pages['transactions'], 'render' )
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
	 * Redirect the default CPT list table to the custom Campaigns page.
	 *
	 * @return void
	 */
	public function redirect_campaign_list(): void {
		$screen = get_current_screen();

		if ( $screen && 'mission_campaign' === $screen->post_type ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::CAMPAIGNS_SLUG ) );
			exit;
		}
	}

	/**
	 * Highlight the Mission menu when viewing campaign CPT screens.
	 *
	 * @param string $parent_file The parent file slug.
	 *
	 * @return string
	 */
	public function set_campaign_parent_menu( string $parent_file ): string {
		$screen = get_current_screen();

		if ( $screen && 'mission_campaign' === $screen->post_type ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlight the Campaigns submenu when viewing campaign CPT screens.
	 *
	 * @param string|null $submenu_file The submenu file slug.
	 *
	 * @return string|null
	 */
	public function set_campaign_submenu_file( ?string $submenu_file ): ?string {
		$screen = get_current_screen();

		if ( $screen && 'mission_campaign' === $screen->post_type ) {
			return self::CAMPAIGNS_SLUG;
		}

		return $submenu_file;
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, not a remote URL.
		$svg_content = file_get_contents( $logo_path );
		if ( false === $svg_content ) {
			return 'dashicons-heart';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding SVG for data URI, not obfuscation.
		$base64 = base64_encode( $svg_content );
		return 'data:image/svg+xml;base64,' . $base64;
	}
}
