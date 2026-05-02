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
		'UGX',
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
}
