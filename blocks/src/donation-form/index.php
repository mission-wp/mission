<?php
/**
 * Block Name: Donation Form
 * Description: A donation form block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use Mission\Blocks\DonationFormSettings;

$settings = DonationFormSettings::resolve( $attributes );

$block_classes = array( 'mission-donation-form' );
if ( ! empty( $attributes['align'] ) ) {
	$block_classes[] = 'align' . $attributes['align'];
}
?>

<section
	<?php echo get_block_wrapper_attributes( array( 'class' => implode( ' ', $block_classes ) ) ); ?>
	data-settings="<?php echo esc_attr( wp_json_encode( $settings ) ); ?>"
>
	<!-- Donation form will be rendered here by the Interactivity API -->
</section>
