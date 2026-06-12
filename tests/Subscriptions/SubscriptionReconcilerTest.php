<?php
/**
 * Tests for the SubscriptionReconciler.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Subscriptions;

use MissionDP\Database\DatabaseModule;
use MissionDP\Models\Subscription;
use MissionDP\Settings\SettingsService;
use MissionDP\Subscriptions\SubscriptionReconciler;
use WP_UnitTestCase;

/**
 * Subscription reconciler test class.
 *
 * The Mission API is mocked via pre_http_request; each test asserts on the
 * captured requests and on subscription state reloaded from the database.
 */
class SubscriptionReconcilerTest extends WP_UnitTestCase {

	/**
	 * Reconciler under test.
	 *
	 * @var SubscriptionReconciler
	 */
	private SubscriptionReconciler $reconciler;

	/**
	 * Captured HTTP requests as [ 'url' => string, 'args' => array ] entries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $http_requests = [];

	/**
	 * Filters added during the test, removed in tear_down.
	 *
	 * @var array<int, array{string, callable}>
	 */
	private array $filters_to_remove = [];

	/**
	 * Create tables once before any tests run.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		DatabaseModule::create_tables();
	}

	/**
	 * Set up fresh state before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = [];
		$this->reconciler    = new SubscriptionReconciler();
	}

	/**
	 * Clean up tables, settings, cron, and filters after each test.
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}missiondp_subscriptions" );
		// phpcs:enable

		delete_option( SettingsService::OPTION_NAME );
		wp_clear_scheduled_hook( SubscriptionReconciler::CRON_HOOK );

		foreach ( $this->filters_to_remove as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback );
		}
		$this->filters_to_remove = [];

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Store a connected Stripe account in settings.
	 *
	 * @param string $site_token Site token for the account.
	 * @return void
	 */
	private function connect_stripe( string $site_token = 'tok_test_abc123' ): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_accounts' => [
					[
						'site_id'           => 'site_default',
						'site_token'        => $site_token,
						'account_id'        => 'acct_test_connected',
						'display_name'      => 'Default Account',
						'connection_status' => 'connected',
						'charges_enabled'   => true,
						'is_default'        => true,
					],
				],
			]
		);
	}

	/**
	 * Mock the Mission API, capturing requests and returning a canned response.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response body array (JSON-encoded
	 *                                                 into a 200) or a WP_Error.
	 * @param int                            $code     HTTP status code for array responses.
	 * @return void
	 */
	private function mock_api( $response, int $code = 200 ): void {
		$callback = function ( $preempt, $args, $url ) use ( $response, $code ) {
			$this->http_requests[] = [
				'url'  => $url,
				'args' => $args,
			];

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return [
				'response' => [ 'code' => $code ],
				'body'     => is_string( $response ) ? $response : wp_json_encode( $response ),
			];
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->filters_to_remove[] = [ 'pre_http_request', $callback ];
	}

	/**
	 * Create a stale active subscription with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Column values to override.
	 * @return Subscription
	 */
	private function create_subscription( array $overrides = [] ): Subscription {
		static $counter = 0;
		++$counter;

		$subscription = new Subscription( array_merge(
			[
				'status'                  => Subscription::STATUS_ACTIVE,
				'amount'                  => 2500,
				'total_amount'            => 2500,
				'currency'                => 'usd',
				'frequency'               => 'monthly',
				'gateway_subscription_id' => "sub_test_{$counter}",
				'date_next_renewal'       => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ),
			],
			$overrides
		) );

		$subscription->save();

		return $subscription;
	}

	// -------------------------------------------------------------------------
	// Precondition tests.
	// -------------------------------------------------------------------------

	/**
	 * Test no API requests are made when Stripe is not connected.
	 */
	public function test_no_requests_when_stripe_not_connected(): void {
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test subscriptions renewing in the future are not checked.
	 */
	public function test_no_requests_when_no_stale_subscriptions(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription( [
			'date_next_renewal' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		] );

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test subscriptions within the two-day stale threshold are not checked.
	 */
	public function test_no_requests_for_subscription_within_threshold(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription( [
			'date_next_renewal' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
		] );

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test non-active subscriptions are never checked, however stale.
	 */
	public function test_non_active_subscriptions_not_checked(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription( [ 'status' => Subscription::STATUS_CANCELLED ] );
		$this->create_subscription( [ 'status' => Subscription::STATUS_PAST_DUE ] );
		$this->create_subscription( [ 'status' => Subscription::STATUS_PAUSED ] );

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test subscriptions without a gateway subscription ID are skipped.
	 */
	public function test_subscription_without_gateway_id_skipped(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription( [ 'gateway_subscription_id' => null ] );

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test subscriptions whose site token cannot be resolved are skipped.
	 */
	public function test_unresolvable_site_token_skips_subscription(): void {
		// An account list with no usable token: reconcile() proceeds past the
		// connected check but resolve_site_token() comes back empty.
		$this->connect_stripe( '' );
		$this->mock_api( [ 'status' => 'canceled' ] );
		$this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertCount( 0, $this->http_requests );
	}

	// -------------------------------------------------------------------------
	// Request shape tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a stale active subscription produces one authorized status request.
	 */
	public function test_stale_subscription_queried_with_auth(): void {
		$this->connect_stripe( 'tok_test_abc123' );
		$this->mock_api( [ 'status' => 'active' ] );
		$subscription = $this->create_subscription( [ 'is_test' => true ] );

		$this->reconciler->reconcile();

		$this->assertCount( 1, $this->http_requests );

		$request = $this->http_requests[0];
		$this->assertStringContainsString( 'api.missionwp.com/subscription-status', $request['url'] );
		$this->assertStringContainsString( 'subscription_id=' . $subscription->gateway_subscription_id, $request['url'] );
		$this->assertStringContainsString( 'test_mode=1', $request['url'] );
		$this->assertSame( 'Bearer tok_test_abc123', $request['args']['headers']['Authorization'] );
	}

	// -------------------------------------------------------------------------
	// Status mapping tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a canceled Stripe status cancels the subscription and stamps the date.
	 */
	public function test_canceled_status_cancels_subscription(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$saved = Subscription::find( $subscription->id );
		$this->assertSame( Subscription::STATUS_CANCELLED, $saved->status );
		$this->assertNotNull( $saved->date_cancelled );
	}

	/**
	 * Test a past_due Stripe status maps to past_due.
	 */
	public function test_past_due_status_maps_to_past_due(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'past_due' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertSame( Subscription::STATUS_PAST_DUE, Subscription::find( $subscription->id )->status );
	}

	/**
	 * Test an unpaid Stripe status also maps to past_due.
	 */
	public function test_unpaid_status_maps_to_past_due(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'unpaid' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertSame( Subscription::STATUS_PAST_DUE, Subscription::find( $subscription->id )->status );
	}

	/**
	 * Test a paused Stripe status maps to paused.
	 */
	public function test_paused_status_maps_to_paused(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'paused' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertSame( Subscription::STATUS_PAUSED, Subscription::find( $subscription->id )->status );
	}

	/**
	 * Test unmapped Stripe statuses leave the subscription untouched.
	 */
	public function test_unmapped_status_leaves_subscription_untouched(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'trialing' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$saved = Subscription::find( $subscription->id );
		$this->assertSame( Subscription::STATUS_ACTIVE, $saved->status );
		$this->assertNull( $saved->date_cancelled );
	}

	// -------------------------------------------------------------------------
	// Error handling tests.
	// -------------------------------------------------------------------------

	/**
	 * Test an HTTP error response leaves the subscription untouched.
	 */
	public function test_wp_error_response_leaves_subscription_untouched(): void {
		$this->connect_stripe();
		$this->mock_api( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( Subscription::STATUS_ACTIVE, Subscription::find( $subscription->id )->status );
	}

	/**
	 * Test a response without a status field leaves the subscription untouched.
	 */
	public function test_missing_status_in_body_leaves_subscription_untouched(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'other_field' => 'value' ] );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertSame( Subscription::STATUS_ACTIVE, Subscription::find( $subscription->id )->status );
	}

	/**
	 * Test a malformed JSON body leaves the subscription untouched.
	 */
	public function test_malformed_json_body_leaves_subscription_untouched(): void {
		$this->connect_stripe();
		$this->mock_api( 'not valid json {' );
		$subscription = $this->create_subscription();

		$this->reconciler->reconcile();

		$this->assertSame( Subscription::STATUS_ACTIVE, Subscription::find( $subscription->id )->status );
	}

	// -------------------------------------------------------------------------
	// Cron wiring tests.
	// -------------------------------------------------------------------------

	/**
	 * Test init schedules the daily cron event exactly once.
	 */
	public function test_init_schedules_daily_cron_once(): void {
		wp_clear_scheduled_hook( SubscriptionReconciler::CRON_HOOK );
		$this->assertFalse( wp_next_scheduled( SubscriptionReconciler::CRON_HOOK ) );

		$this->reconciler->init();

		$first_run = wp_next_scheduled( SubscriptionReconciler::CRON_HOOK );
		$this->assertNotFalse( $first_run );
		$this->assertSame( 'daily', wp_get_schedule( SubscriptionReconciler::CRON_HOOK ) );

		// A second init must not add another event.
		$this->reconciler->init();
		$this->assertSame( $first_run, wp_next_scheduled( SubscriptionReconciler::CRON_HOOK ) );
	}

	/**
	 * Test the cron hook runs reconcile when fired.
	 */
	public function test_cron_hook_runs_reconcile(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'canceled' ] );
		$subscription = $this->create_subscription();

		// The plugin registered the action at boot; firing the hook must
		// reach reconcile() without this test calling it directly.
		do_action( SubscriptionReconciler::CRON_HOOK );

		$this->assertSame( Subscription::STATUS_CANCELLED, Subscription::find( $subscription->id )->status );
	}
}
