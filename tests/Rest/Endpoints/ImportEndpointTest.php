<?php
/**
 * Tests for the import REST endpoints.
 *
 * Focused on the contracts that protect users: capability checks on every
 * route, upload rejection rules, expired upload handling, per-user job
 * scoping, and the in-flight concurrency guard.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Import endpoint test class.
 */
class ImportEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var \WP_REST_Server
	 */
	private $server;

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Second admin user (for cross-user scoping tests).
	 *
	 * @var int
	 */
	private int $other_admin_id;

	/**
	 * Subscriber user.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Temp files created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_import_jobs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donors" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = new \WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );

		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->other_admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $this->admin_id );

		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = [];

		$uploads = wp_get_upload_dir();
		$stored  = glob( trailingslashit( $uploads['basedir'] ) . 'mission-imports/mdp_job_*' );
		foreach ( $stored ?: [] as $file ) {
			unlink( $file );
		}

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_import_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );

		delete_option( SettingsService::OPTION_NAME );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Write a small donors CSV and return its $_FILES-style array.
	 *
	 * @param int $rows Number of donor rows.
	 * @return array
	 */
	private function make_upload( int $rows = 3 ): array {
		$path     = wp_tempnam( 'mission-test-upload' );
		$csv_path = $path . '.csv';
		rename( $path, $csv_path );

		$handle = fopen( $csv_path, 'w' );
		fputcsv( $handle, [ 'Email', 'First Name' ] );
		for ( $i = 1; $i <= $rows; $i++ ) {
			fputcsv( $handle, [ "endpoint{$i}@example.com", "First{$i}" ] );
		}
		fclose( $handle );

		$this->temp_files[] = $csv_path;

		return [
			'name'     => basename( $csv_path ),
			'tmp_name' => $csv_path,
			'size'     => filesize( $csv_path ),
			'type'     => 'text/csv',
			'error'    => 0,
		];
	}

	/**
	 * Dispatch a validate upload request.
	 *
	 * @param array $file $_FILES-style array.
	 * @return \WP_REST_Response
	 */
	private function dispatch_validate( array $file ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/import/validate' );
		$request->set_param( 'type', 'donors' );
		$request->set_file_params( [ 'file' => $file ] );

		return $this->server->dispatch( $request );
	}

	/**
	 * Validate and start an import, returning the start response.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch_start(): \WP_REST_Response {
		$validated = $this->dispatch_validate( $this->make_upload() );
		$this->assertSame( 200, $validated->get_status() );

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/import/start' );
		$request->set_param( 'file_id', $validated->get_data()['file_id'] );
		$request->set_param( 'duplicate_strategy', 'skip' );

		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Test every import route rejects users without manage_options.
	 */
	public function test_all_routes_require_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		// Well-formed requests, so the capability check is the only thing
		// that can reject them (WP validates required params before the
		// permission callback runs).
		$routes = [
			[ 'GET', '/mission-donation-platform/v1/import/columns', [ 'type' => 'donors' ] ],
			[ 'POST', '/mission-donation-platform/v1/import/validate', [ 'type' => 'donors' ] ],
			[ 'POST', '/mission-donation-platform/v1/import/start', [ 'file_id' => 'mdp_imp_x', 'duplicate_strategy' => 'skip' ] ],
			[ 'GET', '/mission-donation-platform/v1/import/status', [ 'job_id' => 'mdp_job_x' ] ],
			[ 'POST', '/mission-donation-platform/v1/import/cancel', [ 'job_id' => 'mdp_job_x' ] ],
			[ 'GET', '/mission-donation-platform/v1/import/active', [ 'type' => 'donors' ] ],
		];

		foreach ( $routes as [ $method, $route, $params ] ) {
			$request = new WP_REST_Request( $method, $route );
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}

			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status(), "{$method} {$route} should be forbidden for subscribers." );
		}
	}

	// -------------------------------------------------------------------------
	// Upload validation
	// -------------------------------------------------------------------------

	/**
	 * Test a valid upload returns the preview contract.
	 */
	public function test_validate_returns_preview_contract(): void {
		$response = $this->dispatch_validate( $this->make_upload( 3 ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 3, $data['rows_detected'] );
		$this->assertSame( 3, $data['rows_importable'] );
		$this->assertSame( [], $data['columns_unmatched'] );
		$this->assertStringStartsWith( 'mdp_imp_', $data['file_id'] );
	}

	/**
	 * Test oversized uploads and unsupported extensions are rejected.
	 */
	public function test_validate_rejects_oversized_and_unsupported_files(): void {
		$oversized         = $this->make_upload();
		$oversized['size'] = 11 * 1024 * 1024;

		$response = $this->dispatch_validate( $oversized );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_too_large', $response->get_data()['code'] );

		$wrong_ext         = $this->make_upload();
		$wrong_ext['name'] = 'donors.xlsx';

		$response = $this->dispatch_validate( $wrong_ext );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_extension', $response->get_data()['code'] );
	}

	/**
	 * Test starting with an expired or forged file_id fails cleanly.
	 *
	 * Upload transients expire after an hour, so this is a real user path,
	 * not just an attack surface.
	 */
	public function test_start_with_expired_file_id_fails_cleanly(): void {
		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/import/start' );
		$request->set_param( 'file_id', 'mdp_imp_expiredorforged1' );
		$request->set_param( 'duplicate_strategy', 'skip' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_expired', $response->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Job scoping and lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Test job status and cancel are invisible to other users, even admins.
	 */
	public function test_job_status_and_cancel_are_scoped_to_the_owner(): void {
		$started = $this->dispatch_start();
		$this->assertSame( 202, $started->get_status() );
		$job_id = $started->get_data()['job_id'];

		wp_set_current_user( $this->other_admin_id );

		$status = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/import/status' );
		$status->set_param( 'job_id', $job_id );
		$response = $this->server->dispatch( $status );

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayNotHasKey( 'imported', (array) $response->get_data() );

		$cancel = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/import/cancel' );
		$cancel->set_param( 'job_id', $job_id );
		$this->assertSame( 404, $this->server->dispatch( $cancel )->get_status() );

		// The owner still sees it.
		wp_set_current_user( $this->admin_id );
		$status = new WP_REST_Request( 'GET', '/mission-donation-platform/v1/import/status' );
		$status->set_param( 'job_id', $job_id );
		$this->assertSame( 200, $this->server->dispatch( $status )->get_status() );
	}

	/**
	 * Test a second concurrent import of the same type is refused with 409.
	 */
	public function test_concurrent_import_of_same_type_returns_conflict(): void {
		// Keep the first job unfinished so it stays "active".
		add_filter( 'missiondp_import_batch_size', fn() => 1 );

		$first = $this->dispatch_start();
		$this->assertSame( 202, $first->get_status() );

		$second = $this->dispatch_start();

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'import_in_progress', $second->get_data()['code'] );

		remove_all_filters( 'missiondp_import_batch_size' );
	}
}
