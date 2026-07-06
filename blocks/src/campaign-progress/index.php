<?php
/**
 * Block Name: Campaign Progress
 * Description: Display campaign fundraising progress with a progress bar and stats.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\DonorDashboard\PrimaryColorResolver;
use MissionDP\Models\Campaign;
use MissionDP\Models\Fundraiser;
use MissionDP\Models\Team;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
$campaign = BlockSupport::resolve_campaign( $attributes );

if ( ! $campaign ) {
	return;
}

// Settings and test mode.
$mission_settings = get_option( 'missiondp_settings', [] );
$is_test          = (bool) ( $mission_settings['test_mode'] ?? false );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Campaign data.
$goal_amount   = $campaign->goal_amount;
$goal_type     = $campaign->goal_type;
$goal_progress = $campaign->get_goal_progress( $is_test );
$has_goal      = $goal_amount > 0;
$percentage    = $has_goal ? (int) min( 100, round( $goal_progress / $goal_amount * 100 ) ) : 0;
$real_percent  = $has_goal ? (int) round( $goal_progress / $goal_amount * 100 ) : 0;

// Donor count.
$donor_count = $is_test ? $campaign->test_donor_count : $campaign->donor_count;

// Peer-to-peer campaigns add fundraiser/team counts and a sign-up CTA.
$is_p2p           = $campaign->is_p2p();
$fundraiser_count = $is_p2p ? Fundraiser::count( [ 'campaign_id' => $campaign->id, 'status' => Fundraiser::STATUS_ACTIVE ] ) : 0;
$team_count       = $is_p2p ? Team::count( [ 'campaign_id' => $campaign->id, 'status' => Team::STATUS_ACTIVE ] ) : 0;
$show_become      = $campaign->is_registration_open();

// Days remaining.
$date_end       = $campaign->date_end;
$has_end_date   = ! empty( $date_end );
$days_remaining = null;
if ( $has_end_date ) {
	$end            = new DateTime( $date_end, new DateTimeZone( wp_timezone_string() ) );
	$now            = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );
	$diff           = $now->diff( $end );
	$days_remaining = $diff->invert ? 0 : $diff->days;
}

// Show/hide toggles.
$show_donors         = $attributes['showDonors'] ?? true;
$show_days_remaining = ( $attributes['showDaysRemaining'] ?? true ) && $has_end_date;

// Donate button.
$donate_action = $attributes['donateButtonAction'] ?? 'scroll';
$donate_url    = $attributes['donateButtonUrl'] ?? '';
$show_button   = 'hide' !== $donate_action;

// Display text by goal type.
if ( 'amount' === $goal_type ) {
	$progress_text = Currency::format_amount( $goal_progress, $currency );
	$goal_text     = $has_goal
		/* translators: %s: formatted goal amount */
		? sprintf( __( 'raised of %s goal', 'mission-donation-platform' ), Currency::format_amount( $goal_amount, $currency ) )
		: __( 'raised', 'mission-donation-platform' );
} elseif ( 'donations' === $goal_type ) {
	$progress_text = number_format_i18n( $goal_progress );
	$goal_text     = $has_goal
		/* translators: %s: goal number */
		? sprintf( _n( 'donation of %s goal', 'donations of %s goal', $goal_progress, 'mission-donation-platform' ), number_format_i18n( $goal_amount ) )
		: _n( 'donation', 'donations', $goal_progress, 'mission-donation-platform' );
} else {
	$progress_text = number_format_i18n( $goal_progress );
	$goal_text     = $has_goal
		/* translators: %s: goal number */
		? sprintf( _n( 'donor of %s goal', 'donors of %s goal', $goal_progress, 'mission-donation-platform' ), number_format_i18n( $goal_amount ) )
		: _n( 'donor', 'donors', $goal_progress, 'mission-donation-platform' );
}

// Primary color.
$primary_color = $mission_settings['primary_color'] ?? '#2fa36b';
$color_style   = PrimaryColorResolver::inline_style( $primary_color );

// Build context for Interactivity API.
$context = [
	'donateAction' => $donate_action,
];

// Build the output. The legacy mission-campaign-progress/mission-cp-* classes
// carry no plugin styles; they're kept so user CSS overrides written against
// shipped releases keep applying.
ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-progress mission-campaign-progress' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/campaign-progress"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
	style="<?php echo esc_attr( $color_style ); ?>"
>
	<div class="mission-progress__header mission-cp-header">
		<span class="mission-progress__raised mission-cp-raised"><?php echo esc_html( $progress_text ); ?></span>
		<span class="mission-progress__goal mission-cp-goal"><?php echo esc_html( $goal_text ); ?></span>
		<?php if ( $has_goal ) : ?>
			<span class="mission-progress__percentage mission-cp-percentage"><?php echo esc_html( $real_percent . '%' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $has_goal ) : ?>
		<div class="mission-progress__bar mission-cp-bar" data-wp-init="callbacks.animateBar">
			<div class="mission-progress__bar-fill mission-cp-bar__fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
		</div>
	<?php endif; ?>

	<?php if ( $show_donors || $show_days_remaining || $is_p2p ) : ?>
		<div class="mission-progress__stats mission-cp-stats">
			<?php if ( $show_donors ) : ?>
				<div class="mission-progress__stat mission-cp-stat">
					<span class="mission-progress__stat-value mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $donor_count ) ); ?></span>
					<span class="mission-progress__stat-label mission-cp-stat__label"><?php echo esc_html( _n( 'donor', 'donors', $donor_count, 'mission-donation-platform' ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $is_p2p ) : ?>
				<div class="mission-progress__stat mission-cp-stat">
					<span class="mission-progress__stat-value mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $fundraiser_count ) ); ?></span>
					<span class="mission-progress__stat-label mission-cp-stat__label"><?php echo esc_html( _n( 'fundraiser', 'fundraisers', $fundraiser_count, 'mission-donation-platform' ) ); ?></span>
				</div>
				<div class="mission-progress__stat mission-cp-stat">
					<span class="mission-progress__stat-value mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $team_count ) ); ?></span>
					<span class="mission-progress__stat-label mission-cp-stat__label"><?php echo esc_html( _n( 'team', 'teams', $team_count, 'mission-donation-platform' ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $show_days_remaining ) : ?>
				<div class="mission-progress__stat mission-cp-stat">
					<span class="mission-progress__stat-value mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $days_remaining ) ); ?></span>
					<span class="mission-progress__stat-label mission-cp-stat__label"><?php echo esc_html( _n( 'day remaining', 'days remaining', $days_remaining, 'mission-donation-platform' ) ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $show_become || $show_button ) : ?>
		<div class="mission-progress__actions">
			<?php if ( $show_become ) : ?>
				<button type="button" class="mission-progress__btn" data-wp-on--click="actions.openSignup">
					<?php esc_html_e( 'Become a Fundraiser', 'mission-donation-platform' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $show_button ) : ?>
				<?php $donate_btn_class = ( $show_become ? 'mission-progress__btn mission-progress__btn--secondary' : 'mission-progress__btn' ) . ' mission-cp-donate-btn'; ?>
				<?php if ( 'scroll' === $donate_action ) : ?>
					<button
						type="button"
						class="<?php echo esc_attr( $donate_btn_class ); ?>"
						data-wp-on--click="actions.scrollToForm"
					>
						<?php esc_html_e( 'Donate Now', 'mission-donation-platform' ); ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $donate_url ); ?>" class="<?php echo esc_attr( $donate_btn_class ); ?>">
						<?php esc_html_e( 'Donate Now', 'mission-donation-platform' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the campaign progress block output.
 *
 * @param string   $output     HTML output.
 * @param Campaign $campaign   Campaign model.
 * @param array    $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_campaign_progress_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
