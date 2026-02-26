<?php
/**
 * Tests for the Donation model.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Models\Donation;
use WP_UnitTestCase;

/**
 * Donation model test class.
 */
class DonationTest extends WP_UnitTestCase {

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$donation = new Donation();

		$this->assertNull( $donation->id );
		$this->assertSame( 'pending', $donation->status );
		$this->assertSame( 'one_time', $donation->type );
		$this->assertSame( 0, $donation->donor_id );
		$this->assertSame( 0, $donation->amount );
		$this->assertSame( 0, $donation->total_amount );
		$this->assertSame( 'usd', $donation->currency );
		$this->assertFalse( $donation->is_anonymous );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$data = array(
			'id'                      => 42,
			'status'                  => 'completed',
			'type'                    => 'recurring',
			'donor_id'                => 5,
			'subscription_id'         => 10,
			'parent_id'               => 3,
			'form_id'                 => 1,
			'campaign_id'             => 7,
			'amount'                  => 5000,
			'fee_amount'              => 150,
			'tip_amount'              => 500,
			'total_amount'            => 5650,
			'currency'                => 'eur',
			'payment_gateway'         => 'stripe',
			'gateway_transaction_id'  => 'txn_123',
			'gateway_subscription_id' => 'sub_456',
			'is_anonymous'            => true,
			'donor_ip'                => '127.0.0.1',
			'date_created'            => '2025-01-01 00:00:00',
			'date_completed'          => '2025-01-01 00:01:00',
			'date_refunded'           => '2025-01-02 00:00:00',
			'date_modified'           => '2025-01-02 00:00:00',
		);

		$donation = new Donation( $data );

		$this->assertSame( 42, $donation->id );
		$this->assertSame( 'completed', $donation->status );
		$this->assertSame( 'recurring', $donation->type );
		$this->assertSame( 5, $donation->donor_id );
		$this->assertSame( 10, $donation->subscription_id );
		$this->assertSame( 3, $donation->parent_id );
		$this->assertSame( 7, $donation->campaign_id );
		$this->assertSame( 5000, $donation->amount );
		$this->assertSame( 5650, $donation->total_amount );
		$this->assertSame( 'stripe', $donation->payment_gateway );
		$this->assertTrue( $donation->is_anonymous );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$donation = new Donation( array( 'donor_id' => 1, 'form_id' => 1 ) );

		$this->assertNull( $donation->id );
		$this->assertNull( $donation->subscription_id );
		$this->assertNull( $donation->parent_id );
		$this->assertNull( $donation->campaign_id );
		$this->assertNull( $donation->gateway_transaction_id );
		$this->assertNull( $donation->gateway_subscription_id );
		$this->assertNull( $donation->date_completed );
		$this->assertNull( $donation->date_refunded );
	}
}
