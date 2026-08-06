<?php
/**
 * Block Name: Team Image
 * Description: A team's cover photo.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the team from the block attribute or the queried shell page.
	$team = BlockSupport::resolve_team( $attributes );

	if ( ! $team ) {
		return;
	}

	// Validate the requested resolution against the registered image sizes.
	$resolution    = $attributes['resolution'] ?? 'large';
	$valid_sizes   = array_keys( wp_get_registered_image_subsizes() );
	$valid_sizes[] = 'full';
	$resolution    = in_array( $resolution, $valid_sizes, true ) ? $resolution : 'large';

	$alt = $attributes['alt'] ?? '';
	$alt = '' !== $alt ? $alt : $team->name;

	$inline_style = BlockSupport::image_inline_style( $attributes );

	$image_html = BlockSupport::image_html( $team->cover_image, $alt, $resolution, [ 'style' => $inline_style ] );

	if ( '' === $image_html ) {
		return;
	}

	// Border is skip-serialized and shadow is applied to the image, so adjust the
	// wrapper to match (matching the Campaign Image block).
	$border_styles   = $attributes['style']['border'] ?? [];
	$shadow          = $attributes['style']['shadow'] ?? '';
	$wrapper_classes = 'mission-ti-image';
	if ( ! empty( $border_styles ) ) {
		$wrapper_classes .= ' has-custom-border';
	}
	$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => $wrapper_classes ] );
	if ( $shadow ) {
		$wrapper_attrs = preg_replace( '/box-shadow:[^;]*;?\s*/', '', $wrapper_attrs );
	}

	$output = sprintf( '<figure %s>%s</figure>', $wrapper_attrs, $image_html );

	/**
	 * Filters the team image block output.
	 *
	 * @param string $output     HTML output.
	 * @param Team   $team       Team model.
	 * @param array  $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_team_image_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
