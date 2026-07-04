<?php
/**
 * Shared rendering helpers for peer-to-peer blocks.
 *
 * @package MissionDP
 */

namespace MissionDP\P2P;

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\DonorDashboard\PrimaryColorResolver;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers the P2P display blocks share: model resolution from block
 * attributes, the primary-color CSS variables (derived from the admin color
 * setting), and progress math.
 */
class BlockSupport {

	/**
	 * Resolve the fundraiser a block should render, from the block attribute or
	 * the queried fundraiser shell page.
	 *
	 * @param array $attributes Block attributes.
	 * @return Fundraiser|null
	 */
	public static function resolve_fundraiser( array $attributes ): ?Fundraiser {
		if ( ! empty( $attributes['fundraiserId'] ) ) {
			return Fundraiser::find( (int) $attributes['fundraiserId'] );
		}

		$current_post = get_post();
		if ( $current_post && Fundraiser::POST_TYPE === $current_post->post_type ) {
			return Fundraiser::find_by_post_id( $current_post->ID );
		}

		return null;
	}

	/**
	 * Resolve the team a block should render, from the block attribute or the
	 * queried team shell page.
	 *
	 * @param array $attributes Block attributes.
	 * @return Team|null
	 */
	public static function resolve_team( array $attributes ): ?Team {
		if ( ! empty( $attributes['teamId'] ) ) {
			return Team::find( (int) $attributes['teamId'] );
		}

		$current_post = get_post();
		if ( $current_post && Team::POST_TYPE === $current_post->post_type ) {
			return Team::find_by_post_id( $current_post->ID );
		}

		return null;
	}

	/**
	 * Resolve the campaign a block should render, from the block attribute or
	 * the queried campaign page.
	 *
	 * @param array $attributes Block attributes.
	 * @return Campaign|null
	 */
	public static function resolve_campaign( array $attributes ): ?Campaign {
		if ( ! empty( $attributes['campaignId'] ) ) {
			return Campaign::find( (int) $attributes['campaignId'] );
		}

		$current_post = get_post();
		if ( $current_post && CampaignPostType::POST_TYPE === $current_post->post_type ) {
			return Campaign::find_by_post_id( $current_post->ID );
		}

		return null;
	}

	/**
	 * Build the inline `--mission-*` primary color custom properties for a block
	 * root, derived from the admin color setting.
	 *
	 * @return string A `style` attribute value (already safe for esc_attr).
	 */
	public static function primary_color_style(): string {
		$settings = get_option( 'missiondp_settings', [] );
		$primary  = (string) ( $settings['primary_color'] ?? '#2fa36b' );

		return PrimaryColorResolver::inline_style( $primary );
	}

	/**
	 * Up-to-two-letter initials from a display name.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	public static function initials( string $name ): string {
		$parts   = preg_split( '/\s+/', trim( $name ) ) ?: [];
		$letters = '';
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$letters .= mb_substr( $part, 0, 1 );
			}
			if ( mb_strlen( $letters ) >= 2 ) {
				break;
			}
		}

		return mb_strtoupper( $letters ?: mb_substr( trim( $name ), 0, 1 ) ) ?: '?';
	}

	/**
	 * The gold/silver/bronze medal SVG for a leaderboard rank.
	 *
	 * @param int $rank Rank (1-3).
	 * @return string SVG markup, or an empty string for ranks off the podium.
	 */
	public static function medal_svg( int $rank ): string {
		$palettes = [
			1 => [ '#D4A843', '#C4962F', '#E8C96A', '#7A5C1F' ],
			2 => [ '#B0B4BC', '#9CA0A8', '#D0D4DC', '#5C5F66' ],
			3 => [ '#C68E5B', '#B07A48', '#DAA872', '#6B4420' ],
		];

		if ( ! isset( $palettes[ $rank ] ) ) {
			return '';
		}

		[ $fill, $stroke, $ring, $number ] = $palettes[ $rank ];

		return sprintf(
			'<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="%1$s" stroke="%2$s" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="%3$s" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="%4$s" font-family="-apple-system, sans-serif">%5$d</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="%1$s" stroke="%2$s" stroke-width="0.75" stroke-linejoin="round"/></svg>',
			$fill,
			$stroke,
			$ring,
			$number,
			$rank
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
