<?php
/**
 * Tests for the TipCalculator class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Tip;

use MissionDP\Settings\SettingsService;
use MissionDP\Tip\TipCalculator;
use WP_UnitTestCase;

/**
 * TipCalculator test class.
 */
class TipCalculatorTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( SettingsService::OPTION_NAME );

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// default_fixed_fee / max_fixed_fee
	// -------------------------------------------------------------------------

	/**
	 * Test the default fixed fee scales with the currency's decimals.
	 */
	public function test_default_fixed_fee_scales_with_decimals(): void {
		$this->assertSame( 30, TipCalculator::default_fixed_fee( 'USD' ) );
		$this->assertSame( 30, TipCalculator::default_fixed_fee( 'EUR' ) );
		$this->assertSame( 0, TipCalculator::default_fixed_fee( 'JPY' ) );
		$this->assertSame( 300, TipCalculator::default_fixed_fee( 'KWD' ) );
	}

	/**
	 * Test the default fixed fee is case-insensitive.
	 */
	public function test_default_fixed_fee_case_insensitive(): void {
		$this->assertSame( 30, TipCalculator::default_fixed_fee( 'usd' ) );
		$this->assertSame( 0, TipCalculator::default_fixed_fee( 'jpy' ) );
	}

	/**
	 * Test the maximum fixed fee per decimal class.
	 */
	public function test_max_fixed_fee(): void {
		$this->assertSame( 1000, TipCalculator::max_fixed_fee( 'USD' ) );
		$this->assertSame( 1000, TipCalculator::max_fixed_fee( 'JPY' ) );
		$this->assertSame( 10000, TipCalculator::max_fixed_fee( 'KWD' ) );
	}

	// -------------------------------------------------------------------------
	// absorb_fee
	// -------------------------------------------------------------------------

	/**
	 * Test fee absorption shifts the incremental fee from tip to donation.
	 *
	 * With donation=5000, tip=500, rate=2.9%, fixed=30:
	 *   fee_with_tip    = round(5500 * 0.029 + 30) = 190
	 *   fee_without_tip = round(5000 * 0.029 + 30) = 175
	 *   incremental     = 15 → donation 5015, tip 485.
	 */
	public function test_absorb_fee_shifts_incremental_fee(): void {
		$donation = 5000;
		$tip      = 500;

		TipCalculator::absorb_fee( $donation, $tip, 0.029, 30 );

		$this->assertSame( 5015, $donation );
		$this->assertSame( 485, $tip );
	}

	/**
	 * Test fee absorption preserves the total charge.
	 */
	public function test_absorb_fee_preserves_total(): void {
		foreach ( [ [ 5000, 500 ], [ 100, 15 ], [ 123456, 9876 ] ] as [ $donation, $tip ] ) {
			$adjusted_donation = $donation;
			$adjusted_tip      = $tip;

			TipCalculator::absorb_fee( $adjusted_donation, $adjusted_tip, 0.029, 30 );

			$this->assertSame( $donation + $tip, $adjusted_donation + $adjusted_tip );
		}
	}

	/**
	 * Test fee absorption keeps whole-unit currencies chargeable.
	 *
	 * For UGX the incremental fee is rounded to a multiple of 100 minor units
	 * so the shifted donation and tip stay whole-unit amounts.
	 */
	public function test_absorb_fee_rounds_for_whole_unit_currencies(): void {
		$donation = 50000; // 500 UGX.
		$tip      = 5000;  // 50 UGX.

		TipCalculator::absorb_fee( $donation, $tip, 0.029, 30, 'ugx' );

		$this->assertSame( 0, $donation % 100 );
		$this->assertSame( 0, $tip % 100 );
		$this->assertSame( 55000, $donation + $tip );
	}

	/**
	 * Test fee absorption is a no-op without a tip.
	 */
	public function test_absorb_fee_noop_without_tip(): void {
		$donation = 5000;
		$tip      = 0;

		TipCalculator::absorb_fee( $donation, $tip, 0.029, 30 );

		$this->assertSame( 5000, $donation );
		$this->assertSame( 0, $tip );
	}

	// -------------------------------------------------------------------------
	// get_fee_params_from_settings
	// -------------------------------------------------------------------------

	/**
	 * Test default fee params on a fresh USD site.
	 */
	public function test_fee_params_default_usd(): void {
		[ $rate, $fixed ] = TipCalculator::get_fee_params_from_settings( new SettingsService() );

		$this->assertEqualsWithDelta( 0.029, $rate, 1e-9 );
		$this->assertSame( 30, $fixed );
	}

	/**
	 * Test the default fixed fee follows the configured currency.
	 */
	public function test_fee_params_default_scales_for_currency(): void {
		update_option( SettingsService::OPTION_NAME, [ 'currency' => 'JPY' ] );

		[ , $fixed ] = TipCalculator::get_fee_params_from_settings( new SettingsService() );

		$this->assertSame( 0, $fixed );

		update_option( SettingsService::OPTION_NAME, [ 'currency' => 'KWD' ] );

		[ , $fixed ] = TipCalculator::get_fee_params_from_settings( new SettingsService() );

		$this->assertSame( 300, $fixed );
	}

	/**
	 * Test an explicitly stored fixed fee wins over the currency default.
	 */
	public function test_fee_params_stored_value_wins(): void {
		update_option(
			SettingsService::OPTION_NAME,
			[
				'currency'         => 'JPY',
				'stripe_fee_fixed' => 50,
			]
		);

		[ , $fixed ] = TipCalculator::get_fee_params_from_settings( new SettingsService() );

		$this->assertSame( 50, $fixed );
	}
}
