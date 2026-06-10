<?php
/**
 * Tests for the DonationFormAliases class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Shortcodes;

use MissionDP\Shortcodes\DonationFormAliases;
use WP_UnitTestCase;

/**
 * DonationFormAliases test class.
 */
class DonationFormAliasesTest extends WP_UnitTestCase {

	/**
	 * Every frequency key the donation form accepts.
	 *
	 * @var array<string>
	 */
	private const FREQUENCIES = [ 'one_time', 'weekly', 'monthly', 'quarterly', 'annually' ];

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'missiondp_settings' );

		parent::tear_down();
	}

	/**
	 * Major-unit amounts expand to minor units under every frequency key.
	 */
	public function test_amounts_expand_to_all_frequencies(): void {
		$attributes = DonationFormAliases::expand( [ 'amounts' => '10,25' ] );

		$this->assertSame( self::FREQUENCIES, array_keys( $attributes['amountsByFrequency'] ) );

		foreach ( self::FREQUENCIES as $frequency ) {
			$this->assertSame( [ 1000, 2500 ], $attributes['amountsByFrequency'][ $frequency ] );
		}
	}

	/**
	 * Zero-decimal currencies are not multiplied by 100.
	 */
	public function test_amounts_respect_zero_decimal_currency(): void {
		update_option( 'missiondp_settings', [ 'currency' => 'JPY' ] );

		$attributes = DonationFormAliases::expand( [ 'amounts' => '500' ] );

		$this->assertSame( [ 500 ], $attributes['amountsByFrequency']['one_time'] );
	}

	/**
	 * default_amount and minimum_amount convert from major units.
	 */
	public function test_default_and_minimum_amount_convert(): void {
		$attributes = DonationFormAliases::expand(
			[
				'default_amount' => '25',
				'minimum_amount' => '5',
			]
		);

		$this->assertSame( array_fill_keys( self::FREQUENCIES, 2500 ), $attributes['defaultAmounts'] );
		$this->assertSame( 500, $attributes['minimumAmount'] );
	}

	/**
	 * Invalid or absent values produce no attributes.
	 */
	public function test_invalid_values_are_dropped(): void {
		$this->assertSame( [], DonationFormAliases::expand( [] ) );
		$this->assertSame( [], DonationFormAliases::expand( [ 'amounts' => 'abc' ] ) );
		$this->assertSame( [], DonationFormAliases::expand( [ 'default_amount' => '' ] ) );
	}

	/**
	 * Invalid items in an amounts list are discarded, valid ones kept.
	 */
	public function test_mixed_amounts_list_keeps_valid_items(): void {
		$attributes = DonationFormAliases::expand( [ 'amounts' => '10,abc,25' ] );

		$this->assertSame( [ 1000, 2500 ], $attributes['amountsByFrequency']['one_time'] );
	}
}
