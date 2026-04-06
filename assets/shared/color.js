/**
 * Darken a hex color by a percentage.
 *
 * @param {string} hex     Hex color (e.g. '#2fa36b').
 * @param {number} percent Percentage to darken (0–100).
 * @return {string} Darkened hex color.
 */
export function darkenColor( hex, percent ) {
  const h = hex.replace( '#', '' );
  const r = Math.max(
    0,
    Math.round( parseInt( h.substring( 0, 2 ), 16 ) * ( 1 - percent / 100 ) )
  );
  const g = Math.max(
    0,
    Math.round( parseInt( h.substring( 2, 4 ), 16 ) * ( 1 - percent / 100 ) )
  );
  const b = Math.max(
    0,
    Math.round( parseInt( h.substring( 4, 6 ), 16 ) * ( 1 - percent / 100 ) )
  );
  return `#${ r.toString( 16 ).padStart( 2, '0' ) }${ g
    .toString( 16 )
    .padStart( 2, '0' ) }${ b.toString( 16 ).padStart( 2, '0' ) }`;
}

/**
 * Compute primary-color CSS custom properties from a hex color.
 *
 * @param {string} color Hex color.
 * @return {Object} CSS custom properties object.
 */
export function computePrimaryColorVars( color ) {
  const hex = color.replace( '#', '' );
  const r = parseInt( hex.substring( 0, 2 ), 16 );
  const g = parseInt( hex.substring( 2, 4 ), 16 );
  const b = parseInt( hex.substring( 4, 6 ), 16 );
  const luminance = ( 0.299 * r + 0.587 * g + 0.114 * b ) / 255;

  return {
    '--mission-primary': color,
    '--mission-primary-hover': darkenColor( color, 12 ),
    '--mission-primary-light': `rgba(${ r }, ${ g }, ${ b }, 0.08)`,
    '--mission-primary-text': luminance > 0.5 ? '#1e1e1e' : '#ffffff',
    '--mission-primary-text-on-light':
      luminance > 0.5 ? darkenColor( color, 45 ) : color,
  };
}
