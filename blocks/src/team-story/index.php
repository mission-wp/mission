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
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the team from the block attribute or the queried shell page.
	$team = BlockSupport::resolve_team( $attributes );

	if ( ! $team ) {
		return;
	}

	// Teams without a story get the block's fallback text (editable in the
	// editor), so the section never renders empty.
	$story = trim( $team->description );
	if ( '' === $story ) {
		$story = trim( $attributes['fallback'] ?? '' );
	}
	if ( '' === $story ) {
		$story = __( 'We have teamed up because we can make a bigger difference together. Every donation to a team member brings us closer to our shared goal.', 'mission-donation-platform' );
	}

	$output = sprintf(
		'<div %s>%s</div>',
		get_block_wrapper_attributes( [ 'class' => 'mission-ts-story' ] ),
		wpautop( wp_kses_post( $story ) )
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
