<?php
/**
 * Shared rendering helpers for peer-to-peer blocks.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers the P2P display blocks share: the primary-color CSS variables
 * (derived from the admin color setting) and progress math.
 */
class BlockSupport {

	/**
	 * Build the inline `--mission-*` primary color custom properties for a block
	 * root, derived from the admin color setting.
	 *
	 * @return string A `style` attribute value (already safe for esc_attr).
	 */
	public static function primary_color_style(): string {
		$settings = get_option( 'missiondp_settings', [] );
		$primary  = (string) ( $settings['primary_color'] ?? '#2fa36b' );
		$hex      = ltrim( $primary, '#' );

		$component = static function ( int $offset, float $percent ) use ( $hex ): int {
			return max( 0, (int) round( hexdec( substr( $hex, $offset, 2 ) ) * ( 1 - $percent / 100 ) ) );
		};

		$hover = sprintf( '#%02x%02x%02x', $component( 0, 12 ), $component( 2, 12 ), $component( 4, 12 ) );

		$r         = hexdec( substr( $hex, 0, 2 ) );
		$g         = hexdec( substr( $hex, 2, 2 ) );
		$b         = hexdec( substr( $hex, 4, 2 ) );
		$luminance = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
		$text      = $luminance > 0.5 ? '#1e1e1e' : '#ffffff';

		return sprintf(
			'--mission-primary: %s; --mission-primary-hover: %s; --mission-primary-text: %s;',
			$primary,
			$hover,
			$text
		);
	}

	/**
	 * Progress percentage toward a goal, clamped to 0-100.
	 *
	 * @param int $raised Amount raised (minor units).
	 * @param int $goal   Goal (minor units).
	 * @return int
	 */
	public static function progress_percent( int $raised, int $goal ): int {
		if ( $goal <= 0 ) {
			return 0;
		}

		return (int) min( 100, round( $raised / $goal * 100 ) );
	}

	/**
	 * Render an image stored as either an attachment ID or a URL.
	 *
	 * Fundraiser/team cover and profile images are a varchar that may hold either
	 * form, so callers don't need to branch.
	 *
	 * @param string $image Attachment ID or image URL.
	 * @param string $alt   Alt text.
	 * @param string $size  Registered image size for attachment IDs.
	 * @param array  $attr  Extra HTML attributes for the img tag (e.g. style, class).
	 * @return string Image HTML, or an empty string when there is no image.
	 */
	public static function image_html( string $image, string $alt = '', string $size = 'large', array $attr = [] ): string {
		$image = trim( $image );

		if ( '' === $image ) {
			return '';
		}

		if ( ctype_digit( $image ) ) {
			return (string) wp_get_attachment_image( (int) $image, $size, false, array_merge( [ 'alt' => $alt ], $attr ) );
		}

		$extra = '';
		foreach ( $attr as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$extra .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return sprintf( '<img src="%s" alt="%s" loading="lazy"%s />', esc_url( $image ), esc_attr( $alt ), $extra );
	}

	/**
	 * Build the inline style string for an image element from block attributes.
	 *
	 * Mirrors the core Image block (and the JS `computeImageStyles` helper):
	 * aspect ratio, width/height, object-fit scale, border, and box-shadow.
	 * Border and shadow come from the skip-serialized `style` attribute and are
	 * applied to the image rather than the wrapper.
	 *
	 * @param array $attributes Block attributes.
	 * @return string A `;`-joined style string, safe for esc_attr.
	 */
	public static function image_inline_style( array $attributes ): string {
		$styles       = [];
		$aspect_ratio = $attributes['aspectRatio'] ?? '';
		$width        = $attributes['width'] ?? '';
		$height       = $attributes['height'] ?? '';
		$scale        = $attributes['scale'] ?? 'cover';
		$scale        = in_array( $scale, [ 'cover', 'contain' ], true ) ? $scale : 'cover';

		if ( $aspect_ratio ) {
			$styles[] = 'aspect-ratio:' . $aspect_ratio;
		}
		if ( $aspect_ratio || ( $width && $height ) ) {
			$styles[] = 'object-fit:' . $scale;
		}
		if ( $width ) {
			$styles[] = 'width:' . $width . 'px';
		}
		if ( $height ) {
			$styles[] = 'height:' . $height . 'px';
		}

		$border = $attributes['style']['border'] ?? [];
		if ( ! empty( $border['radius'] ) ) {
			$radius = $border['radius'];
			if ( is_array( $radius ) ) {
				$styles[] = 'border-top-left-radius:' . ( $radius['topLeft'] ?? '0' );
				$styles[] = 'border-top-right-radius:' . ( $radius['topRight'] ?? '0' );
				$styles[] = 'border-bottom-left-radius:' . ( $radius['bottomLeft'] ?? '0' );
				$styles[] = 'border-bottom-right-radius:' . ( $radius['bottomRight'] ?? '0' );
			} else {
				$styles[] = 'border-radius:' . $radius;
			}
		}
		if ( ! empty( $border['width'] ) ) {
			$styles[] = 'border-width:' . $border['width'];
		}
		if ( ! empty( $border['style'] ) ) {
			$styles[] = 'border-style:' . $border['style'];
		}
		if ( ! empty( $border['color'] ) ) {
			$styles[] = 'border-color:' . self::resolve_preset( $border['color'], 'color' );
		}

		$shadow = $attributes['style']['shadow'] ?? '';
		if ( $shadow ) {
			$styles[] = 'box-shadow:' . self::resolve_preset( $shadow, 'shadow' );
		}

		return implode( ';', $styles );
	}

	/**
	 * Expand a theme preset reference (`var:preset|key|slug`) into a CSS value.
	 *
	 * @param string $value      Raw value.
	 * @param string $preset_key Preset namespace ('color' or 'shadow').
	 * @return string A usable CSS value.
	 */
	private static function resolve_preset( string $value, string $preset_key ): string {
		$prefix = 'var:preset|' . $preset_key . '|';
		if ( str_starts_with( $value, $prefix ) ) {
			return 'var(--wp--preset--' . $preset_key . '--' . str_replace( $prefix, '', $value ) . ')';
		}
		return $value;
	}
}
