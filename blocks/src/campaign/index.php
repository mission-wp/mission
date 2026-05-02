<?php
/**
 * Block Name: Campaign Card
 * Description: Display a campaign as a card with image, progress, and a link to its page.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Currency\Currency;
use MissionDP\Models\Campaign;

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
$mission_settings = get_option( 'missiondp_settings', [] );
$is_test          = (bool) ( $mission_settings['test_mode'] ?? false );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Campaign data.
$goal_amount   = $campaign->goal_amount;
$goal_type     = $campaign->goal_type;
$goal_progress = $campaign->get_goal_progress( $is_test );
$has_goal      = $goal_amount > 0;
$percentage    = $has_goal ? (int) min( 100, round( $goal_progress / $goal_amount * 100 ) ) : 0;
$is_ended      = 'ended' === $campaign->status;

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
$show_image       = $attributes['showImage'] ?? true;
$show_tag         = $attributes['showTag'] ?? true;
$show_description = $attributes['showDescription'] ?? true;
$show_progress    = ( $attributes['showProgressBar'] ?? true ) && $has_goal;
$show_donor_count = $attributes['showDonorCount'] ?? true;
$button_text      = $attributes['buttonText'] ?? __( 'View Campaign', 'mission-donation-platform' );

// Campaign URL and image.
$campaign_url = $campaign->get_url();
$image_url    = $campaign->get_image_url( 'large' );
$image_id     = $campaign->get_image_id();
$image_alt    = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

// Tag logic.
$tag_text  = '';
$tag_class = '';

if ( $show_tag ) {
	/**
	 * Filters the number of days before end date to show the "ending soon" tag.
	 *
	 * @param int $days Threshold in days. Default 30.
	 */
	$ending_soon_days = (int) apply_filters( 'missiondp_campaign_card_ending_soon_days', 30 );

	if ( $is_ended || ( $has_end_date && 0 === $days_remaining ) ) {
		$tag_text  = __( 'Ended', 'mission-donation-platform' );
		$tag_class = 'mission-cc-tag--ended';
	} elseif ( $has_goal && $goal_progress >= $goal_amount ) {
		$tag_text  = __( 'Goal Reached', 'mission-donation-platform' );
		$tag_class = 'mission-cc-tag--goal-reached';
	} elseif ( $has_end_date && null !== $days_remaining && $days_remaining <= $ending_soon_days ) {
		/* translators: %d: number of days remaining */
		$tag_text  = sprintf( _n( '%d Day Left', '%d Days Left', $days_remaining, 'mission-donation-platform' ), $days_remaining );
		$tag_class = 'mission-cc-tag--ending-soon';
	}
}

// Progress display text by goal type.
if ( 'amount' === $goal_type ) {
	$raised_text = Currency::format_amount( $goal_progress, $currency );
	$goal_text   = $has_goal
		/* translators: %s: formatted goal amount */
		? sprintf( __( 'of %s', 'mission-donation-platform' ), Currency::format_amount( $goal_amount, $currency ) )
		: '';
} elseif ( 'donations' === $goal_type ) {
	$raised_text = number_format_i18n( $goal_progress );
	$goal_text   = $has_goal
		/* translators: %s: goal number */
		? sprintf( __( 'of %s', 'mission-donation-platform' ), number_format_i18n( $goal_amount ) )
		: '';
} else {
	$raised_text = number_format_i18n( $goal_progress );
	$goal_text   = $has_goal
		/* translators: %s: goal number */
		? sprintf( __( 'of %s', 'mission-donation-platform' ), number_format_i18n( $goal_amount ) )
		: '';
}

// End date display text for meta row.
if ( $is_ended && $has_end_date ) {
	/* translators: %s: formatted date */
	$time_text = sprintf( __( 'Ended %s', 'mission-donation-platform' ), wp_date( 'M j, Y', strtotime( $date_end ) ) );
} elseif ( $has_end_date ) {
	/* translators: %s: formatted date */
	$time_text = sprintf( __( 'Ends %s', 'mission-donation-platform' ), wp_date( 'M j, Y', strtotime( $date_end ) ) );
} else {
	$time_text = __( 'Ongoing', 'mission-donation-platform' );
}

// Card CSS classes.
$card_classes = 'mission-cc';
if ( $is_ended ) {
	$card_classes .= ' mission-cc--ended';
}

// Primary color (same pattern as donation form / campaign progress).
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

// Build the output.
ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-campaign-card' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/campaign"
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>;"
>
	<div class="<?php echo esc_attr( $card_classes ); ?>">
		<?php if ( $show_image ) : ?>
			<a <?php echo $campaign_url ? 'href="' . esc_url( $campaign_url ) . '"' : ''; ?> class="mission-cc-image-wrap" aria-hidden="true" tabindex="-1">
				<?php if ( $tag_text ) : ?>
					<span class="mission-cc-tag <?php echo esc_attr( $tag_class ); ?>"><?php echo esc_html( $tag_text ); ?></span>
				<?php endif; ?>
				<?php if ( $image_url ) : ?>
					<img
						src="<?php echo esc_url( $image_url ); ?>"
						alt=""
						loading="lazy"
					/>
				<?php else : ?>
					<div class="mission-cc-placeholder"></div>
				<?php endif; ?>
			</a>
		<?php endif; ?>

		<div class="mission-cc-body">
			<h3 class="mission-cc-title">
				<?php if ( $campaign_url ) : ?>
					<a href="<?php echo esc_url( $campaign_url ); ?>"><?php echo esc_html( $campaign->title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $campaign->title ); ?>
				<?php endif; ?>
			</h3>

			<?php if ( $show_description && $campaign->description ) : ?>
				<p class="mission-cc-description"><?php echo esc_html( $campaign->description ); ?></p>
			<?php endif; ?>

			<?php if ( $show_progress ) : ?>
				<div class="mission-cc-progress">
					<div class="mission-cc-progress-header">
						<span class="mission-cc-raised"><?php echo esc_html( $raised_text ); ?></span>
						<?php if ( $goal_text ) : ?>
							<span class="mission-cc-goal"><?php echo esc_html( $goal_text ); ?></span>
						<?php endif; ?>
					</div>
					<div class="mission-cc-bar" data-wp-init="callbacks.animateBar">
						<div class="mission-cc-bar__fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
					</div>
					<div class="mission-cc-meta">
						<?php if ( $show_donor_count ) : ?>
							<span class="mission-cc-donors">
								<strong><?php echo esc_html( number_format_i18n( $donor_count ) ); ?></strong>
								<?php esc_html_e( 'donors', 'mission-donation-platform' ); ?>
							</span>
						<?php endif; ?>
						<span class="mission-cc-time"><?php echo esc_html( $time_text ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $campaign_url ) : ?>
			<div class="mission-cc-footer">
				<a href="<?php echo esc_url( $campaign_url ); ?>" class="mission-cc-btn">
					<?php echo esc_html( $button_text ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the campaign card block output.
 *
 * @param string   $output     HTML output.
 * @param Campaign $campaign   Campaign model.
 * @param array    $attributes Block attributes.
 */
echo \MissionDP\Helpers\Kses::block_output( apply_filters( 'missiondp_campaign_card_output', $output, $campaign, $attributes ) );
