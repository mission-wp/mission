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
			)
		);

		$this->assertSame( 5, $sub->id );
		$this->assertSame( 'active', $sub->status );
		$this->assertSame( 'annually', $sub->frequency );
		$this->assertSame( 'sub_abc', $sub->gateway_subscription_id );
		$this->assertSame( 'cus_xyz', $sub->gateway_customer_id );
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
		$this->assertNull( $sub->date_expired );
	}
}
