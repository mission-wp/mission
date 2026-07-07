<?php
/**
 * Default team page block template.
 *
 * Each block resolves the current team from the queried shell post, so the
 * markup is the same for every team. Site owners can re-theme via the
 * `--mission-*` variables, edit the "Team Page" template in the Site Editor, or
 * filter `mission_team_page_template`.
 *
 * @package MissionDP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent.Incorrect -- Block markup must be unindented.
?>
<!-- wp:mission-donation-platform/team-title /-->

<!-- wp:mission-donation-platform/team-image /-->

<!-- wp:mission-donation-platform/team-progress /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'About Our Team', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/team-story /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Team Members', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/team-members /-->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Support this team', 'mission-donation-platform' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:mission-donation-platform/donation-form /-->
