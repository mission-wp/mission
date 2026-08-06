<?php
/**
 * Default fundraiser page block template.
 *
 * Each block resolves the current fundraiser from the queried shell post, so the
 * markup is the same for every fundraiser. Site owners can re-theme via the
 * `--mission-*` variables, edit the "Fundraiser Page" template in the Site
 * Editor, or filter `mission_fundraiser_page_template`.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent.Incorrect -- Block markup must be unindented.
?>
<!-- wp:mission-donation-platform/fundraiser-title /-->

<!-- wp:mission-donation-platform/fundraiser-image /-->

<!-- wp:mission-donation-platform/fundraiser-progress /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'My Story', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/fundraiser-story /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'About the Campaign', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/campaign-description /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Supporters', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/fundraiser-supporters /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Support my fundraiser', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/donation-form /-->
