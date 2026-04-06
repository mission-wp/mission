<?php
/**
 * Block Name: Campaign Progress
 * Description: Display campaign fundraising progress with a progress bar and stats.
 *
 * @package Mission
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use Mission\Campaigns\CampaignPostType;
use Mission\Currency\Currency;
use Mission\Models\Campaign;

defined( 'ABSPATH' ) || exit;

// Resolve the campaign.
$campaign = null;

if ( ! empty( $attributes['campaignId'] ) ) {
	$campaign = Campaign::find( (int) $attributes['campaignId'] );
} else {
	$current_post = get_post();
	if ( $current_post && CampaignPostType::POST_TYPE === $current_post->post_type ) {
		$campaign = Campaign::find_by_post_id( $current_post->ID );
	}
}

if ( ! $campaign ) {
	return;
}

// Settings and test mode.
$mission_settings = get_option( 'mission_settings', [] );
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
		? sprintf( __( 'raised of %s goal', 'mission' ), Currency::format_amount( $goal_amount, $currency ) )
		: __( 'raised', 'mission' );
} elseif ( 'donations' === $goal_type ) {
	$progress_text = number_format_i18n( $goal_progress );
	$goal_text     = $has_goal
		/* translators: %s: goal number */
		? sprintf( __( 'donations of %s goal', 'mission' ), number_format_i18n( $goal_amount ) )
		: __( 'donations', 'mission' );
} else {
	$progress_text = number_format_i18n( $goal_progress );
	$goal_text     = $has_goal
		/* translators: %s: goal number */
		? sprintf( __( 'donors of %s goal', 'mission' ), number_format_i18n( $goal_amount ) )
		: __( 'donors', 'mission' );
}

// Primary color (same pattern as donation form).
$global_primary = $mission_settings['primary_color'] ?? '#2fa36b';
$primary_color  = $global_primary;

$darken_color = static function ( string $hex, float $percent ): string {
	$hex = ltrim( $hex, '#' );
	$r   = max( 0, (int) round( hexdec( substr( $hex, 0, 2 ) ) * ( 1 - $percent / 100 ) ) );
	$g   = max( 0, (int) round( hexdec( substr( $hex, 2, 2 ) ) * ( 1 - $percent / 100 ) ) );
	$b   = max( 0, (int) round( hexdec( substr( $hex, 4, 2 ) ) * ( 1 - $percent / 100 ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
};

$primary_hover = $darken_color( $primary_color, 12 );
$hex_trimmed   = ltrim( $primary_color, '#' );
$primary_r     = hexdec( substr( $hex_trimmed, 0, 2 ) );
$primary_g     = hexdec( substr( $hex_trimmed, 2, 2 ) );
$primary_b     = hexdec( substr( $hex_trimmed, 4, 2 ) );
$luminance     = ( 0.299 * $primary_r + 0.587 * $primary_g + 0.114 * $primary_b ) / 255;
$primary_text  = $luminance > 0.5 ? '#1e1e1e' : '#ffffff';

// Build context for Interactivity API.
$context = [
	'donateAction' => $donate_action,
];

// Build the output.
ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-campaign-progress' ] ) ); ?>
	data-wp-interactive="mission/campaign-progress"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>;"
>
	<div class="mission-cp-header">
		<span class="mission-cp-raised"><?php echo esc_html( $progress_text ); ?></span>
		<span class="mission-cp-goal"><?php echo esc_html( $goal_text ); ?></span>
		<?php if ( $has_goal ) : ?>
			<span class="mission-cp-percentage"><?php echo esc_html( $real_percent . '%' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $has_goal ) : ?>
		<div class="mission-cp-bar" data-wp-init="callbacks.animateBar">
			<div class="mission-cp-bar__fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
		</div>
	<?php endif; ?>

	<?php if ( $show_donors || $show_days_remaining ) : ?>
		<div class="mission-cp-stats">
			<?php if ( $show_donors ) : ?>
				<div class="mission-cp-stat">
					<span class="mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $donor_count ) ); ?></span>
					<span class="mission-cp-stat__label"><?php esc_html_e( 'donors', 'mission' ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $show_days_remaining ) : ?>
				<div class="mission-cp-stat">
					<span class="mission-cp-stat__value"><?php echo esc_html( number_format_i18n( $days_remaining ) ); ?></span>
					<span class="mission-cp-stat__label"><?php esc_html_e( 'days remaining', 'mission' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $show_button ) : ?>
		<?php if ( 'scroll' === $donate_action ) : ?>
			<button
				type="button"
				class="mission-cp-donate-btn"
				data-wp-on--click="actions.scrollToForm"
			>
				<?php esc_html_e( 'Donate Now', 'mission' ); ?>
			</button>
		<?php else : ?>
			<a href="<?php echo esc_url( $donate_url ); ?>" class="mission-cp-donate-btn">
				<?php esc_html_e( 'Donate Now', 'mission' ); ?>
			</a>
		<?php endif; ?>
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
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'mission_campaign_progress_output', $output, $campaign, $attributes );
