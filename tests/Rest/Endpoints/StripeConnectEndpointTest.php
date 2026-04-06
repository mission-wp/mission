<?php
/**
 * Tests for the StripeConnectEndpoint class.
 *
 * @package Mission
 */

namespace Mission\Tests\Rest\Endpoints;

use Mission\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * StripeConnectEndpoint test class.
 */
class StripeConnectEndpointTest extends WP_UnitTestCase {

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
	 * Connect route.
	 *
	 * @var string
	 */
	private string $connect_route = '/mission/v1/stripe/connect';

	/**
	 * Disconnect route.
	 *
	 * @var string
	 */
	private string $disconnect_route = '/mission/v1/stripe/disconnect';

	/**
	 * Captured API requests keyed by endpoint name.
	 *
	 * @var array<string, array>
	 */
	private array $captured_requests = [];

	/**
	 * Per-test override for the default API mock.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $api_mock_override = null;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		delete_option( SettingsService::OPTION_NAME );

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		$this->captured_requests  = [];
		$this->api_mock_override  = null;

		add_filter( 'pre_http_request', [ $this, 'mock_api_responses' ], 10, 3 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		delete_option( SettingsService::OPTION_NAME );
		remove_filter( 'pre_http_request', [ $this, 'mock_api_responses' ], 10 );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Mock & Helpers
	// -------------------------------------------------------------------------

	/**
	 * Default mock for wp_remote_post calls to api.missionwp.com.
	 *
	 * @param false|array|\WP_Error $preempt Response to short-circuit with, or false.
	 * @param array                 $args    Request arguments.
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function mock_api_responses( $preempt, $args, $url ) {
		if ( $this->api_mock_override ) {
			return ( $this->api_mock_override )( $preempt, $args, $url );
		}

		if ( str_contains( $url, '/connect/finalize' ) ) {
			$this->captured_requests['finalize'] = [ 'url' => $url, 'args' => $args ];

			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [
					'site_token'       => 'tok_test_123',
					'account_id'       => 'acct_test_abc',
					'display_name'     => 'Test Org',
					'default_currency' => 'usd',
				] ),
			];
		}

		if ( str_contains( $url, '/register-webhook' ) ) {
			$this->captured_requests['webhook'] = [ 'url' => $url, 'args' => $args ];

			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'webhook_secret' => 'whsec_test_456' ] ),
			];
		}

		if ( str_contains( $url, '/disconnect' ) ) {
			$this->captured_requests['disconnect'] = [ 'url' => $url, 'args' => $args ];

			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		}

		return $preempt;
	}

	/**
	 * Dispatch a POST request to a given route.
	 *
	 * @param string $route  Route path.
	 * @param array  $params Request parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// POST /stripe/connect — 6 tests
	// =========================================================================

	/**
	 * Test connect exchanges auth code for credentials via Mission API.
	 */
	public function test_connect_exchanges_auth_code_for_credentials(): void {
		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'acct_test_abc', $data['stripe_account_id'] );
		$this->assertSame( 'Test Org', $data['stripe_display_name'] );
		$this->assertSame( 'connected', $data['stripe_connection_status'] );
	}

	/**
	 * Test connect stores Stripe account details in settings.
	 */
	public function test_connect_stores_stripe_account_details_in_settings(): void {
		$this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$stored = get_option( SettingsService::OPTION_NAME );

		$this->assertSame( 'acct_test_abc', $stored['stripe_account_id'] );
		$this->assertSame( 'tok_test_123', $stored['stripe_site_token'] );
		$this->assertSame( 'Test Org', $stored['stripe_display_name'] );
		$this->assertSame( 'connected', $stored['stripe_connection_status'] );
		$this->assertSame( 'USD', $stored['currency'] );
		$this->assertSame( 'site_test', $stored['stripe_site_id'] );
		$this->assertFalse( $stored['test_mode'] );
	}

	/**
	 * Test connect registers webhook URL with the Mission API.
	 */
	public function test_connect_registers_webhook_url(): void {
		$this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		// Verify register-webhook was called.
		$this->assertArrayHasKey( 'webhook', $this->captured_requests );

		// Verify correct webhook_url payload.
		$body = json_decode( $this->captured_requests['webhook']['args']['body'], true );
		$this->assertSame( rest_url( 'mission/v1/webhooks/stripe' ), $body['webhook_url'] );

		// Verify Authorization header uses the site_token.
		$this->assertSame(
			'Bearer tok_test_123',
			$this->captured_requests['webhook']['args']['headers']['Authorization']
		);

		// Verify webhook_secret stored in settings.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( 'whsec_test_456', $stored['stripe_webhook_secret'] );
	}

	/**
	 * Test connect returns error on API network failure.
	 */
	public function test_connect_returns_error_on_api_network_failure(): void {
		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/connect/finalize' ) ) {
				return new \WP_Error( 'http_request_failed', 'Connection timed out' );
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'mission_connect_failed', $response->as_error()->get_error_code() );
	}

	/**
	 * Test connect returns error on non-200 API response.
	 */
	public function test_connect_returns_error_on_api_non_200(): void {
		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/connect/finalize' ) ) {
				return [
					'response' => [ 'code' => 400 ],
					'body'     => wp_json_encode( [ 'error' => 'Invalid setup code' ] ),
				];
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$this->assertSame( 400, $response->get_status() );

		$error = $response->as_error();
		$this->assertSame( 'mission_connect_failed', $error->get_error_code() );
		$this->assertSame( 'Invalid setup code', $error->get_error_message() );
	}

	/**
	 * Test connect does not expose site_token in response but stores it in DB.
	 */
	public function test_connect_does_not_expose_site_token_in_response(): void {
		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );
		$data = $response->get_data();

		// Token must NOT appear in the REST response.
		$this->assertArrayNotHasKey( 'stripe_site_token', $data );

		// But it IS stored in the database.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( 'tok_test_123', $stored['stripe_site_token'] );
	}

	// =========================================================================
	// POST /stripe/disconnect — 1 test
	// =========================================================================

	/**
	 * Test disconnect clears Stripe credentials from settings.
	 */
	public function test_disconnect_clears_stripe_credentials(): void {
		// Pre-populate Stripe credentials.
		update_option( SettingsService::OPTION_NAME, [
			'stripe_site_id'           => 'site_test',
			'stripe_site_token'        => 'tok_test',
			'stripe_account_id'        => 'acct_test',
			'stripe_display_name'      => 'Test Org',
			'stripe_connection_status' => 'connected',
			'stripe_webhook_secret'    => 'whsec_test',
		] );

		$response = $this->dispatch_post( $this->disconnect_route );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Response shows cleared fields.
		$this->assertSame( '', $data['stripe_account_id'] );
		$this->assertSame( '', $data['stripe_display_name'] );
		$this->assertSame( 'disconnected', $data['stripe_connection_status'] );
		$this->assertSame( '', $data['stripe_webhook_secret'] );

		// Token not exposed in response.
		$this->assertArrayNotHasKey( 'stripe_site_token', $data );

		// Verify disconnect API was called.
		$this->assertArrayHasKey( 'disconnect', $this->captured_requests );
		$this->assertSame(
			'Bearer tok_test',
			$this->captured_requests['disconnect']['args']['headers']['Authorization']
		);

		// Verify DB also cleared.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( '', $stored['stripe_site_token'] );
		$this->assertSame( '', $stored['stripe_account_id'] );
		$this->assertSame( 'disconnected', $stored['stripe_connection_status'] );
	}

	// =========================================================================
	// Permissions — 4 tests
	// =========================================================================

	/**
	 * Test connect rejects unauthenticated users.
	 */
	public function test_connect_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test connect rejects subscribers (non-admin).
	 */
	public function test_connect_rejects_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test disconnect rejects unauthenticated users.
	 */
	public function test_disconnect_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_post( $this->disconnect_route );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Test disconnect rejects subscribers (non-admin).
	 */
	public function test_disconnect_rejects_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );

		$response = $this->dispatch_post( $this->disconnect_route );

		$this->assertSame( 403, $response->get_status() );
	}

	// =========================================================================
	// Edge cases — 4 tests
	// =========================================================================

	/**
	 * Test connect returns error when required params are missing.
	 */
	public function test_connect_returns_error_when_params_missing(): void {
		$response = $this->dispatch_post( $this->connect_route );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test disconnect succeeds when no Stripe account is connected.
	 */
	public function test_disconnect_succeeds_when_not_connected(): void {
		$response = $this->dispatch_post( $this->disconnect_route );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'disconnected', $response->get_data()['stripe_connection_status'] );
		$this->assertArrayNotHasKey( 'disconnect', $this->captured_requests );
	}

	/**
	 * Test connect rolls back credentials when webhook registration fails.
	 */
	public function test_connect_rolls_back_when_webhook_registration_fails(): void {
		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/connect/finalize' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [
						'site_token'       => 'tok_test_123',
						'account_id'       => 'acct_test_abc',
						'display_name'     => 'Test Org',
						'default_currency' => 'usd',
					] ),
				];
			}

			if ( str_contains( $url, '/register-webhook' ) ) {
				return new \WP_Error( 'http_request_failed', 'Webhook registration timed out' );
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'webhook_registration_failed', $response->as_error()->get_error_code() );

		// Credentials should be rolled back.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( '', $stored['stripe_site_token'] );
		$this->assertSame( '', $stored['stripe_account_id'] );
		$this->assertSame( 'disconnected', $stored['stripe_connection_status'] );
	}

	/**
	 * Test connect returns error when webhook registration fails.
	 *
	 * If the webhook secret isn't saved, subscriptions won't work —
	 * we should not report a successful connection.
	 */
	public function test_connect_returns_error_when_webhook_registration_fails(): void {
		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/connect/finalize' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [
						'site_token'       => 'tok_test_123',
						'account_id'       => 'acct_test_abc',
						'display_name'     => 'Test Org',
						'default_currency' => 'usd',
					] ),
				];
			}

			if ( str_contains( $url, '/register-webhook' ) ) {
				return new \WP_Error( 'http_request_failed', 'Webhook registration timed out' );
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		// Connection should NOT be reported as successful.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );

		// Connection status should not be 'connected'.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertNotSame( 'connected', $stored['stripe_connection_status'] ?? '' );
	}

	/**
	 * Test connect returns error when webhook registration returns no secret.
	 */
	public function test_connect_returns_error_when_webhook_returns_no_secret(): void {
		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/connect/finalize' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [
						'site_token'       => 'tok_test_123',
						'account_id'       => 'acct_test_abc',
						'display_name'     => 'Test Org',
						'default_currency' => 'usd',
					] ),
				];
			}

			if ( str_contains( $url, '/register-webhook' ) ) {
				// API responds 200 but without a webhook_secret.
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [] ),
				];
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->connect_route, [
			'setup_code' => 'sc_test',
			'site_id'    => 'site_test',
		] );

		// Connection should NOT be reported as successful.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );

		// Connection status should not be 'connected'.
		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertNotSame( 'connected', $stored['stripe_connection_status'] ?? '' );
	}

	/**
	 * Test disconnect clears credentials even when API call fails.
	 */
	public function test_disconnect_clears_credentials_when_api_fails(): void {
		update_option( SettingsService::OPTION_NAME, [
			'stripe_site_id'           => 'site_test',
			'stripe_site_token'        => 'tok_test',
			'stripe_account_id'        => 'acct_test',
			'stripe_display_name'      => 'Test Org',
			'stripe_connection_status' => 'connected',
			'stripe_webhook_secret'    => 'whsec_test',
		] );

		$this->api_mock_override = function ( $preempt, $args, $url ) {
			if ( str_contains( $url, '/disconnect' ) ) {
				return new \WP_Error( 'http_request_failed', 'Connection timed out' );
			}

			return $preempt;
		};

		$response = $this->dispatch_post( $this->disconnect_route );

		$this->assertSame( 200, $response->get_status() );

		$stored = get_option( SettingsService::OPTION_NAME );
		$this->assertSame( '', $stored['stripe_account_id'] );
		$this->assertSame( '', $stored['stripe_site_token'] );
		$this->assertSame( 'disconnected', $stored['stripe_connection_status'] );
	}
}
