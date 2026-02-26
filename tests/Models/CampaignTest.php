<?php
/**
 * Tests for the Campaign model.
 *
 * @package Mission
 */

namespace Mission\Tests\Models;

use Mission\Models\Campaign;
use WP_UnitTestCase;

/**
 * Campaign model test class.
 */
class CampaignTest extends WP_UnitTestCase {

	/**
	 * Test default values on empty construction.
	 */
	public function test_defaults_on_empty_construction(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->id );
		$this->assertSame( 'draft', $campaign->status );
		$this->assertSame( '', $campaign->title );
		$this->assertSame( '', $campaign->slug );
		$this->assertSame( 0, $campaign->goal_amount );
		$this->assertSame( 0, $campaign->total_raised );
		$this->assertSame( 0, $campaign->donation_count );
		$this->assertSame( 'usd', $campaign->currency );
	}

	/**
	 * Test full construction from array.
	 */
	public function test_full_construction_from_array(): void {
		$campaign = new Campaign(
			array(
				'id'          => 3,
				'status'      => 'active',
				'title'       => 'Save the Whales',
				'slug'        => 'save-the-whales',
				'description' => 'Help us save whales.',
				'goal_amount' => 100000,
			)
		);

		$this->assertSame( 3, $campaign->id );
		$this->assertSame( 'active', $campaign->status );
		$this->assertSame( 'Save the Whales', $campaign->title );
		$this->assertSame( 'save-the-whales', $campaign->slug );
		$this->assertSame( 'Help us save whales.', $campaign->description );
		$this->assertSame( 100000, $campaign->goal_amount );
	}

	/**
	 * Test nullable fields are null when omitted.
	 */
	public function test_nullable_fields_are_null_when_omitted(): void {
		$campaign = new Campaign();

		$this->assertNull( $campaign->id );
		$this->assertNull( $campaign->description );
		$this->assertNull( $campaign->date_start );
		$this->assertNull( $campaign->date_end );
	}
}
