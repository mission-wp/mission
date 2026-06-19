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

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the fundraiser from the block attribute or the queried shell page.
	$fundraiser = null;

	if ( ! empty( $attributes['fundraiserId'] ) ) {
		$fundraiser = Fundraiser::find( (int) $attributes['fundraiserId'] );
	} else {
		$current_post = get_post();
		if ( $current_post && Fundraiser::POST_TYPE === $current_post->post_type ) {
			$fundraiser = Fundraiser::find_by_post_id( $current_post->ID );
		}
	}

	if ( ! $fundraiser || '' === trim( $fundraiser->story ) ) {
		return;
	}

	$output = sprintf(
		'<div %s>%s</div>',
		get_block_wrapper_attributes( [ 'class' => 'mission-fs-story' ] ),
		wpautop( wp_kses_post( $fundraiser->story ) )
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
