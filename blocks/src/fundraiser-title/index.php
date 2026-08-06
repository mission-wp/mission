<?php
/**
 * Block Name: Fundraiser Title
 * Description: A fundraiser's heading and optional tribute.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Models\Fundraiser;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes ): void {
	// Resolve the fundraiser from the block attribute or the queried shell page.
	$fundraiser = BlockSupport::resolve_fundraiser( $attributes );

	if ( ! $fundraiser ) {
		return;
	}

	$donor        = $fundraiser->donor();
	$name         = $donor ? trim( $donor->first_name . ' ' . $donor->last_name ) : '';
	$name         = $name ?: __( 'A fundraiser', 'mission-donation-platform' );
	$campaign     = $fundraiser->campaign();
	$campaign_url = $campaign ? $campaign->get_url() : null;
	$headline     = $fundraiser->headline;

	$tribute_text = $fundraiser->dedication_label();

	ob_start();
	?>
	<div
		<?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'mission-ft-title' ] ) ); ?>
		style="<?php echo esc_attr( BlockSupport::primary_color_style() ); ?>"
	>
		<h1 class="mission-ft-title__heading">
			<?php
			if ( $campaign_url ) {
				printf(
					/* translators: 1: fundraiser name, 2: linked campaign title */
					esc_html__( '%1$s is fundraising for %2$s', 'mission-donation-platform' ),
					esc_html( $name ),
					'<a href="' . esc_url( $campaign_url ) . '">' . esc_html( $campaign->title ) . '</a>'
				);
			} else {
				echo esc_html( $name );
			}
			?>
		</h1>

		<?php if ( '' !== $headline ) : ?>
			<p class="mission-ft-title__headline"><?php echo esc_html( $headline ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $tribute_text ) : ?>
			<p class="mission-ft-title__tribute"><?php echo esc_html( $tribute_text ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	$output = ob_get_clean();

	/**
	 * Filters the fundraiser title block output.
	 *
	 * @param string     $output     HTML output.
	 * @param Fundraiser $fundraiser Fundraiser model.
	 * @param array      $attributes Block attributes.
	 */
	echo wp_kses( apply_filters( 'mission_fundraiser_title_output', $output, $fundraiser, $attributes ), \MissionDP\Helpers\Kses::block_allowed_html() );
} )( $attributes );
