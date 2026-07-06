<?php
/**
 * Block Name: Top Teams
 * Description: A ranked list of top teams for a peer-to-peer campaign.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the campaign.
	$campaign = BlockSupport::resolve_campaign( $attributes );

	if ( ! $campaign ) {
		return;
	}

	// Settings.
	$mission_settings = get_option( 'missiondp_settings', [] );
	$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

	// Attributes.
	$heading       = $attributes['heading'] ?? __( 'Top Teams', 'mission-donation-platform' );
	$show_avatars  = $attributes['showAvatars'] ?? true;
	$show_ribbons  = $attributes['showRibbons'] ?? true;
	$show_members  = $attributes['showMembers'] ?? true;
	$show_progress = $attributes['showProgress'] ?? true;
	$cta_action    = $attributes['ctaAction'] ?? 'signup';
	$cta_url       = $attributes['ctaUrl'] ?? '';
	$limit         = max( 1, (int) ( $attributes['numberOfTeams'] ?? 5 ) );

	// Query top teams.
	$reporting = new ReportingService();
	$teams     = $reporting->top_teams( $campaign->id, $limit );

	// Batch-warm the post and team caches so per-row get_permalink() calls run no queries.
	if ( $teams ) {
		$post_ids = array_column( $teams, 'post_id' );
		_prime_post_caches( $post_ids, false, false );
		Team::warm_by_post_ids( $post_ids );
	}

	$registration_open = $campaign->is_p2p() && ! empty( $campaign->p2p_settings()['registration_open'] );

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-top-teams' ] ) ); ?>
		data-wp-interactive="mission-donation-platform/top-teams"
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<h3 class="mission-donor-heading"><?php echo esc_html( $heading ); ?></h3>

		<?php if ( empty( $teams ) ) : ?>
			<div class="mission-donor-empty">
				<div class="mission-donor-empty__icon">&#9734;</div>
				<p class="mission-donor-empty__title"><?php esc_html_e( 'No teams yet.', 'mission-donation-platform' ); ?></p>
				<p class="mission-donor-empty__subtitle"><?php esc_html_e( 'Create a team and fundraise together.', 'mission-donation-platform' ); ?></p>
				<?php if ( 'url' === $cta_action && '' !== $cta_url ) : ?>
					<a href="<?php echo esc_url( $cta_url ); ?>" class="mission-donor-empty__btn">
						<?php esc_html_e( 'Start a Team', 'mission-donation-platform' ); ?>
					</a>
				<?php elseif ( 'signup' === $cta_action && $registration_open ) : ?>
					<button type="button" class="mission-donor-empty__btn" data-wp-on--click="actions.openSignup">
						<?php esc_html_e( 'Start a Team', 'mission-donation-platform' ); ?>
					</button>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<ol class="mission-donor-list">
				<?php
				$rank = 0;
				foreach ( $teams as $team ) :
					++$rank;
					$name    = $team['name'];
					$url     = get_permalink( $team['post_id'] );
					$percent = BlockSupport::progress_percent( $team['raised'], $team['goal'] );
					?>
					<li class="mission-donor-item">
						<div class="mission-donor-item-left">
							<?php if ( $show_ribbons && $rank <= 3 ) : ?>
								<span class="mission-tt-medal" title="<?php echo esc_attr( (string) $rank ); ?>">
									<?php echo wp_kses( BlockSupport::medal_svg( $rank ), \MissionDP\Helpers\Kses::block_allowed_html() ); ?>
								</span>
							<?php else : ?>
								<span class="mission-tt-rank"><?php echo esc_html( $rank . '.' ); ?></span>
							<?php endif; ?>
							<?php if ( $show_avatars ) : ?>
								<span class="mission-tt-logo"><?php echo esc_html( BlockSupport::initials( $name ) ); ?></span>
							<?php endif; ?>
							<div class="mission-donor-info">
								<span class="mission-donor-name">
									<?php if ( $url ) : ?>
										<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $name ); ?>
									<?php endif; ?>
								</span>
								<?php if ( $show_members ) : ?>
									<span class="mission-donor-dedication">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of team members */
												_n( '%s member', '%s members', $team['member_count'], 'mission-donation-platform' ),
												number_format_i18n( $team['member_count'] )
											)
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( $show_progress && $team['goal'] > 0 ) : ?>
									<div class="mission-tt-bar">
										<div class="mission-tt-bar-fill" style="--bar-width: <?php echo esc_attr( $percent ); ?>%"></div>
									</div>
								<?php endif; ?>
							</div>
						</div>
						<span class="mission-donor-amount"><?php echo esc_html( Currency::format_amount( $team['raised'], $currency ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the top teams block output.
	 *
	 * @param string   $output     HTML output.
	 * @param Campaign $campaign   Campaign model.
	 * @param array    $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_top_teams_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
