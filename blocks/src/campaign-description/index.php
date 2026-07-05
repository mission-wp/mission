<?php
/**
 * Block Name: Campaign Description
 * Description: The parent campaign's short description, with a link to the campaign.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	$campaign = BlockSupport::resolve_campaign( $attributes );

	// The "learn more" link only makes sense when the campaign was resolved
	// from a fundraiser/team page; on the campaign's own page it would be
	// self-referential.
	$post_type = get_post()->post_type ?? '';
	$is_child  = empty( $attributes['campaignId'] )
		&& in_array( $post_type, [ Fundraiser::POST_TYPE, Team::POST_TYPE ], true );

	if ( ! $campaign ) {
		return;
	}

	// Campaigns without a description get the block's fallback text (editable
	// in the editor), so the section never renders empty.
	$description = trim( $campaign->description );
	if ( '' === $description ) {
		$description = trim( $attributes['fallback'] ?? '' );
	}
	if ( '' === $description ) {
		$description = __( 'This page is part of a larger campaign. Every donation made here counts toward the campaign\'s overall goal.', 'mission-donation-platform' );
	}

	$url = $is_child ? $campaign->get_url() : '';

	ob_start();
	?>
	<div <?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-cd-description' ] ) ); ?>>
		<?php echo wp_kses_post( wpautop( $description ) ); ?>
		<?php if ( $url ) : ?>
			<p class="mission-cd-description__link">
				<a href="<?php echo esc_url( $url ); ?>">
					<?php
					printf(
						/* translators: %s: campaign title */
						esc_html__( 'Learn more about %s', 'mission-donation-platform' ),
						esc_html( $campaign->title )
					);
					?>
					<span aria-hidden="true">&rsaquo;</span>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the campaign description block output.
	 *
	 * @param string   $output     HTML output.
	 * @param Campaign $campaign   Campaign model.
	 * @param array    $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_campaign_description_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
