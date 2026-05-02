<?php
/**
 * Tests for the DonorsEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DonorsEndpoint test class.
 */
class DonorsEndpointTest extends WP_UnitTestCase {

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
	 * Default donor for tests.
	 *
	 * @var Donor
	 */
	private Donor $donor;

	/**
	 * Default campaign for tests.
	 *
	 * @var Campaign
	 */
	private Campaign $campaign;

	/**
	 * Hooks added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $hooks_to_remove = [];

	/**
	 * Filters added during tests that need cleanup.
	 *
	 * @var array<array{string, callable, int}>
	 */
	private array $filters_to_remove = [];

	/**
	 * Create tables once for all tests in this class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_campaigns" );

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

		// Create users.
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		// Create a default donor with address fields.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'address_1'  => '123 Main St',
			'address_2'  => 'Apt 4',
			'city'       => 'Portland',
			'state'      => 'OR',
			'zip'        => '97201',
			'country'    => 'US',
		] );
		$this->donor->save();

		// Create a default campaign.
		$this->campaign = new Campaign( [
			'title'       => 'General Fund',
			'goal_amount' => 100000,
		] );
		$this->campaign->save();

		// Configure settings.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => false,
			'stripe_site_token' => 'test_site_token_123',
		] );

		// Set currency.
		update_option( 'missiondp_currency', 'usd' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		delete_option( SettingsService::OPTION_NAME );
		delete_option( 'missiondp_currency' );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		foreach ( $this->filters_to_remove as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->filters_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a donor with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Donor
	 */
	private function create_donor( array $overrides = [] ): Donor {
		static $counter = 0;
		++$counter;

		$defaults = [
			'email'      => "donor{$counter}@example.com",
			'first_name' => "Donor{$counter}",
			'last_name'  => 'Test',
		];

		$donor = new Donor( array_merge( $defaults, $overrides ) );
		$donor->save();

		return $donor;
	}

	/**
	 * Create a completed transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                 => 'completed',
			'type'                   => 'one-time',
			'donor_id'               => $this->donor->id,
			'campaign_id'            => $this->campaign->id,
			'amount'                 => 2500,
			'tip_amount'             => 0,
			'fee_amount'             => 0,
			'total_amount'           => 2500,
			'currency'               => 'usd',
			'payment_gateway'        => 'stripe',
			'gateway_transaction_id' => 'pi_test_' . wp_rand(),
			'is_test'                => false,
			'date_completed'         => current_time( 'mysql', true ),
		];

		$transaction = new Transaction( array_merge( $defaults, $overrides ) );
		$transaction->save();

		return $transaction;
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

	/**
	 * Dispatch a POST request with JSON body.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Body parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a PUT request with JSON body.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Body parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_put( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Register a filter and track it for automatic cleanup.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_tracked_filter( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 10 );
		$this->filters_to_remove[] = [ $hook, $callback, $priority ];
	}

	// =========================================================================
	// GET /donors (list) — 4 tests
	// =========================================================================

	/**
	 * Test GET list returns paginated donors.
	 */
	public function test_get_list_returns_paginated_donors(): void {
		// Create transactions so each donor passes the has_transactions filter.
		$this->create_transaction( [ 'donor_id' => $this->donor->id ] );

		$donor2 = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor2->id ] );

		$donor3 = $this->create_donor();
		$this->create_transaction( [ 'donor_id' => $donor3->id ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors', [ 'per_page' => 2 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );

		// Verify expected keys on list items.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'email', $item );
		$this->assertArrayHasKey( 'first_name', $item );
		$this->assertArrayHasKey( 'last_name', $item );
		$this->assertArrayHasKey( 'phone', $item );
		$this->assertArrayHasKey( 'total_donated', $item );
		$this->assertArrayHasKey( 'transaction_count', $item );
		$this->assertArrayHasKey( 'last_transaction', $item );
		$this->assertArrayHasKey( 'date_created', $item );
		$this->assertArrayHasKey( 'gravatar_hash', $item );
	}

	/**
	 * Test GET list search by name and email.
	 */
	public function test_get_list_search_by_name_and_email(): void {
		$alice = $this->create_donor( [
			'email'      => 'alice@example.com',
			'first_name' => 'Alice',
			'last_name'  => 'Wonder',
		] );
		$this->create_transaction( [ 'donor_id' => $alice->id ] );

		$bob = $this->create_donor( [
			'email'      => 'bob@example.com',
			'first_name' => 'Bob',
			'last_name'  => 'Builder',
		] );
		$this->create_transaction( [ 'donor_id' => $bob->id ] );

		// Search by partial name.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors', [ 'search' => 'Alice' ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Alice', $data[0]['first_name'] );

		// Search by email.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors', [ 'search' => 'bob@example.com' ] );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Bob', $data[0]['first_name'] );
	}

	/**
	 * Test GET list test mode filtering.
	 */
	public function test_get_list_test_mode_stats(): void {
		// Donor with live transactions only.
		$live_donor = $this->create_donor( [
			'email'      => 'live@example.com',
			'first_name' => 'Live',
			'last_name'  => 'Donor',
		] );
		$this->create_transaction( [ 'donor_id' => $live_donor->id, 'is_test' => false, 'amount' => 5000 ] );

		// Donor with test transactions only.
		$test_donor = $this->create_donor( [
			'email'      => 'test@example.com',
			'first_name' => 'Test',
			'last_name'  => 'Donor',
		] );
		$this->create_transaction( [ 'donor_id' => $test_donor->id, 'is_test' => true, 'amount' => 3000 ] );

		// All donors appear regardless of mode — mode only affects which stats are shown.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors' );
		$data     = $response->get_data();

		// All 3 donors visible (Jane from setUp + Live + Test).
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );

		// In live mode, live donor shows live stats.
		$live_item = $this->find_donor_in_response( $data, 'Live' );
		$this->assertSame( 5000, $live_item['total_donated'] );
		$this->assertSame( 1, $live_item['transaction_count'] );

		// Test donor shows 0 stats in live mode (their transactions are test-only).
		$test_item = $this->find_donor_in_response( $data, 'Test' );
		$this->assertSame( 0, $test_item['total_donated'] );
		$this->assertSame( 0, $test_item['transaction_count'] );
	}

	/**
	 * Find a donor in the response data by first name.
	 *
	 * @param array  $data       Response data array.
	 * @param string $first_name First name to search for.
	 * @return array|null
	 */
	private function find_donor_in_response( array $data, string $first_name ): ?array {
		foreach ( $data as $item ) {
			if ( $item['first_name'] === $first_name ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Test GET list ordering by total_donated.
	 */
	public function test_get_list_ordering(): void {
		$low = $this->create_donor( [
			'email'      => 'low@example.com',
			'first_name' => 'Low',
		] );
		$this->create_transaction( [ 'donor_id' => $low->id, 'amount' => 1000, 'total_amount' => 1000 ] );

		$high = $this->create_donor( [
			'email'      => 'high@example.com',
			'first_name' => 'High',
		] );
		$this->create_transaction( [ 'donor_id' => $high->id, 'amount' => 50000, 'total_amount' => 50000 ] );

		// ASC order — Jane (0) first, then Low, then High.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors', [
			'orderby' => 'total_donated',
			'order'   => 'ASC',
		] );
		$data = $response->get_data();

		$this->assertSame( 'Jane', $data[0]['first_name'] );
		$this->assertSame( 'Low', $data[1]['first_name'] );
		$this->assertSame( 'High', $data[2]['first_name'] );

		// DESC order — High first.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors', [
			'orderby' => 'total_donated',
			'order'   => 'DESC',
		] );
		$data = $response->get_data();

		$this->assertSame( 'High', $data[0]['first_name'] );
		$this->assertSame( 'Low', $data[1]['first_name'] );
		$this->assertSame( 'Jane', $data[2]['first_name'] );
	}

	// =========================================================================
	// GET /donors/{id} (single) — 2 tests
	// =========================================================================

	/**
	 * Test GET single returns full donor with aggregates.
	 */
	public function test_get_single_returns_full_donor_with_aggregates(): void {
		$response = $this->dispatch_get( "/mission-donation-platform/v1/donors/{$this->donor->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Core fields.
		$this->assertSame( $this->donor->id, $data['id'] );
		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );

		// Detail-specific keys.
		$this->assertArrayHasKey( 'is_recurring', $data );
		$this->assertArrayHasKey( 'is_top_donor', $data );
		$this->assertArrayHasKey( 'since_label', $data );
		$this->assertArrayHasKey( 'first_transaction', $data );
		$this->assertArrayHasKey( 'date_modified', $data );

		// Aggregate fields.
		$this->assertArrayHasKey( 'total_donated', $data );
		$this->assertArrayHasKey( 'transaction_count', $data );

		// Address fields.
		$this->assertSame( '123 Main St', $data['address_1'] );
		$this->assertSame( 'Apt 4', $data['address_2'] );
		$this->assertSame( 'Portland', $data['city'] );
		$this->assertSame( 'OR', $data['state'] );
		$this->assertSame( '97201', $data['zip'] );
		$this->assertSame( 'US', $data['country'] );

		// Gravatar hash.
		$this->assertSame(
			md5( strtolower( trim( 'jane@example.com' ) ) ),
			$data['gravatar_hash']
		);
	}

	/**
	 * Test GET single returns 404 for nonexistent donor.
	 */
	public function test_get_single_404_for_nonexistent(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'donor_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// POST /donors (create) — 3 tests
	// =========================================================================

	/**
	 * Test POST create with required fields.
	 */
	public function test_post_create_with_required_fields(): void {
		$response = $this->dispatch_post( '/mission-donation-platform/v1/donors', [
			'email'      => 'new@example.com',
			'first_name' => 'New',
			'last_name'  => 'Person',
		] );
		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'donor_id', $data );

		// Verify persisted in DB.
		$donor = Donor::find( $data['donor_id'] );
		$this->assertNotNull( $donor );
		$this->assertSame( 'new@example.com', $donor->email );
		$this->assertSame( 'New', $donor->first_name );
		$this->assertSame( 'Person', $donor->last_name );
	}

	/**
	 * Test POST create rejects duplicate email.
	 */
	public function test_post_create_rejects_duplicate_email(): void {
		$response = $this->dispatch_post( '/mission-donation-platform/v1/donors', [
			'email'      => 'jane@example.com', // Already exists from set_up().
			'first_name' => 'Another',
			'last_name'  => 'Jane',
		] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'duplicate_donor', $response->as_error()->get_error_code() );
	}

	/**
	 * Test POST create validates email format.
	 */
	public function test_post_create_validates_email_format(): void {
		$response = $this->dispatch_post( '/mission-donation-platform/v1/donors', [
			'email'      => 'not-an-email',
			'first_name' => 'Bad',
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// PUT /donors/{id} (update) — 2 tests
	// =========================================================================

	/**
	 * Test PUT update allowed fields.
	 */
	public function test_put_update_allowed_fields(): void {
		$response = $this->dispatch_put( "/mission-donation-platform/v1/donors/{$this->donor->id}", [
			'first_name' => 'Janet',
			'last_name'  => 'Smith',
			'phone'      => '555-1234',
			'address_1'  => '456 Oak Ave',
			'city'       => 'Seattle',
			'state'      => 'WA',
			'zip'        => '98101',
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Verify response has updated values.
		$this->assertSame( 'Janet', $data['first_name'] );
		$this->assertSame( 'Smith', $data['last_name'] );
		$this->assertSame( '555-1234', $data['phone'] );
		$this->assertSame( '456 Oak Ave', $data['address_1'] );
		$this->assertSame( 'Seattle', $data['city'] );
		$this->assertSame( 'WA', $data['state'] );
		$this->assertSame( '98101', $data['zip'] );

		// Verify persisted in DB.
		$updated = Donor::find( $this->donor->id );
		$this->assertSame( 'Janet', $updated->first_name );
		$this->assertSame( 'Smith', $updated->last_name );
		$this->assertSame( '555-1234', $updated->phone );
	}

	/**
	 * Test PUT update email uniqueness.
	 */
	public function test_put_update_email_uniqueness(): void {
		$other = $this->create_donor( [
			'email'      => 'other@example.com',
			'first_name' => 'Other',
		] );

		// Try to change default donor's email to one that already exists.
		$response = $this->dispatch_put( "/mission-donation-platform/v1/donors/{$this->donor->id}", [
			'email' => 'other@example.com',
		] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'duplicate_donor', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// GET /donors/summary — 1 test
	// =========================================================================

	/**
	 * Test GET summary returns donor stats.
	 */
	public function test_get_summary_returns_donor_stats(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors/summary' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'total_donors', $data );
		$this->assertArrayHasKey( 'top_donor_name', $data );
		$this->assertArrayHasKey( 'top_donor_total', $data );
		$this->assertArrayHasKey( 'average_donated', $data );
		$this->assertArrayHasKey( 'repeat_donors', $data );
	}

	// =========================================================================
	// Permissions — 2 tests
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_requests_rejected(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test subscriber role is rejected.
	 */
	public function test_subscriber_role_rejected(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/donors' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
