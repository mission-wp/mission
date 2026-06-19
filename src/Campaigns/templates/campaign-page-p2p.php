<?php
/**
 * Default page content for a peer-to-peer campaign.
 *
 * Variables available:
 *
 * @var int    $campaign_id Campaign table ID.
 * @var string $org_name    Escaped organization name.
 * @var string $description Escaped campaign description (may be empty).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent.Incorrect -- Block markup must be unindented.
?>
<!-- wp:mission-donation-platform/campaign-image {"aspectRatio":"16/9","align":"center","style":{"border":{"radius":{"topLeft":"8px","topRight":"8px","bottomLeft":"8px","bottomRight":"8px"},"width":"1px"}},"borderColor":"contrast"} /-->

<!-- wp:mission-donation-platform/campaign-progress {"campaignId":<?php echo (int) $campaign_id; ?>} /-->
<?php if ( $description ) : ?>

<!-- wp:heading {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|10"}}}} -->
<h2 class="wp-block-heading" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--10)"><?php echo esc_html__( 'About This Campaign', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo wp_kses_post( $description ); ?></p>
<!-- /wp:paragraph -->

<?php endif; ?>
<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Leaderboard', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:mission-donation-platform/top-teams {"campaignId":<?php echo (int) $campaign_id; ?>} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:mission-donation-platform/top-fundraisers {"campaignId":<?php echo (int) $campaign_id; ?>} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Recent Donations', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/recent-donors /-->

<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center"><?php echo esc_html__( 'Make a Donation', 'mission-donation-platform' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php echo esc_html__( 'Your gift supports the campaign and helps every fundraiser reach their goal.', 'mission-donation-platform' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:mission-donation-platform/donation-form {"campaignId":<?php echo (int) $campaign_id; ?>} /-->

<!-- wp:mission-donation-platform/signup-modal {"campaignId":<?php echo (int) $campaign_id; ?>} /-->
