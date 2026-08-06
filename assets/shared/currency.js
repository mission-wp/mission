/**
 * Currency utilities.
 *
 * Reads the site currency from the localized `missiondpAdmin.currency` value
 * (defaults to USD) and provides formatting helpers.
 */
import { getCurrencyDecimals, minorToMajor } from '@shared/currencies';

/**
 * Get the configured currency code.
 *
 * @return {string} Uppercase ISO 4217 code (e.g. "USD").
 */
export function getCurrencyCode() {
  return window.missiondpAdmin?.currency || 'USD';
}

/**
 * Get the locale to format with: the WP site (or admin user) locale from the
 * html lang attribute, so client formatting matches server-rendered amounts
 * instead of following the browser's own locale.
 *
 * @return {string|undefined} BCP 47 locale, or undefined to let Intl decide.
 */
function getLocale() {
  return document.documentElement.lang || undefined;
}

/**
 * Build an Intl currency formatter, falling back to the browser locale when
 * the html lang attribute isn't a valid BCP 47 tag.
 *
 * @param {Object} options Intl.NumberFormat options (style: 'currency', …).
 * @return {Intl.NumberFormat} Formatter instance.
 */
function currencyFormatter( options ) {
  try {
    return new Intl.NumberFormat( getLocale(), options );
  } catch {
    return new Intl.NumberFormat( undefined, options );
  }
}

/**
 * Get the currency symbol for a given currency code (or the site default).
 *
 * @param {string=} currencyCode Optional ISO 4217 code (e.g. "EUR"). Defaults to site currency.
 * @return {string} Symbol like "$", "€", "£", "¥".
 */
export function getCurrencySymbol( currencyCode ) {
  const code = currencyCode?.toUpperCase() || getCurrencyCode();

  try {
    const formatted = currencyFormatter( {
      style: 'currency',
      currency: code,
      maximumFractionDigits: 0,
    } ).format( 0 );

    return formatted.replace( /[\d.,\s]/g, '' ).trim();
  } catch {
    return '$';
  }
}

/**
 * Format a value in minor units (cents) as a currency string.
 *
 * @param {number}  minorUnits               Amount in minor units (e.g. 4500 = $45.00).
 * @param {string=} currencyCode             Optional ISO 4217 code override (e.g. "EUR").
 * @param {Object}  [options]
 * @param {boolean} [options.stripZeroCents] Drop ".00" when the amount is a whole number.
 * @return {string} Formatted string like "$45.00" or "¥4500".
 */
export function formatAmount(
  minorUnits,
  currencyCode,
  { stripZeroCents = false } = {}
) {
  const code = currencyCode?.toUpperCase() || getCurrencyCode();
  const decimals = getCurrencyDecimals( code );
  const value = minorToMajor( minorUnits, code );
  const fractionDigits =
    stripZeroCents && Number.isInteger( value ) ? 0 : decimals;

  try {
    return currencyFormatter( {
      style: 'currency',
      currency: code,
      minimumFractionDigits: fractionDigits,
      maximumFractionDigits: fractionDigits,
    } ).format( value );
  } catch {
    return `$${ fractionDigits ? value.toFixed( decimals ) : value }`;
  }
}
