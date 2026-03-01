<?php
/**
 * Currency helper.
 *
 * @package Mission
 */

namespace Mission\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for currency formatting and symbol lookup.
 */
class Currency {

	/**
	 * Fallback currency symbols for when the intl extension is unavailable.
	 */
	private const SYMBOLS = array(
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
	);

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
}
