<?php
/**
 * Tests for the Currency helper class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Currency;

use MissionDP\Currency\Currency;
use WP_UnitTestCase;

/**
 * Currency test class.
 */
class CurrencyTest extends WP_UnitTestCase {

	/**
	 * Test decimals for two-decimal currencies.
	 */
	public function test_get_decimals_two_decimal_currencies(): void {
		$this->assertSame( 2, Currency::get_decimals( 'USD' ) );
		$this->assertSame( 2, Currency::get_decimals( 'EUR' ) );
		$this->assertSame( 2, Currency::get_decimals( 'GBP' ) );
	}

	/**
	 * Test decimals for zero-decimal currencies.
	 */
	public function test_get_decimals_zero_decimal_currencies(): void {
		$this->assertSame( 0, Currency::get_decimals( 'JPY' ) );
		$this->assertSame( 0, Currency::get_decimals( 'KRW' ) );
		$this->assertSame( 0, Currency::get_decimals( 'VND' ) );
	}

	/**
	 * Test decimals for three-decimal currencies.
	 */
	public function test_get_decimals_three_decimal_currencies(): void {
		$this->assertSame( 3, Currency::get_decimals( 'KWD' ) );
		$this->assertSame( 3, Currency::get_decimals( 'BHD' ) );
	}

	/**
	 * Test ISK and UGX are two-decimal in Stripe's API representation.
	 *
	 * Both transitioned to zero-decimal in ISO 4217, but Stripe represents
	 * them as two-decimal values (charge 5 UGX = amount 500).
	 */
	public function test_isk_and_ugx_are_two_decimal(): void {
		$this->assertSame( 2, Currency::get_decimals( 'ISK' ) );
		$this->assertSame( 2, Currency::get_decimals( 'UGX' ) );
	}

	/**
	 * Test the rounding unit for whole-unit-only currencies.
	 */
	public function test_rounding_unit(): void {
		$this->assertSame( 100, Currency::rounding_unit( 'ISK' ) );
		$this->assertSame( 100, Currency::rounding_unit( 'UGX' ) );
		$this->assertSame( 100, Currency::rounding_unit( 'isk' ) );
		$this->assertSame( 1, Currency::rounding_unit( 'USD' ) );
		$this->assertSame( 1, Currency::rounding_unit( 'JPY' ) );
	}

	/**
	 * Test the minimum charge floor per currency.
	 */
	public function test_minimum_charge(): void {
		// One major unit where Stripe publishes no higher minimum.
		$this->assertSame( 100, Currency::minimum_charge( 'USD' ) );
		$this->assertSame( 100, Currency::minimum_charge( 'EUR' ) );
		$this->assertSame( 1000, Currency::minimum_charge( 'KWD' ) );

		// Stripe's published minimums where they exceed one major unit.
		$this->assertSame( 50, Currency::minimum_charge( 'JPY' ) );
		$this->assertSame( 17500, Currency::minimum_charge( 'HUF' ) );
		$this->assertSame( 1500, Currency::minimum_charge( 'CZK' ) );
		$this->assertSame( 1000, Currency::minimum_charge( 'MXN' ) );
		$this->assertSame( 50, Currency::minimum_charge( 'jpy' ) );
	}

	/**
	 * Test decimals lookup is case-insensitive.
	 */
	public function test_get_decimals_case_insensitive(): void {
		$this->assertSame( 0, Currency::get_decimals( 'jpy' ) );
		$this->assertSame( 3, Currency::get_decimals( 'kwd' ) );
	}

	/**
	 * Test unknown currencies default to two decimals.
	 */
	public function test_get_decimals_unknown_defaults_to_two(): void {
		$this->assertSame( 2, Currency::get_decimals( 'XYZ' ) );
	}

	/**
	 * Test minor-to-major conversion per decimal class.
	 */
	public function test_minor_to_major(): void {
		$this->assertSame( 45.0, Currency::minor_to_major( 4500, 'USD' ) );
		$this->assertSame( 500.0, Currency::minor_to_major( 500, 'JPY' ) );
		$this->assertSame( 1.5, Currency::minor_to_major( 1500, 'KWD' ) );
	}

	/**
	 * Test format_amount uses two decimals for USD.
	 */
	public function test_format_amount_usd(): void {
		$formatted = Currency::format_amount( 5000, 'USD' );

		$this->assertStringContainsString( '50.00', $formatted );
	}

	/**
	 * Test format_amount uses no decimals for JPY.
	 */
	public function test_format_amount_jpy(): void {
		$formatted = Currency::format_amount( 500, 'JPY' );

		$this->assertStringContainsString( '500', $formatted );
		$this->assertStringNotContainsString( '.', $formatted );
	}

	/**
	 * Test format_amount uses three decimals for KWD.
	 */
	public function test_format_amount_kwd(): void {
		$formatted = Currency::format_amount( 1500, 'KWD' );

		$this->assertStringContainsString( '1.500', $formatted );
	}

	/**
	 * Test get_symbol returns the dollar sign for USD.
	 */
	public function test_get_symbol_usd(): void {
		$this->assertSame( '$', Currency::get_symbol( 'USD' ) );
	}
}
