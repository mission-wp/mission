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
	$team = null;

	if ( ! empty( $attributes['teamId'] ) ) {
		$team = Team::find( (int) $attributes['teamId'] );
	} else {
		$current_post = get_post();
		if ( $current_post && Team::POST_TYPE === $current_post->post_type ) {
			$team = Team::find_by_post_id( $current_post->ID );
		}
	}

	if ( ! $team ) {
		return;
	}

	$image_html = BlockSupport::image_html( $team->cover_image, $team->name );

	if ( '' === $image_html ) {
		return;
	}

	$output = sprintf(
		'<figure %s>%s</figure>',
		get_block_wrapper_attributes( [ 'class' => 'mission-ti-image' ] ),
		$image_html
	);

	/**
	 * Filters the team image block output.
	 *
	 * @param string $output     HTML output.
	 * @param Team   $team       Team model.
	 * @param array  $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_team_image_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
