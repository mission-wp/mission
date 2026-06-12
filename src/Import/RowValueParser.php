<?php
/**
 * Stateless value coercion for import rows.
 *
 * @package MissionDP
 */

namespace MissionDP\Import;

use MissionDP\Currency\Currency;
use MissionDP\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Parses raw CSV/JSON cell values into model-ready amounts, dates, and booleans.
 *
 * @internal Serves the import pipeline; ImportService is the public API.
 */
class RowValueParser {

	/**
	 * Convert a major-unit currency string into minor units.
	 *
	 * @param string      $value    Raw amount string.
	 * @param string|null $currency ISO 4217 code deciding minor-unit decimals; null uses the site currency.
	 */
	public function parse_amount( string $value, ?string $currency = null ): int {
		$cleaned = preg_replace( '/[^0-9.\-]/', '', $this->normalize_decimal_separators( $value ) );

		if ( null === $cleaned || '' === $cleaned || ! is_numeric( $cleaned ) ) {
			return 0;
		}

		$major      = (float) $cleaned;
		$multiplier = 10 ** Currency::get_decimals( $currency ?: $this->site_currency() );

		return (int) round( $major * $multiplier );
	}

	/**
	 * Convert a free-form date string into MySQL datetime.
	 *
	 * @param string $value Date input.
	 */
	public function parse_date( string $value ): ?string {
		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Coerce a CSV truthy/falsy string to bool.
	 *
	 * @param mixed $value Raw value.
	 */
	public function parse_bool( $value ): bool {
		$normalized = strtolower( trim( (string) $value ) );

		return in_array( $normalized, [ '1', 'true', 'yes', 'y', 'on' ], true );
	}

	/**
	 * The site's configured currency code.
	 *
	 * Read per call rather than cached: the handler's ImportService instance
	 * lives for the whole request, and get_option is already cached.
	 *
	 * @return string
	 */
	public function site_currency(): string {
		return (string) ( new SettingsService() )->get( 'currency', 'USD' );
	}

	/**
	 * Normalize European-style separators ahead of numeric parsing.
	 *
	 * When a value contains both '.' and ',', the last of the two is the
	 * decimal separator in every locale, so "1.500,50" and "1,500.50" both
	 * mean 1500.50. Single-separator values keep the US reading (comma as
	 * thousands separator); RowValidator warns on likely decimal commas.
	 *
	 * @param string $value Raw amount string.
	 * @return string
	 */
	private function normalize_decimal_separators( string $value ): string {
		$last_comma = strrpos( $value, ',' );
		$last_dot   = strrpos( $value, '.' );

		if ( false === $last_comma || false === $last_dot ) {
			return $value;
		}

		if ( $last_comma > $last_dot ) {
			return str_replace( ',', '.', str_replace( '.', '', $value ) );
		}

		return $value;
	}
}
