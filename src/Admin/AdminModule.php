<?php
/**
 * Admin module - handles admin functionality.
 *
 * @package Mission
 */

namespace Mission\Admin;

use Mission\Admin\Pages\CampaignsPage;
use Mission\Admin\Pages\DashboardPage;
use Mission\Admin\Pages\DonorsPage;
use Mission\Admin\Pages\SettingsPage;
use Mission\Admin\Pages\SubscriptionsPage;
use Mission\Admin\Pages\ToolsPage;
use Mission\Admin\Pages\TransactionsPage;

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
	 * Submenu slug for Subscriptions.
	 */
	public const SUBSCRIPTIONS_SLUG = 'mission-subscriptions';

	/**
	 * Submenu slug for Settings.
	 */
	public const SETTINGS_SLUG = 'mission-settings';

	/**
	 * Submenu slug for Tools.
	 */
	public const TOOLS_SLUG = 'mission-tools';

	/**
	 * Page instances.
	 *
	 * @var array<string, AdminPage>
	 */
	private array $pages = [];

	/**
	 * Initialize the admin module.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->pages = [
			'dashboard'     => new DashboardPage(),
			'campaigns'     => new CampaignsPage(),
			'transactions'  => new TransactionsPage(),
			'donors'        => new DonorsPage(),
			'subscriptions' => new SubscriptionsPage(),
			'settings'      => new SettingsPage(),
			'tools'         => new ToolsPage(),
		];

		add_action( 'init', [ $this, 'register_feature_signup_meta' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_bar_menu', [ $this, 'add_test_mode_indicator' ], 999 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_test_mode_admin_bar_css' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_test_mode_admin_bar_css' ] );
		add_action( 'load-edit.php', [ $this, 'redirect_campaign_list' ] );
		add_filter( 'parent_file', [ $this, 'set_campaign_parent_menu' ] );
		add_filter( 'submenu_file', [ $this, 'set_campaign_submenu_file' ] );
		add_filter( 'plugin_action_links_' . MISSION_BASENAME, [ $this, 'add_plugin_action_links' ] );
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

		wp_enqueue_media();

		// Enqueue code editor (CodeMirror) for the email template HTML editor.
		if ( 'missionwp_page_mission-settings' === $screen->id ) {
			$code_editor_settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );

			if ( false !== $code_editor_settings ) {
				wp_localize_script( 'code-editor', 'missionCodeEditor', $code_editor_settings );
			}
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
			[],
			$asset['version']
		);

		wp_enqueue_style(
			'mission-admin',
			MISSION_URL . 'admin/build/mission-admin.css',
			[ 'wp-components', 'mission-admin-vendor' ],
			$asset['version']
		);

		// Enqueue block editor assets when viewing a campaign detail page.
		$this->maybe_enqueue_block_editor( $screen );

		$settings = get_option( 'mission_settings', [] );

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		wp_localize_script(
			'mission-admin',
			'missionAdmin',
			[
				'restUrl'                   => rest_url( 'mission/v1/' ),
				'restNonce'                 => wp_create_nonce( 'wp_rest' ),
				'adminUrl'                  => admin_url(),
				'page'                      => $screen->id,
				'version'                   => MISSION_VERSION,
				'currency'                  => $settings['currency'] ?? 'USD',
				'testMode'                  => ! empty( $settings['test_mode'] ),
				'stripeConnected'           => ( $settings['stripe_connection_status'] ?? 'disconnected' ) === 'connected',
				'stripeConnectUrl'          => 'https://api.missionwp.com/connect/start?' . http_build_query(
					[
						'domain'     => $domain,
						'return_url' => admin_url( 'admin.php?page=mission-settings' ),
					]
				),
				'stripeConnectUrlDashboard' => 'https://api.missionwp.com/connect/start?' . http_build_query(
					[
						'domain'     => $domain,
						'return_url' => admin_url( 'admin.php?page=mission' ),
					]
				),
				'onboardingCompleted'       => ! empty( $settings['onboarding_completed'] ),
				'orgName'                   => $settings['org_name'] ?? get_bloginfo( 'name' ),
				'orgCountry'                => $settings['org_country'] ?? 'US',
				'adminEmail'                => get_option( 'admin_email' ),
				'featureSignups'            => self::get_feature_signups(),
			]
		);
	}

	/**
	 * Enqueue block editor styles and settings for the campaign detail page.
	 *
	 * @param \WP_Screen $screen The current admin screen.
	 */
	private function maybe_enqueue_block_editor( \WP_Screen $screen ): void {
		if ( 'missionwp_page_mission-campaigns' !== $screen->id ) {
			return;
		}

		// Only load on the campaign detail view (has ?campaign=ID).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for page context.
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		if ( ! $campaign_id ) {
			return;
		}

		// Enqueue block editor UI, editor chrome, and block-level styles.
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-editor' );
		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-format-library' );

		// Fire the block editor assets action so registered blocks
		// get their editor scripts/styles enqueued.
		do_action( 'enqueue_block_editor_assets' );

		// Build editor settings and inline them for the JS block editor.
		$block_editor_context = new \WP_Block_Editor_Context( [ 'name' => 'mission/campaign-editor' ] );
		$editor_settings      = get_block_editor_settings(
			[],
			$block_editor_context
		);

		wp_add_inline_script(
			'mission-admin',
			'window.missionEditorSettings = ' . wp_json_encode( $editor_settings ) . ';',
			'before'
		);

		// Register the "mission" block category in the JS store before block
		// scripts run. Without this, registerBlockType() warns about an
		// invalid category because BlockEditorProvider hasn't mounted yet.
		wp_add_inline_script(
			'wp-blocks',
			'( function() {' .
				'var c = wp.blocks.getCategories();' .
				'if ( ! c.some( function( cat ) { return cat.slug === "mission"; } ) ) {' .
					'wp.blocks.setCategories( [ { slug: "mission", title: "MissionWP" } ].concat( c ) );' .
				'}' .
			'} )();',
			'after'
		);

		// Bootstrap server-side block definitions so registerBlockType()
		// calls in block scripts can merge the full metadata (title, category, etc.).
		$block_definitions = get_block_editor_server_block_settings();
		wp_add_inline_script(
			'wp-blocks',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( $block_definitions ) . ');',
			'after'
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
		$mission_screens = [
			'toplevel_page_mission',
			'missionwp_page_mission-campaigns',
			'missionwp_page_mission-transactions',
			'missionwp_page_mission-donors',
			'missionwp_page_mission-subscriptions',
			'missionwp_page_mission-settings',
			'missionwp_page_mission-tools',
			'mission_campaign',
		];

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
			__( 'MissionWP', 'missionwp-donation-platform' ),
			__( 'MissionWP', 'missionwp-donation-platform' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this->pages['dashboard'], 'render' ],
			$icon_url,
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'missionwp-donation-platform' ),
			__( 'Dashboard', 'missionwp-donation-platform' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this->pages['dashboard'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'missionwp-donation-platform' ),
			__( 'Campaigns', 'missionwp-donation-platform' ),
			'manage_options',
			self::CAMPAIGNS_SLUG,
			[ $this->pages['campaigns'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Transactions', 'missionwp-donation-platform' ),
			__( 'Transactions', 'missionwp-donation-platform' ),
			'manage_options',
			self::TRANSACTIONS_SLUG,
			[ $this->pages['transactions'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donors', 'missionwp-donation-platform' ),
			__( 'Donors', 'missionwp-donation-platform' ),
			'manage_options',
			self::DONORS_SLUG,
			[ $this->pages['donors'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Subscriptions', 'missionwp-donation-platform' ),
			__( 'Subscriptions', 'missionwp-donation-platform' ),
			'manage_options',
			self::SUBSCRIPTIONS_SLUG,
			[ $this->pages['subscriptions'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'missionwp-donation-platform' ),
			__( 'Settings', 'missionwp-donation-platform' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ $this->pages['settings'], 'render' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tools', 'missionwp-donation-platform' ),
			__( 'Tools', 'missionwp-donation-platform' ),
			'manage_options',
			self::TOOLS_SLUG,
			[ $this->pages['tools'], 'render' ]
		);
	}

	/**
	 * Add a "Settings" link to the plugin action links on the Plugins page.
	 *
	 * @param array<string, string> $links Existing plugin action links.
	 *
	 * @return array<string, string>
	 */
	public function add_plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
			__( 'Settings', 'missionwp-donation-platform' )
		);

		return [ 'settings' => $settings_link ] + $links;
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
	 * Add a test mode indicator to the admin bar.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 *
	 * @return void
	 */
	public function add_test_mode_indicator( \WP_Admin_Bar $admin_bar ): void {
		$settings = get_option( 'mission_settings', [] );
		$is_test  = ! empty( $settings['test_mode'] );

		$admin_bar->add_menu(
			[
				'id'     => 'mission-test-mode',
				'parent' => 'top-secondary',
				'title'  => __( 'MissionWP Test Mode Active', 'missionwp-donation-platform' ),
				'href'   => admin_url( 'admin.php?page=mission-settings' ),
				'meta'   => [
					'class' => 'mission-test-mode-indicator' . ( $is_test ? '' : ' hidden' ),
				],
			]
		);
	}

	/**
	 * Attach inline CSS for the test mode admin bar indicator to the core admin-bar stylesheet.
	 *
	 * @return void
	 */
	public function enqueue_test_mode_admin_bar_css(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css = '#wpadminbar .mission-test-mode-indicator .ab-item{background:#d63638!important;color:#fff!important;font-weight:600}'
			. '#wpadminbar .mission-test-mode-indicator:hover .ab-item{background:#b32d2e!important;color:#fff!important}'
			. '#wpadminbar .mission-test-mode-indicator.hidden{display:none}';

		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Redirect to the Mission dashboard after first-time activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'mission_do_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'mission_do_activation_redirect' );

		if (
			wp_doing_ajax()
			|| wp_doing_cron()
			|| defined( 'REST_REQUEST' )
			|| isset( $_GET['activate-multi'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! current_user_can( 'manage_options' )
		) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mission' ) );
		exit;
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

	/**
	 * Register feature signup user meta keys for the REST API.
	 *
	 * @return void
	 */
	public function register_feature_signup_meta(): void {
		foreach ( [ 'import', 'migration', 'features' ] as $feature ) {
			register_meta(
				'user',
				'mission_feature_signup_' . $feature,
				[
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_email',
					'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
				]
			);
		}
	}

	/**
	 * Get the current user's feature signup state.
	 *
	 * Returns an object mapping feature keys to the email used to sign up,
	 * or an empty object if the user hasn't signed up for anything.
	 *
	 * @return array<string, string>
	 */
	private static function get_feature_signups(): array {
		$user_id = get_current_user_id();
		$signups = [];

		foreach ( [ 'import', 'migration', 'features' ] as $feature ) {
			$email = get_user_meta( $user_id, 'mission_feature_signup_' . $feature, true );
			if ( $email ) {
				$signups[ $feature ] = $email;
			}
		}

		return $signups;
	}
}
