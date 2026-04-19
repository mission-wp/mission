<?php
/**
 * Block Name: Campaign Grid
 * Description: Display multiple campaigns in a responsive grid layout.
 *
 * @package Mission
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use Mission\Currency\Currency;
use Mission\Models\Campaign;

defined( 'ABSPATH' ) || exit;

// Build query arguments.
$status_filter      = $attributes['statusFilter'] ?? 'active';
$number_of_campaigns = (int) ( $attributes['numberOfCampaigns'] ?? 6 );
$orderby            = $attributes['orderby'] ?? 'date_created';
$order              = $attributes['order'] ?? 'DESC';

$query_args = [
	'per_page'        => $number_of_campaigns,
	'orderby'         => $orderby,
	'order'           => $order,
	'show_in_listings' => true,
];

if ( 'all' === $status_filter ) {
	$query_args['status__in'] = [ 'active', 'ended' ];
} else {
	$query_args['status'] = $status_filter;
}

/**
 * Filters the campaign grid query arguments.
 *
 * @param array $query_args Query arguments for Campaign::query().
 * @param array $attributes Block attributes.
 */
$query_args = apply_filters( 'mission_campaign_grid_query_args', $query_args, $attributes );

$campaigns = Campaign::query( $query_args );

/**
 * Filters the campaigns displayed in the grid.
 *
 * @param Campaign[] $campaigns  The queried campaigns.
 * @param array      $attributes Block attributes.
 */
$campaigns = apply_filters( 'mission_campaign_grid_campaigns', $campaigns, $attributes );

if ( empty( $campaigns ) ) {
	return;
}

// Settings and test mode.
$mission_settings = get_option( 'mission_settings', [] );
$is_test          = (bool) ( $mission_settings['test_mode'] ?? false );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Color variables.
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

$columns = (int) ( $attributes['columns'] ?? 2 );

// Show/hide toggles.
$show_image       = $attributes['showImage'] ?? true;
$show_tag         = $attributes['showTag'] ?? true;
$show_description = $attributes['showDescription'] ?? true;
$show_progress_attr = $attributes['showProgressBar'] ?? true;
$show_donor_count = $attributes['showDonorCount'] ?? true;
$button_text      = $attributes['buttonText'] ?? __( 'View Campaign', 'missionwp-donation-platform' );

/** This filter is documented in blocks/src/campaign/index.php */
$ending_soon_days = (int) apply_filters( 'mission_campaign_card_ending_soon_days', 30 );

// Build the output.
ob_start();
?>
<div
	<?php
	echo wp_kses_post(
		get_block_wrapper_attributes( [ 'class' => 'mission-campaign-grid' ] )
	);
	?>
	data-wp-interactive="mission/campaign"
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>; --mission-cg-columns: <?php echo esc_attr( $columns ); ?>;"
>
	<div class="mission-cg-grid">
		<?php foreach ( $campaigns as $campaign ) : ?>
			<?php
			// Per-card data.
			$goal_amount   = $campaign->goal_amount;
			$goal_type     = $campaign->goal_type;
			$goal_progress = $campaign->get_goal_progress( $is_test );
			$has_goal      = $goal_amount > 0;
			$percentage    = $has_goal ? (int) min( 100, round( $goal_progress / $goal_amount * 100 ) ) : 0;
			$is_ended      = 'ended' === $campaign->status;
			$donor_count   = $is_test ? $campaign->test_donor_count : $campaign->donor_count;
			$show_progress = $show_progress_attr && $has_goal;

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

			// Tag.
			$tag_text  = '';
			$tag_class = '';
			if ( $show_tag ) {
				if ( $is_ended || ( $has_end_date && 0 === $days_remaining ) ) {
					$tag_text  = __( 'Ended', 'missionwp-donation-platform' );
					$tag_class = 'mission-cc-tag--ended';
				} elseif ( $has_goal && $goal_progress >= $goal_amount ) {
					$tag_text  = __( 'Goal Reached', 'missionwp-donation-platform' );
					$tag_class = 'mission-cc-tag--goal-reached';
				} elseif ( $has_end_date && null !== $days_remaining && $days_remaining <= $ending_soon_days ) {
					/* translators: %d: number of days remaining */
					$tag_text  = sprintf( _n( '%d Day Left', '%d Days Left', $days_remaining, 'missionwp-donation-platform' ), $days_remaining );
					$tag_class = 'mission-cc-tag--ending-soon';
				}
			}

			// Progress text.
			$raised_text = '';
			$goal_text   = '';
			if ( 'amount' === $goal_type ) {
				$raised_text = Currency::format_amount( $goal_progress, $currency );
				$goal_text   = $has_goal
					/* translators: %s: formatted goal amount */
					? sprintf( __( 'of %s', 'missionwp-donation-platform' ), Currency::format_amount( $goal_amount, $currency ) )
					: '';
			} else {
				$raised_text = number_format_i18n( $goal_progress );
				$goal_text   = $has_goal
					/* translators: %s: goal number */
					? sprintf( __( 'of %s', 'missionwp-donation-platform' ), number_format_i18n( $goal_amount ) )
					: '';
			}

			// Time text.
			if ( $is_ended && $has_end_date ) {
				/* translators: %s: formatted date */
				$time_text = sprintf( __( 'Ended %s', 'missionwp-donation-platform' ), wp_date( 'M j, Y', strtotime( $date_end ) ) );
			} elseif ( $has_end_date ) {
				/* translators: %s: formatted date */
				$time_text = sprintf( __( 'Ends %s', 'missionwp-donation-platform' ), wp_date( 'M j, Y', strtotime( $date_end ) ) );
			} else {
				$time_text = __( 'Ongoing', 'missionwp-donation-platform' );
			}

			// Campaign URL and image.
			$campaign_url = $campaign->get_url();
			$image_url    = $campaign->get_image_url( 'large' );

			$card_classes = 'mission-cc' . ( $is_ended ? ' mission-cc--ended' : '' );

			ob_start();
			?>
			<div class="<?php echo esc_attr( $card_classes ); ?>">
				<?php if ( $show_image ) : ?>
					<a <?php echo $campaign_url ? 'href="' . esc_url( $campaign_url ) . '"' : ''; ?> class="mission-cc-image-wrap" aria-hidden="true" tabindex="-1">
						<?php if ( $tag_text ) : ?>
							<span class="mission-cc-tag <?php echo esc_attr( $tag_class ); ?>"><?php echo esc_html( $tag_text ); ?></span>
						<?php endif; ?>
						<?php if ( $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy" />
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
										<?php esc_html_e( 'donors', 'missionwp-donation-platform' ); ?>
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
			<?php
			$card_html = ob_get_clean();

			/**
			 * Filters a single card's HTML within the campaign grid.
			 *
			 * @param string   $card_html  The card HTML.
			 * @param Campaign $campaign   The campaign model.
			 * @param array    $attributes Block attributes.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo apply_filters( 'mission_campaign_grid_card_output', $card_html, $campaign, $attributes );
			?>
		<?php endforeach; ?>
	</div>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the full campaign grid block output.
 *
 * @param string     $output     Full grid HTML.
 * @param Campaign[] $campaigns  The campaigns.
 * @param array      $attributes Block attributes.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'mission_campaign_grid_output', $output, $campaigns, $attributes );
