<?php
/**
 * Block Name: Top Donors
 * Description: Display a ranked list of top donors for a campaign.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\DonorDashboard\DashboardLabels;
use MissionDP\DonorDashboard\PrimaryColorResolver;
use MissionDP\Models\Campaign;
use MissionDP\P2P\BlockSupport;
use MissionDP\Reporting\ReportingService;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
$campaign = BlockSupport::resolve_campaign( $attributes );

if ( ! $campaign ) {
	return;
}

// Settings.
$mission_settings = get_option( 'missiondp_settings', [] );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

// Attributes.
$heading         = $attributes['heading'] ?? __( 'Top Donors', 'mission-donation-platform' );
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
$primary_color = $mission_settings['primary_color'] ?? '#2fa36b';
$color_style   = PrimaryColorResolver::inline_style( $primary_color );

// Interactivity API context.
$context = [
	'donateAction' => $donate_action,
];

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-top-donors' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/top-donors"
	<?php echo wp_kses_post( wp_interactivity_data_wp_context( $context ) ); ?>
	style="<?php echo esc_attr( $color_style ); ?>"
>
	<h3 class="mission-donor-heading"><?php echo esc_html( $heading ); ?></h3>

	<?php if ( empty( $donors ) ) : ?>
		<div class="mission-donor-empty">
			<div class="mission-donor-empty__icon">&#9734;</div>
			<p class="mission-donor-empty__title"><?php esc_html_e( 'This spot is wide open.', 'mission-donation-platform' ); ?></p>
			<p class="mission-donor-empty__subtitle"><?php esc_html_e( 'Make a donation and claim the top of the list.', 'mission-donation-platform' ); ?></p>
			<?php if ( 'hide' !== $donate_action ) : ?>
				<?php if ( 'scroll' === $donate_action ) : ?>
					<button
						type="button"
						class="mission-donor-empty__btn"
						data-wp-on--click="actions.scrollToForm"
					>
						<?php esc_html_e( 'Be the First Donor', 'mission-donation-platform' ); ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $donate_url ); ?>" class="mission-donor-empty__btn">
						<?php esc_html_e( 'Be the First Donor', 'mission-donation-platform' ); ?>
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

				$first    = $donor['first_name'] ?? '';
				$last     = $donor['last_name'] ?? '';
				$initials = DashboardLabels::person_initials( $first, $last, (bool) $donor['is_anonymous'] );

				if ( $donor['is_anonymous'] ) {
					$name = __( 'Anonymous', 'mission-donation-platform' );
				} else {
					$name = trim( $first . ' ' . mb_substr( $last, 0, 1 ) . '.' );
					if ( '.' === $name ) {
						$name = __( 'Anonymous', 'mission-donation-platform' );
					}
				}

				$dedication = '';
				if ( $show_dedication && ! empty( $donor['tribute_type'] ) ) {
					$prefix     = 'in_memory' === $donor['tribute_type']
						? __( 'In memory of', 'mission-donation-platform' )
						: __( 'In honor of', 'mission-donation-platform' );
					$dedication = $prefix . ' ' . ( $donor['honoree_name'] ?? '' );
				}

				$formatted_amount = Currency::format_amount( $donor['total'], $currency );
				?>
				<li class="mission-donor-item">
					<div class="mission-donor-item-left">
						<?php if ( $show_ribbons && $rank <= 3 ) : ?>
							<span class="mission-td-medal" title="<?php echo esc_attr( (string) $rank ); ?>">
								<?php echo wp_kses( BlockSupport::medal_svg( $rank ), \MissionDP\Helpers\Kses::block_allowed_html() ); ?>
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
echo wp_kses( apply_filters( 'mission_top_donors_output', $output, $campaign, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes, $content, $block );
