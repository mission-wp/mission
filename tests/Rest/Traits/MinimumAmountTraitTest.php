<?php
/**
 * Tests for the MinimumAmountTrait.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Rest\Traits;

use MissionDP\Currency\Currency;
use MissionDP\Rest\Traits\MinimumAmountTrait;
use WP_UnitTestCase;

/**
 * MinimumAmountTrait test class.
 */
class MinimumAmountTraitTest extends WP_UnitTestCase {

	/**
	 * Test double exposing the trait's private validation method.
	 *
	 * @var object
	 */
	private object $validator;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->validator = new class() {
			use MinimumAmountTrait;

			/**
			 * Expose the private trait method for testing.
			 *
			 * @param int    $amount         Donation amount in minor units.
			 * @param int    $source_post_id Post ID containing the form block.
			 * @param string $form_id        The block's formId attribute.
			 * @param string $currency       ISO 4217 currency code.
			 * @return \WP_Error|true
			 */
			public function validate( int $amount, int $source_post_id, string $form_id, string $currency ): \WP_Error|bool {
				return $this->validate_minimum_amount( $amount, $source_post_id, $form_id, $currency );
			}
		};
	}

	/**
	 * Create a post containing a donation form block.
	 *
	 * @param string $form_id Form ID attribute.
	 * @param string $attrs   Extra block attributes JSON fragment (e.g. '"minimumAmount":1000,').
	 * @return int Post ID.
	 */
	private function create_form_post( string $form_id, string $attrs = '' ): int {
		return self::factory()->post->create(
			[
				'post_content' => '<!-- wp:mission-donation-platform/donation-form {' . $attrs . '"formId":"' . $form_id . '"} /-->',
				'post_status'  => 'publish',
			]
		);
	}

	/**
	 * Test the hard floor is one major unit for USD.
	 */
	public function test_hard_floor_usd(): void {
		$result = $this->validator->validate( 99, 0, '', 'usd' );

		$this->assertWPError( $result );
		$this->assertSame( 'donation_below_minimum', $result->get_error_code() );

		$this->assertTrue( $this->validator->validate( 100, 0, '', 'usd' ) );
	}

	/**
	 * Test the JPY floor follows Stripe's published ¥50 minimum.
	 */
	public function test_hard_floor_jpy(): void {
		$result = $this->validator->validate( 49, 0, '', 'jpy' );

		$this->assertWPError( $result );

		// ¥99 was rejected by the old flat 100-minor-unit floor; it is valid now.
		$this->assertTrue( $this->validator->validate( 99, 0, '', 'jpy' ) );
		$this->assertTrue( $this->validator->validate( 50, 0, '', 'jpy' ) );
	}

	/**
	 * Test the HUF floor follows Stripe's published 175 HUF minimum.
	 */
	public function test_hard_floor_huf(): void {
		$result = $this->validator->validate( 17400, 0, '', 'huf' );

		$this->assertWPError( $result );

		$this->assertTrue( $this->validator->validate( 17500, 0, '', 'huf' ) );
	}

	/**
	 * Test whole-unit currencies reject fractional amounts.
	 */
	public function test_whole_unit_currencies_reject_fractions(): void {
		// 10.50 ISK cannot be charged; 10 ISK can.
		$result = $this->validator->validate( 1050, 0, '', 'isk' );

		$this->assertWPError( $result );
		$this->assertSame( 'donation_invalid_amount', $result->get_error_code() );

		$this->assertTrue( $this->validator->validate( 1000, 0, '', 'isk' ) );

		$result = $this->validator->validate( 550, 0, '', 'ugx' );

		$this->assertWPError( $result );

		$this->assertTrue( $this->validator->validate( 500, 0, '', 'ugx' ) );
	}

	/**
	 * Test the hard floor is one major unit for KWD (three-decimal).
	 */
	public function test_hard_floor_kwd(): void {
		$result = $this->validator->validate( 999, 0, '', 'kwd' );

		$this->assertWPError( $result );

		$this->assertTrue( $this->validator->validate( 1000, 0, '', 'kwd' ) );
	}

	/**
	 * Test the floor error message is formatted in the given currency.
	 */
	public function test_floor_error_message_uses_currency(): void {
		$result = $this->validator->validate( 50, 0, '', 'usd' );

		$this->assertStringContainsString(
			Currency::format_amount( 100, 'USD' ),
			$result->get_error_message()
		);
	}

	/**
	 * Test the block-level minimum is enforced.
	 */
	public function test_block_minimum_enforced(): void {
		$post_id = $this->create_form_post( 'f_min10', '"minimumAmount":1000,' );

		$result = $this->validator->validate( 500, $post_id, 'f_min10', 'usd' );

		$this->assertWPError( $result );
		$this->assertSame( 'donation_below_minimum', $result->get_error_code() );

		$this->assertTrue( $this->validator->validate( 1000, $post_id, 'f_min10', 'usd' ) );
	}

	/**
	 * Test the block minimum error message is formatted in the given currency.
	 */
	public function test_block_minimum_message_uses_currency(): void {
		$post_id = $this->create_form_post( 'f_eur', '"minimumAmount":2000,' );

		$result = $this->validator->validate( 1500, $post_id, 'f_eur', 'eur' );

		$this->assertWPError( $result );
		$this->assertStringContainsString(
			Currency::format_amount( 2000, 'EUR' ),
			$result->get_error_message()
		);
	}

	/**
	 * Test the default block minimum applies when the attribute is absent.
	 */
	public function test_default_block_minimum(): void {
		$post_id = $this->create_form_post( 'f_default' );

		$result = $this->validator->validate( 400, $post_id, 'f_default', 'usd' );

		$this->assertWPError( $result );

		$this->assertTrue( $this->validator->validate( 500, $post_id, 'f_default', 'usd' ) );
	}

	/**
	 * Test validation passes without form context when above the floor.
	 */
	public function test_no_form_context_only_enforces_floor(): void {
		$this->assertTrue( $this->validator->validate( 150, 0, '', 'usd' ) );
		$this->assertTrue( $this->validator->validate( 150, -1, 'f_x', 'usd' ) );
	}
}
