<?php
/**
 * Block Name: Fundraiser Progress
 * Description: A fundraiser's progress thermometer, stats, and donate/share buttons.
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


( static function ( $attributes, $content, $block ): void {
// Resolve the fundraiser from the block attribute or the queried shell page.
$fundraiser = null;

if ( ! empty( $attributes['fundraiserId'] ) ) {
	$fundraiser = Fundraiser::find( (int) $attributes['fundraiserId'] );
} else {
	$current_post = get_post();
	if ( $current_post && Fundraiser::POST_TYPE === $current_post->post_type ) {
		$fundraiser = Fundraiser::find_by_post_id( $current_post->ID );
	}
}

if ( ! $fundraiser ) {
	return;
}

$mission_settings = get_option( 'missiondp_settings', [] );
$is_test          = (bool) ( $mission_settings['test_mode'] ?? false );
$currency         = strtoupper( $mission_settings['currency'] ?? 'USD' );

$raised     = $fundraiser->amount_raised( $is_test );
$goal       = $fundraiser->goal;
$has_goal   = $goal > 0;
$percentage = BlockSupport::progress_percent( $raised, $goal );
$donors     = $is_test ? $fundraiser->test_donor_count : $fundraiser->donor_count;
$donations  = $is_test ? $fundraiser->test_transaction_count : $fundraiser->transaction_count;

$share_url = $fundraiser->get_url() ?? '';

ob_start();
?>
<div
	<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-fp-progress' ] ) ); ?>
	data-wp-interactive="mission-donation-platform/fundraiser-progress"
	style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
>
	<div class="mission-fp-progress__header">
		<span class="mission-fp-progress__raised"><?php echo esc_html( Currency::format_amount( $raised, $currency ) ); ?></span>
		<?php if ( $has_goal ) : ?>
			<span class="mission-fp-progress__goal">
				<?php
				/* translators: %s: formatted goal amount */
				echo esc_html( sprintf( __( 'raised of %s goal', 'mission-donation-platform' ), Currency::format_amount( $goal, $currency ) ) );
				?>
			</span>
			<span class="mission-fp-progress__percentage"><?php echo esc_html( $percentage . '%' ); ?></span>
		<?php else : ?>
			<span class="mission-fp-progress__goal"><?php esc_html_e( 'raised', 'mission-donation-platform' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $has_goal ) : ?>
		<div class="mission-fp-progress__bar" data-wp-init="callbacks.animateBar">
			<div class="mission-fp-progress__fill" style="--bar-width: <?php echo esc_attr( $percentage ); ?>%"></div>
		</div>
	<?php endif; ?>

	<div class="mission-fp-progress__stats">
		<div class="mission-fp-progress__stat">
			<span class="mission-fp-progress__stat-value"><?php echo esc_html( number_format_i18n( $donations ) ); ?></span>
			<span class="mission-fp-progress__stat-label"><?php esc_html_e( 'Donations', 'mission-donation-platform' ); ?></span>
		</div>
		<div class="mission-fp-progress__stat">
			<span class="mission-fp-progress__stat-value"><?php echo esc_html( number_format_i18n( $donors ) ); ?></span>
			<span class="mission-fp-progress__stat-label"><?php esc_html_e( 'Donors', 'mission-donation-platform' ); ?></span>
		</div>
	</div>

	<div class="mission-fp-progress__actions">
		<button type="button" class="mission-fp-progress__btn" data-wp-on--click="actions.scrollToForm">
			<?php esc_html_e( 'Donate', 'mission-donation-platform' ); ?>
		</button>
		<button
			type="button"
			class="mission-fp-progress__btn mission-fp-progress__btn--secondary"
			data-wp-on--click="actions.share"
			data-share-url="<?php echo esc_url( $share_url ); ?>"
		>
			<?php esc_html_e( 'Share', 'mission-donation-platform' ); ?>
		</button>
	</div>
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
} )( $attributes, $content, $block );
