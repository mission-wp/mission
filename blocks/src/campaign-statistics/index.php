<?php
/**
 * Block Name: Campaign Statistic
 * Description: Display a key statistic for a campaign.
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
use MissionDP\Reporting\ReportingService;

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

// Stat values from the campaign model.
$total_raised = $is_test ? $campaign->test_total_raised : $campaign->total_raised;
$txn_count    = $is_test ? $campaign->test_transaction_count : $campaign->transaction_count;
$donor_count  = $is_test ? $campaign->test_donor_count : $campaign->donor_count;

// Compute the requested statistic.
$stat_type = $attributes['statType'] ?? 'total_raised';

$raw_value = match ( $stat_type ) {
	'total_raised'     => $total_raised,
	'donation_count'   => $txn_count,
	'donor_count'      => $donor_count,
	'average_donation' => $txn_count > 0 ? (int) round( $total_raised / $txn_count ) : 0,
	'top_donation'     => ( new ReportingService() )->top_donation_for_campaign( $campaign->id, $is_test ),
	default            => $total_raised,
};

// Format: currency stats get symbol + decimals, count stats get localized number.
$is_currency = in_array( $stat_type, [ 'total_raised', 'average_donation', 'top_donation' ], true );

$formatted_value = $is_currency
	? Currency::format_amount( $raw_value, $currency )
	: number_format_i18n( $raw_value );

// Heading.
$heading      = $attributes['heading'] ?? __( 'Total Raised', 'mission-donation-platform' );
$show_heading = $attributes['showHeading'] ?? true;

// Wrapper classes and CSS custom properties.
$classes = [ 'mission-campaign-stat' ];

$text_align = $attributes['textAlign'] ?? '';
if ( $text_align ) {
	$classes[] = 'has-text-align-' . $text_align;
}

$style_parts = [];

$heading_font_size = $attributes['headingFontSize'] ?? '';
if ( $heading_font_size ) {
	$style_parts[] = '--mission-cs-heading-size:' . esc_attr( $heading_font_size );
}

$heading_color = $attributes['headingColor'] ?? '';
if ( $heading_color ) {
	$style_parts[] = '--mission-cs-heading-color:' . esc_attr( $heading_color );
}

$heading_text_transform = $attributes['headingTextTransform'] ?? 'uppercase';
if ( $heading_text_transform && 'uppercase' !== $heading_text_transform ) {
	$style_parts[] = '--mission-cs-heading-case:' . esc_attr( $heading_text_transform );
}

$wrapper_args = [ 'class' => implode( ' ', $classes ) ];
if ( $style_parts ) {
	$wrapper_args['style'] = implode( ';', $style_parts );
}

// Build the output.
ob_start();
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes( $wrapper_args ) ); ?>>
	<?php if ( $show_heading ) : ?>
		<p class="mission-cs-heading"><?php echo wp_kses_post( $heading ); ?></p>
	<?php endif; ?>
	<span class="mission-cs-value"><?php echo esc_html( $formatted_value ); ?></span>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the campaign statistics block output.
 *
 * @param string   $output     HTML output.
 * @param Campaign $campaign   Campaign model.
 * @param array    $attributes Block attributes.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'missiondp_campaign_statistics_output', $output, $campaign, $attributes );
