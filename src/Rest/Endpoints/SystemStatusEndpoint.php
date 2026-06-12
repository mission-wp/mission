<?php
/**
 * REST endpoint for the system status page.
 *
 * @package MissionDP
 */

namespace MissionDP\Rest\Endpoints;

use MissionDP\Currency\Currency;
use MissionDP\Database\DatabaseModule;
use MissionDP\Database\Schema;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use MissionDP\Rest\RestModule;
use MissionDP\Rest\Traits\AdminPermissionTrait;
use MissionDP\Settings\SettingsService;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * System status endpoint class.
 *
 * Gathers environment, server, database, plugin, and theme information
 * for the Tools > Status admin panel and support reports.
 */
class SystemStatusEndpoint {

	use AdminPermissionTrait;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 */
	public function __construct(
		private SettingsService $settings,
	) {}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			RestModule::NAMESPACE,
			'/system-status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);
	}

	/**
	 * Return system status data.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		$data = [
			'mission_donation_platform' => $this->get_mission_environment(),
			'wordpress'                 => $this->get_wordpress_environment(),
			'server'                    => $this->get_server_environment(),
			'database'                  => $this->get_database_info(),
			'plugins'                   => $this->get_active_plugins(),
			'theme'                     => $this->get_theme_info(),
		];

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Gather Mission-specific environment data.
	 *
	 * @return array
	 */
	private function get_mission_environment(): array {
		$currency  = $this->settings->get( 'currency', 'USD' );
		$symbol    = Currency::get_symbol( $currency );
		$accounts  = $this->settings->get_stripe_accounts_public();
		$test_mode = (bool) $this->settings->get( 'test_mode', false );
		$webhook   = ! empty( $this->settings->get( 'stripe_webhook_secret', '' ) );

		return [
			'version'                   => MISSIONDP_VERSION,
			'db_version'                => get_option( DatabaseModule::DB_VERSION_OPTION, '' ),
			'stripe_connected'          => ! empty( $accounts ),
			'stripe_accounts'           => $accounts,
			'stripe_mode'               => $test_mode ? 'test' : 'live',
			'stripe_webhook_configured' => $webhook,
			'webhook_url'               => rest_url( RestModule::NAMESPACE . '/webhook' ),
			'test_mode'                 => $test_mode,
			'currency'                  => $currency,
			'currency_symbol'           => $symbol,
			'active_campaigns'          => Campaign::count( [ 'status' => Campaign::STATUS_ACTIVE ] ),
			'total_donors'              => Donor::count(),
			'total_transactions'        => Transaction::count(),
		];
	}

	/**
	 * Gather WordPress environment data.
	 *
	 * @return array
	 */
	private function get_wordpress_environment(): array {
		$timezone_string = wp_timezone_string();
		$timezone        = new \DateTimeZone( $timezone_string );
		$utc_offset      = $timezone->getOffset( new \DateTimeImmutable( 'now', $timezone ) ) / 3600;
		$offset_label    = 'UTC' . ( $utc_offset >= 0 ? '+' : '' ) . $utc_offset;

		return [
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'version'      => get_bloginfo( 'version' ),
			'multisite'    => is_multisite(),
			'memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'Unknown', 'mission-donation-platform' ),
			'debug_mode'   => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'cron'         => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'language'     => get_locale(),
			'timezone'     => $timezone_string,
			'utc_offset'   => $offset_label,
		];
	}

	/**
	 * Gather server environment data.
	 *
	 * @return array
	 */
	private function get_server_environment(): array {
		global $wpdb;

		$curl_info = function_exists( 'curl_version' ) ? curl_version() : null;

		return [
			'software'               => sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? __( 'Unknown', 'mission-donation-platform' ) ) ),
			'php_version'            => phpversion(),
			'php_memory_limit'       => ini_get( 'memory_limit' ),
			'php_max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'php_max_input_vars'     => (int) ini_get( 'max_input_vars' ),
			'php_max_upload_size'    => ini_get( 'upload_max_filesize' ),
			'mysql_version'          => $wpdb->db_server_info(),
			'curl_version'           => $curl_info ? $curl_info['version'] . ', ' . $curl_info['ssl_version'] : __( 'Not available', 'mission-donation-platform' ),
			'fsockopen'              => function_exists( 'fsockopen' ),
			'curl'                   => function_exists( 'curl_init' ),
			'domdocument'            => class_exists( 'DOMDocument' ),
			'gzip'                   => extension_loaded( 'zlib' ),
		];
	}

	/**
	 * Gather database information including table sizes.
	 *
	 * @return array
	 */
	private function get_database_info(): array {
		global $wpdb;

		$table_names = ( new Schema() )->get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema query, no WP API exists.
		$all_sizes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name, data_length, index_length
				FROM information_schema.tables
				WHERE table_schema = %s',
				$wpdb->dbname
			)
		);

		$total_size   = 0;
		$mission_size = 0;
		$table_map    = [];

		foreach ( $all_sizes as $row ) {
			$data_bytes  = (int) $row->data_length;
			$index_bytes = (int) $row->index_length;
			$total_size += $data_bytes + $index_bytes;

			if ( in_array( $row->table_name, $table_names, true ) ) {
				$mission_size                 += $data_bytes + $index_bytes;
				$table_map[ $row->table_name ] = [
					'data_size'  => round( $data_bytes / 1024 / 1024, 2 ),
					'index_size' => round( $index_bytes / 1024 / 1024, 2 ),
				];
			}
		}

		$tables = [];
		foreach ( $table_names as $name ) {
			$tables[] = [
				'name'       => $name,
				'data_size'  => $table_map[ $name ]['data_size'] ?? 0,
				'index_size' => $table_map[ $name ]['index_size'] ?? 0,
			];
		}

		return [
			'prefix'         => $wpdb->prefix,
			'total_size'     => round( $total_size / 1024 / 1024, 2 ),
			'missiondp_size' => round( $mission_size / 1024 / 1024, 2 ),
			'tables'         => $tables,
		];
	}

	/**
	 * Get active plugins list.
	 *
	 * @return array
	 */
	private function get_active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );
		$result         = [];

		foreach ( $active_plugins as $plugin_path ) {
			if ( ! isset( $all_plugins[ $plugin_path ] ) ) {
				continue;
			}

			$plugin   = $all_plugins[ $plugin_path ];
			$result[] = [
				'name'    => $plugin['Name'] ?? '',
				'author'  => wp_strip_all_tags( $plugin['AuthorName'] ?? $plugin['Author'] ?? '' ),
				'version' => $plugin['Version'] ?? '',
			];
		}

		return $result;
	}

	/**
	 * Get active theme information.
	 *
	 * @return array
	 */
	private function get_theme_info(): array {
		$theme = wp_get_theme();

		return [
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'author'      => $theme->get( 'Author' ),
			'child_theme' => is_child_theme(),
			'block_theme' => wp_is_block_theme(),
		];
	}
}
