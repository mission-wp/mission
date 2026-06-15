<?php
/**
 * Block Name: P2P Leaderboard
 * Description: The campaign leaderboard: top teams and top fundraisers side by side.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
	// Resolve the campaign from the block attribute or the queried campaign page.
	$campaign = null;

	if ( ! empty( $attributes['campaignId'] ) ) {
		$campaign = Campaign::find( (int) $attributes['campaignId'] );
	} else {
		$current_post = get_post();
		if ( $current_post && Campaign::POST_TYPE === $current_post->post_type ) {
			$campaign = Campaign::find_by_post_id( $current_post->ID );
		}
	}

	if ( ! $campaign ) {
		return;
	}

	$limit    = max( 1, (int) ( $attributes['limit'] ?? 5 ) );
	$settings = get_option( 'missiondp_settings', [] );
	$currency = strtoupper( $settings['currency'] ?? 'USD' );

	$reporting    = new ReportingService();
	$teams        = $reporting->top_teams( $campaign->id, $limit );
	$fundraisers  = $reporting->top_fundraisers( $campaign->id, $limit );

	// Compute up-to-two-letter initials from a display name.
	$initials = static function ( string $name ): string {
		$parts   = preg_split( '/\s+/', trim( $name ) ) ?: [];
		$letters = '';
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$letters .= mb_substr( $part, 0, 1 );
			}
			if ( mb_strlen( $letters ) >= 2 ) {
				break;
			}
		}

		return mb_strtoupper( $letters ?: mb_substr( trim( $name ), 0, 1 ) );
	};

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-lb' ] ) ); ?>
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<div class="mission-lb__grid">
			<?php if ( $teams ) : ?>
				<div class="mission-lb__column">
					<div class="mission-lb__column-header"><?php esc_html_e( 'Top Teams', 'mission-donation-platform' ); ?></div>
					<ul class="mission-lb__list">
						<?php foreach ( $teams as $index => $team ) : ?>
							<?php
							$rank    = $index + 1;
							$percent = BlockSupport::progress_percent( $team['raised'], $team['goal'] );
							$url     = get_permalink( $team['post_id'] );
							?>
							<li class="mission-lb__item">
								<span class="mission-lb__rank mission-lb__rank--<?php echo esc_attr( (string) $rank ); ?>"><?php echo esc_html( number_format_i18n( $rank ) ); ?></span>
								<span class="mission-lb__logo"><?php echo esc_html( $initials( $team['name'] ) ); ?></span>
								<div class="mission-lb__info">
									<div class="mission-lb__name">
										<?php if ( $url ) : ?>
											<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $team['name'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $team['name'] ); ?>
										<?php endif; ?>
									</div>
									<div class="mission-lb__meta">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of team members */
												_n( '%s member', '%s members', $team['member_count'], 'mission-donation-platform' ),
												number_format_i18n( $team['member_count'] )
											)
										);
										?>
									</div>
									<?php if ( $team['goal'] > 0 ) : ?>
										<div class="mission-lb__mini-bar">
											<div class="mission-lb__mini-fill" style="--bar-width: <?php echo esc_attr( $percent ); ?>%"></div>
										</div>
									<?php endif; ?>
								</div>
								<span class="mission-lb__amount-value"><?php echo esc_html( Currency::format_amount( $team['raised'], $currency ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $fundraisers ) : ?>
				<div class="mission-lb__column">
					<div class="mission-lb__column-header"><?php esc_html_e( 'Top Fundraisers', 'mission-donation-platform' ); ?></div>
					<ul class="mission-lb__list">
						<?php foreach ( $fundraisers as $index => $fundraiser ) : ?>
							<?php
							$rank = $index + 1;
							$url  = get_permalink( $fundraiser['post_id'] );
							?>
							<li class="mission-lb__item">
								<span class="mission-lb__rank mission-lb__rank--<?php echo esc_attr( (string) $rank ); ?>"><?php echo esc_html( number_format_i18n( $rank ) ); ?></span>
								<span class="mission-lb__avatar"><?php echo esc_html( $initials( $fundraiser['name'] ) ); ?></span>
								<div class="mission-lb__info">
									<div class="mission-lb__name">
										<?php if ( $url ) : ?>
											<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $fundraiser['name'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $fundraiser['name'] ); ?>
										<?php endif; ?>
									</div>
									<div class="mission-lb__meta">
										<?php if ( '' !== $fundraiser['team_name'] ) : ?>
											<span class="mission-lb__team-tag"><?php echo esc_html( $fundraiser['team_name'] ); ?></span>
										<?php else : ?>
											<?php esc_html_e( 'Individual fundraiser', 'mission-donation-platform' ); ?>
										<?php endif; ?>
									</div>
								</div>
								<span class="mission-lb__amount-value"><?php echo esc_html( Currency::format_amount( $fundraiser['raised'], $currency ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! $teams && ! $fundraisers ) : ?>
				<p class="mission-lb__empty"><?php esc_html_e( 'No fundraisers yet.', 'mission-donation-platform' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the P2P leaderboard block output.
	 *
	 * @param string   $output     HTML output.
	 * @param Campaign $campaign   Campaign model.
	 * @param array    $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_p2p_leaderboard_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
