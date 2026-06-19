/**
 * Shared image style helpers for the campaign/fundraiser/team image blocks.
 *
 * These blocks mirror the core Image block: aspect ratio, dimensions, scale,
 * border, and shadow are applied as inline styles on the image element so the
 * editor preview and the frontend render stay in sync.
 */

/**
 * Whether the block is using a wide or full alignment.
 *
 * @param {string} align Alignment attribute.
 * @return {boolean} True for wide/full alignment.
 */
export function isWideAligned( align ) {
  return [ 'wide', 'full' ].includes( align );
}

/**
 * Whether the scale (object-fit) control should be shown.
 *
 * Scale only matters once the image has a fixed shape, i.e. an aspect ratio or
 * both dimensions, and it does not apply to wide/full alignments.
 *
 * @param {Object} attributes Block attributes.
 * @param {string} align      Alignment attribute.
 * @return {boolean} True when the scale control is relevant.
 */
export function showScaleControl( attributes, align ) {
  const { aspectRatio, width, height } = attributes;
  return (
    ! isWideAligned( align ) && Boolean( aspectRatio || ( width && height ) )
  );
}

/**
 * Resolve a stored color value, expanding theme preset references.
 *
 * @param {string} value     Raw value (may be `var:preset|color|slug`).
 * @param {string} presetKey Preset namespace ('color' or 'shadow').
 * @return {string} A usable CSS value.
 */
function resolvePreset( value, presetKey ) {
  const prefix = `var:preset|${ presetKey }|`;
  if ( value.startsWith( prefix ) ) {
    return `var(--wp--preset--${ presetKey }--${ value.replace(
      prefix,
      ''
    ) })`;
  }
  return value;
}

/**
 * Build the inline style object for an image element from block attributes.
 *
 * Matches the core Image block: aspect ratio, width/height (px coerced),
 * object-fit scale, border (radius/width/style/color), and box-shadow. Border
 * and shadow come from the skip-serialized `style` attribute and are applied to
 * the image rather than the wrapper.
 *
 * @param {Object} attributes Block attributes.
 * @param {string} align      Alignment attribute.
 * @return {Object} A React style object.
 */
export function computeImageStyles( attributes, align ) {
  const { aspectRatio, width, height, scale } = attributes;
  const style = {};

  if ( aspectRatio ) {
    style.aspectRatio = aspectRatio;
  }
  if ( showScaleControl( attributes, align ) ) {
    style.objectFit = scale;
  }
  if ( width ) {
    style.width = width.includes( 'px' ) ? width : width + 'px';
  }
  if ( height ) {
    style.height = height.includes( 'px' ) ? height : height + 'px';
  }

  const border = attributes?.style?.border || {};
  if ( border.radius ) {
    if ( typeof border.radius === 'object' ) {
      style.borderTopLeftRadius = border.radius.topLeft || 0;
      style.borderTopRightRadius = border.radius.topRight || 0;
      style.borderBottomLeftRadius = border.radius.bottomLeft || 0;
      style.borderBottomRightRadius = border.radius.bottomRight || 0;
    } else {
      style.borderRadius = border.radius;
    }
  }
  if ( border.width ) {
    style.borderWidth = border.width;
  }
  if ( border.style ) {
    style.borderStyle = border.style;
  }
  if ( border.color ) {
    style.borderColor = resolvePreset( border.color, 'color' );
  }

  const shadow = attributes?.style?.shadow || '';
  if ( shadow ) {
    style.boxShadow = resolvePreset( shadow, 'shadow' );
  }

  return style;
}
