<?php
/**
 * Block Name: Fundraiser Image
 * Description: A fundraiser's cover photo, set from their dashboard.
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
	$fundraiser = null;

	if ( ! empty( $attributes['fundraiserId'] ) ) {
		$fundraiser = Fundraiser::find( (int) $attributes['fundraiserId'] );
	} else {
		$current_post = get_post();
		if ( $current_post && Fundraiser::POST_TYPE === $current_post->post_type ) {
			$fundraiser = Fundraiser::find_by_post_id( $current_post->ID );
		}
	}

	if ( ! $fundraiser ) {
		return;
	}

	$donor = $fundraiser->donor();
	$name  = $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
	$name  = $name ?: __( 'A fundraiser', 'mission-donation-platform' );

	$image_html = BlockSupport::image_html( $fundraiser->cover_image, $name );

	if ( '' === $image_html ) {
		return;
	}

	$output = sprintf(
		'<figure %s>%s</figure>',
		get_block_wrapper_attributes( [ 'class' => 'mission-fi-image' ] ),
		$image_html
	);

	/**
	 * Filters the fundraiser image block output.
	 *
	 * @param string     $output     HTML output.
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param array      $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_fundraiser_image_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
