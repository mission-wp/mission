<?php
/**
 * Admin module - handles admin functionality.
 *
 * @package Mission
 */

namespace Mission\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin module class.
 */
class AdminModule {

	/**
	 * Menu slug for the main Mission menu.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'mission';

	/**
	 * Initialize the admin module.
	 *
	 * @return void
	 */
	public function init(): void {
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
			array( $this, 'render_dashboard_page' ),
			$icon_url,
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'mission' ),
			__( 'Dashboard', 'mission' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
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

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Dashboard coming soon.', 'mission' ); ?></p>
		</div>
		<?php
	}
}
