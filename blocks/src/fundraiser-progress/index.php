<?php
/**
 * Block Name: Fundraiser Progress
 * Description: A fundraiser's progress bar, stats, and donate/share buttons.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Currency\Currency;
use MissionDP\Models\Fundraiser;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the fundraiser from the block attribute or the queried shell page.
	$fundraiser = BlockSupport::resolve_fundraiser( $attributes );

	if ( ! $fundraiser ) {
		return;
	}

	$mission_settings = get_option( 'missiondp_settings', [] );
	$is_test          = (bool) ( $mission_settings['test_mode'] ?? false );
	$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

	// Fundraiser data.
	$raised     = $fundraiser->amount_raised( $is_test );
	$goal       = $fundraiser->goal;
	$has_goal   = $goal > 0;
	$percentage = BlockSupport::progress_percent( $raised, $goal );
	$donors     = $is_test ? $fundraiser->test_donor_count : $fundraiser->donor_count;
	$donations  = $is_test ? $fundraiser->test_transaction_count : $fundraiser->transaction_count;
	$share_url  = $fundraiser->get_url() ?? '';

	// Attributes.
	$show_donations = $attributes['showDonations'] ?? true;
	$show_donors    = $attributes['showDonors'] ?? true;
	$show_share     = ( $attributes['showShare'] ?? true ) && '' !== $share_url;
	$donate_action  = $attributes['donateButtonAction'] ?? 'scroll';
	$donate_url     = $attributes['donateButtonUrl'] ?? '';
	$show_donate    = 'hide' !== $donate_action;
	$show_stats     = $show_donations || $show_donors;

	// Goal text.
	$goal_text = $has_goal
		/* translators: %s: formatted goal amount */
		? sprintf( __( 'raised of %s goal', 'mission-donation-platform' ), Currency::format_amount( $goal, $currency ) )
		: __( 'raised', 'mission-donation-platform' );

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-progress' ] ) ); ?>
		data-wp-interactive="mission-donation-platform/fundraiser-progress"
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

		<?php if ( $show_stats ) : ?>
			<div class="mission-progress__stats">
				<?php if ( $show_donations ) : ?>
					<div class="mission-progress__stat">
						<span class="mission-progress__stat-value"><?php echo esc_html( number_format_i18n( $donations ) ); ?></span>
						<span class="mission-progress__stat-label"><?php echo esc_html( _n( 'donation', 'donations', $donations, 'mission-donation-platform' ) ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $show_donors ) : ?>
					<div class="mission-progress__stat">
						<span class="mission-progress__stat-value"><?php echo esc_html( number_format_i18n( $donors ) ); ?></span>
						<span class="mission-progress__stat-label"><?php echo esc_html( _n( 'donor', 'donors', $donors, 'mission-donation-platform' ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $show_donate || $show_share ) : ?>
			<div class="mission-progress__actions">
				<?php if ( $show_donate ) : ?>
					<?php if ( 'scroll' === $donate_action ) : ?>
						<button type="button" class="mission-progress__btn" data-wp-on--click="actions.scrollToForm">
							<?php esc_html_e( 'Donate Now', 'mission-donation-platform' ); ?>
						</button>
					<?php else : ?>
						<a href="<?php echo esc_url( $donate_url ); ?>" class="mission-progress__btn">
							<?php esc_html_e( 'Donate Now', 'mission-donation-platform' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $show_share ) : ?>
					<?php $share_class = $show_donate ? 'mission-progress__btn mission-progress__btn--secondary' : 'mission-progress__btn'; ?>
					<button
						type="button"
						class="<?php echo esc_attr( $share_class ); ?>"
						data-wp-on--click="actions.share"
						data-share-url="<?php echo esc_url( $share_url ); ?>"
					>
						<?php esc_html_e( 'Share', 'mission-donation-platform' ); ?>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the fundraiser progress block output.
	 *
	 * @param string     $output     HTML output.
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param array      $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_fundraiser_progress_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
