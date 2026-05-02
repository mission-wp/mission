<?php
/**
 * Tests for the SubscriptionsEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Donor;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * SubscriptionsEndpoint test class.
 */
class SubscriptionsEndpointTest extends WP_UnitTestCase {

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
			'test_mode'        => false,
			'stripe_site_token' => 'test_site_token_123',
		] );

		// Set currency.
		update_option( 'missiondp_currency', 'usd' );

		// Default HTTP mock: cancel API returns 200.
		$this->add_tracked_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'api.missionwp.com/cancel-subscription' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'success' => true ] ),
					];
				}
				return $preempt;
			},
			10,
		);
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
	 * Create a subscription with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		$defaults = [
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
			'gateway_customer_id'     => 'cus_test_' . wp_rand(),
			'is_test'                 => false,
		];

		$subscription = new Subscription( array_merge( $defaults, $overrides ) );
		$subscription->save();

		return $subscription;
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
			'type'                   => 'monthly',
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
	// GET /subscriptions (list) — 8 tests
	// =========================================================================

	/**
	 * Test GET list returns paginated subscriptions.
	 */
	public function test_get_list_returns_paginated_subscriptions(): void {
		$this->create_subscription();
		$this->create_subscription();
		$this->create_subscription();

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'per_page' => 2 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );

		// Verify expected keys on list items.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'status', $item );
		$this->assertArrayHasKey( 'amount', $item );
		$this->assertArrayHasKey( 'fee_amount', $item );
		$this->assertArrayHasKey( 'tip_amount', $item );
		$this->assertArrayHasKey( 'total_amount', $item );
		$this->assertArrayHasKey( 'currency', $item );
		$this->assertArrayHasKey( 'frequency', $item );
		$this->assertArrayHasKey( 'renewal_count', $item );
		$this->assertArrayHasKey( 'total_renewed', $item );
		$this->assertArrayHasKey( 'date_created', $item );
		$this->assertArrayHasKey( 'date_next_renewal', $item );
		$this->assertArrayHasKey( 'donor_name', $item );
		$this->assertArrayHasKey( 'donor_email', $item );
		$this->assertArrayHasKey( 'donor_id', $item );
		$this->assertArrayHasKey( 'campaign_title', $item );
		$this->assertArrayHasKey( 'campaign_id', $item );
	}

	/**
	 * Test GET list filters by status.
	 */
	public function test_get_list_filters_by_status(): void {
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription( [ 'status' => 'active' ] );
		$this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'status' => 'cancelled' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'cancelled', $response->get_data()[0]['status'] );
	}

	/**
	 * Test GET list filters by campaign_id.
	 */
	public function test_get_list_filters_by_campaign_id(): void {
		$campaign2 = new Campaign( [ 'title' => 'Building Fund', 'goal_amount' => 50000 ] );
		$campaign2->save();

		$this->create_subscription( [ 'campaign_id' => $this->campaign->id ] );
		$this->create_subscription( [ 'campaign_id' => $campaign2->id ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'campaign_id' => $campaign2->id ] );

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

		$this->create_subscription( [ 'donor_id' => $this->donor->id ] );
		$this->create_subscription( [ 'donor_id' => $donor2->id ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'donor_id' => $donor2->id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Bob Smith', $response->get_data()[0]['donor_name'] );
	}

	/**
	 * Test GET list search by donor name/email.
	 */
	public function test_get_list_search_by_donor_name_email(): void {
		$donor2 = new Donor( [
			'email'      => 'alice@example.com',
			'first_name' => 'Alice',
			'last_name'  => 'Wonder',
		] );
		$donor2->save();

		$this->create_subscription( [ 'donor_id' => $this->donor->id ] );
		$this->create_subscription( [ 'donor_id' => $donor2->id ] );

		// Search by first name.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'search' => 'Alice' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Alice Wonder', $response->get_data()[0]['donor_name'] );

		// Search by email.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions', [ 'search' => 'alice@example.com' ] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'alice@example.com', $response->get_data()[0]['donor_email'] );
	}

	/**
	 * Test GET list test mode filtering.
	 */
	public function test_get_list_test_mode_filtering(): void {
		$this->create_subscription( [ 'is_test' => false ] );
		$this->create_subscription( [ 'is_test' => true ] );

		// Live mode (default) — only live subscriptions.
		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		// Switch to test mode — only test subscriptions.
		update_option( SettingsService::OPTION_NAME, [
			'test_mode'         => true,
			'stripe_site_token' => 'test_site_token_123',
		] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Test GET list response includes donor_name, donor_email, campaign_title.
	 */
	public function test_get_list_includes_donor_and_campaign_data(): void {
		$this->create_subscription();

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );
		$item     = $response->get_data()[0];

		$this->assertSame( 'Jane Doe', $item['donor_name'] );
		$this->assertSame( 'jane@example.com', $item['donor_email'] );
		$this->assertSame( 'General Fund', $item['campaign_title'] );
	}

	/**
	 * Test GET list response includes frequency field.
	 */
	public function test_get_list_includes_frequency(): void {
		$this->create_subscription( [ 'frequency' => 'monthly' ] );
		$this->create_subscription( [ 'frequency' => 'annually' ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'frequency', $data[0] );
		$this->assertArrayHasKey( 'frequency', $data[1] );

		$frequencies = array_column( $data, 'frequency' );
		$this->assertContains( 'monthly', $frequencies );
		$this->assertContains( 'annually', $frequencies );
	}

	// =========================================================================
	// GET /subscriptions/{id} (single) — 5 tests
	// =========================================================================

	/**
	 * Test GET single returns full subscription with all expected keys.
	 */
	public function test_get_single_returns_full_subscription(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Donate Page',
			'post_status' => 'publish',
		] );

		$subscription = $this->create_subscription( [ 'source_post_id' => $post_id ] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/subscriptions/{$subscription->id}" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Top-level fields.
		$this->assertSame( $subscription->id, $data['id'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( $this->donor->id, $data['donor_id'] );
		$this->assertSame( $post_id, $data['source_post_id'] );
		$this->assertSame( $this->campaign->id, $data['campaign_id'] );
		$this->assertSame( 2500, $data['amount'] );
		$this->assertSame( 'usd', $data['currency'] );
		$this->assertSame( 'monthly', $data['frequency'] );
		$this->assertSame( 'stripe', $data['payment_gateway'] );
		$this->assertArrayHasKey( 'gateway_subscription_id', $data );
		$this->assertArrayHasKey( 'gateway_customer_id', $data );
		$this->assertArrayHasKey( 'renewal_count', $data );
		$this->assertArrayHasKey( 'total_renewed', $data );
		$this->assertArrayHasKey( 'is_test', $data );
		$this->assertArrayHasKey( 'date_created', $data );
		$this->assertArrayHasKey( 'date_next_renewal', $data );
		$this->assertArrayHasKey( 'date_cancelled', $data );
		$this->assertArrayHasKey( 'date_modified', $data );

		// Sub-objects.
		$this->assertIsArray( $data['donor'] );
		$this->assertSame( $this->donor->id, $data['donor']['id'] );

		$this->assertIsArray( $data['campaign'] );
		$this->assertSame( $this->campaign->id, $data['campaign']['id'] );
		$this->assertSame( 'General Fund', $data['campaign']['title'] );

		$this->assertIsArray( $data['transactions'] );
	}

	/**
	 * Test GET single donor object includes all expected fields.
	 */
	public function test_get_single_donor_object_fields(): void {
		$subscription = $this->create_subscription();

		$response = $this->dispatch_get( "/mission-donation-platform/v1/subscriptions/{$subscription->id}" );
		$donor    = $response->get_data()['donor'];

		$this->assertSame( $this->donor->id, $donor['id'] );
		$this->assertSame( 'jane@example.com', $donor['email'] );
		$this->assertSame( 'Jane', $donor['first_name'] );
		$this->assertSame( 'Doe', $donor['last_name'] );
		$this->assertSame( md5( strtolower( trim( 'jane@example.com' ) ) ), $donor['gravatar_hash'] );
		$this->assertArrayHasKey( 'total_donated', $donor );
		$this->assertArrayHasKey( 'transaction_count', $donor );
		$this->assertSame( '123 Main St', $donor['address_1'] );
		$this->assertSame( 'Apt 4', $donor['address_2'] );
		$this->assertSame( 'Portland', $donor['city'] );
		$this->assertSame( 'OR', $donor['state'] );
		$this->assertSame( '97201', $donor['zip'] );
		$this->assertSame( 'US', $donor['country'] );
	}

	/**
	 * Test GET single includes source_title and source_url from source_post_id.
	 */
	public function test_get_single_source_title_and_url(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Donate Now',
			'post_status' => 'publish',
		] );

		$subscription = $this->create_subscription( [ 'source_post_id' => $post_id ] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/subscriptions/{$subscription->id}" );
		$data     = $response->get_data();

		$this->assertSame( 'Donate Now', $data['source_title'] );
		$this->assertSame( get_permalink( $post_id ), $data['source_url'] );
	}

	/**
	 * Test GET single transactions include computed financial fields.
	 */
	public function test_get_single_transactions_financial_fields(): void {
		$subscription = $this->create_subscription( [
			'amount'       => 1000,
			'fee_amount'   => 61,
			'tip_amount'   => 150,
			'total_amount' => 1211,
		] );

		$txn = $this->create_transaction( [
			'subscription_id' => $subscription->id,
			'amount'          => 1000,
			'fee_amount'      => 61,
			'tip_amount'      => 150,
			'total_amount'    => 1211,
		] );
		$txn->add_meta( 'stripe_fee_percent', '2.9' );
		$txn->add_meta( 'stripe_fee_fixed', '30' );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/subscriptions/{$subscription->id}" );
		$data     = $response->get_data();
		$t        = $data['transactions'][0];

		$this->assertSame( 1000, $t['amount'] );
		$this->assertSame( 61, $t['fee_amount'] );
		$this->assertSame( 150, $t['tip_amount'] );
		$this->assertSame( 1211, $t['total_amount'] );
		$this->assertArrayHasKey( 'processing_fee', $t );
		$this->assertArrayHasKey( 'fee_recovered', $t );
		$this->assertArrayHasKey( 'adjusted_tip', $t );
		$this->assertSame( 61, $t['processing_fee'] );
		$this->assertSame( 61, $t['fee_recovered'] );
		$this->assertGreaterThan( 0, $t['adjusted_tip'] );
	}

	/**
	 * Test GET single returns 404 for nonexistent subscription.
	 */
	public function test_get_single_404_for_nonexistent(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'subscription_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// PATCH /subscriptions/{id} — 4 tests
	// =========================================================================

	/**
	 * Test PATCH updates campaign_id.
	 */
	public function test_patch_updates_campaign_id(): void {
		$campaign2 = new Campaign( [ 'title' => 'Building Fund', 'goal_amount' => 50000 ] );
		$campaign2->save();

		$subscription = $this->create_subscription( [ 'campaign_id' => $this->campaign->id ] );

		$response = $this->dispatch_patch( "/mission-donation-platform/v1/subscriptions/{$subscription->id}", [
			'campaign_id' => $campaign2->id,
		] );

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $campaign2->id, $data['campaign_id'] );

		// Verify persisted.
		$updated = Subscription::find( $subscription->id );
		$this->assertSame( $campaign2->id, $updated->campaign_id );
	}

	/**
	 * Test PATCH updates status.
	 */
	public function test_patch_updates_status(): void {
		$subscription = $this->create_subscription( [ 'status' => 'active' ] );

		$response = $this->dispatch_patch( "/mission-donation-platform/v1/subscriptions/{$subscription->id}", [
			'status' => 'cancelled',
		] );

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $data['status'] );

		// Verify persisted.
		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'cancelled', $updated->status );
	}

	/**
	 * Test PATCH returns full detail response after update.
	 */
	public function test_patch_returns_detail_response(): void {
		$subscription = $this->create_subscription();

		$response = $this->dispatch_patch( "/mission-donation-platform/v1/subscriptions/{$subscription->id}", [
			'status' => 'cancelled',
		] );

		$data = $response->get_data();

		// Should include all detail keys, not just list keys.
		$this->assertArrayHasKey( 'donor', $data );
		$this->assertArrayHasKey( 'campaign', $data );
		$this->assertArrayHasKey( 'transactions', $data );
		$this->assertArrayHasKey( 'gateway_subscription_id', $data );
		$this->assertArrayHasKey( 'payment_gateway', $data );
		$this->assertArrayHasKey( 'source_post_id', $data );
	}

	/**
	 * Test PATCH returns 404 for nonexistent subscription.
	 */
	public function test_patch_404_for_nonexistent(): void {
		$response = $this->dispatch_patch( '/mission-donation-platform/v1/subscriptions/999999', [
			'status' => 'cancelled',
		] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'subscription_not_found', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// POST /subscriptions/{id}/cancel — 2 tests
	// =========================================================================

	/**
	 * Test POST cancel transitions active subscription to cancelled.
	 */
	public function test_cancel_transitions_active_to_cancelled(): void {
		$subscription = $this->create_subscription( [ 'status' => 'active' ] );

		$response = $this->dispatch_post( "/mission-donation-platform/v1/subscriptions/{$subscription->id}/cancel" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'cancelled', $data['status'] );

		// Verify persisted.
		$updated = Subscription::find( $subscription->id );
		$this->assertSame( 'cancelled', $updated->status );
		$this->assertNotNull( $updated->date_cancelled );
	}

	/**
	 * Test POST cancel rejects already cancelled subscription.
	 */
	public function test_cancel_rejects_already_cancelled(): void {
		$subscription = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$response = $this->dispatch_post( "/mission-donation-platform/v1/subscriptions/{$subscription->id}/cancel" );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'subscription_not_cancellable', $response->as_error()->get_error_code() );
	}

	// =========================================================================
	// GET /subscriptions/summary — 2 tests
	// =========================================================================

	/**
	 * Test GET summary returns expected keys.
	 */
	public function test_get_summary_returns_expected_keys(): void {
		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions/summary' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'mrr', $data );
		$this->assertArrayHasKey( 'previous_mrr', $data );
		$this->assertArrayHasKey( 'active', $data );
		$this->assertArrayHasKey( 'new_this_month', $data );
		$this->assertArrayHasKey( 'average_monthly', $data );
		$this->assertArrayHasKey( 'churned', $data );
		$this->assertArrayHasKey( 'churned_mrr', $data );
	}

	/**
	 * Test GET summary MRR values are correctly normalized.
	 */
	public function test_get_summary_mrr_values_normalized(): void {
		$this->create_subscription( [ 'amount' => 1000, 'total_amount' => 1000, 'frequency' => 'monthly' ] );
		$this->create_subscription( [ 'amount' => 2000, 'total_amount' => 2000, 'frequency' => 'monthly' ] );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions/summary' );
		$data     = $response->get_data();

		$this->assertSame( 3000, $data['mrr'] );
		$this->assertSame( 2, $data['active'] );
		$this->assertSame( 1500, $data['average_monthly'] );
	}

	// =========================================================================
	// Permissions — 2 tests
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_requests_rejected(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test subscriber role is rejected.
	 */
	public function test_subscriber_role_rejected(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch_get( '/mission-donation-platform/v1/subscriptions' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
