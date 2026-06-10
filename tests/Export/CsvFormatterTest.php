<?php
/**
 * Tests for the CsvFormatter class.
 *
 * @package MissionDP
 */

namespace MissionDP\Tests\Export;

use MissionDP\Export\Formatters\CsvFormatter;
use WP_UnitTestCase;

/**
 * CsvFormatter test class.
 */
class CsvFormatterTest extends WP_UnitTestCase {

	/**
	 * Format a single amount value through the CSV formatter.
	 *
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency Currency code for the row.
	 * @return string The formatted amount cell.
	 */
	private function format_amount_cell( int $amount, string $currency ): string {
		$formatter = new CsvFormatter();

		$csv = $formatter->format(
			[
				[
					'key'   => 'amount',
					'label' => 'Amount',
					'type'  => 'amount',
				],
			],
			[
				[
					'amount'    => $amount,
					'_currency' => $currency,
				],
			],
			'transactions'
		);

		$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );

		return $lines[1];
	}

	/**
	 * Test amounts export with two decimals for USD.
	 */
	public function test_amount_two_decimals_usd(): void {
		$this->assertSame( '10.50', $this->format_amount_cell( 1050, 'usd' ) );
	}

	/**
	 * Test amounts export with no decimals for JPY.
	 */
	public function test_amount_zero_decimals_jpy(): void {
		$this->assertSame( '500', $this->format_amount_cell( 500, 'jpy' ) );
	}

	/**
	 * Test amounts export with three decimals for KWD.
	 */
	public function test_amount_three_decimals_kwd(): void {
		$this->assertSame( '1.500', $this->format_amount_cell( 1500, 'kwd' ) );
	}
}
