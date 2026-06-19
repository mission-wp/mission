<?php
/**
 * Block Name: Campaign Image
 * Description: Display the image associated with a campaign.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use MissionDP\Campaigns\CampaignPostType;
use MissionDP\Models\Campaign;
use MissionDP\P2P\BlockSupport;

defined( 'ABSPATH' ) || exit;


( static function ( $attributes, $content, $block ): void {
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

// Determine the image size from the resolution attribute.
$resolution    = $attributes['resolution'] ?? 'large';
$valid_sizes   = array_keys( wp_get_registered_image_subsizes() );
$valid_sizes[] = 'full';
$resolution    = in_array( $resolution, $valid_sizes, true ) ? $resolution : 'large';

$image_url = $campaign->get_image_url( $resolution );

if ( ! $image_url ) {
	return;
}

$image_id  = $campaign->get_image_id();
$image_alt = $attributes['alt'] ?? '';

// Fall back to the attachment alt text if none specified.
if ( '' === $image_alt && $image_id ) {
	$image_alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
}

// Inline styles for the img element (aspect ratio, dimensions, border, shadow).
$inline_style   = BlockSupport::image_inline_style( $attributes );
$img_style_attr = '' !== $inline_style ? ' style="' . esc_attr( $inline_style ) . '"' : '';

// Border and shadow drive the wrapper class and box-shadow stripping below.
$border_styles = $attributes['style']['border'] ?? [];
$shadow        = $attributes['style']['shadow'] ?? '';

// Get intrinsic dimensions and responsive attributes from the image metadata.
$img_width_attr  = '';
$img_height_attr = '';
$srcset_attr     = '';
$sizes_attr      = '';

if ( $image_id ) {
	$image_src = wp_get_attachment_image_src( $image_id, $resolution );

	if ( $image_src ) {
		$img_width_attr  = ' width="' . (int) $image_src[1] . '"';
		$img_height_attr = ' height="' . (int) $image_src[2] . '"';
	}

	// Only include responsive srcset/sizes for large or full resolutions.
	// Smaller sizes like thumbnail and medium are intentionally fixed.
	if ( in_array( $resolution, [ 'large', 'full' ], true ) ) {
		$srcset = wp_get_attachment_image_srcset( $image_id, $resolution );
		$sizes  = wp_get_attachment_image_sizes( $image_id, $resolution );

		if ( $srcset ) {
			$srcset_attr = ' srcset="' . esc_attr( $srcset ) . '"';
		}
		if ( $sizes ) {
			$sizes_attr = ' sizes="' . esc_attr( $sizes ) . '"';
		}
	}
}

$wrapper_classes = 'mission-campaign-image';
if ( ! empty( $border_styles ) ) {
	$wrapper_classes .= ' has-custom-border';
}
$wrapper_attrs = get_block_wrapper_attributes( [ 'class' => $wrapper_classes ] );

// Strip box-shadow from the wrapper since we apply it to the img instead.
if ( $shadow ) {
	$wrapper_attrs = preg_replace( '/box-shadow:[^;]*;?\s*/', '', $wrapper_attrs );
}

?>
<figure <?php echo wp_kses_post( $wrapper_attrs ); ?>>
	<img
		src="<?php echo esc_url( $image_url ); ?>"
		alt="<?php echo esc_attr( $image_alt ); ?>"
		<?php echo wp_kses_post( $img_width_attr ); ?>
		<?php echo wp_kses_post( $img_height_attr ); ?>
		<?php echo wp_kses_post( $srcset_attr ); ?>
		<?php echo wp_kses_post( $sizes_attr ); ?>
		<?php echo wp_kses_post( $img_style_attr ); ?>
		loading="lazy"
	/>
</figure>
<?php } )( $attributes, $content, $block );
