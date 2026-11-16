/**
 * Translated strings for Interactivity API view modules.
 *
 * Script modules can't import @wordpress/i18n, so each block's PHP template
 * passes its strings through the block context's `i18n` map.
 */

/**
 * Look up a translated string, falling back to English.
 *
 * The fallback covers cached pages rendered before a string was added.
 *
 * @param {Object}    ctx      Block context holding the `i18n` map.
 * @param {string}    key      Key in the map.
 * @param {string}    fallback English fallback.
 * @param {...string} values   Values for the %s or %1$s placeholders, in order.
 * @return {string} Translated string with placeholders filled.
 */
export function t( ctx, key, fallback, ...values ) {
  return values.reduce(
    ( text, value, index ) =>
      text
        .replace( `%${ index + 1 }$s`, () => value )
        .replace( '%s', () => value ),
    ctx?.i18n?.[ key ] || fallback
  );
}
