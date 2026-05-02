<?php
/**
 * Tests for the TransactionHistoryEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Donor;
use MissionDP\Models\Transaction;
use MissionDP\Models\TransactionHistory;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * TransactionHistoryEndpoint test class.
 */
class TransactionHistoryEndpointTest extends WP_UnitTestCase {

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
	 * Default transaction for tests.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

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

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		// Create a donor and transaction for tests.
		$donor = new Donor( [
			'email'      => 'donor@example.com',
			'first_name' => 'Test',
			'last_name'  => 'Donor',
		] );
		$donor->save();

		$this->transaction = new Transaction( [
			'status'         => 'completed',
			'donor_id'       => $donor->id,
			'source_post_id' => 1,
			'amount'         => 5000,
			'total_amount'   => 5000,
		] );
		$this->transaction->save();

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
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a history entry with sensible defaults.
	 *
	 * @param array $overrides Column values to override.
	 * @return TransactionHistory
	 */
	private function create_entry( array $overrides = [] ): TransactionHistory {
		$defaults = [
			'transaction_id' => $this->transaction->id,
			'event_type'     => 'status_change',
			'actor_type'     => 'user',
			'actor_id'       => $this->admin_id,
			'context'        => wp_json_encode( [ 'old_status' => 'pending', 'new_status' => 'completed' ] ),
		];

		$entry = new TransactionHistory( array_merge( $defaults, $overrides ) );
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
	// GET /transactions/{id}/history — 3 tests
	// =========================================================================

	/**
	 * Test GET returns history entries for a transaction.
	 */
	public function test_get_returns_history_entries(): void {
		// TransactionHistoryModule auto-logs a 'payment_initiated' entry on transaction
		// creation, so there is already 1 entry from set_up(). Adding 2 more = 3 total.
		$this->create_entry( [ 'event_type' => 'status_change' ] );
		$this->create_entry( [ 'event_type' => 'refund' ] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/transactions/{$this->transaction->id}/history" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		// 1 auto-logged payment_initiated entry from set_up() + 2 manually created = 3.
		$this->assertCount( 3, $data );

		// Verify expected fields on the first entry.
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'transaction_id', $item );
		$this->assertArrayHasKey( 'event_type', $item );
		$this->assertArrayHasKey( 'actor_type', $item );
		$this->assertArrayHasKey( 'actor_id', $item );
		$this->assertArrayHasKey( 'actor_name', $item );
		$this->assertArrayHasKey( 'context', $item );
		$this->assertArrayHasKey( 'created_at', $item );

		$this->assertSame( $this->transaction->id, $item['transaction_id'] );

		// Verify pagination headers reflect total count.
		$this->assertSame( 3, (int) $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Test GET entries are ordered chronologically (DESC).
	 */
	public function test_get_entries_ordered_chronologically(): void {
		// Use future dates to ensure our entries sort after the auto-created one.
		$older = $this->create_entry( [
			'event_type' => 'created',
			'created_at' => '2099-01-01 10:00:00',
		] );
		$newer = $this->create_entry( [
			'event_type' => 'status_change',
			'created_at' => '2099-01-02 10:00:00',
		] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/transactions/{$this->transaction->id}/history" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// DESC order: newest first — our two entries should be at the top.
		$this->assertSame( $newer->id, $data[0]['id'] );
		$this->assertSame( $older->id, $data[1]['id'] );
	}

	/**
	 * Test GET context JSON is decoded correctly.
	 */
	public function test_get_context_decoded_correctly(): void {
		$context_data = [ 'old_status' => 'pending', 'new_status' => 'completed' ];
		$this->create_entry( [ 'context' => wp_json_encode( $context_data ) ] );

		$response = $this->dispatch_get( "/mission-donation-platform/v1/transactions/{$this->transaction->id}/history" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data[0]['context'] );
		$this->assertSame( 'pending', $data[0]['context']['old_status'] );
		$this->assertSame( 'completed', $data[0]['context']['new_status'] );
	}

	// =========================================================================
	// Permissions — 1 test
	// =========================================================================

	/**
	 * Test unauthenticated requests are rejected.
	 */
	public function test_permissions_unauthenticated_rejected(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch_get( "/mission-donation-platform/v1/transactions/{$this->transaction->id}/history" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		// Subscriber role.
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_get( "/mission-donation-platform/v1/transactions/{$this->transaction->id}/history" );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}
}
