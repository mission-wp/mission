<?php
/**
 * Block Name: Donate Button
 * Description: A customizable donate button that scrolls to a donation form or links to a URL.
 *
 * @package MissionDP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

// Helper to resolve WordPress preset value notation → CSS variable.
$resolve_preset = static function ( string $value ): string {
	if ( str_starts_with( $value, 'var:preset|' ) ) {
		$parts = explode( '|', $value );
		if ( count( $parts ) === 3 ) {
			return sprintf( 'var(--wp--preset--%s--%s)', $parts[1], $parts[2] );
		}
	}
	return $value;
};

$text          = $attributes['text'] ?? __( 'Donate Now', 'mission-donation-platform' );
$donate_action = $attributes['donateButtonAction'] ?? 'scroll';
$donate_url    = $attributes['donateButtonUrl'] ?? '';

// ── Inner element: inline styles ────────────────────────────────────────────

$inner_styles  = [];
$inner_classes = [ 'mission-donate-button__link' ];

// Color.
$bg_slug       = $attributes['backgroundColor'] ?? '';
$text_slug     = $attributes['textColor'] ?? '';
$gradient_slug = $attributes['gradient'] ?? '';
$style_bg      = $attributes['style']['color']['background'] ?? '';
$style_text    = $attributes['style']['color']['text'] ?? '';
$style_grad    = $attributes['style']['color']['gradient'] ?? '';

if ( $bg_slug ) {
	$inner_classes[] = "has-{$bg_slug}-background-color";
	$inner_classes[] = 'has-background';
} elseif ( $style_bg ) {
	$inner_classes[] = 'has-background';
	$inner_styles[]  = 'background-color:' . esc_attr( $resolve_preset( $style_bg ) );
}

if ( $text_slug ) {
	$inner_classes[] = "has-{$text_slug}-color";
	$inner_classes[] = 'has-text-color';
} elseif ( $style_text ) {
	$inner_classes[] = 'has-text-color';
	$inner_styles[]  = 'color:' . esc_attr( $resolve_preset( $style_text ) );
}

if ( $gradient_slug ) {
	$inner_classes[] = "has-{$gradient_slug}-gradient-background";
	$inner_classes[] = 'has-background';
} elseif ( $style_grad ) {
	$inner_classes[] = 'has-background';
	$inner_styles[]  = 'background:' . esc_attr( $resolve_preset( $style_grad ) );
}

// Typography.
$font_size_slug = $attributes['fontSize'] ?? '';
$style_typo     = $attributes['style']['typography'] ?? [];

if ( $font_size_slug ) {
	$inner_classes[] = "has-{$font_size_slug}-font-size";
} elseif ( ! empty( $style_typo['fontSize'] ) ) {
	$inner_styles[] = 'font-size:' . esc_attr( $style_typo['fontSize'] );
}

$font_family_slug = $attributes['fontFamily'] ?? '';
if ( $font_family_slug ) {
	$inner_classes[] = "has-{$font_family_slug}-font-family";
} elseif ( ! empty( $style_typo['fontFamily'] ) ) {
	$inner_styles[] = 'font-family:' . esc_attr( $style_typo['fontFamily'] );
}

if ( ! empty( $style_typo['fontWeight'] ) ) {
	$inner_styles[] = 'font-weight:' . esc_attr( $style_typo['fontWeight'] );
}
if ( ! empty( $style_typo['fontStyle'] ) ) {
	$inner_styles[] = 'font-style:' . esc_attr( $style_typo['fontStyle'] );
}
if ( ! empty( $style_typo['textTransform'] ) ) {
	$inner_styles[] = 'text-transform:' . esc_attr( $style_typo['textTransform'] );
}
if ( ! empty( $style_typo['letterSpacing'] ) ) {
	$inner_styles[] = 'letter-spacing:' . esc_attr( $style_typo['letterSpacing'] );
}
if ( ! empty( $style_typo['lineHeight'] ) ) {
	$inner_styles[] = 'line-height:' . esc_attr( $style_typo['lineHeight'] );
}

// Spacing (padding).
$padding = $attributes['style']['spacing']['padding'] ?? [];
if ( ! empty( $padding['top'] ) ) {
	$inner_styles[] = 'padding-top:' . esc_attr( $resolve_preset( $padding['top'] ) );
}
if ( ! empty( $padding['right'] ) ) {
	$inner_styles[] = 'padding-right:' . esc_attr( $resolve_preset( $padding['right'] ) );
}
if ( ! empty( $padding['bottom'] ) ) {
	$inner_styles[] = 'padding-bottom:' . esc_attr( $resolve_preset( $padding['bottom'] ) );
}
if ( ! empty( $padding['left'] ) ) {
	$inner_styles[] = 'padding-left:' . esc_attr( $resolve_preset( $padding['left'] ) );
}

// Border.
$border = $attributes['style']['border'] ?? [];
if ( ! empty( $border['radius'] ) ) {
	$radius = $border['radius'];
	if ( is_array( $radius ) ) {
		if ( ! empty( $radius['topLeft'] ) ) {
			$inner_styles[] = 'border-top-left-radius:' . esc_attr( $radius['topLeft'] );
		}
		if ( ! empty( $radius['topRight'] ) ) {
			$inner_styles[] = 'border-top-right-radius:' . esc_attr( $radius['topRight'] );
		}
		if ( ! empty( $radius['bottomLeft'] ) ) {
			$inner_styles[] = 'border-bottom-left-radius:' . esc_attr( $radius['bottomLeft'] );
		}
		if ( ! empty( $radius['bottomRight'] ) ) {
			$inner_styles[] = 'border-bottom-right-radius:' . esc_attr( $radius['bottomRight'] );
		}
	} else {
		$inner_styles[] = 'border-radius:' . esc_attr( $radius );
	}
}
if ( ! empty( $border['width'] ) ) {
	$inner_styles[] = 'border-width:' . esc_attr( $border['width'] );
}
if ( ! empty( $border['style'] ) ) {
	$inner_styles[] = 'border-style:' . esc_attr( $border['style'] );
}
if ( ! empty( $border['color'] ) ) {
	$inner_styles[] = 'border-color:' . esc_attr( $resolve_preset( $border['color'] ) );
}

// Shadow.
$shadow = $attributes['style']['shadow'] ?? '';
if ( $shadow ) {
	$inner_styles[] = 'box-shadow:' . esc_attr( $resolve_preset( $shadow ) );
}

$inner_style_attr = $inner_styles ? ' style="' . implode( ';', $inner_styles ) . '"' : '';
$inner_class_attr = implode( ' ', $inner_classes );

// ── Wrapper: width handling ─────────────────────────────────────────────────

$dimension_width     = $attributes['style']['dimensions']['width'] ?? '';
$wrapper_extra_class = '';
$wrapper_extra_style = '';

if ( $dimension_width ) {
	$preset_widths = [ '25%', '50%', '75%', '100%' ];
	if ( in_array( $dimension_width, $preset_widths, true ) ) {
		$n                   = (int) str_replace( '%', '', $dimension_width );
		$wrapper_extra_class = "has-custom-width mission-donate-button__width-{$n}";
	} else {
		$wrapper_extra_class = 'has-custom-width';
		$wrapper_extra_style = "width:{$dimension_width};";
	}
}

$wrapper_args = [];
if ( $wrapper_extra_class ) {
	$wrapper_args['class'] = $wrapper_extra_class;
}
if ( $wrapper_extra_style ) {
	$wrapper_args['style'] = $wrapper_extra_style;
}
$wrapper_attrs = get_block_wrapper_attributes( $wrapper_args );

// Strip shadow from wrapper — WordPress serializes it there since shadow
// doesn't support __experimentalSkipSerialization. We apply it to the inner element.
if ( $shadow ) {
	$wrapper_attrs = preg_replace( '/box-shadow:[^;]*;?\s*/', '', $wrapper_attrs );
}

// Strip width from wrapper if we are handling it via classes.
if ( $dimension_width && ! $wrapper_extra_style ) {
	$wrapper_attrs = preg_replace( '/\bwidth:[^;]*;?\s*/', '', $wrapper_attrs );
}

// ── Render ──────────────────────────────────────────────────────────────────

ob_start();
?>
<div
	<?php echo wp_kses_post( $wrapper_attrs ); ?>
	data-wp-interactive="mission-donation-platform/donate-button"
>
	<?php if ( 'scroll' === $donate_action ) : ?>
		<button
			type="button"
			class="<?php echo esc_attr( $inner_class_attr ); ?>"
			<?php echo wp_kses_post( $inner_style_attr ); ?>
			data-wp-on--click="actions.scrollToForm"
		>
			<?php echo wp_kses_post( $text ); ?>
		</button>
	<?php else : ?>
		<a
			href="<?php echo esc_url( $donate_url ); ?>"
			class="<?php echo esc_attr( $inner_class_attr ); ?>"
			<?php echo wp_kses_post( $inner_style_attr ); ?>
		>
			<?php echo wp_kses_post( $text ); ?>
		</a>
	<?php endif; ?>
</div>
<?php
$output = ob_get_clean();

/**
 * Filters the donate button block output.
 *
 * @param string $output     HTML output.
 * @param array  $attributes Block attributes.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped above, filter consumers are responsible for their additions.
echo apply_filters( 'missiondp_donate_button_output', $output, $attributes );
