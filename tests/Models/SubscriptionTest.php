<?php
/**
 * Tests for the Subscription model.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Models\Subscription;
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
	 * Test pause() returns false for non-active subscriptions.
	 */
	public function test_pause_fails_when_cancelled(): void {
		$sub = new Subscription( [ 'status' => 'cancelled' ] );

		$result = $sub->pause();

		$this->assertFalse( $result );
		$this->assertSame( 'cancelled', $sub->status );
	}

	/**
	 * Test pause() returns false for pending subscriptions.
	 */
	public function test_pause_fails_when_pending(): void {
		$sub = new Subscription( [ 'status' => 'pending' ] );

		$result = $sub->pause();

		$this->assertFalse( $result );
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
	 * Test resume() returns false for non-paused subscriptions.
	 */
	public function test_resume_fails_when_cancelled(): void {
		$sub = new Subscription( [ 'status' => 'cancelled' ] );

		$result = $sub->resume();

		$this->assertFalse( $result );
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
	 * Test update_amount() fails when cancelled.
	 */
	public function test_update_amount_fails_when_cancelled(): void {
		$sub = new Subscription( [
			'status'     => 'cancelled',
			'amount'     => 2500,
			'tip_amount' => 0,
		] );

		$result = $sub->update_amount( 5000, 0 );

		$this->assertFalse( $result );
		$this->assertSame( 2500, $sub->amount );
	}

	/**
	 * Test update_amount() fails when pending.
	 */
	public function test_update_amount_fails_when_pending(): void {
		$sub = new Subscription( [
			'status'     => 'pending',
			'amount'     => 2500,
			'tip_amount' => 0,
		] );

		$result = $sub->update_amount( 5000, 0 );

		$this->assertFalse( $result );
		$this->assertSame( 2500, $sub->amount );
	}

	/**
	 * Test create_setup_intent() returns false without gateway_customer_id.
	 */
	public function test_create_setup_intent_fails_without_customer_id(): void {
		$sub = new Subscription( [
			'status'              => 'active',
			'gateway_customer_id' => null,
		] );

		$this->assertFalse( $sub->create_setup_intent() );
	}

	/**
	 * Test update_payment_method() fails when cancelled.
	 */
	public function test_update_payment_method_fails_when_cancelled(): void {
		$sub = new Subscription( [
			'status'                  => 'cancelled',
			'gateway_subscription_id' => 'sub_abc',
		] );

		$this->assertFalse( $sub->update_payment_method( 'pm_test' ) );
	}

	/**
	 * Test update_payment_method() fails when pending.
	 */
	public function test_update_payment_method_fails_when_pending(): void {
		$sub = new Subscription( [
			'status'                  => 'pending',
			'gateway_subscription_id' => 'sub_abc',
		] );

		$this->assertFalse( $sub->update_payment_method( 'pm_test' ) );
	}

	/**
	 * Test update_payment_method() fails without gateway_subscription_id.
	 */
	public function test_update_payment_method_fails_without_gateway_id(): void {
		$sub = new Subscription( [
			'status'                  => 'active',
			'gateway_subscription_id' => null,
		] );

		$this->assertFalse( $sub->update_payment_method( 'pm_test' ) );
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
