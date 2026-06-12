<?php
/**
 * Tests for the PaymentIntentVerifier.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Payments;

use MissionDP\Payments\PaymentIntentVerifier;
use MissionDP\Settings\SettingsService;
use WP_UnitTestCase;

/**
 * PaymentIntent verifier test class.
 */
class PaymentIntentVerifierTest extends WP_UnitTestCase {

	/**
	 * Verifier under test.
	 *
	 * @var PaymentIntentVerifier
	 */
	private PaymentIntentVerifier $verifier;

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
	 * Set up a fresh verifier before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = [];
		$this->verifier      = new PaymentIntentVerifier( new SettingsService() );
	}

	/**
	 * Clean up settings and filters after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );

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
	 * @return void
	 */
	private function connect_stripe(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'stripe_accounts' => [
					[
						'site_id'           => 'site_default',
						'site_token'        => 'tok_test_abc123',
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
	 * @param array<string, mixed>|string|\WP_Error $response Response body (array is
	 *                                                        JSON-encoded) or a WP_Error.
	 * @param int                                   $code     HTTP status code.
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

	// -------------------------------------------------------------------------
	// Success path tests.
	// -------------------------------------------------------------------------

	/**
	 * Test a 200 response with a status is returned as verified.
	 */
	public function test_successful_verification(): void {
		$this->connect_stripe();
		$this->mock_api(
			[
				'status'          => 'succeeded',
				'amount_received' => 5000,
				'currency'        => 'USD',
				'payment_method'  => [
					'brand' => 'visa',
					'last4' => '4242',
				],
			]
		);

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertTrue( $result['verified'] );
		$this->assertSame( 'succeeded', $result['stripe_status'] );
		$this->assertSame( 5000, $result['amount_received'] );
		$this->assertSame( 'usd', $result['currency'] );
		$this->assertSame( [ 'brand' => 'visa', 'last4' => '4242' ], $result['payment_method'] );
	}

	/**
	 * Test missing optional fields fall back to safe defaults.
	 */
	public function test_successful_verification_with_minimal_body(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'processing' ] );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertTrue( $result['verified'] );
		$this->assertSame( 'processing', $result['stripe_status'] );
		$this->assertSame( 0, $result['amount_received'] );
		$this->assertSame( '', $result['currency'] );
		$this->assertSame( [], $result['payment_method'] );
	}

	/**
	 * Test the request carries the payment intent, test mode, and Bearer auth.
	 */
	public function test_request_shape(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'status' => 'succeeded' ] );

		$this->verifier->verify( 'pi_test_456', true );

		$this->assertCount( 1, $this->http_requests );

		$request = $this->http_requests[0];
		$this->assertStringContainsString( 'api.missionwp.com/confirm-payment-intent', $request['url'] );
		$this->assertSame( 'Bearer tok_test_abc123', $request['args']['headers']['Authorization'] );

		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 'pi_test_456', $body['payment_intent_id'] );
		$this->assertTrue( $body['test_mode'] );
	}

	// -------------------------------------------------------------------------
	// Failure taxonomy tests.
	// -------------------------------------------------------------------------

	/**
	 * Test no Stripe connection short-circuits without an HTTP request.
	 */
	public function test_stripe_not_connected(): void {
		$this->mock_api( [ 'status' => 'succeeded' ] );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'stripe_not_connected' ], $result );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * Test a network error maps to api_unreachable.
	 */
	public function test_network_error_maps_to_api_unreachable(): void {
		$this->connect_stripe();
		$this->mock_api( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'api_unreachable' ], $result );
	}

	/**
	 * Test a 404 maps to api_not_deployed.
	 */
	public function test_404_maps_to_api_not_deployed(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'error' => 'not found' ], 404 );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'api_not_deployed' ], $result );
	}

	/**
	 * Test a server error maps to api_error.
	 */
	public function test_500_maps_to_api_error(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'error' => 'boom' ], 500 );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'api_error' ], $result );
	}

	/**
	 * Test a 200 without a status field maps to api_error.
	 */
	public function test_missing_status_maps_to_api_error(): void {
		$this->connect_stripe();
		$this->mock_api( [ 'amount_received' => 5000 ] );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'api_error' ], $result );
	}

	/**
	 * Test a malformed JSON body maps to api_error.
	 */
	public function test_malformed_json_maps_to_api_error(): void {
		$this->connect_stripe();
		$this->mock_api( 'not valid json {' );

		$result = $this->verifier->verify( 'pi_test_123', false );

		$this->assertSame( [ 'verified' => false, 'reason' => 'api_error' ], $result );
	}
}
