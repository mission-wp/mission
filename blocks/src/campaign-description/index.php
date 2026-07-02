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

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the campaign. On a fundraiser/team page it is the parent campaign;
	// the "learn more" link only makes sense from that child context.
	$campaign   = null;
	$is_child   = false;

	if ( ! empty( $attributes['campaignId'] ) ) {
		$campaign = Campaign::find( (int) $attributes['campaignId'] );
	} else {
		$current_post = get_post();
		$post_type    = $current_post->post_type ?? '';

		if ( Fundraiser::POST_TYPE === $post_type ) {
			$campaign = Fundraiser::find_by_post_id( $current_post->ID )?->campaign();
			$is_child = true;
		} elseif ( Team::POST_TYPE === $post_type ) {
			$campaign = Team::find_by_post_id( $current_post->ID )?->campaign();
			$is_child = true;
		} elseif ( CampaignPostType::POST_TYPE === $post_type ) {
			$campaign = Campaign::find_by_post_id( $current_post->ID );
		}
	}

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
