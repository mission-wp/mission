<?php
/**
 * Computes primary color CSS custom properties from a hex color.
 *
 * Shared by the donor dashboard and donation form blocks.
 *
 * @package Mission
 */

namespace Mission\DonorDashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Primary color resolver.
 */
class PrimaryColorResolver {

	/**
	 * Compute CSS custom properties from a primary color hex.
	 *
	 * Returns an associative array of property => value pairs suitable for
	 * building an inline style attribute.
	 *
	 * @param string $hex Primary color hex (e.g. '#2fa36b').
	 * @return array<string, string> CSS property => value map.
	 */
	public static function compute( string $hex ): array {
		$hex_trimmed = ltrim( $hex, '#' );
		$r           = hexdec( substr( $hex_trimmed, 0, 2 ) );
		$g           = hexdec( substr( $hex_trimmed, 2, 2 ) );
		$b           = hexdec( substr( $hex_trimmed, 4, 2 ) );
		$luminance   = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;

		$darken = static function ( string $color, float $percent ): string {
			$color = ltrim( $color, '#' );
			$dr    = max( 0, (int) round( hexdec( substr( $color, 0, 2 ) ) * ( 1 - $percent / 100 ) ) );
			$dg    = max( 0, (int) round( hexdec( substr( $color, 2, 2 ) ) * ( 1 - $percent / 100 ) ) );
			$db    = max( 0, (int) round( hexdec( substr( $color, 4, 2 ) ) * ( 1 - $percent / 100 ) ) );
			return sprintf( '#%02x%02x%02x', $dr, $dg, $db );
		};

		$primary_text          = $luminance > 0.5 ? '#1e1e1e' : '#ffffff';
		$primary_text_on_light = $luminance > 0.5 ? $darken( $hex, 45 ) : $hex;

		return [
			'--mission-primary'               => $hex,
			'--mission-primary-hover'         => $darken( $hex, 12 ),
			'--mission-primary-light'         => "color-mix(in srgb, {$hex} 10%, transparent)",
			'--mission-primary-text'          => $primary_text,
			'--mission-primary-text-on-light' => $primary_text_on_light,
		];
	}

	/**
	 * Build an inline style string from computed properties.
	 *
	 * @param string $hex Primary color hex.
	 * @return string Escaped inline style value.
	 */
	public static function inline_style( string $hex ): string {
		$parts = [];

		foreach ( self::compute( $hex ) as $prop => $value ) {
			$parts[] = esc_attr( $prop ) . ':' . esc_attr( $value );
		}

		return implode( ';', $parts );
	}
}
