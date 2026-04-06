<?php
/**
 * Block Name: Top Donors
 * Description: Display a ranked list of top donors for a campaign.
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
use Mission\Reporting\ReportingService;

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

// Settings.
$mission_settings = get_option( 'mission_settings', [] );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Attributes.
$heading         = $attributes['heading'] ?? __( 'Top Donors', 'mission' );
$show_avatars    = $attributes['showAvatars'] ?? true;
$show_dedication = $attributes['showDedication'] ?? true;
$show_ribbons    = $attributes['showRibbons'] ?? true;
$donate_action   = $attributes['donateButtonAction'] ?? 'scroll';
$donate_url      = $attributes['donateButtonUrl'] ?? '';
$limit           = (int) ( $attributes['numberOfDonors'] ?? 5 );

// Query top donors.
$reporting = new ReportingService();
$donors    = $reporting->top_donors_for_campaign( $campaign->id, $limit );

// Primary color.
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

// Interactivity API context.
$context = [
	'donateAction' => $donate_action,
];

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-top-donors' ] ) ); ?>
	data-wp-interactive="mission/top-donors"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>;"
>
	<h3 class="mission-donor-heading"><?php echo esc_html( $heading ); ?></h3>

	<?php if ( empty( $donors ) ) : ?>
		<div class="mission-donor-empty">
			<div class="mission-donor-empty__icon">&#9734;</div>
			<p class="mission-donor-empty__title"><?php esc_html_e( 'This spot is wide open.', 'mission' ); ?></p>
			<p class="mission-donor-empty__subtitle"><?php esc_html_e( 'Make a donation and claim the top of the list.', 'mission' ); ?></p>
			<?php if ( 'hide' !== $donate_action ) : ?>
				<?php if ( 'scroll' === $donate_action ) : ?>
					<button
						type="button"
						class="mission-donor-empty__btn"
						data-wp-on--click="actions.scrollToForm"
					>
						<?php esc_html_e( 'Be the First Donor', 'mission' ); ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $donate_url ); ?>" class="mission-donor-empty__btn">
						<?php esc_html_e( 'Be the First Donor', 'mission' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<ol class="mission-donor-list">
			<?php
			$rank = 0;
			foreach ( $donors as $donor ) :
				++$rank;

				if ( $donor['is_anonymous'] ) {
					$name     = __( 'Anonymous', 'mission' );
					$initials = '?';
				} else {
					$first = $donor['first_name'] ?? '';
					$last  = $donor['last_name'] ?? '';
					$name  = trim( $first . ' ' . mb_substr( $last, 0, 1 ) . '.' );
					if ( '.' === $name ) {
						$name = __( 'Anonymous', 'mission' );
					}
					$initials = strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );
					if ( '' === trim( $initials ) ) {
						$initials = '?';
					}
				}

				$dedication = '';
				if ( $show_dedication && ! empty( $donor['tribute_type'] ) ) {
					$prefix     = 'in_memory' === $donor['tribute_type']
						? __( 'In memory of', 'mission' )
						: __( 'In honor of', 'mission' );
					$dedication = $prefix . ' ' . ( $donor['honoree_name'] ?? '' );
				}

				$formatted_amount = Currency::format_amount( $donor['total'], $currency );
				?>
				<li class="mission-donor-item">
					<div class="mission-donor-item-left">
						<?php if ( $show_ribbons && $rank <= 3 ) : ?>
							<span class="mission-td-medal" title="<?php echo esc_attr( $rank ); ?>">
								<?php if ( 1 === $rank ) : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#D4A843" stroke="#C4962F" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#E8C96A" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#7A5C1F" font-family="-apple-system, sans-serif">1</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#D4A843" stroke="#C4962F" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php elseif ( 2 === $rank ) : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#B0B4BC" stroke="#9CA0A8" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#D0D4DC" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#5C5F66" font-family="-apple-system, sans-serif">2</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#B0B4BC" stroke="#9CA0A8" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php else : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#C68E5B" stroke="#B07A48" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#DAA872" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#6B4420" font-family="-apple-system, sans-serif">3</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#C68E5B" stroke="#B07A48" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php endif; ?>
							</span>
						<?php else : ?>
							<span class="mission-td-rank"><?php echo esc_html( $rank . '.' ); ?></span>
						<?php endif; ?>
						<?php if ( $show_avatars ) : ?>
							<span class="mission-donor-avatar"><?php echo esc_html( $initials ); ?></span>
						<?php endif; ?>
						<div class="mission-donor-info">
							<span class="mission-donor-name"><?php echo esc_html( $name ); ?></span>
							<?php if ( $dedication ) : ?>
								<span class="mission-donor-dedication"><?php echo esc_html( $dedication ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<span class="mission-donor-amount"><?php echo esc_html( $formatted_amount ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the top donors block output.
 *
 * @param string   $output     HTML output.
 * @param Campaign $campaign   Campaign model.
 * @param array    $attributes Block attributes.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'mission_top_donors_output', $output, $campaign, $attributes );
