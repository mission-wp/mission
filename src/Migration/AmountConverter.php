<?php
/**
 * Converts source-plugin amounts to Mission's integer minor units.
 *
 * @package MissionDP
 */

namespace MissionDP\Migration;

use MissionDP\Currency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Source plugins typically store amounts as decimal strings in major units
 * (e.g. GiveWP's "25.000000"). Mission stores integer minor units (cents).
 */
class AmountConverter {

	/**
	 * Convert a decimal-string amount in major units to integer minor units.
	 *
	 * Zero-decimal currencies (JPY, KRW, ...) pass through unchanged.
	 *
	 * @param string $value    Decimal amount, e.g. "25.00".
	 * @param string $currency ISO 4217 currency code.
	 */
	public static function to_minor_units( string $value, string $currency ): int {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $value );

		if ( null === $cleaned || '' === $cleaned || ! is_numeric( $cleaned ) ) {
			return 0;
		}

		$multiplier = 10 ** Currency::get_decimals( $currency );

		return (int) round( (float) $cleaned * $multiplier );
	}
}
