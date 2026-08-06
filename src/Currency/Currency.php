<?php
/**
 * Currency helper.
 *
 * @package MissionDP
 */

namespace MissionDP\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for currency formatting and symbol lookup.
 */
class Currency {

	/**
	 * Zero-decimal currencies — amounts are already in the smallest unit.
	 */
	private const ZERO_DECIMAL = [
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	];

	/**
	 * Three-decimal currencies — smallest unit is 1/1000.
	 */
	private const THREE_DECIMAL = [
		'BHD',
		'JOD',
		'KWD',
		'OMR',
		'TND',
	];

	/**
	 * Currencies Stripe represents as two-decimal values where the decimal
	 * part must always be 00 (charging fractions is not possible).
	 */
	private const WHOLE_UNIT = [
		'ISK',
		'UGX',
	];

	/**
	 * Stripe's published minimum charge amounts, in minor units, for the
	 * currencies where that minimum exceeds one major unit.
	 *
	 * @see https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
	 */
	private const STRIPE_MINIMUMS = [
		'AED' => 200,
		'CZK' => 1500,
		'DKK' => 250,
		'HKD' => 400,
		'HUF' => 17500,
		'JPY' => 50,
		'KRW' => 50,
		'MXN' => 1000,
		'MYR' => 200,
		'NOK' => 300,
		'PLN' => 200,
		'RON' => 200,
		'SEK' => 300,
		'THB' => 1000,
	];

	/**
	 * Fallback currency symbols for when the intl extension is unavailable.
	 */
	private const SYMBOLS = [
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'JPY' => '¥',
		'CHF' => 'CHF',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'NZD' => 'NZ$',
		'MXN' => 'MX$',
		'BRL' => 'R$',
		'INR' => '₹',
		'ZAR' => 'R',
		'PLN' => 'zł',
		'ILS' => '₪',
		'HKD' => 'HK$',
		'SGD' => 'S$',
	];

	/**
	 * Get the currency symbol for a given currency code.
	 *
	 * Uses the PHP intl extension when available, otherwise falls back
	 * to a static map.
	 *
	 * @param string $currency_code ISO 4217 currency code (e.g. 'USD').
	 *
	 * @return string Currency symbol.
	 */
	public static function get_symbol( string $currency_code = 'USD' ): string {
		$currency_code = strtoupper( $currency_code );

		if ( class_exists( \NumberFormatter::class ) ) {
			$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			$formatter->setTextAttribute( \NumberFormatter::CURRENCY_CODE, $currency_code );

			return $formatter->getSymbol( \NumberFormatter::CURRENCY_SYMBOL );
		}

		return self::SYMBOLS[ $currency_code ] ?? $currency_code;
	}

	/**
	 * Get the number of decimal places for a currency.
	 *
	 * @param string $code Uppercase ISO 4217 currency code.
	 *
	 * @return int 0, 2, or 3.
	 */
	public static function get_decimals( string $code ): int {
		$code = strtoupper( $code );

		if ( in_array( $code, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}

		if ( in_array( $code, self::THREE_DECIMAL, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Get the smallest chargeable increment in minor units.
	 *
	 * ISK and UGX are represented as two-decimal values but cannot be charged
	 * in fractions, so their amounts must be multiples of 100 minor units.
	 *
	 * @param string $code ISO 4217 currency code.
	 *
	 * @return int 100 for whole-unit-only currencies, otherwise 1.
	 */
	public static function rounding_unit( string $code ): int {
		return in_array( strtoupper( $code ), self::WHOLE_UNIT, true ) ? 100 : 1;
	}

	/**
	 * Get the minimum chargeable donation in minor units.
	 *
	 * One major unit, raised to Stripe's published minimum charge amount where
	 * that is higher (e.g. ¥50, 175 HUF). Stripe's minimums technically apply
	 * to the settlement currency, so this is a best-effort guard.
	 *
	 * @param string $code ISO 4217 currency code.
	 *
	 * @return int Minimum amount in minor units.
	 */
	public static function minimum_charge( string $code ): int {
		$code = strtoupper( $code );

		return max( 10 ** self::get_decimals( $code ), self::STRIPE_MINIMUMS[ $code ] ?? 0 );
	}

	/**
	 * Convert a minor-units integer to a major-units float.
	 *
	 * @param int    $minor_units Amount in smallest currency unit.
	 * @param string $code        Uppercase ISO 4217 currency code.
	 *
	 * @return float Display value (e.g. 4500 → 45.00 for USD, 500 → 500 for JPY).
	 */
	public static function minor_to_major( int $minor_units, string $code ): float {
		$decimals = self::get_decimals( $code );

		if ( 0 === $decimals ) {
			return (float) $minor_units;
		}

		return $minor_units / ( 10 ** $decimals );
	}

	/**
	 * Convert a major-units value (what a person enters) to a minor-units integer.
	 *
	 * @param float  $major_units Display value (e.g. 45.00 for USD, 500 for JPY).
	 * @param string $code        ISO 4217 currency code.
	 *
	 * @return int Amount in the smallest currency unit (e.g. 45.00 → 4500 for USD).
	 */
	public static function major_to_minor( float $major_units, string $code ): int {
		$decimals = self::get_decimals( $code );

		return (int) round( $major_units * ( 10 ** $decimals ) );
	}

	/**
	 * Format a minor-unit amount as a currency string.
	 *
	 * @param int    $minor_units   Amount in minor units (e.g. 5000 = $50.00).
	 * @param string $currency_code Uppercase ISO 4217 currency code.
	 *
	 * @return string Formatted amount with symbol (e.g. "$50.00", "¥500").
	 */
	public static function format_amount( int $minor_units, string $currency_code ): string {
		$currency_code = strtoupper( $currency_code );
		$symbol        = self::get_symbol( $currency_code );
		$decimals      = self::get_decimals( $currency_code );
		$major         = self::minor_to_major( $minor_units, $currency_code );

		return $symbol . number_format( $major, $decimals );
	}

	/**
	 * Format a minor-unit amount for the current site locale.
	 *
	 * Mirrors the JS formatAmount() helper (assets/shared/currency.js) so
	 * server-rendered amounts match what Intl.NumberFormat produces after
	 * hydration. Uses the PHP intl extension when available, otherwise falls
	 * back to a symbol-prefixed number_format_i18n().
	 *
	 * @param int    $minor_units      Amount in minor units (e.g. 5000 = $50.00).
	 * @param string $currency_code    Uppercase ISO 4217 currency code.
	 * @param bool   $strip_zero_cents Drop the ".00" when the amount is a whole number.
	 *
	 * @return string Formatted amount with symbol (e.g. "$50.00", "50,00 €").
	 */
	public static function format_amount_i18n( int $minor_units, string $currency_code, bool $strip_zero_cents = false ): string {
		$currency_code = strtoupper( $currency_code );
		$decimals      = self::get_decimals( $currency_code );
		$major         = self::minor_to_major( $minor_units, $currency_code );
		$digits        = $strip_zero_cents && floor( $major ) === $major ? 0 : $decimals;

		if ( class_exists( \NumberFormatter::class ) ) {
			$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $digits );
			$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $digits );

			$formatted = $formatter->formatCurrency( $major, $currency_code );
			if ( false !== $formatted ) {
				return $formatted;
			}
		}

		return self::get_symbol( $currency_code ) . number_format_i18n( $major, $digits );
	}
}
