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
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
// Resolve the team from the block attribute or the queried shell page.
$team = null;

if ( ! empty( $attributes['teamId'] ) ) {
	$team = Team::find( (int) $attributes['teamId'] );
} else {
	$current_post = get_post();
	if ( $current_post && Team::POST_TYPE === $current_post->post_type ) {
		$team = Team::find_by_post_id( $current_post->ID );
	}
}

if ( ! $team ) {
	return;
}

$mission_settings = get_option( 'missiondp_settings', [] );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

$members = ( new ReportingService() )->team_members( $team->id );
$count   = count( $members );

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

				$name = $member['name'];

				// Build initials from the first letter of each of the first two words.
				$words    = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
				$initials = '';
				foreach ( array_slice( $words, 0, 2 ) as $word ) {
					$initials .= mb_substr( $word, 0, 1 );
				}
				$initials = strtoupper( $initials );
				if ( '' === $initials ) {
					$initials = '?';
				}

				$percentage = BlockSupport::progress_percent( $member['raised'], $member['goal'] );
				$permalink  = get_permalink( $member['post_id'] );
				?>
				<li class="mission-tm__item">
					<span class="mission-tm__rank"><?php echo esc_html( number_format_i18n( $rank ) ); ?></span>
					<div class="mission-tm__avatar"><?php echo esc_html( $initials ); ?></div>
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
} )( $attributes, $content, $block );
