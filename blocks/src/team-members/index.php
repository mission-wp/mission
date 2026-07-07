<?php
/**
 * Block Name: Team Members
 * Description: A team's ranked member list with per-member progress.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\DonorDashboard\DashboardLabels;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the team from the block attribute or the queried shell page.
	$team = BlockSupport::resolve_team( $attributes );

	if ( ! $team ) {
		return;
	}

	$mission_settings = get_option( 'missiondp_settings', [] );
	$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

	$members = ( new ReportingService() )->team_members( $team->id );
	$count   = count( $members );

	// Batch-warm the post and fundraiser caches so per-row get_permalink() calls run no queries.
	if ( $members ) {
		$post_ids = array_column( $members, 'post_id' );
		_prime_post_caches( $post_ids, false, false );
		Fundraiser::warm_by_post_ids( $post_ids );
	}

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-tm' ] ) ); ?>
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<p class="mission-tm__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of fundraisers on the team */
					_n( '%s fundraiser', '%s fundraisers', $count, 'mission-donation-platform' ),
					number_format_i18n( $count )
				)
			);
			?>
		</p>

		<?php if ( $members ) : ?>
			<ul class="mission-tm__list">
				<?php
				$rank = 0;
				foreach ( $members as $member ) :
					++$rank;

					$name       = $member['name'];
					$percentage = BlockSupport::progress_percent( $member['raised'], $member['goal'] );
					$permalink  = get_permalink( $member['post_id'] );
					?>
					<li class="mission-tm__item">
						<span class="mission-tm__rank"><?php echo esc_html( number_format_i18n( $rank ) ); ?></span>
						<div class="mission-tm__avatar"><?php echo esc_html( DashboardLabels::person_initials( $member['first_name'], $member['last_name'] ) ); ?></div>
						<div class="mission-tm__info">
							<div class="mission-tm__name">
								<?php if ( $permalink ) : ?>
									<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $name ); ?>
								<?php endif; ?>
								<?php if ( $member['is_captain'] ) : ?>
									<span class="mission-tm__badge"><?php esc_html_e( 'Captain', 'mission-donation-platform' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="mission-tm__bar">
								<div class="mission-tm__bar-fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
							</div>
						</div>
						<div class="mission-tm__amount">
							<div class="mission-tm__amount-value"><?php echo esc_html( Currency::format_amount( $member['raised'], $currency ) ); ?></div>
							<div class="mission-tm__amount-label">
								<?php
								/* translators: %s: formatted goal amount */
								echo esc_html( sprintf( __( 'of %s', 'mission-donation-platform' ), Currency::format_amount( $member['goal'], $currency ) ) );
								?>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="mission-tm__empty"><?php esc_html_e( 'No members have joined this team yet.', 'mission-donation-platform' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the team members block output.
	 *
	 * @param string $output     HTML output.
	 * @param Team   $team       Team model.
	 * @param array  $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_team_members_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
