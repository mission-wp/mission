<?php
/**
 * Tests for the DashboardEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\ActivityLog;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DashboardEndpoint test class.
 */
class DashboardEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );

		DatabaseModule::create_tables();
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => false,
			'currency'          => 'USD',
			'stripe_site_token' => 'test_site_token_123',
		] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a GET request.
	 *
	 * @param string $route  Route path.
	 * @param array  $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_get( string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Create a completed transaction within the current period.
	 *
	 * @param int   $amount    Amount in cents.
	 * @param int   $donor_id  Donor ID.
	 * @param array $overrides Additional overrides.
	 * @return Transaction
	 */
	private function create_transaction( int $amount, int $donor_id, array $overrides = [] ): Transaction {
		$transaction = new Transaction( array_merge(
			[
				'status'         => 'completed',
				'donor_id'       => $donor_id,
				'source_post_id' => 1,
				'amount'         => $amount,
				'total_amount'   => $amount,
				'date_created'   => gmdate( 'Y-m-d H:i:s' ),
				'date_completed' => gmdate( 'Y-m-d H:i:s' ),
			],
			$overrides
		) );
		$transaction->save();

		return $transaction;
	}

	// =========================================================================
	// GET /dashboard — 5 tests
	// =========================================================================

	/**
	 * Test GET returns stats (total raised, donor count, transaction count).
	 */
	public function test_get_returns_stats(): void {
		$donor1 = new Donor( [ 'email' => 'a@example.com', 'first_name' => 'A' ] );
		$donor1->save();
		$donor2 = new Donor( [ 'email' => 'b@example.com', 'first_name' => 'B' ] );
		$donor2->save();

		$this->create_transaction( 5000, $donor1->id );
		$this->create_transaction( 3000, $donor2->id );

		$response = $this->dispatch_get( '/mission/v1/dashboard' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'stats', $data );

		$stats = $data['stats'];
		$this->assertArrayHasKey( 'total_donations', $stats );
		$this->assertArrayHasKey( 'total_donors', $stats );
		$this->assertArrayHasKey( 'average_donation', $stats );
		$this->assertSame( 8000, $stats['total_donations'] );
		$this->assertSame( 2, $stats['total_donors'] );
		$this->assertSame( 4000, $stats['average_donation'] );
	}

	/**
	 * Test GET returns activity feed entries.
	 */
	public function test_get_returns_activity_feed(): void {
		$entry = new ActivityLog( [
			'event'       => 'donation_completed',
			'object_type' => 'transaction',
			'object_id'   => 1,
			'data'        => wp_json_encode( [ 'amount' => 5000 ] ),
			'is_test'     => false,
		] );
		$entry->save();

		$response = $this->dispatch_get( '/mission/v1/dashboard' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'activity', $data );
		$this->assertCount( 1, $data['activity'] );

		$activity_item = $data['activity'][0];
		$this->assertSame( 'donation_completed', $activity_item['event'] );
		$this->assertSame( 'transaction', $activity_item['object_type'] );
		$this->assertIsArray( $activity_item['data'] );
		$this->assertSame( 5000, $activity_item['data']['amount'] );
	}

	/**
	 * Test GET accepts period parameter for date range filtering.
	 */
	public function test_get_accepts_period_parameter(): void {
		// Just verify each valid period returns a 200 response with the expected shape.
		foreach ( [ 'today', 'week', 'month' ] as $period ) {
			$response = $this->dispatch_get( '/mission/v1/dashboard', [ 'period' => $period ] );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), "Period '{$period}' should return 200." );
			$this->assertArrayHasKey( 'stats', $data );
			$this->assertArrayHasKey( 'chart', $data );
			$this->assertArrayHasKey( 'campaigns', $data );
			$this->assertArrayHasKey( 'activity', $data );
			$this->assertArrayHasKey( 'stripe_connected', $data );
			$this->assertArrayHasKey( 'currency', $data );
		}
	}

	/**
	 * Test GET returns previous period comparison data.
	 */
	public function test_get_returns_previous_period_comparison(): void {
		$response = $this->dispatch_get( '/mission/v1/dashboard' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$stats = $data['stats'];
		$this->assertArrayHasKey( 'total_donations_previous', $stats );
		$this->assertArrayHasKey( 'total_donors_previous', $stats );
		$this->assertArrayHasKey( 'average_donation_previous', $stats );
	}

	/**
	 * Test GET test mode filtering excludes live activity in test mode.
	 */
	public function test_get_test_mode_filtering(): void {
		// Create a live activity entry and a test activity entry.
		$live = new ActivityLog( [
			'event'       => 'donation_completed',
			'object_type' => 'transaction',
			'object_id'   => 1,
			'is_test'     => false,
		] );
		$live->save();

		$test = new ActivityLog( [
			'event'       => 'donation_completed',
			'object_type' => 'transaction',
			'object_id'   => 2,
			'is_test'     => true,
		] );
		$test->save();

		// In live mode, only live activity should appear.
		$response = $this->dispatch_get( '/mission/v1/dashboard' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['activity'] );
		$this->assertSame( 1, $data['activity'][0]['object_id'] );

		// Switch to test mode.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => true,
			'currency'          => 'USD',
			'stripe_site_token' => 'test_site_token_123',
		] );

		$response = $this->dispatch_get( '/mission/v1/dashboard' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['activity'] );
		$this->assertSame( 2, $data['activity'][0]['object_id'] );
	}

	// =========================================================================
	// Permissions — 1 test
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_permissions_unauthenticated_rejected(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch_get( '/mission/v1/dashboard' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		// Subscriber role.
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_get( '/mission/v1/dashboard' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
