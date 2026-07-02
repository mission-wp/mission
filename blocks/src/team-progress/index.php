<?php
/**
 * Block Name: Team Progress
 * Description: A team's progress bar, stats, and donate/join buttons.
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


( static function ( $attributes ): void {
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

$totals       = ( new ReportingService() )->team_totals( $team->id );
$raised       = (int) $totals['raised'];
$donations    = (int) $totals['donations'];
$member_count = (int) $totals['member_count'];

$goal       = $team->goal;
$has_goal   = $goal > 0;
$percentage = BlockSupport::progress_percent( $raised, $goal );

$goal_text = $has_goal
	/* translators: %s: formatted goal amount */
	? sprintf( __( 'raised of %s team goal', 'mission-donation-platform' ), Currency::format_amount( $goal, $currency ) )
	: __( 'raised', 'mission-donation-platform' );

// The sign-up modal only renders while registration is open, so the Join
// button would silently no-op without this gate (campaign-progress does the same).
$p2p_settings = $team->campaign()?->p2p_settings() ?? [];
$show_join    = ! empty( $p2p_settings['registration_open'] );

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-progress' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/team-progress"
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<div class="mission-progress__header">
		<span class="mission-progress__raised"><?php echo esc_html( Currency::format_amount( $raised, $currency ) ); ?></span>
		<span class="mission-progress__goal"><?php echo esc_html( $goal_text ); ?></span>
		<?php if ( $has_goal ) : ?>
			<span class="mission-progress__percentage"><?php echo esc_html( $percentage . '%' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $has_goal ) : ?>
		<div class="mission-progress__bar" data-wp-init="callbacks.animateBar">
			<div class="mission-progress__bar-fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
		</div>
	<?php endif; ?>

	<div class="mission-progress__stats">
		<div class="mission-progress__stat">
			<span class="mission-progress__stat-value"><?php echo esc_html( number_format_i18n( $member_count ) ); ?></span>
			<span class="mission-progress__stat-label"><?php esc_html_e( 'members', 'mission-donation-platform' ); ?></span>
		</div>
		<div class="mission-progress__stat">
			<span class="mission-progress__stat-value"><?php echo esc_html( number_format_i18n( $donations ) ); ?></span>
			<span class="mission-progress__stat-label"><?php esc_html_e( 'donations', 'mission-donation-platform' ); ?></span>
		</div>
		<div class="mission-progress__stat">
			<span class="mission-progress__stat-value"><?php echo esc_html( Currency::format_amount( $raised, $currency ) ); ?></span>
			<span class="mission-progress__stat-label"><?php esc_html_e( 'raised', 'mission-donation-platform' ); ?></span>
		</div>
	</div>

	<div class="mission-progress__actions">
		<button type="button" class="mission-progress__btn" data-wp-on--click="actions.scrollToForm">
			<?php esc_html_e( 'Donate to the Team', 'mission-donation-platform' ); ?>
		</button>
		<?php if ( $show_join ) : ?>
			<button
				type="button"
				class="mission-progress__btn mission-progress__btn--secondary"
				data-wp-on--click="actions.openSignup"
			>
				<?php esc_html_e( 'Join this Team', 'mission-donation-platform' ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the team progress block output.
 *
 * @param string $output     HTML output.
 * @param Team   $team       Team model.
 * @param array  $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_team_progress_output', $output, $team, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
