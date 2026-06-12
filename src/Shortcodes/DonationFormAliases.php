<?php
/**
 * Convenience aliases for the donation form shortcode.
 *
 * @package MissionDP
 */

namespace MissionDP\Shortcodes;

use MissionDP\Constants\Frequency;
use MissionDP\Currency\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Expands flat, major-unit money attributes (amounts="10,25,50") into the
 * donation form block's per-frequency, minor-unit attribute shapes, which are
 * object-typed and therefore not expressible as plain shortcode strings.
 */
class DonationFormAliases {

	/**
	 * Every frequency key the donation form accepts. Frequencies not enabled
	 * on the form simply ignore their entry.
	 *
	 * @var array<string>
	 */
	private const FREQUENCIES = Frequency::ALL;

	/**
	 * Expand alias attributes into typed block attributes.
	 *
	 * All money values are in major units (e.g. dollars), matching what a
	 * donor sees, and are converted to minor units using the site currency.
	 *
	 * @param array<string, mixed> $raw_atts Raw shortcode attributes.
	 *
	 * @return array<string, mixed> Block attributes to merge over the coerced ones.
	 */
	public static function expand( array $raw_atts ): array {
		$decimals   = Currency::get_decimals( self::site_currency() );
		$attributes = [];

		$amounts = self::to_minor_list( $raw_atts['amounts'] ?? '', $decimals );
		if ( $amounts ) {
			$attributes['amountsByFrequency'] = array_fill_keys( self::FREQUENCIES, $amounts );
		}

		$default_amount = self::to_minor( $raw_atts['default_amount'] ?? '', $decimals );
		if ( null !== $default_amount ) {
			$attributes['defaultAmounts'] = array_fill_keys( self::FREQUENCIES, $default_amount );
		}

		$minimum_amount = self::to_minor( $raw_atts['minimum_amount'] ?? '', $decimals );
		if ( null !== $minimum_amount ) {
			$attributes['minimumAmount'] = $minimum_amount;
		}

		return $attributes;
	}

	/**
	 * Get the configured site currency code.
	 *
	 * @return string Uppercase ISO 4217 code.
	 */
	private static function site_currency(): string {
		return strtoupper( get_option( 'missiondp_settings', [] )['currency'] ?? 'USD' );
	}

	/**
	 * Convert a major-unit value to minor units.
	 *
	 * @param mixed $value    Raw shortcode value.
	 * @param int   $decimals Currency decimal places.
	 *
	 * @return int|null Minor units, or null when not numeric.
	 */
	private static function to_minor( mixed $value, int $decimals ): ?int {
		if ( ! is_scalar( $value ) || ! is_numeric( trim( (string) $value ) ) ) {
			return null;
		}

		return (int) round( (float) trim( (string) $value ) * 10 ** $decimals );
	}

	/**
	 * Convert a comma-separated list of major-unit values to minor units.
	 *
	 * @param mixed $value    Raw shortcode value.
	 * @param int   $decimals Currency decimal places.
	 *
	 * @return array<int> Minor-unit amounts; invalid items are discarded.
	 */
	private static function to_minor_list( mixed $value, int $decimals ): array {
		if ( ! is_scalar( $value ) ) {
			return [];
		}

		$amounts = [];

		foreach ( explode( ',', (string) $value ) as $item ) {
			$minor = self::to_minor( $item, $decimals );

			if ( null !== $minor ) {
				$amounts[] = $minor;
			}
		}

		return $amounts;
	}
}
