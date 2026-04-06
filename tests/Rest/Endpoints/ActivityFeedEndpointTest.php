<?php
/**
 * Tests for the ActivityFeedEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\ActivityLog;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ActivityFeedEndpoint test class.
 */
class ActivityFeedEndpointTest extends WP_UnitTestCase {

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
	 * Create an activity log entry with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return ActivityLog
	 */
	private function create_entry( array $overrides = [] ): ActivityLog {
		$defaults = [
			'event'       => 'donation_completed',
			'object_type' => 'transaction',
			'object_id'   => 1,
			'is_test'     => false,
		];

		$entry = new ActivityLog( array_merge( $defaults, $overrides ) );
		$entry->save();

		return $entry;
	}

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

	// =========================================================================
	// GET /activity — 5 tests
	// =========================================================================

	/**
	 * Test GET returns paginated activity log entries.
	 */
	public function test_get_returns_paginated_entries(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_entry( [ 'object_id' => $i ] );
		}

		$response = $this->dispatch_get( '/mission/v1/activity', [ 'per_page' => 2 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		// Verify pagination headers.
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );

		// Verify response shape.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'event', $item );
		$this->assertArrayHasKey( 'object_type', $item );
		$this->assertArrayHasKey( 'object_id', $item );
		$this->assertArrayHasKey( 'actor_id', $item );
		$this->assertArrayHasKey( 'data', $item );
		$this->assertArrayHasKey( 'date_created', $item );
	}

	/**
	 * Test GET page parameter returns correct page of results.
	 */
	public function test_get_page_parameter_returns_correct_page(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_entry( [ 'object_id' => $i ] );
		}

		// Page 1 of 2.
		$page1 = $this->dispatch_get( '/mission/v1/activity', [ 'per_page' => 2, 'page' => 1 ] );
		$this->assertCount( 2, $page1->get_data() );

		// Page 2 of 2.
		$page2 = $this->dispatch_get( '/mission/v1/activity', [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertCount( 1, $page2->get_data() );

		// No overlap between pages.
		$page1_ids = array_column( $page1->get_data(), 'id' );
		$page2_ids = array_column( $page2->get_data(), 'id' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	/**
	 * Test GET filters by object_type.
	 */
	public function test_get_filters_by_object_type(): void {
		$this->create_entry( [ 'object_type' => 'transaction', 'object_id' => 1 ] );
		$this->create_entry( [ 'object_type' => 'transaction', 'object_id' => 2 ] );
		$this->create_entry( [ 'object_type' => 'donor', 'object_id' => 1 ] );

		$response = $this->dispatch_get( '/mission/v1/activity', [ 'object_type' => 'transaction' ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		foreach ( $data as $item ) {
			$this->assertSame( 'transaction', $item['object_type'] );
		}
	}

	/**
	 * Test GET filters by object_id.
	 */
	public function test_get_filters_by_object_id(): void {
		$this->create_entry( [ 'object_id' => 42 ] );
		$this->create_entry( [ 'object_id' => 42 ] );
		$this->create_entry( [ 'object_id' => 99 ] );

		$response = $this->dispatch_get( '/mission/v1/activity', [ 'object_id' => 42 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );

		foreach ( $data as $item ) {
			$this->assertSame( 42, $item['object_id'] );
		}
	}

	/**
	 * Test GET filters by object_type and object_id combined.
	 */
	public function test_get_filters_by_object_type_and_id(): void {
		$this->create_entry( [ 'object_type' => 'transaction', 'object_id' => 5 ] );
		$this->create_entry( [ 'object_type' => 'donor', 'object_id' => 5 ] );
		$this->create_entry( [ 'object_type' => 'transaction', 'object_id' => 10 ] );

		$response = $this->dispatch_get( '/mission/v1/activity', [
			'object_type' => 'transaction',
			'object_id'   => 5,
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'transaction', $data[0]['object_type'] );
		$this->assertSame( 5, $data[0]['object_id'] );
	}

	/**
	 * Test GET test mode filtering.
	 */
	public function test_get_test_mode_filtering(): void {
		$this->create_entry( [ 'is_test' => false, 'object_id' => 1 ] );
		$this->create_entry( [ 'is_test' => true, 'object_id' => 2 ] );

		// Live mode — only live entries.
		$response = $this->dispatch_get( '/mission/v1/activity' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 1, $data[0]['object_id'] );

		// Switch to test mode.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => true,
			'stripe_site_token' => 'test_site_token_123',
		] );

		$response = $this->dispatch_get( '/mission/v1/activity' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 2, $data[0]['object_id'] );
	}

	// =========================================================================
	// Permissions — 1 test
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_permissions_unauthenticated_rejected(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch_get( '/mission/v1/activity' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		// Subscriber role.
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_get( '/mission/v1/activity' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
