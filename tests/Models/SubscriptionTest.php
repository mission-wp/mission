<?php
/**
 * Tests for the Subscription model.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Models;

use MissionDP\Models\Subscription;
use MissionDP\Settings\SettingsService;
use WP_Error;
use WP_UnitTestCase;

/**
 * Subscription model test class.
 */
class SubscriptionTest extends WP_UnitTestCase {

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$sub = new Subscription();

		$this->assertNull( $sub->id );
		$this->assertSame( 'pending', $sub->status );
		$this->assertSame( 0, $sub->donor_id );
		$this->assertSame( 'monthly', $sub->frequency );
		$this->assertSame( 'usd', $sub->currency );
		$this->assertSame( 0, $sub->renewal_count );
		$this->assertSame( 0, $sub->total_renewed );
		$this->assertFalse( $sub->is_test );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$sub = new Subscription(
			array(
				'id'                      => 5,
				'status'                  => 'active',
				'donor_id'                => 2,
				'source_post_id'                 => 1,
				'amount'                  => 2500,
				'total_amount'            => 2500,
				'frequency'               => 'annually',
				'payment_gateway'         => 'stripe',
				'gateway_subscription_id' => 'sub_abc',
				'gateway_customer_id'     => 'cus_xyz',
				'is_test'                 => true,
			)
		);

		$this->assertSame( 5, $sub->id );
		$this->assertSame( 'active', $sub->status );
		$this->assertSame( 'annually', $sub->frequency );
		$this->assertSame( 'sub_abc', $sub->gateway_subscription_id );
		$this->assertSame( 'cus_xyz', $sub->gateway_customer_id );
		$this->assertTrue( $sub->is_test );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$sub = new Subscription();

		$this->assertNull( $sub->id );
		$this->assertNull( $sub->campaign_id );
		$this->assertNull( $sub->initial_transaction_id );
		$this->assertNull( $sub->gateway_subscription_id );
		$this->assertNull( $sub->gateway_customer_id );
		$this->assertNull( $sub->date_next_renewal );
		$this->assertNull( $sub->date_cancelled );
	}

	/**
	 * Test pause() sets status to paused when active.
	 */
	public function test_pause_sets_status_to_paused(): void {
		$sub = new Subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$result = $sub->pause();

		$this->assertTrue( $result );
		$this->assertSame( 'paused', $sub->status );
	}

	/**
	 * Test pause() is idempotent when already paused.
	 */
	public function test_pause_returns_true_when_already_paused(): void {
		$sub = new Subscription( [ 'status' => 'paused' ] );

		$result = $sub->pause();

		$this->assertTrue( $result );
		$this->assertSame( 'paused', $sub->status );
	}

	/**
	 * Test pause() returns a 400 WP_Error for non-active subscriptions.
	 */
	public function test_pause_fails_when_cancelled(): void {
		$sub = new Subscription( [ 'status' => 'cancelled' ] );

		$result = $sub->pause();

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_pausable', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'cancelled', $sub->status );
	}

	/**
	 * Test pause() returns a 400 WP_Error for pending subscriptions.
	 */
	public function test_pause_fails_when_pending(): void {
		$sub = new Subscription( [ 'status' => 'pending' ] );

		$result = $sub->pause();

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_pausable', $result->get_error_code() );
		$this->assertSame( 'pending', $sub->status );
	}

	/**
	 * Test resume() sets status to active when paused.
	 */
	public function test_resume_sets_status_to_active(): void {
		$sub = new Subscription( [
			'status'                  => 'paused',
			'frequency'               => 'monthly',
			'gateway_subscription_id' => null,
		] );

		$result = $sub->resume();

		$this->assertTrue( $result );
		$this->assertSame( 'active', $sub->status );
		$this->assertNotNull( $sub->date_next_renewal );
	}

	/**
	 * Test resume() is idempotent when already active.
	 */
	public function test_resume_returns_true_when_already_active(): void {
		$sub = new Subscription( [ 'status' => 'active' ] );

		$result = $sub->resume();

		$this->assertTrue( $result );
		$this->assertSame( 'active', $sub->status );
	}

	/**
	 * Test resume() returns a 400 WP_Error for non-paused subscriptions.
	 */
	public function test_resume_fails_when_cancelled(): void {
		$sub = new Subscription( [ 'status' => 'cancelled' ] );

		$result = $sub->resume();

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_resumable', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'cancelled', $sub->status );
	}

	/**
	 * Test cancel() sets status to cancelled.
	 */
	public function test_cancel_sets_status_to_cancelled(): void {
		$sub = new Subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$result = $sub->cancel();

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', $sub->status );
		$this->assertNotNull( $sub->date_cancelled );
	}

	/**
	 * Test cancel() is idempotent when already cancelled.
	 */
	public function test_cancel_returns_true_when_already_cancelled(): void {
		$sub = new Subscription( [ 'status' => 'cancelled' ] );

		$result = $sub->cancel();

		$this->assertTrue( $result );
	}

	/**
	 * Test update_amount() sets new amounts when active.
	 */
	public function test_update_amount_sets_amounts_when_active(): void {
		$sub = new Subscription( [
			'status'                  => 'active',
			'amount'                  => 2500,
			'tip_amount'              => 375,
			'total_amount'            => 2875,
			'gateway_subscription_id' => null,
		] );

		$result = $sub->update_amount( 5000, 750 );

		$this->assertTrue( $result );
		$this->assertSame( 5000, $sub->amount );
		$this->assertSame( 750, $sub->tip_amount );
		$this->assertSame( 5750, $sub->total_amount );
	}

	/**
	 * Test update_amount() works when paused.
	 */
	public function test_update_amount_works_when_paused(): void {
		$sub = new Subscription( [
			'status'                  => 'paused',
			'amount'                  => 2500,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'gateway_subscription_id' => null,
		] );

		$result = $sub->update_amount( 10000, 0 );

		$this->assertTrue( $result );
		$this->assertSame( 10000, $sub->amount );
		$this->assertSame( 0, $sub->tip_amount );
		$this->assertSame( 10000, $sub->total_amount );
	}

	/**
	 * Test update_amount() returns a 400 WP_Error when cancelled.
	 */
	public function test_update_amount_fails_when_cancelled(): void {
		$sub = new Subscription( [
			'status'     => 'cancelled',
			'amount'     => 2500,
			'tip_amount' => 0,
		] );

		$result = $sub->update_amount( 5000, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_updatable', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 2500, $sub->amount );
	}

	/**
	 * Test update_amount() returns a 400 WP_Error when pending.
	 */
	public function test_update_amount_fails_when_pending(): void {
		$sub = new Subscription( [
			'status'     => 'pending',
			'amount'     => 2500,
			'tip_amount' => 0,
		] );

		$result = $sub->update_amount( 5000, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_updatable', $result->get_error_code() );
		$this->assertSame( 2500, $sub->amount );
	}

	/**
	 * Test create_setup_intent() returns a 400 WP_Error without gateway_customer_id.
	 */
	public function test_create_setup_intent_fails_without_customer_id(): void {
		$sub = new Subscription( [
			'status'              => 'active',
			'gateway_customer_id' => null,
		] );

		$result = $sub->create_setup_intent();

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_missing_gateway_data', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test create_setup_intent() returns a 400 WP_Error when cancelled.
	 */
	public function test_create_setup_intent_fails_when_cancelled(): void {
		$sub = new Subscription( [
			'status'              => 'cancelled',
			'gateway_customer_id' => 'cus_xyz',
		] );

		$result = $sub->create_setup_intent();

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_updatable', $result->get_error_code() );
	}

	/**
	 * Test update_payment_method() returns a 400 WP_Error when cancelled.
	 */
	public function test_update_payment_method_fails_when_cancelled(): void {
		$sub = new Subscription( [
			'status'                  => 'cancelled',
			'gateway_subscription_id' => 'sub_abc',
		] );

		$result = $sub->update_payment_method( 'pm_test' );

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_updatable', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test update_payment_method() returns a 400 WP_Error when pending.
	 */
	public function test_update_payment_method_fails_when_pending(): void {
		$sub = new Subscription( [
			'status'                  => 'pending',
			'gateway_subscription_id' => 'sub_abc',
		] );

		$result = $sub->update_payment_method( 'pm_test' );

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_not_updatable', $result->get_error_code() );
	}

	/**
	 * Test update_payment_method() returns a 400 WP_Error without gateway_subscription_id.
	 */
	public function test_update_payment_method_fails_without_gateway_id(): void {
		$sub = new Subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$result = $sub->update_payment_method( 'pm_test' );

		$this->assertWPError( $result );
		$this->assertSame( 'subscription_missing_gateway_data', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test cancel() succeeds from paused (cancellable from any non-cancelled state).
	 */
	public function test_cancel_succeeds_when_paused(): void {
		$sub = new Subscription( [
			'status'                  => 'paused',
			'gateway_subscription_id' => null,
		] );

		$this->assertTrue( $sub->cancel() );
		$this->assertSame( 'cancelled', $sub->status );
	}

	/**
	 * Test cancel() succeeds from pending (cancellable from any non-cancelled state).
	 */
	public function test_cancel_succeeds_when_pending(): void {
		$sub = new Subscription( [
			'status'                  => 'pending',
			'gateway_subscription_id' => null,
		] );

		$this->assertTrue( $sub->cancel() );
		$this->assertSame( 'cancelled', $sub->status );
	}

	/**
	 * Test cancel() returns mission_api_unreachable when the API request errors.
	 */
	public function test_cancel_returns_api_unreachable_on_network_failure(): void {
		$sub = $this->create_gateway_subscription( 'active' );
		$this->mock_api_network_failure();

		$result = $sub->cancel();

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_unreachable', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 'active', $sub->status );
		$this->assertSame( 'active', Subscription::find( $sub->id )->status );
	}

	/**
	 * Test cancel() returns mission_api_error with the upstream status on non-200.
	 */
	public function test_cancel_returns_api_error_on_upstream_500(): void {
		$sub = $this->create_gateway_subscription( 'active' );
		$this->mock_api_response( 500 );

		$result = $sub->cancel();

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_error', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 500, $result->get_error_data()['upstream_status'] );
		$this->assertSame( 'active', $sub->status );
	}

	/**
	 * Test pause() leaves the subscription active when the API is unreachable.
	 */
	public function test_pause_returns_api_unreachable_on_network_failure(): void {
		$sub = $this->create_gateway_subscription( 'active' );
		$this->mock_api_network_failure();

		$result = $sub->pause();

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_unreachable', $result->get_error_code() );
		$this->assertSame( 'active', $sub->status );
	}

	/**
	 * Test resume() leaves the subscription paused when the API is unreachable.
	 */
	public function test_resume_returns_api_unreachable_on_network_failure(): void {
		$sub = $this->create_gateway_subscription( 'paused' );
		$this->mock_api_network_failure();

		$result = $sub->resume();

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_unreachable', $result->get_error_code() );
		$this->assertSame( 'paused', $sub->status );
	}

	/**
	 * Test update_amount() leaves amounts unchanged and fires no hook on API failure.
	 */
	public function test_update_amount_returns_api_error_and_keeps_amounts(): void {
		$fired = false;
		add_action( 'mission_subscription_amount_changed', function () use ( &$fired ) {
			$fired = true;
		} );

		$sub = $this->create_gateway_subscription( 'active', [
			'amount'       => 2500,
			'tip_amount'   => 0,
			'total_amount' => 2500,
		] );
		$this->mock_api_response( 503 );

		$result = $sub->update_amount( 5000, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_error', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['upstream_status'] );
		$this->assertSame( 2500, $sub->amount );
		$this->assertFalse( $fired, 'Hook should not fire when the API call fails.' );
	}

	/**
	 * Test update_payment_method() returns mission_api_invalid_response when card data is missing.
	 */
	public function test_update_payment_method_rejects_response_without_card(): void {
		$sub = $this->create_gateway_subscription( 'active' );
		$this->mock_api_response( 200, [ 'success' => true ] );

		$result = $sub->update_payment_method( 'pm_test' );

		$this->assertWPError( $result );
		$this->assertSame( 'mission_api_invalid_response', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Create a saved subscription with a gateway ID and a configured site token,
	 * so lifecycle methods reach the Mission API call.
	 *
	 * @param string               $status Subscription status.
	 * @param array<string, mixed> $extra  Additional subscription fields.
	 * @return Subscription
	 */
	private function create_gateway_subscription( string $status, array $extra = [] ): Subscription {
		update_option( SettingsService::OPTION_NAME, [ 'stripe_site_token' => 'test_site_token_123' ] );

		$sub = new Subscription( array_merge( [
			'status'                  => $status,
			'gateway_subscription_id' => 'sub_abc',
			'gateway_customer_id'     => 'cus_xyz',
		], $extra ) );
		$sub->save();

		return $sub;
	}

	/**
	 * Mock all Mission API requests to fail at the transport level.
	 */
	private function mock_api_network_failure(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_contains( $url, 'api.missionwp.com' ) ) {
					return new WP_Error( 'http_request_failed', 'Connection timed out' );
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Mock all Mission API requests to return the given HTTP response.
	 *
	 * @param int                  $code HTTP status code.
	 * @param array<string, mixed> $body Response body to JSON-encode.
	 */
	private function mock_api_response( int $code, array $body = [] ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $code, $body ) {
				if ( str_contains( $url, 'api.missionwp.com' ) ) {
					return [
						'response' => [ 'code' => $code ],
						'body'     => wp_json_encode( $body ),
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Test update_amount() fires mission_subscription_amount_changed when amount changes.
	 */
	public function test_update_amount_fires_amount_changed_action(): void {
		$fired_args = null;

		add_action( 'mission_subscription_amount_changed', function () use ( &$fired_args ) {
			$fired_args = func_get_args();
		}, 10, 3 );

		$sub = new Subscription( [
			'status'                  => 'active',
			'amount'                  => 2500,
			'tip_amount'              => 0,
			'total_amount'            => 2500,
			'gateway_subscription_id' => null,
		] );

		$sub->update_amount( 5000, 0 );

		$this->assertNotNull( $fired_args, 'Hook should fire when donation amount changes.' );
		$this->assertInstanceOf( Subscription::class, $fired_args[0] );
		$this->assertSame( 2500, $fired_args[1] );
		$this->assertSame( 5000, $fired_args[2] );
	}

	/**
	 * Test update_amount() does not fire hook when only tip changes.
	 */
	public function test_update_amount_does_not_fire_when_only_tip_changes(): void {
		$fired = false;

		add_action( 'mission_subscription_amount_changed', function () use ( &$fired ) {
			$fired = true;
		} );

		$sub = new Subscription( [
			'status'                  => 'active',
			'amount'                  => 2500,
			'tip_amount'              => 375,
			'total_amount'            => 2875,
			'gateway_subscription_id' => null,
		] );

		$sub->update_amount( 2500, 750 );

		$this->assertFalse( $fired, 'Hook should not fire when donation amount is unchanged.' );
	}
}
