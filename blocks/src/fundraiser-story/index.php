<?php
/**
 * Block Name: Fundraiser Story
 * Description: The story a fundraiser wrote about why they are fundraising.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Fundraiser;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the fundraiser from the block attribute or the queried shell page.
	$fundraiser = BlockSupport::resolve_fundraiser( $attributes );

	if ( ! $fundraiser ) {
		return;
	}

	// Fundraisers who skipped the story get the block's fallback text
	// (editable in the editor), so the section never renders empty.
	$story = trim( $fundraiser->story );
	if ( '' === $story ) {
		$story = trim( $attributes['fallback'] ?? '' );
	}
	if ( '' === $story ) {
		$story = __( 'I am raising money for a cause that means a lot to me. Every donation, big or small, helps me get closer to my goal. Thank you for your support!', 'mission-donation-platform' );
	}

	$output = sprintf(
		'<div %s>%s</div>',
		get_block_wrapper_attributes( [ 'class' => 'mission-fs-story' ] ),
		wpautop( wp_kses_post( $story ) )
	);

	/**
	 * Filters the fundraiser story block output.
	 *
	 * @param string     $output     HTML output.
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param array      $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_fundraiser_story_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
