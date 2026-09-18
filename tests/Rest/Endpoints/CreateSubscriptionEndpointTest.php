<?php
/**
 * Tests for the CreateSubscriptionEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Campaign;
use MissionDP\Models\Subscription;
use MissionDP\Models\Transaction;
use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * CreateSubscriptionEndpoint test class.
 */
class CreateSubscriptionEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Campaign ID for tests.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Body of the last Mission API request.
	 *
	 * @var array|null
	 */
	private ?array $last_api_body = null;

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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}missiondp_subscriptionmeta" );
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

		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_accounts'    => [
					[
						'site_id'           => 'site_default',
						'site_token'        => 'tok_test_abc123',
						'account_id'        => 'acct_test_connected',
						'display_name'      => 'Default Account',
						'connection_status' => 'connected',
						'charges_enabled'   => true,
						'webhook_secret'    => 'whsec_default',
						'is_default'        => true,
						'connected_at'      => gmdate( 'c' ),
					],
				],
				'stripe_site_token'  => 'tok_test_abc123',
				'stripe_account_id'  => 'acct_test_connected',
				'stripe_fee_percent' => 2.9,
				'stripe_fee_fixed'   => 30,
				'test_mode'          => false,
			]
		);

		$campaign = new Campaign(
			[
				'title'       => 'General Fund',
				'goal_amount' => 100000,
			]
		);
		$campaign->save();
		$this->campaign_id = $campaign->id;

		$this->last_api_body = null;
		add_filter( 'pre_http_request', [ $this, 'mock_api_success' ], 10, 3 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server, $wpdb;

		$wp_rest_server = null;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_transactions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaignmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_campaigns" );

		delete_option( SettingsService::OPTION_NAME );

		remove_filter( 'pre_http_request', [ $this, 'mock_api_success' ], 10 );

		parent::tear_down();
	}

	/**
	 * Mock: Mission API returns a successful subscription.
	 *
	 * @param false|array $preempt Whether to preempt the request.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array|false
	 */
	public function mock_api_success( $preempt, $args, $url ) {
		if ( ! str_contains( $url, 'api.missionwp.com/create-subscription' ) ) {
			return $preempt;
		}

		$this->last_api_body = json_decode( $args['body'], true );

		return [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'client_secret'        => 'pi_test123_secret_abc456',
					'connected_account_id' => 'acct_test_connected',
					'subscription_id'      => 'sub_test123',
					'customer_id'          => 'cus_test123',
				]
			),
		];
	}

	/**
	 * Build and dispatch a create-subscription request with sensible defaults.
	 *
	 * @param array $overrides Parameters to override the defaults.
	 * @return \WP_REST_Response
	 */
	private function make_request( array $overrides = [] ): \WP_REST_Response {
		$defaults = [
			'donation_amount'  => 5000,
			'tip_amount'       => 0,
			'fee_amount'       => 0,
			'donor_email'      => 'jane@example.com',
			'donor_first_name' => 'Jane',
			'donor_last_name'  => 'Doe',
			'frequency'        => 'monthly',
			'campaign_id'      => $this->campaign_id,
		];

		$request = new WP_REST_Request( 'POST', '/mission-donation-platform/v1/donations/create-subscription' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( $defaults, $overrides ) ) );

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Test a subscription and its first transaction are created as pending.
	 */
	public function test_creates_pending_subscription_and_transaction(): void {
		$response = $this->make_request( [ 'tip_amount' => 750 ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pi_test123_secret_abc456', $data['client_secret'] );

		$subscription = Subscription::find( $data['subscription_id'] );
		$transaction  = Transaction::find( $data['transaction_id'] );

		$this->assertSame( 'pending', $subscription->status );
		$this->assertSame( 'monthly', $subscription->frequency );
		$this->assertSame( 750, $subscription->tip_amount );
		$this->assertSame( $subscription->id, $transaction->subscription_id );
		$this->assertSame( 'tip', $this->last_api_body['fee_mode'] );
		$this->assertFalse( $this->last_api_body['tip_hidden'] );
		$this->assertSame( '', $this->last_api_body['page_url'] );
	}

	/**
	 * Test a hidden tip forces flat fee mode and a zero tip in the API body.
	 */
	public function test_tip_hidden_forces_flat_fee_mode_in_api_body(): void {
		$this->make_request( [
			'tip_amount' => 750,
			'fee_mode'   => 'tip',
			'tip_hidden' => true,
			'page_url'   => 'https://example.org/donate/',
		] );

		$this->assertSame( 'flat', $this->last_api_body['fee_mode'] );
		$this->assertSame( 0, $this->last_api_body['tip_amount'] );
		$this->assertSame( 5000, $this->last_api_body['donation_amount'] );
		$this->assertTrue( $this->last_api_body['tip_hidden'] );
		$this->assertSame( 'https://example.org/donate/', $this->last_api_body['page_url'] );
	}

	/**
	 * Test a hidden tip stores meta on both the transaction and subscription.
	 */
	public function test_tip_hidden_stores_meta_on_transaction_and_subscription(): void {
		$response     = $this->make_request( [
			'tip_amount' => 750,
			'tip_hidden' => true,
			'page_url'   => 'https://example.org/donate/',
		] );
		$data         = $response->get_data();
		$subscription = Subscription::find( $data['subscription_id'] );
		$transaction  = Transaction::find( $data['transaction_id'] );

		$this->assertSame( 0, $subscription->tip_amount );
		$this->assertSame( 0, $transaction->tip_amount );
		$this->assertSame( 'flat', $subscription->get_meta( 'fee_mode' ) );
		$this->assertSame( 'flat', $transaction->get_meta( 'fee_mode' ) );
		$this->assertSame( '1', $subscription->get_meta( 'tip_hidden' ) );
		$this->assertSame( '1', $transaction->get_meta( 'tip_hidden' ) );
	}
}
