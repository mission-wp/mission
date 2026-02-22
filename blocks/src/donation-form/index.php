<?php
/**
 * Block Name: Donation Form
 * Description: A donation form block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

$block_classes = array( 'mission-donation-form' );
if ( ! empty( $attributes['align'] ) ) {
	$block_classes[] = 'align' . $attributes['align'];
}
?>

<section <?php echo get_block_wrapper_attributes( array( 'class' => implode( ' ', $block_classes ) ) ); ?>>
	<!-- Donation form will be rendered here -->
</section>
