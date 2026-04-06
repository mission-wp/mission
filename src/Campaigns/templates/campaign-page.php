<?php
/**
 * Default campaign page content template.
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
<!-- wp:mission/campaign-image {"aspectRatio":"16/9","align":"center","style":{"border":{"radius":{"topLeft":"8px","topRight":"8px","bottomLeft":"8px","bottomRight":"8px"},"width":"1px"}},"borderColor":"contrast"} /-->
<?php if ( $description ) : ?>

<!-- wp:heading {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|10"}}}} -->
<h2 class="wp-block-heading" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--10)">About This Campaign</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo wp_kses_post( $description ); ?></p>
<!-- /wp:paragraph -->

<?php endif; ?>
<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:mission/campaign-progress {"campaignId":<?php echo (int) $campaign_id; ?>} /-->

<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Why Your Support Matters</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Every contribution to <?php echo esc_html( $org_name ); ?> makes a real difference. Your donation goes directly toward our programs and the communities we serve. No amount is too small — together, we can reach our goal and make an even greater impact.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Thank you for being part of our mission. If you're unable to donate right now, sharing this campaign with your friends and family helps just as much.</p>
<!-- /wp:paragraph -->

<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:mission/top-donors {"campaignId":<?php echo (int) $campaign_id; ?>} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:mission/recent-donors /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:separator {"className":"is-style-wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}},"color":{"background":"#dadada"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-wide" style="margin-top:var(--wp--preset--spacing--30);margin-bottom:var(--wp--preset--spacing--30);background-color:#dadada;color:#dadada"/>
<!-- /wp:separator -->

<!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center">Make a Donation</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Choose an amount to support our mission.</p>
<!-- /wp:paragraph -->

<!-- wp:mission/donation-form {"campaignId":<?php echo (int) $campaign_id; ?>} /-->
