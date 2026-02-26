<?php
/**
 * Tests for the Transaction model.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Models\Transaction;
use WP_UnitTestCase;

/**
 * Transaction model test class.
 */
class TransactionTest extends WP_UnitTestCase {

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$transaction = new Transaction();

		$this->assertNull( $transaction->id );
		$this->assertSame( 'pending', $transaction->status );
		$this->assertSame( 'one_time', $transaction->type );
		$this->assertSame( 0, $transaction->donor_id );
		$this->assertSame( 0, $transaction->amount );
		$this->assertSame( 0, $transaction->total_amount );
		$this->assertSame( 'usd', $transaction->currency );
		$this->assertFalse( $transaction->is_anonymous );
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
			'source_post_id'                 => 1,
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

		$transaction = new Transaction( $data );

		$this->assertSame( 42, $transaction->id );
		$this->assertSame( 'completed', $transaction->status );
		$this->assertSame( 'recurring', $transaction->type );
		$this->assertSame( 5, $transaction->donor_id );
		$this->assertSame( 10, $transaction->subscription_id );
		$this->assertSame( 3, $transaction->parent_id );
		$this->assertSame( 7, $transaction->campaign_id );
		$this->assertSame( 5000, $transaction->amount );
		$this->assertSame( 5650, $transaction->total_amount );
		$this->assertSame( 'stripe', $transaction->payment_gateway );
		$this->assertTrue( $transaction->is_anonymous );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$transaction = new Transaction( array( 'donor_id' => 1, 'form_id' => 1 ) );

		$this->assertNull( $transaction->id );
		$this->assertNull( $transaction->subscription_id );
		$this->assertNull( $transaction->parent_id );
		$this->assertNull( $transaction->campaign_id );
		$this->assertNull( $transaction->gateway_transaction_id );
		$this->assertNull( $transaction->gateway_subscription_id );
		$this->assertNull( $transaction->date_completed );
		$this->assertNull( $transaction->date_refunded );
	}
}
