<?php
/**
 * Tests for the TransactionsEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Database\DatabaseModule;
use Mission\Models\Campaign;
use Mission\Models\Donor;
use Mission\Models\Transaction;
use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TransactionsEndpoint test class.
 */
class TransactionsEndpointTest extends WP_UnitTestCase {

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

		// Create users.
		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		// Create a default donor.
		$this->donor = new Donor( [
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
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
			'test_mode'          => false,
			'stripe_fee_percent' => 2.9,
			'stripe_fee_fixed'   => 30,
		] );

		// Set currency.
		update_option( 'mission_currency', 'usd' );
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
			'amount'                 => 5000,
			'tip_amount'             => 0,
			'fee_amount'             => 0,
			'total_amount'           => 5000,
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
	 * Dispatch a PATCH request with JSON body.
	 *
	 * @param string $route Route path.
	 * @param array  $body  Body parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_patch( string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', $route );
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
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_tracked_action( string $hook, callable $callback, int $priority = 10 ): void {
		add_action( $hook, $callback, $priority );
		$this->hooks_to_remove[] = [ $hook, $callback, $priority ];
	}

	// =========================================================================
	// GET /transactions (list) — 6 tests
	// =========================================================================

	/**
	 * Test GET list returns paginated transactions.
	 */
	public function test_get_list_returns_paginated_transactions(): void {
		$this->create_transaction();
		$this->create_transaction();
		$this->create_transaction();

		$response = $this->dispatch_get( '/mission/v1/transactions', [ 'per_page' => 2 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );

		// Verify expected keys on list items.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'donor_name', $item );
		$this->assertArrayHasKey( 'donor_email', $item );
		$this->assertArrayHasKey( 'amount', $item );
		$this->assertArrayHasKey( 'currency', $item );
		$this->assertArrayHasKey( 'status', $item );
		$this->assertArrayHasKey( 'campaign_title', $item );
		$this->assertArrayHasKey( 'date_created', $item );
	}

	/**
	 * Test GET list filters by status.
	 */
	public function test_get_list_filters_by_status(): void {
		$this->create_transaction( [ 'status' => 'completed' ] );
		$this->create_transaction( [ 'status' => 'completed' ] );
		$this->create_transaction( [ 'status' => 'refunded' ] );

		$response = $this->dispatch_get( '/mission/v1/transactions', [ 'status' => 'refunded' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'refunded', $response->get_data()[0]['status'] );
	}

	/**
	 * Test GET list filters by campaign_id.
	 */
	public function test_get_list_filters_by_campaign_id(): void {
		$campaign2 = new Campaign( [ 'title' => 'Building Fund', 'goal_amount' => 50000 ] );
		$campaign2->save();

		$this->create_transaction( [ 'campaign_id' => $this->campaign->id ] );
		$this->create_transaction( [ 'campaign_id' => $campaign2->id ] );

		$response = $this->dispatch_get( '/mission/v1/transactions', [ 'campaign_id' => $campaign2->id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( $campaign2->id, $response->get_data()[0]['campaign_id'] );
	}

	/**
	 * Test GET list filters by donor_id.
	 */
	public function test_get_list_filters_by_donor_id(): void {
		$donor2 = new Donor( [
			'email'      => 'bob@example.com',
			'first_name' => 'Bob',
			'last_name'  => 'Smith',
		] );
		$donor2->save();

		$this->create_transaction( [ 'donor_id' => $this->donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor2->id ] );

		$response = $this->dispatch_get( '/mission/v1/transactions', [ 'donor_id' => $donor2->id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Bob Smith', $response->get_data()[0]['donor_name'] );
	}

	/**
	 * Test GET list search by donor name/email.
	 */
	public function test_get_list_search_by_donor_name_email(): void {
		$donor2 = new Donor( [
			'email'      => 'bob@example.com',
			'first_name' => 'Bob',
			'last_name'  => 'Smith',
		] );
		$donor2->save();

		$this->create_transaction( [ 'donor_id' => $this->donor->id ] );
		$this->create_transaction( [ 'donor_id' => $donor2->id ] );

		$response = $this->dispatch_get( '/mission/v1/transactions', [ 'search' => 'bob' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Bob Smith', $response->get_data()[0]['donor_name'] );
	}

	/**
	 * Test GET list test mode filtering.
	 */
	public function test_get_list_test_mode_filtering(): void {
		$this->create_transaction( [ 'is_test' => false ] );
		$this->create_transaction( [ 'is_test' => true ] );

		// Live mode (default) — only live transactions.
		$response = $this->dispatch_get( '/mission/v1/transactions' );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		// Switch to test mode — only test transactions.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode'          => true,
			'stripe_fee_percent' => 2.9,
			'stripe_fee_fixed'   => 30,
		] );

		$response = $this->dispatch_get( '/mission/v1/transactions' );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	// =========================================================================
	// GET /transactions/{id} (single) — 2 tests
	// =========================================================================

	/**
	 * Test GET single returns full transaction with all expected keys.
	 */
	public function test_get_single_returns_full_transaction(): void {
		$transaction = $this->create_transaction();

		$response = $this->dispatch_get( "/mission/v1/transactions/{$transaction->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Core fields.
		$this->assertSame( $transaction->id, $data['id'] );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 'one_time', $data['type'] );
		$this->assertSame( 5000, $data['amount'] );
		$this->assertSame( 'usd', $data['currency'] );
		$this->assertSame( 'stripe', $data['payment_gateway'] );

		// Calculated fields.
		$this->assertArrayHasKey( 'net_amount', $data );
		$this->assertArrayHasKey( 'processing_fee', $data );
		$this->assertArrayHasKey( 'fee_recovered', $data );
		$this->assertArrayHasKey( 'adjusted_tip', $data );

		// Sub-objects.
		$this->assertIsArray( $data['donor'] );
		$this->assertSame( $this->donor->id, $data['donor']['id'] );
		$this->assertSame( 'Jane', $data['donor']['first_name'] );

		$this->assertIsArray( $data['campaign'] );
		$this->assertSame( $this->campaign->id, $data['campaign']['id'] );
		$this->assertSame( 'General Fund', $data['campaign']['title'] );

		// Billing address and meta.
		$this->assertIsArray( $data['billing_address'] );
		$this->assertArrayHasKey( 'meta', $data );
	}

	/**
	 * Test GET single returns 404 for nonexistent transaction.
	 */
	public function test_get_single_404_for_nonexistent(): void {
		$response = $this->dispatch_get( '/mission/v1/transactions/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transaction_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// Calculated fields — 4 tests
	// =========================================================================

	/**
	 * Test net_amount without fee recovery.
	 *
	 * amount=5000, fee_amount=0 → processing_fee=round(5000*0.029+30)=175, net_amount=5000-175=4825
	 */
	public function test_net_amount_without_fee_recovery(): void {
		$transaction = $this->create_transaction( [
			'amount'       => 5000,
			'fee_amount'   => 0,
			'total_amount' => 5000,
		] );

		$response = $this->dispatch_get( "/mission/v1/transactions/{$transaction->id}" );
		$data     = $response->get_data();

		$this->assertSame( 175, $data['processing_fee'] );
		$this->assertSame( 4825, $data['net_amount'] );
	}

	/**
	 * Test processing_fee with fee recovery.
	 *
	 * fee_amount=175 → processing_fee=175, net_amount=5000 (donor covered fees)
	 */
	public function test_processing_fee_with_fee_recovery(): void {
		$transaction = $this->create_transaction( [
			'amount'       => 5000,
			'fee_amount'   => 175,
			'total_amount' => 5175,
		] );

		$response = $this->dispatch_get( "/mission/v1/transactions/{$transaction->id}" );
		$data     = $response->get_data();

		$this->assertSame( 175, $data['processing_fee'] );
		$this->assertSame( 5000, $data['net_amount'] );
	}

	/**
	 * Test fee_recovered equals fee_amount.
	 */
	public function test_fee_recovered_equals_fee_amount(): void {
		$transaction = $this->create_transaction( [
			'amount'       => 5000,
			'fee_amount'   => 200,
			'total_amount' => 5200,
		] );

		$response = $this->dispatch_get( "/mission/v1/transactions/{$transaction->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $data['fee_recovered'] );
	}

	/**
	 * Test adjusted tip calculation.
	 *
	 * amount=5000, fee_amount=175, tip=500, total=5675
	 * stripe_fee_with=round(5675*0.029+30)=195, stripe_fee_without=round(5175*0.029+30)=180
	 * adjusted_tip=500-(195-180)=485
	 */
	public function test_adjusted_tip_calculation(): void {
		$transaction = $this->create_transaction( [
			'amount'       => 5000,
			'fee_amount'   => 175,
			'tip_amount'   => 500,
			'total_amount' => 5675,
		] );

		$response = $this->dispatch_get( "/mission/v1/transactions/{$transaction->id}" );
		$data     = $response->get_data();

		$this->assertSame( 485, $data['adjusted_tip'] );
	}

	// =========================================================================
	// POST /transactions (create) — 5 tests
	// =========================================================================

	/**
	 * Test POST creates a manual transaction.
	 */
	public function test_post_create_manual_transaction(): void {
		$response = $this->dispatch_post( '/mission/v1/transactions', [
			'donor_email'      => 'newdonor@example.com',
			'donor_first_name' => 'New',
			'donor_last_name'  => 'Donor',
			'donation_amount'  => 10000,
			'campaign_id'      => $this->campaign->id,
		] );

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertIsInt( $data['transaction_id'] );

		// Verify persisted transaction.
		$transaction = Transaction::find( $data['transaction_id'] );
		$this->assertSame( 'completed', $transaction->status );
		$this->assertSame( 'manual', $transaction->payment_gateway );
		$this->assertSame( 10000, $transaction->amount );
		$this->assertSame( 10000, $transaction->total_amount );

		// Verify donor was created.
		$donor = Donor::find_by_email( 'newdonor@example.com' );
		$this->assertNotNull( $donor );
		$this->assertSame( 'New', $donor->first_name );
	}

	/**
	 * Test POST validates donation_amount is required.
	 *
	 * The schema defines donation_amount as required. Omitting it returns 400.
	 */
	public function test_post_create_validates_amount(): void {
		$response = $this->dispatch_post( '/mission/v1/transactions', [
			'donor_email'      => 'test@example.com',
			'donor_first_name' => 'Test',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test POST validates email format.
	 */
	public function test_post_create_validates_email(): void {
		$response = $this->dispatch_post( '/mission/v1/transactions', [
			'donor_email'      => 'not-an-email',
			'donor_first_name' => 'Test',
			'donation_amount'  => 5000,
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->as_error()->get_error_code() );
	}

	/**
	 * Test POST fires mission_transaction_created hook.
	 */
	public function test_post_create_fires_hooks(): void {
		$hook_fired = false;
		$hook_txn   = null;

		$this->add_tracked_action(
			'mission_transaction_created',
			function ( $transaction ) use ( &$hook_fired, &$hook_txn ) {
				$hook_fired = true;
				$hook_txn   = $transaction;
			}
		);

		$this->dispatch_post( '/mission/v1/transactions', [
			'donor_email'      => 'hooktest@example.com',
			'donor_first_name' => 'Hook',
			'donation_amount'  => 5000,
		] );

		$this->assertTrue( $hook_fired, 'mission_transaction_created hook should fire.' );
		$this->assertInstanceOf( Transaction::class, $hook_txn );
		$this->assertSame( 'completed', $hook_txn->status );
	}

	/**
	 * Test POST updates donor and campaign aggregates for completed transactions.
	 */
	public function test_post_create_updates_aggregates(): void {
		$this->dispatch_post( '/mission/v1/transactions', [
			'donor_email'      => 'jane@example.com',
			'donor_first_name' => 'Jane',
			'donation_amount'  => 7500,
			'campaign_id'      => $this->campaign->id,
		] );

		$donor = Donor::find( $this->donor->id );
		$this->assertSame( 7500, $donor->total_donated );
		$this->assertSame( 1, $donor->transaction_count );

		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 7500, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );
	}

	// =========================================================================
	// PATCH /transactions/{id} — 2 tests
	// =========================================================================

	/**
	 * Test PATCH updates allowed fields.
	 */
	public function test_patch_updates_allowed_fields(): void {
		$campaign2 = new Campaign( [ 'title' => 'Building Fund', 'goal_amount' => 50000 ] );
		$campaign2->save();

		$transaction = $this->create_transaction( [
			'is_anonymous' => false,
			'campaign_id'  => $this->campaign->id,
		] );

		$response = $this->dispatch_patch( "/mission/v1/transactions/{$transaction->id}", [
			'is_anonymous' => true,
			'campaign_id'  => $campaign2->id,
		] );

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['is_anonymous'] );
		$this->assertSame( $campaign2->id, $data['campaign']['id'] );

		// Verify persisted.
		$updated = Transaction::find( $transaction->id );
		$this->assertTrue( $updated->is_anonymous );
		$this->assertSame( $campaign2->id, $updated->campaign_id );
	}

	/**
	 * Test PATCH status change fires transition hooks.
	 */
	public function test_patch_status_change_fires_hooks(): void {
		$generic_fired  = false;
		$generic_args   = [];
		$specific_fired = false;

		$generic_callback = function ( $transaction, $old_status, $new_status ) use ( &$generic_fired, &$generic_args ) {
			$generic_fired = true;
			$generic_args  = [
				'old_status' => $old_status,
				'new_status' => $new_status,
			];
		};

		add_action( 'mission_transaction_status_transition', $generic_callback, 10, 3 );
		$this->hooks_to_remove[] = [ 'mission_transaction_status_transition', $generic_callback, 10 ];

		$this->add_tracked_action(
			'mission_transaction_status_completed_to_refunded',
			function () use ( &$specific_fired ) {
				$specific_fired = true;
			},
			10
		);

		$transaction = $this->create_transaction( [ 'status' => 'completed' ] );

		$this->dispatch_patch( "/mission/v1/transactions/{$transaction->id}", [
			'status' => 'refunded',
		] );

		$this->assertTrue( $generic_fired, 'Generic transition hook should fire.' );
		$this->assertSame( 'completed', $generic_args['old_status'] );
		$this->assertSame( 'refunded', $generic_args['new_status'] );
		$this->assertTrue( $specific_fired, 'Specific completed_to_refunded hook should fire.' );
	}

	// =========================================================================
	// DELETE /transactions/{id} — 2 tests
	// =========================================================================

	/**
	 * Test DELETE removes transaction.
	 */
	public function test_delete_removes_transaction(): void {
		$transaction = $this->create_transaction();

		$response = $this->dispatch_delete( "/mission/v1/transactions/{$transaction->id}" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNull( Transaction::find( $transaction->id ) );
	}

	/**
	 * Test DELETE decrements donor and campaign aggregates.
	 */
	public function test_delete_decrements_aggregates(): void {
		$transaction = $this->create_transaction( [
			'amount'      => 5000,
			'tip_amount'  => 300,
			'total_amount' => 5300,
		] );

		// Sanity: aggregates incremented on creation.
		$donor    = Donor::find( $this->donor->id );
		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 5000, $donor->total_donated );
		$this->assertSame( 1, $donor->transaction_count );
		$this->assertSame( 5000, $campaign->total_raised );
		$this->assertSame( 1, $campaign->transaction_count );

		// Delete the transaction.
		$this->dispatch_delete( "/mission/v1/transactions/{$transaction->id}" );

		// Aggregates should be decremented.
		$donor    = Donor::find( $this->donor->id );
		$campaign = Campaign::find( $this->campaign->id );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->transaction_count );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->transaction_count );
	}

	// =========================================================================
	// GET /transactions/summary — 1 test
	// =========================================================================

	/**
	 * Test GET summary returns aggregate stats.
	 */
	public function test_get_summary_returns_aggregate_stats(): void {
		$this->create_transaction( [ 'amount' => 3000, 'total_amount' => 3000, 'status' => 'completed' ] );
		$this->create_transaction( [ 'amount' => 7000, 'total_amount' => 7000, 'status' => 'completed' ] );
		$this->create_transaction( [ 'amount' => 2000, 'total_amount' => 2000, 'status' => 'refunded' ] );

		$response = $this->dispatch_get( '/mission/v1/transactions/summary' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 10000, $data['total_revenue'] );
		$this->assertSame( 2, $data['total_donations'] );
		$this->assertSame( 5000, $data['average_donation'] );
		$this->assertSame( 1, $data['total_refunded'] );
		$this->assertSame( 2000, $data['total_refunded_amount'] );
	}

	// =========================================================================
	// Permissions — 2 tests
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_requests_rejected(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/mission/v1/transactions' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test subscriber role is rejected.
	 */
	public function test_subscriber_role_rejected(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch_get( '/mission/v1/transactions' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
