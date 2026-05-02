<?php
/**
 * Tests for the PaymentConfigEndpoint class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Endpoints;

use MissionDP\Settings\SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * PaymentConfigEndpoint test class.
 */
class PaymentConfigEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var \WP_REST_Server
	 */
	private \WP_REST_Server $server;

	/**
	 * Route for the endpoint.
	 *
	 * @var string
	 */
	private string $route = '/mission-donation-platform/v1/donations/payment-config';

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a GET request.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch_get(): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $this->route );

		return $this->server->dispatch( $request );
	}

	// =========================================================================
	// GET /donations/payment-config — 4 tests
	// =========================================================================

	/**
	 * Test GET returns connected_account_id when Stripe is connected.
	 */
	public function test_get_returns_connected_account_id_when_stripe_connected(): void {
		update_option( SettingsService::OPTION_NAME, [
			'stripe_account_id' => 'acct_test_123',
		] );

		$response = $this->dispatch_get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'acct_test_123', $data['connected_account_id'] );
	}

	/**
	 * Test GET returns empty string when Stripe is not connected.
	 */
	public function test_get_returns_empty_string_when_stripe_not_connected(): void {
		$response = $this->dispatch_get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $data['connected_account_id'] );
	}

	/**
	 * Test GET is publicly accessible without authentication.
	 */
	public function test_get_is_publicly_accessible_without_auth(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch_get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'connected_account_id', $data );
	}

	/**
	 * Test GET does not expose sensitive keys like tokens or secrets.
	 */
	public function test_get_does_not_expose_sensitive_keys(): void {
		update_option( SettingsService::OPTION_NAME, [
			'stripe_account_id'     => 'acct_test_123',
			'stripe_site_token'     => 'tok_secret_456',
			'stripe_webhook_secret' => 'whsec_secret_789',
			'stripe_site_id'        => 'site_abc',
		] );

		$response = $this->dispatch_get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'connected_account_id' ], array_keys( $data ) );
		$this->assertArrayNotHasKey( 'stripe_site_token', $data );
		$this->assertArrayNotHasKey( 'stripe_webhook_secret', $data );
		$this->assertArrayNotHasKey( 'stripe_site_id', $data );
	}
}
