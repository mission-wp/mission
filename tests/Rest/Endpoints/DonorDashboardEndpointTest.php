<?php
/**
 * Tests for the DonorDashboardEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Subscription;
use Mission\Models\Transaction;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * DonorDashboardEndpoint test class.
 */
class DonorDashboardEndpointTest extends WP_UnitTestCase {

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
	 * Donor WP user ID.
	 *
	 * @var int
	 */
	private int $donor_user_id;

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
	 * Create tables and register role once for all tests.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptionmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mission_campaigns" );

		DatabaseModule::create_tables();

		// Ensure the mission_donor role exists for these tests.
		if ( ! get_role( 'mission_donor' ) ) {
			add_role( 'mission_donor', 'Donor', [] );
		}
	}

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create admin user.
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Create donor WP user with mission_donor role.
		$this->donor_user_id = self::factory()->user->create( [
			'role'       => 'mission_donor',
			'user_email' => 'jane@example.com',
		] );

		// Create donor record linked to WP user.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'user_id'    => $this->donor_user_id,
			'address_1'  => '123 Main St',
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
		update_option( SettingsService::OPTION_NAME, [ 'test_mode' => false ] );
		update_option( 'mission_currency', 'usd' );

		// Default: authenticate as donor.
		wp_set_current_user( $this->donor_user_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_activity_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transaction_history" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_notes" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mission_campaigns" );

		delete_option( SettingsService::OPTION_NAME );
		delete_option( 'mission_currency' );

		foreach ( $this->hooks_to_remove as [ $hook, $callback, $priority ] ) {
			remove_action( $hook, $callback, $priority );
		}
		$this->hooks_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a completed transaction with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Transaction
	 */
	private function create_transaction( array $overrides = [] ): Transaction {
		$defaults = [
			'status'                 => 'completed',
			'type'                   => 'one_time',
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
	 * Create a subscription with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$subscription = new Subscription( array_merge( [
			'status'                  => 'active',
			'donor_id'                => $this->donor->id,
			'campaign_id'             => $this->campaign->id,
			'amount'                  => 2500,
			'fee_amount'              => 0,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'currency'                => 'usd',
			'frequency'               => 'monthly',
			'payment_gateway'         => 'stripe',
			'gateway_subscription_id' => 'sub_test_' . wp_rand(),
			'is_test'                 => false,
		], $overrides ) );
		$subscription->save();

		return $subscription;
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
	 * Dispatch a PUT request.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Request body.
	 * @return \WP_REST_Response
	 */
	private function dispatch_put( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a POST request.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Request body.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a DELETE request.
	 *
	 * @param string $route Route path.
	 * @return \WP_REST_Response
	 */
	private function dispatch_delete( string $route ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', $route );

		return $this->server->dispatch( $request );
	}

	/**
	 * Register an action hook and track it for automatic cleanup.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	private function add_tracked_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, $priority, $accepted_args );
		$this->hooks_to_remove[] = [ $hook, $callback, $priority ];
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	/**
	 * Test unauthenticated request returns 401.
	 */
	public function test_unauthenticated_returns_401(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_not_logged_in', $response->as_error()->get_error_code() );
	}

	/**
	 * Test admin user returns 403.
	 */
	public function test_admin_returns_403(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test donor user returns 200.
	 */
	public function test_donor_returns_200(): void {
		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Overview
	// -------------------------------------------------------------------------

	/**
	 * Test overview returns correct stats and recent transactions.
	 */
	public function test_overview_returns_stats_and_recent(): void {
		// Create 7 transactions.
		for ( $i = 0; $i < 7; $i++ ) {
			$this->create_transaction( [ 'amount' => 1000, 'total_amount' => 1000 ] );
		}

		// Update donor aggregate fields.
		$this->donor->total_donated     = 7000;
		$this->donor->transaction_count = 7;
		$this->donor->save();

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7000, $data['stats']['total_donated'] );
		$this->assertSame( 7, $data['stats']['transaction_count'] );
		$this->assertSame( 1000, $data['stats']['average_donation'] );
		$this->assertCount( 5, $data['recent_transactions'] );
	}

	/**
	 * Test overview only returns active subscriptions.
	 */
	public function test_overview_returns_active_subscriptions(): void {
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['active_subscriptions'] );
		$this->assertSame( 'active', $data['active_subscriptions'][0]['status'] );
	}

	/**
	 * Test overview for donor with no data.
	 */
	public function test_overview_empty_donor(): void {
		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/overview' );
		$data     = $response->get_data();

		$this->assertSame( 0, $data['stats']['total_donated'] );
		$this->assertSame( 0, $data['stats']['transaction_count'] );
		$this->assertSame( 0, $data['stats']['average_donation'] );
		$this->assertCount( 0, $data['recent_transactions'] );
		$this->assertCount( 0, $data['active_subscriptions'] );
	}

	// -------------------------------------------------------------------------
	// Transactions
	// -------------------------------------------------------------------------

	/**
	 * Test transactions returns paginated results with headers.
	 */
	public function test_transactions_returns_paginated(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->create_transaction();
		}

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/transactions', [ 'per_page' => 10 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $data );
		$this->assertSame( '25', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Test transactions filter by year.
	 */
	public function test_transactions_filter_by_year(): void {
		$this->create_transaction( [ 'date_created' => '2025-06-15 12:00:00' ] );
		$this->create_transaction( [ 'date_created' => '2025-11-01 12:00:00' ] );
		$this->create_transaction( [ 'date_created' => '2026-02-10 12:00:00' ] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/transactions', [ 'year' => 2025 ] );
		$data     = $response->get_data();

		$this->assertCount( 2, $data );
	}

	/**
	 * Test transactions filter by type.
	 */
	public function test_transactions_filter_by_type(): void {
		$this->create_transaction( [ 'type' => 'one_time' ] );
		$this->create_transaction( [ 'type' => 'monthly' ] );
		$this->create_transaction( [ 'type' => 'monthly' ] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/transactions', [ 'type' => 'monthly' ] );
		$data     = $response->get_data();

		$this->assertCount( 2, $data );
		$this->assertSame( 'monthly', $data[0]['type'] );
	}

	/**
	 * Test transactions filter by type=recurring excludes one_time.
	 */
	public function test_transactions_filter_by_type_recurring(): void {
		$this->create_transaction( [ 'type' => 'one_time' ] );
		$this->create_transaction( [ 'type' => 'monthly' ] );
		$this->create_transaction( [ 'type' => 'quarterly' ] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/transactions', [ 'type' => 'recurring' ] );
		$data     = $response->get_data();

		$this->assertCount( 2, $data );
		foreach ( $data as $txn ) {
			$this->assertNotSame( 'one_time', $txn['type'] );
		}
	}

	/**
	 * Test transactions filter by campaign.
	 */
	public function test_transactions_filter_by_campaign(): void {
		$other_campaign = new Campaign( [ 'title' => 'Other', 'goal_amount' => 50000 ] );
		$other_campaign->save();

		$this->create_transaction( [ 'campaign_id' => $this->campaign->id ] );
		$this->create_transaction( [ 'campaign_id' => $other_campaign->id ] );

		$response = $this->dispatch_get(
			'/mission/v1/donor-dashboard/transactions',
			[ 'campaign_id' => $this->campaign->id ]
		);
		$data = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'General Fund', $data[0]['campaign_name'] );
	}

	// -------------------------------------------------------------------------
	// Subscriptions
	// -------------------------------------------------------------------------

	/**
	 * Test subscriptions returns computed fields.
	 */
	public function test_subscriptions_returns_all_with_computed_fields(): void {
		$sub = $this->create_subscription( [
			'amount'        => 2500,
			'total_amount'  => 2500,
			'renewal_count' => 3,
			'total_renewed' => 7500,
		] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/subscriptions' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 10000, $data[0]['total_given'] );
		$this->assertSame( 'General Fund', $data[0]['campaign_name'] );
		$this->assertSame( 3, $data[0]['renewal_count'] );
	}

	/**
	 * Test subscriptions excludes test data.
	 */
	public function test_subscriptions_excludes_test(): void {
		$this->create_subscription( [ 'is_test' => false ] );
		$this->create_subscription( [ 'is_test' => true ] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/subscriptions' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
	}

	// -------------------------------------------------------------------------
	// Receipts
	// -------------------------------------------------------------------------

	/**
	 * Test receipts returns annual summaries.
	 */
	public function test_receipts_returns_annual_summaries(): void {
		$this->create_transaction( [
			'amount'         => 3000,
			'total_amount'   => 3000,
			'date_completed' => '2025-06-15 12:00:00',
		] );
		$this->create_transaction( [
			'amount'         => 5000,
			'total_amount'   => 5000,
			'date_completed' => '2026-02-10 12:00:00',
		] );

		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/receipts' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		// Ordered DESC — 2026 first.
		$this->assertSame( 2026, $data[0]['year'] );
		$this->assertSame( 5000, $data[0]['total'] );
		$this->assertSame( 2025, $data[1]['year'] );
		$this->assertSame( 3000, $data[1]['total'] );
	}

	// -------------------------------------------------------------------------
	// Profile
	// -------------------------------------------------------------------------

	/**
	 * Test get profile returns fields and preference defaults.
	 */
	public function test_get_profile_returns_fields_and_preferences(): void {
		$response = $this->dispatch_get( '/mission/v1/donor-dashboard/profile' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'Portland', $data['city'] );
		$this->assertArrayHasKey( 'gravatar_hash', $data );

		// Check preference defaults.
		$this->assertTrue( $data['preferences']['email_receipts'] );
		$this->assertTrue( $data['preferences']['email_campaign_updates'] );
		$this->assertTrue( $data['preferences']['email_annual_reminder'] );
	}

	/**
	 * Test update profile saves fields.
	 */
	public function test_update_profile_saves_fields(): void {
		$response = $this->dispatch_put( '/mission/v1/donor-dashboard/profile', [
			'first_name' => 'Janet',
			'last_name'  => 'Smith',
			'city'       => 'Seattle',
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Janet', $data['first_name'] );
		$this->assertSame( 'Smith', $data['last_name'] );
		$this->assertSame( 'Seattle', $data['city'] );

		// Verify persisted.
		$fresh = Donor::find( $this->donor->id );
		$this->assertSame( 'Janet', $fresh->first_name );
	}

	/**
	 * Test update profile does not accept email changes.
	 */
	public function test_update_profile_does_not_change_email(): void {
		$this->dispatch_put( '/mission/v1/donor-dashboard/profile', [
			'email' => 'newemail@example.com',
		] );

		$fresh = Donor::find( $this->donor->id );
		$this->assertSame( 'jane@example.com', $fresh->email );
	}

	// -------------------------------------------------------------------------
	// Preferences
	// -------------------------------------------------------------------------

	/**
	 * Test update preferences saves meta.
	 */
	public function test_update_preferences_saves_meta(): void {
		$response = $this->dispatch_put( '/mission/v1/donor-dashboard/preferences', [
			'email_receipts' => false,
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['email_receipts'] );

		// Verify meta persisted.
		$fresh = Donor::find( $this->donor->id );
		$this->assertSame( '0', $fresh->get_meta( 'email_receipts' ) );
	}

	// -------------------------------------------------------------------------
	// Delete Account
	// -------------------------------------------------------------------------

	/**
	 * Test delete account unlinks WP user and preserves donor record.
	 */
	public function test_delete_account_unlinks_wp_user(): void {
		$response = $this->dispatch_delete( '/mission/v1/donor-dashboard/account' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Donor record preserved with null user_id.
		$fresh = Donor::find( $this->donor->id );
		$this->assertNotNull( $fresh );
		$this->assertNull( $fresh->user_id );

		// WP user deleted.
		$this->assertFalse( get_userdata( $this->donor_user_id ) );
	}

	/**
	 * Test delete account fires action hook.
	 */
	public function test_delete_account_fires_action(): void {
		$fired = false;

		$this->add_tracked_action(
			'mission_donor_account_deleted',
			function ( $donor, $user_id ) use ( &$fired ) {
				$fired = true;
				$this->assertInstanceOf( Donor::class, $donor );
				$this->assertIsInt( $user_id );
			},
			10,
			2,
		);

		$this->dispatch_delete( '/mission/v1/donor-dashboard/account' );

		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Cancel
	// -------------------------------------------------------------------------

	/**
	 * Test cancel subscription requires authentication.
	 */
	public function test_cancel_subscription_requires_auth(): void {
		wp_set_current_user( 0 );
		$sub = $this->create_subscription();

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/cancel" );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test cancel subscription requires donor role.
	 */
	public function test_cancel_subscription_requires_donor_role(): void {
		wp_set_current_user( $this->admin_id );
		$sub = $this->create_subscription();

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/cancel" );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test cancel subscription ownership check.
	 */
	public function test_cancel_subscription_ownership_check(): void {
		// Create a subscription owned by a different donor.
		$other_donor = new Donor( [
			'email'      => 'other@example.com',
			'first_name' => 'Other',
		] );
		$other_donor->save();

		$sub = $this->create_subscription( [ 'donor_id' => $other_donor->id ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/cancel" );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test cancel active subscription succeeds.
	 */
	public function test_cancel_subscription_success(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/cancel" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'cancelled', $data['status'] );

		$fresh = Subscription::find( $sub->id );
		$this->assertSame( 'cancelled', $fresh->status );
		$this->assertNotNull( $fresh->date_cancelled );
	}

	/**
	 * Test cancel already cancelled subscription returns 400.
	 */
	public function test_cancel_subscription_already_cancelled(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/cancel" );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test cancel subscription returns 404 for nonexistent ID.
	 */
	public function test_cancel_subscription_not_found(): void {
		$response = $this->dispatch_post( '/mission/v1/donor-dashboard/subscriptions/99999/cancel' );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Pause
	// -------------------------------------------------------------------------

	/**
	 * Test pause active subscription succeeds.
	 */
	public function test_pause_subscription_success(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/pause" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'paused', $data['status'] );

		$fresh = Subscription::find( $sub->id );
		$this->assertSame( 'paused', $fresh->status );
	}

	/**
	 * Test pause non-active subscription returns 400.
	 */
	public function test_pause_subscription_not_active(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/pause" );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test pause already paused subscription returns 400.
	 */
	public function test_pause_subscription_already_paused(): void {
		$sub = $this->create_subscription( [ 'status' => 'paused' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/pause" );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Resume
	// -------------------------------------------------------------------------

	/**
	 * Test resume paused subscription succeeds.
	 */
	public function test_resume_subscription_success(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'paused',
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/resume" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'active', $data['status'] );

		$fresh = Subscription::find( $sub->id );
		$this->assertSame( 'active', $fresh->status );
		$this->assertNotNull( $fresh->date_next_renewal );
	}

	/**
	 * Test resume non-paused subscription returns 400.
	 */
	public function test_resume_subscription_not_paused(): void {
		$sub = $this->create_subscription( [ 'status' => 'active' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/resume" );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test resume cancelled subscription returns 400.
	 */
	public function test_resume_subscription_cancelled(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/resume" );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Update Amount
	// -------------------------------------------------------------------------

	/**
	 * Test update amount succeeds on active subscription.
	 */
	public function test_update_amount_success(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'amount'                  => 2500,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 5000,
				'tip_amount'      => 750,
			]
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5000, $data['amount'] );
		$this->assertSame( 750, $data['tip_amount'] );
		$this->assertSame( 5750, $data['total_amount'] );

		$fresh = Subscription::find( $sub->id );
		$this->assertSame( 5000, $fresh->amount );
		$this->assertSame( 750, $fresh->tip_amount );
		$this->assertSame( 5750, $fresh->total_amount );
	}

	/**
	 * Test update amount works on paused subscription.
	 */
	public function test_update_amount_works_when_paused(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'paused',
			'amount'                  => 2500,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 10000,
				'tip_amount'      => 0,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 10000, $response->get_data()['amount'] );
	}

	/**
	 * Test update amount fails on cancelled subscription.
	 */
	public function test_update_amount_fails_when_cancelled(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 5000,
				'tip_amount'      => 0,
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test update amount requires authentication.
	 */
	public function test_update_amount_requires_auth(): void {
		wp_set_current_user( 0 );
		$sub = $this->create_subscription();

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 5000,
				'tip_amount'      => 0,
			]
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test update amount ownership check.
	 */
	public function test_update_amount_ownership_check(): void {
		$other_donor = new Donor( [
			'email'      => 'other2@example.com',
			'first_name' => 'Other',
		] );
		$other_donor->save();

		$sub = $this->create_subscription( [ 'donor_id' => $other_donor->id ] );

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 5000,
				'tip_amount'      => 0,
			]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test update amount validates minimum.
	 */
	public function test_update_amount_validates_minimum(): void {
		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$response = $this->dispatch_put(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/amount",
			[
				'donation_amount' => 50,
				'tip_amount'      => 0,
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Setup Intent
	// -------------------------------------------------------------------------

	/**
	 * Test setup intent requires authentication.
	 */
	public function test_setup_intent_requires_auth(): void {
		wp_set_current_user( 0 );
		$sub = $this->create_subscription();

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/setup-intent" );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test setup intent ownership check.
	 */
	public function test_setup_intent_ownership_check(): void {
		$other_donor = new Donor( [
			'email'      => 'other-si@example.com',
			'first_name' => 'Other',
		] );
		$other_donor->save();

		$sub = $this->create_subscription( [ 'donor_id' => $other_donor->id ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/setup-intent" );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test setup intent fails when cancelled.
	 */
	public function test_setup_intent_fails_when_cancelled(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/setup-intent" );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test setup intent success with mocked API response.
	 */
	public function test_setup_intent_success(): void {
		update_option( SettingsService::OPTION_NAME, [ 'stripe_site_token' => 'tok_test' ] );

		$sub = $this->create_subscription( [
			'status'              => 'active',
			'gateway_customer_id' => 'cus_test_123',
		] );

		$mock = function () {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [
					'client_secret'      => 'seti_secret_abc',
					'connected_account_id' => 'acct_test_456',
				] ),
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$this->hooks_to_remove[] = [ 'pre_http_request', $mock, 10 ];

		$response = $this->dispatch_post( "/mission/v1/donor-dashboard/subscriptions/{$sub->id}/setup-intent" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'seti_secret_abc', $data['client_secret'] );
		$this->assertSame( 'acct_test_456', $data['connected_account_id'] );
	}

	// -------------------------------------------------------------------------
	// Subscription Actions — Update Payment Method
	// -------------------------------------------------------------------------

	/**
	 * Test update payment method requires authentication.
	 */
	public function test_update_payment_method_requires_auth(): void {
		wp_set_current_user( 0 );
		$sub = $this->create_subscription();

		$response = $this->dispatch_post(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/payment-method",
			[ 'payment_method_id' => 'pm_test' ]
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test update payment method ownership check.
	 */
	public function test_update_payment_method_ownership_check(): void {
		$other_donor = new Donor( [
			'email'      => 'other-pm@example.com',
			'first_name' => 'Other',
		] );
		$other_donor->save();

		$sub = $this->create_subscription( [ 'donor_id' => $other_donor->id ] );

		$response = $this->dispatch_post(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/payment-method",
			[ 'payment_method_id' => 'pm_test' ]
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test update payment method fails when cancelled.
	 */
	public function test_update_payment_method_fails_when_cancelled(): void {
		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/payment-method",
			[ 'payment_method_id' => 'pm_test' ]
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test update payment method success with mocked API response.
	 */
	public function test_update_payment_method_success(): void {
		update_option( SettingsService::OPTION_NAME, [ 'stripe_site_token' => 'tok_test' ] );

		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => 'sub_test_pm',
		] );

		$mock = function () {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [
					'status' => 'updated',
					'card'   => [
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2028,
					],
				] ),
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$this->hooks_to_remove[] = [ 'pre_http_request', $mock, 10 ];

		$response = $this->dispatch_post(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/payment-method",
			[ 'payment_method_id' => 'pm_new_card' ]
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'visa', $data['brand'] );
		$this->assertSame( '4242', $data['last4'] );
		$this->assertSame( 12, $data['exp_month'] );
		$this->assertSame( 2028, $data['exp_year'] );

		// Verify meta was persisted.
		$fresh = Subscription::find( $sub->id );
		$this->assertSame( 'visa', $fresh->get_meta( 'payment_method_brand' ) );
		$this->assertSame( '4242', $fresh->get_meta( 'payment_method_last4' ) );
		$this->assertSame( '12', $fresh->get_meta( 'payment_method_exp_month' ) );
		$this->assertSame( '2028', $fresh->get_meta( 'payment_method_exp_year' ) );
	}

	/**
	 * Test update payment method fires action hook.
	 */
	public function test_update_payment_method_fires_action(): void {
		update_option( SettingsService::OPTION_NAME, [ 'stripe_site_token' => 'tok_test' ] );

		$sub = $this->create_subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => 'sub_test_hook',
		] );

		$mock = function () {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [
					'status' => 'updated',
					'card'   => [
						'brand'     => 'mastercard',
						'last4'     => '5555',
						'exp_month' => 6,
						'exp_year'  => 2027,
					],
				] ),
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$this->hooks_to_remove[] = [ 'pre_http_request', $mock, 10 ];

		$fired = false;
		$this->add_tracked_action( 'mission_subscription_payment_method_updated', function () use ( &$fired ) {
			$fired = true;
		} );

		$this->dispatch_post(
			"/mission/v1/donor-dashboard/subscriptions/{$sub->id}/payment-method",
			[ 'payment_method_id' => 'pm_hook_test' ]
		);

		$this->assertTrue( $fired );
	}
}
