<?php
/**
 * Block Name: Top Fundraisers
 * Description: A ranked list of top fundraisers for a peer-to-peer campaign.
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


( static function ( $attributes ): void {
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
$mission_settings = get_option( 'missiondp_settings', [] );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Attributes.
$heading      = $attributes['heading'] ?? __( 'Top Fundraisers', 'mission-donation-platform' );
$show_avatars = $attributes['showAvatars'] ?? true;
$show_ribbons = $attributes['showRibbons'] ?? true;
$show_team    = $attributes['showTeam'] ?? true;
$cta_action   = $attributes['ctaAction'] ?? 'signup';
$cta_url      = $attributes['ctaUrl'] ?? '';
$limit        = max( 1, (int) ( $attributes['numberOfFundraisers'] ?? 5 ) );

// Query top fundraisers.
$reporting    = new ReportingService();
$fundraisers  = $reporting->top_fundraisers( $campaign->id, $limit );

$registration_open = $campaign->is_p2p() && ! empty( $campaign->p2p_settings()['registration_open'] );

// Primary color.
$primary_color = $mission_settings['primary_color'] ?? '#2fa36b';

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

// Up-to-two-letter initials from a display name.
$initials_of = static function ( string $name ): string {
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

	return mb_strtoupper( $letters ?: mb_substr( trim( $name ), 0, 1 ) ) ?: '?';
};

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-top-fundraisers' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/top-fundraisers"
	style="--mission-primary: <?php echo esc_attr( $primary_color ); ?>; --mission-primary-hover: <?php echo esc_attr( $primary_hover ); ?>; --mission-primary-text: <?php echo esc_attr( $primary_text ); ?>;"
>
	<h3 class="mission-donor-heading"><?php echo esc_html( $heading ); ?></h3>

	<?php if ( empty( $fundraisers ) ) : ?>
		<div class="mission-donor-empty">
			<div class="mission-donor-empty__icon">&#9734;</div>
			<p class="mission-donor-empty__title"><?php esc_html_e( 'Be the first fundraiser.', 'mission-donation-platform' ); ?></p>
			<p class="mission-donor-empty__subtitle"><?php esc_html_e( 'Start your own page and rally your friends.', 'mission-donation-platform' ); ?></p>
			<?php if ( 'url' === $cta_action && '' !== $cta_url ) : ?>
				<a href="<?php echo esc_url( $cta_url ); ?>" class="mission-donor-empty__btn">
					<?php esc_html_e( 'Become a Fundraiser', 'mission-donation-platform' ); ?>
				</a>
			<?php elseif ( 'signup' === $cta_action && $registration_open ) : ?>
				<button type="button" class="mission-donor-empty__btn" data-wp-on--click="actions.openSignup">
					<?php esc_html_e( 'Become a Fundraiser', 'mission-donation-platform' ); ?>
				</button>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<ol class="mission-donor-list">
			<?php
			$rank = 0;
			foreach ( $fundraisers as $fundraiser ) :
				++$rank;
				$name = $fundraiser['name'];
				$url  = get_permalink( $fundraiser['post_id'] );
				?>
				<li class="mission-donor-item">
					<div class="mission-donor-item-left">
						<?php if ( $show_ribbons && $rank <= 3 ) : ?>
							<span class="mission-tf-medal" title="<?php echo esc_attr( (string) $rank ); ?>">
								<?php if ( 1 === $rank ) : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#D4A843" stroke="#C4962F" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#E8C96A" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#7A5C1F" font-family="-apple-system, sans-serif">1</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#D4A843" stroke="#C4962F" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php elseif ( 2 === $rank ) : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#B0B4BC" stroke="#9CA0A8" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#D0D4DC" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#5C5F66" font-family="-apple-system, sans-serif">2</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#B0B4BC" stroke="#9CA0A8" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php else : ?>
									<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="7" fill="#C68E5B" stroke="#B07A48" stroke-width="1"/><circle cx="12" cy="9" r="5" fill="none" stroke="#DAA872" stroke-width="0.75" opacity="0.6"/><text x="12" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="#6B4420" font-family="-apple-system, sans-serif">3</text><path d="M7.5 15L6 22l6-3 6 3-1.5-7" fill="#C68E5B" stroke="#B07A48" stroke-width="0.75" stroke-linejoin="round"/></svg>
								<?php endif; ?>
							</span>
						<?php else : ?>
							<span class="mission-tf-rank"><?php echo esc_html( $rank . '.' ); ?></span>
						<?php endif; ?>
						<?php if ( $show_avatars ) : ?>
							<span class="mission-donor-avatar"><?php echo esc_html( $initials_of( $name ) ); ?></span>
						<?php endif; ?>
						<div class="mission-donor-info">
							<span class="mission-donor-name">
								<?php if ( $url ) : ?>
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $name ); ?>
								<?php endif; ?>
							</span>
							<?php if ( $show_team ) : ?>
								<span class="mission-donor-dedication">
									<?php if ( '' !== $fundraiser['team_name'] ) : ?>
										<?php echo esc_html( $fundraiser['team_name'] ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Individual fundraiser', 'mission-donation-platform' ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</div>
					</div>
					<span class="mission-donor-amount"><?php echo esc_html( Currency::format_amount( $fundraiser['raised'], $currency ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the top fundraisers block output.
 *
 * @param string   $output     HTML output.
 * @param Campaign $campaign   Campaign model.
 * @param array    $attributes Block attributes.
 */
echo wp_kses( apply_filters( 'mission_top_fundraisers_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
