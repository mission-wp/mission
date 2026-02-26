<?php
/**
 * Tests for the Donor model.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Models\Donor;
use WP_UnitTestCase;

/**
 * Donor model test class.
 */
class DonorTest extends WP_UnitTestCase {

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$donor = new Donor();

		$this->assertNull( $donor->id );
		$this->assertNull( $donor->user_id );
		$this->assertSame( '', $donor->email );
		$this->assertSame( '', $donor->first_name );
		$this->assertSame( '', $donor->last_name );
		$this->assertSame( 0, $donor->total_donated );
		$this->assertSame( 0, $donor->transaction_count );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$donor = new Donor(
			array(
				'id'         => 10,
				'user_id'    => 3,
				'email'      => 'test@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
			)
		);

		$this->assertSame( 10, $donor->id );
		$this->assertSame( 3, $donor->user_id );
		$this->assertSame( 'test@example.com', $donor->email );
		$this->assertSame( 'Jane', $donor->first_name );
		$this->assertSame( 'Doe', $donor->last_name );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$donor = new Donor();

		$this->assertNull( $donor->id );
		$this->assertNull( $donor->user_id );
		$this->assertNull( $donor->first_transaction );
		$this->assertNull( $donor->last_transaction );
	}
}
