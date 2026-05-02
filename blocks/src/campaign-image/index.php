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

// Build inline styles for the img element (matching core/image pattern).
$img_styles   = [];
$aspect_ratio = $attributes['aspectRatio'] ?? '';
$width        = $attributes['width'] ?? '';
$height       = $attributes['height'] ?? '';
$scale        = $attributes['scale'] ?? 'cover';
$valid_scales = [ 'cover', 'contain' ];
$scale        = in_array( $scale, $valid_scales, true ) ? $scale : 'cover';

if ( $aspect_ratio ) {
	$img_styles[] = 'aspect-ratio:' . esc_attr( $aspect_ratio );
}
if ( $aspect_ratio || ( $width && $height ) ) {
	$img_styles[] = 'object-fit:' . esc_attr( $scale );
}
if ( $width ) {
	$img_styles[] = 'width:' . esc_attr( $width ) . 'px';
}
if ( $height ) {
	$img_styles[] = 'height:' . esc_attr( $height ) . 'px';
}

// Border styles (skip-serialized, applied manually to img).
$border_styles = $attributes['style']['border'] ?? [];
if ( ! empty( $border_styles['radius'] ) ) {
	$radius = $border_styles['radius'];
	if ( is_array( $radius ) ) {
		$img_styles[] = 'border-top-left-radius:' . esc_attr( $radius['topLeft'] ?? '0' );
		$img_styles[] = 'border-top-right-radius:' . esc_attr( $radius['topRight'] ?? '0' );
		$img_styles[] = 'border-bottom-left-radius:' . esc_attr( $radius['bottomLeft'] ?? '0' );
		$img_styles[] = 'border-bottom-right-radius:' . esc_attr( $radius['bottomRight'] ?? '0' );
	} else {
		$img_styles[] = 'border-radius:' . esc_attr( $radius );
	}
}
if ( ! empty( $border_styles['width'] ) ) {
	$img_styles[] = 'border-width:' . esc_attr( $border_styles['width'] );
}
if ( ! empty( $border_styles['style'] ) ) {
	$img_styles[] = 'border-style:' . esc_attr( $border_styles['style'] );
}
if ( ! empty( $border_styles['color'] ) ) {
	$color = $border_styles['color'];
	if ( str_starts_with( $color, 'var:preset|color|' ) ) {
		$color = 'var(--wp--preset--color--' . str_replace( 'var:preset|color|', '', $color ) . ')';
	}
	$img_styles[] = 'border-color:' . esc_attr( $color );
}

// Shadow (skip-serialized, applied manually to img).
$shadow = $attributes['style']['shadow'] ?? '';
if ( $shadow ) {
	if ( str_starts_with( $shadow, 'var:preset|shadow|' ) ) {
		$shadow = 'var(--wp--preset--shadow--' . str_replace( 'var:preset|shadow|', '', $shadow ) . ')';
	}
	$img_styles[] = 'box-shadow:' . esc_attr( $shadow );
}

$img_style_attr = $img_styles ? ' style="' . implode( ';', $img_styles ) . '"' : '';

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
