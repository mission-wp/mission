<?php
/**
 * Block Name: Team Story
 * Description: A team's description of who they are and why they are fundraising.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Team;

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

	if ( ! $team || '' === trim( $team->description ) ) {
		return;
	}

	$output = sprintf(
		'<div %s>%s</div>',
		get_block_wrapper_attributes( [ 'class' => 'mission-ts-story' ] ),
		wpautop( wp_kses_post( $team->description ) )
	);

	/**
	 * Filters the team story block output.
	 *
	 * @param string $output     HTML output.
	 * @param Team   $team       Team model.
	 * @param array  $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_team_story_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
